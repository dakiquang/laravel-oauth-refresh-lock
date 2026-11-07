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
        private readonly ?string $rejectedAccessToken = null,
    ) {}

    /** Refresh when the access token expires within this many seconds. */
    public function skew(int $seconds): self
    {
        return new self($this->coordinator, $this->key, max(0, $seconds), $this->waitSeconds, $this->rejectedAccessToken);
    }

    /** How long to wait for another process that is refreshing the same token. */
    public function wait(int $seconds): self
    {
        return new self($this->coordinator, $this->key, $this->skewSeconds, max(0, $seconds), $this->rejectedAccessToken);
    }

    /**
     * The provider rejected this access token (for example with HTTP 401), so
     * refresh it whatever its expiry. Needed for providers that do not say when
     * access tokens expire. When another process has already replaced it, the
     * stored token is returned without a refresh.
     */
    public function rejected(string $accessToken): self
    {
        return new self($this->coordinator, $this->key, $this->skewSeconds, $this->waitSeconds, $accessToken);
    }

    /**
     * @param  Refresher|Closure(StoredToken): RefreshedToken  $refresher
     */
    public function token(Refresher|Closure $refresher): StoredToken
    {
        return $this->coordinator->token($this->key, $refresher, $this->skewSeconds, $this->waitSeconds, $this->rejectedAccessToken);
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
