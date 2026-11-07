<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Events;

/**
 * The provider rejected the refresh token (for example `invalid_grant`) and the
 * token was stored as revoked. Dispatched after the lock is released.
 *
 * Usually the user has to connect again, but check the stored token before
 * acting: if a refresh outlived the lock, the provider may have rejected a
 * refresh token that the slow process had just rotated. That process then
 * restores its valid tokens and a TokenRefreshed for the same key follows.
 */
final class TokenRevokedByProvider
{
    public function __construct(
        public readonly string $key,
        public readonly string $reason,
    ) {}
}
