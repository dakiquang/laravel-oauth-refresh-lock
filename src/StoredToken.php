<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock;

use Carbon\CarbonImmutable;

/**
 * A token set as kept by a TokenStore. Immutable: every change returns a new
 * instance with a higher version, which stores use for compare-and-swap writes.
 */
final class StoredToken
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $meta  Anything the app wants to keep with the token (tenant id, provider name...).
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly array $scopes = [],
        public readonly array $meta = [],
        public readonly int $version = 0,
        public readonly ?CarbonImmutable $revokedAt = null,
        public readonly ?string $revokedReason = null,
        public readonly ?int $revokedFromVersion = null,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * True when the access token is already expired or expires within $seconds.
     * A token without a known expiry is treated as never expiring.
     */
    public function expiresWithin(int $seconds, CarbonImmutable $now): bool
    {
        return $this->expiresAt !== null
            && $this->expiresAt->lessThanOrEqualTo($now->addSeconds($seconds));
    }

    /**
     * The token after a successful refresh. Providers that do not rotate the
     * refresh token return none, so the current one is kept.
     */
    public function withRefreshed(RefreshedToken $refreshed): self
    {
        return new self(
            accessToken: $refreshed->accessToken,
            refreshToken: $refreshed->refreshToken ?? $this->refreshToken,
            expiresAt: $refreshed->expiresAt,
            scopes: $refreshed->scopes ?? $this->scopes,
            meta: $this->meta,
            version: $this->version + 1,
        );
    }

    /**
     * The token marked revoked. `revokedFromVersion` records which generation was
     * rejected, so a process that rotated that very generation can tell that the
     * rejection was caused by its own rotation.
     */
    public function withRevoked(string $reason, CarbonImmutable $now): self
    {
        return new self(
            accessToken: $this->accessToken,
            refreshToken: $this->refreshToken,
            expiresAt: $this->expiresAt,
            scopes: $this->scopes,
            meta: $this->meta,
            version: $this->version + 1,
            revokedAt: $now,
            revokedReason: $reason,
            revokedFromVersion: $this->version,
        );
    }
}
