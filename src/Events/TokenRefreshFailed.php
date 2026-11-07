<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Events;

use Dakiquang\OAuthRefreshLock\RefreshFailure;

/**
 * The provider call failed and the token was kept: a temporary failure or one
 * the classifier did not recognise. A revocation is TokenRevokedByProvider.
 * Dispatched after the lock is released. `code` is the classifier's short code,
 * never the provider's message.
 */
final class TokenRefreshFailed
{
    public function __construct(
        public readonly string $key,
        public readonly RefreshFailure $failure,
        public readonly string $code,
    ) {}
}
