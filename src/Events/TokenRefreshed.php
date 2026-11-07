<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Events;

use Carbon\CarbonImmutable;

/**
 * This process refreshed the token and stored the result. Dispatched after the
 * lock is released. Carries no token values.
 */
final class TokenRefreshed
{
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly ?CarbonImmutable $expiresAt,
    ) {}
}
