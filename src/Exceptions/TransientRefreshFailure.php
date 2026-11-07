<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Exceptions;

use Throwable;

/**
 * The refresh failed for a reason that may go away (network, timeout, 5xx,
 * rate limit). The stored token was left untouched. The provider's own error
 * is available through getPrevious(); it is not copied into this message
 * because it may contain a response body.
 */
final class TransientRefreshFailure extends OAuthRefreshLockException
{
    public function __construct(
        public readonly string $key,
        public readonly string $errorCode,
        Throwable $previous,
    ) {
        parent::__construct("Refreshing the OAuth token for key [{$key}] failed temporarily ({$errorCode}); the token was kept.", 0, $previous);
    }
}
