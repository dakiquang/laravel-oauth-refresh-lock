<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Exceptions;

use Throwable;

/**
 * The refresh failed for a reason that may go away (network, timeout, 5xx,
 * rate limit). The stored token was left untouched.
 */
final class TransientRefreshFailure extends OAuthRefreshLockException
{
    public function __construct(
        public readonly string $key,
        Throwable $previous,
    ) {
        parent::__construct("Refreshing the OAuth token for key [{$key}] failed temporarily; the token was kept. {$previous->getMessage()}", 0, $previous);
    }
}
