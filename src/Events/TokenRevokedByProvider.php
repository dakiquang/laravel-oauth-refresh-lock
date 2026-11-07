<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Events;

/**
 * The provider rejected the refresh token (for example `invalid_grant`) and the
 * token is now stored as revoked: the user has to connect again. Dispatched
 * after the lock is released.
 */
final class TokenRevokedByProvider
{
    public function __construct(
        public readonly string $key,
        public readonly string $reason,
    ) {}
}
