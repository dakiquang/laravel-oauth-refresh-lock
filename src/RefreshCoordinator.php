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

        try {
            $refreshed = $refresher->refresh($current);
        } catch (Throwable $error) {
            $this->fail($key, $current, $error);
        }

        $next = $current->withRefreshed($refreshed);

        if ($this->store->put($key, $next, expectedVersion: $current->version)) {
            return $next;
        }

        // Someone wrote without the lock (a reconnect, a manual fix). Theirs wins.
        return $this->load($key);
    }

    /**
     * @throws TokenRevoked|TransientRefreshFailure|Throwable
     */
    private function fail(string $key, StoredToken $current, Throwable $error): never
    {
        $failure = $this->classifier->classify($error);

        if ($failure === RefreshFailure::Revoked) {
            $reason = $this->reason($error);
            // Keep the record, marked revoked, so the app knows who has to reconnect.
            $this->store->put($key, $current->withRevoked($reason, CarbonImmutable::now()), expectedVersion: $current->version);

            throw new TokenRevoked($key, $reason, $error);
        }

        if ($failure === RefreshFailure::Transient) {
            throw new TransientRefreshFailure($key, $error);
        }

        throw $error;
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

    private function reason(Throwable $error): string
    {
        $message = trim($error->getMessage());

        return mb_strimwidth($message === '' ? $error::class : $message, 0, 200, '…');
    }
}
