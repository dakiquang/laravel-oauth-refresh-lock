<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock;

use Closure;
use Dakiquang\OAuthRefreshLock\Contracts\Refresher;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;

/**
 * Fluent options for one token key. Each setter returns a copy.
 */
final class PendingRefresh
{
    public function __construct(
        private readonly RefreshCoordinator $coordinator,
        private readonly string $key,
        private readonly int $skewSeconds,
        private readonly int $waitSeconds,
    ) {}

    /** Refresh when the access token expires within this many seconds. */
    public function skew(int $seconds): self
    {
        return new self($this->coordinator, $this->key, max(0, $seconds), $this->waitSeconds);
    }

    /** How long to wait for another process that is refreshing the same token. */
    public function wait(int $seconds): self
    {
        return new self($this->coordinator, $this->key, $this->skewSeconds, max(0, $seconds));
    }

    /**
     * @param  Refresher|Closure(StoredToken): RefreshedToken  $refresher
     */
    public function token(Refresher|Closure $refresher): StoredToken
    {
        return $this->coordinator->token($this->key, $refresher, $this->skewSeconds, $this->waitSeconds);
    }

    /**
     * @param  Refresher|Closure(StoredToken): RefreshedToken  $refresher
     */
    public function accessToken(Refresher|Closure $refresher): string
    {
        return $this->token($refresher)->accessToken;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(TokenStore): TReturn  $callback
     * @return TReturn
     */
    public function withLock(Closure $callback): mixed
    {
        return $this->coordinator->withLock($this->key, $callback, $this->waitSeconds);
    }
}
