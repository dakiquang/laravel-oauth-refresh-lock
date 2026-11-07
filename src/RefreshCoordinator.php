<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock;

use Carbon\CarbonImmutable;
use Closure;
use Dakiquang\OAuthRefreshLock\Contracts\ErrorClassifier;
use Dakiquang\OAuthRefreshLock\Contracts\Refresher;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Exceptions\RefreshLockTimeout;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenNotFound;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenRevoked;
use Dakiquang\OAuthRefreshLock\Exceptions\TransientRefreshFailure;
use Dakiquang\OAuthRefreshLock\Refreshers\CallbackRefresher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

/**
 * Hands out access tokens and refreshes them at most once per expiry, however
 * many processes ask at the same time.
 *
 * The lock is a lease: if a refresh outlives `lockSeconds`, another process
 * can take the lock and send the same (now retired) refresh token. That
 * process gets `invalid_grant` and may record a revocation. The process that
 * actually rotated the token detects this when its compare-and-swap fails and
 * restores the valid token. Keep the refresher's HTTP timeout well below
 * `lockSeconds` so this stays rare.
 */
class RefreshCoordinator
{
    public function __construct(
        private readonly TokenStore $store,
        private readonly LockProvider $locks,
        private readonly ErrorClassifier $classifier,
        private readonly string $lockPrefix = 'oauth-refresh-lock:',
        private readonly int $skewSeconds = 120,
        private readonly int $lockSeconds = 30,
        private readonly int $waitSeconds = 10,
    ) {}

    public function for(string $key): PendingRefresh
    {
        return new PendingRefresh($this, $key, $this->skewSeconds, $this->waitSeconds);
    }

    /**
     * A token whose access token is valid for at least $skewSeconds.
     * A token stored without an expiry is returned as is: there is nothing to compare.
     *
     * @param  Refresher|Closure(StoredToken): RefreshedToken  $refresher
     *
     * @throws TokenNotFound when nothing is stored for the key
     * @throws TokenRevoked when the token was revoked, now or earlier
     * @throws TransientRefreshFailure when the refresh failed temporarily; the token was kept
     * @throws RefreshLockTimeout when another process held the lock longer than $waitSeconds
     */
    public function token(string $key, Refresher|Closure $refresher, int $skewSeconds, int $waitSeconds): StoredToken
    {
        $token = $this->load($key);

        // Fast path: no lock while the token is still fresh.
        if (! $token->expiresWithin($skewSeconds, CarbonImmutable::now())) {
            return $token;
        }

        $refresher = $refresher instanceof Closure ? new CallbackRefresher($refresher) : $refresher;

        try {
            /** @var StoredToken */
            return $this->locks
                ->lock($this->lockName($key), $this->lockSeconds)
                ->block($waitSeconds, fn (): StoredToken => $this->refreshWhileLocked($key, $refresher, $skewSeconds));
        } catch (LockTimeoutException $timeout) {
            throw new RefreshLockTimeout($key, $waitSeconds, $timeout);
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
        try {
            return $this->locks
                ->lock($this->lockName($key), $this->lockSeconds)
                ->block($waitSeconds, fn () => $callback($this->store));
        } catch (LockTimeoutException $timeout) {
            throw new RefreshLockTimeout($key, $waitSeconds, $timeout);
        }
    }

    private function refreshWhileLocked(string $key, Refresher $refresher, int $skewSeconds): StoredToken
    {
        // Read again: the process that held the lock before us may have refreshed already.
        $current = $this->load($key);

        if (! $current->expiresWithin($skewSeconds, CarbonImmutable::now())) {
            return $current;
        }

        // Stores keep whole seconds; compare revocations against the start of this second.
        $startedAt = CarbonImmutable::now()->startOfSecond();

        try {
            $refreshed = $refresher->refresh($current);
        } catch (Throwable $error) {
            return $this->fail($key, $current, $error);
        }

        $next = $current->withRefreshed($refreshed);

        if ($this->store->put($key, $next, expectedVersion: $current->version)) {
            return $next;
        }

        // Someone wrote while we were refreshing. If it is a revocation recorded after we
        // started, it came from a process that reused the refresh token we just rotated
        // (our lease expired). Our new tokens are the valid ones: put them back.
        $stored = $this->store->get($key);
        if ($stored !== null && $stored->revokedAt !== null && $stored->revokedAt->greaterThanOrEqualTo($startedAt)) {
            $restored = $stored->withRefreshed($refreshed);
            if ($this->store->put($key, $restored, expectedVersion: $stored->version)) {
                return $restored;
            }
        }

        // Otherwise a reconnect or manual fix wins.
        return $this->load($key);
    }

    /**
     * @throws TokenRevoked|TransientRefreshFailure|Throwable
     */
    private function fail(string $key, StoredToken $current, Throwable $error): StoredToken
    {
        $classification = $this->classifier->classify($error);

        if ($classification->failure === RefreshFailure::Transient) {
            throw new TransientRefreshFailure($key, $classification->code, $error);
        }

        if ($classification->failure !== RefreshFailure::Revoked) {
            throw $error;
        }

        $revoked = $current->withRevoked($classification->code, CarbonImmutable::now());
        if ($this->store->put($key, $revoked, expectedVersion: $current->version)) {
            throw new TokenRevoked($key, $classification->code, $error);
        }

        // The version moved while we called the provider: someone refreshed or
        // reconnected, so our refresh token was just stale. Use what they stored.
        return $this->load($key);
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
