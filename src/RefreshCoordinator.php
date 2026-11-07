<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock;

use Carbon\CarbonImmutable;
use Closure;
use Dakiquang\OAuthRefreshLock\Contracts\ErrorClassifier;
use Dakiquang\OAuthRefreshLock\Contracts\Refresher;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Events\TokenRefreshed;
use Dakiquang\OAuthRefreshLock\Events\TokenRefreshFailed;
use Dakiquang\OAuthRefreshLock\Events\TokenRevokedByProvider;
use Dakiquang\OAuthRefreshLock\Exceptions\RefreshLockTimeout;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenNotFound;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenRevoked;
use Dakiquang\OAuthRefreshLock\Exceptions\TransientRefreshFailure;
use Dakiquang\OAuthRefreshLock\Refreshers\CallbackRefresher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Hands out access tokens and refreshes them at most once per expiry, however
 * many processes ask at the same time.
 *
 * The lock is a lease: if a refresh outlives `lockSeconds`, another process
 * can take the lock and send the same (now retired) refresh token. That
 * process gets `invalid_grant` and records a revocation of that generation.
 * The process that actually rotated it detects this when its compare-and-swap
 * fails and restores the valid token. Keep the refresher's HTTP timeout well
 * below `lockSeconds` so this stays rare.
 *
 * To disconnect a user, forget() the token (inside withLock()). A revoked
 * state is reserved for tokens the provider rejected.
 */
class RefreshCoordinator
{
    use DetectsConcurrencyErrors;

    public function __construct(
        private readonly TokenStore $store,
        private readonly LockProvider $locks,
        private readonly ErrorClassifier $classifier,
        private readonly string $lockPrefix = 'oauth-refresh-lock:',
        private readonly int $skewSeconds = 120,
        private readonly int $lockSeconds = 30,
        private readonly int $waitSeconds = 10,
        private readonly ?Dispatcher $events = null,
    ) {}

    public function for(string $key): PendingRefresh
    {
        return new PendingRefresh($this, $key, $this->skewSeconds, $this->waitSeconds);
    }

    /**
     * A token whose access token is valid for at least $skewSeconds.
     * A token stored without an expiry is returned as is: there is nothing to compare.
     * With $rejectedAccessToken, the token is also refreshed when it is still
     * that access token, whatever its expiry.
     *
     * @param  Refresher|Closure(StoredToken): RefreshedToken  $refresher
     *
     * @throws TokenNotFound when nothing is stored for the key
     * @throws TokenRevoked when the token was revoked, now or earlier
     * @throws TransientRefreshFailure when the refresh failed temporarily; the token was kept
     * @throws RefreshLockTimeout when another process held the lock longer than $waitSeconds
     */
    public function token(string $key, Refresher|Closure $refresher, int $skewSeconds, int $waitSeconds, ?string $rejectedAccessToken = null): StoredToken
    {
        $token = $this->load($key);

        // Fast path: no lock while the token is still usable.
        if (! $this->needsRefresh($token, $skewSeconds, $rejectedAccessToken)) {
            return $token;
        }

        $refresher = $refresher instanceof Closure ? new CallbackRefresher($refresher) : $refresher;

        // Events are collected under the lock and dispatched once it is released,
        // so slow listeners never hold the lock. As with any synchronous Laravel
        // listener, a listener's exception reaches the caller: after a refresh the
        // token is already stored; after a failure the original error is its previous.
        $events = [];
        try {
            return $this->underLock($key, $waitSeconds, function () use ($key, $refresher, $skewSeconds, $rejectedAccessToken, &$events): StoredToken {
                return $this->refreshWhileLocked($key, $refresher, $skewSeconds, $rejectedAccessToken, $events);
            });
        } finally {
            foreach ($events as $event) {
                $this->events?->dispatch($event);
            }
        }
    }

    /**
     * Run $callback while holding the refresh lock for $key, for example to
     * store a token from an OAuth callback or to disconnect.
     *
     * @template TReturn
     *
     * @param  Closure(TokenStore): TReturn  $callback
     * @return TReturn
     *
     * @throws RefreshLockTimeout
     */
    public function withLock(string $key, Closure $callback, int $waitSeconds): mixed
    {
        return $this->underLock($key, $waitSeconds, fn () => $callback($this->store));
    }

    /**
     * Laravel 10–12's database lock can deadlock on MySQL while workers compete
     * for the lock row. Such an error while acquiring only means this worker lost
     * the race, so keep waiting. Once the callback has started, any error is
     * rethrown: retrying could call the provider a second time.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws RefreshLockTimeout
     */
    private function underLock(string $key, int $waitSeconds, Closure $callback): mixed
    {
        $deadline = microtime(true) + $waitSeconds;
        $started = false;
        $run = function () use ($callback, &$started): mixed {
            $started = true;

            return $callback();
        };

        while (true) {
            try {
                /** @var TReturn */
                return $this->locks
                    ->lock($this->lockName($key), $this->lockSeconds)
                    ->block(max(0, (int) ceil($deadline - microtime(true))), $run);
            } catch (LockTimeoutException $timeout) {
                throw new RefreshLockTimeout($key, $waitSeconds, $timeout);
            } catch (QueryException $error) {
                // No retry without time left for one: the next attempt must start before the deadline.
                if ($started || ! $this->causedByConcurrencyError($error) || microtime(true) + 0.25 >= $deadline) {
                    throw $error;
                }
                usleep(250_000);
            }
        }
    }

    /**
     * @param  list<object>  $events
     */
    private function refreshWhileLocked(string $key, Refresher $refresher, int $skewSeconds, ?string $rejectedAccessToken, array &$events): StoredToken
    {
        // Read again: the process that held the lock before us may have refreshed already.
        $current = $this->load($key);

        if (! $this->needsRefresh($current, $skewSeconds, $rejectedAccessToken)) {
            return $current;
        }

        try {
            $refreshed = $refresher->refresh($current);
        } catch (Throwable $error) {
            return $this->fail($key, $current, $error, $events);
        }

        $next = $current->withRefreshed($refreshed);

        if ($this->store->put($key, $next, expectedVersion: $current->version)) {
            $events[] = new TokenRefreshed($key, $next->version, $next->expiresAt);

            return $next;
        }

        // Someone wrote while we were refreshing. If it is a revocation of the exact
        // generation we just rotated, it came from a process that reused our retired
        // refresh token after our lease expired. Our new tokens are the valid ones.
        $stored = $this->store->get($key);
        if ($stored !== null && $stored->isRevoked() && $stored->revokedFromVersion === $current->version) {
            $restored = $stored->withRefreshed($refreshed);
            if ($this->store->put($key, $restored, expectedVersion: $stored->version)) {
                $events[] = new TokenRefreshed($key, $restored->version, $restored->expiresAt);

                return $restored;
            }
        }

        // Otherwise a reconnect or manual fix wins.
        return $this->load($key);
    }

    /**
     * @param  list<object>  $events
     *
     * @throws TokenRevoked|TransientRefreshFailure|Throwable
     */
    private function fail(string $key, StoredToken $current, Throwable $error, array &$events): StoredToken
    {
        $classification = $this->classifier->classify($error);

        if ($classification->failure !== RefreshFailure::Revoked) {
            $events[] = new TokenRefreshFailed($key, $classification->failure, $classification->code);

            throw $classification->failure === RefreshFailure::Transient
                ? new TransientRefreshFailure($key, $classification->code, $error)
                : $error;
        }

        $revoked = $current->withRevoked($classification->code, CarbonImmutable::now());
        if ($this->store->put($key, $revoked, expectedVersion: $current->version)) {
            $events[] = new TokenRevokedByProvider($key, $classification->code);

            throw new TokenRevoked($key, $classification->code, $error);
        }

        // The version moved while we called the provider: someone refreshed or
        // reconnected, so our refresh token was just stale. Use what they stored.
        return $this->load($key);
    }

    private function needsRefresh(StoredToken $token, int $skewSeconds, ?string $rejectedAccessToken): bool
    {
        return $token->expiresWithin($skewSeconds, CarbonImmutable::now())
            || ($rejectedAccessToken !== null && hash_equals($token->accessToken, $rejectedAccessToken));
    }

    private function load(string $key): StoredToken
    {
        $token = $this->store->get($key) ?? throw TokenNotFound::forKey($key);

        if ($token->isRevoked()) {
            throw new TokenRevoked($key, $token->revokedReason ?? 'revoked');
        }

        return $token;
    }

    private function lockName(string $key): string
    {
        return $this->lockPrefix.$key;
    }
}
