<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Exceptions;

use Throwable;

final class TokenRevoked extends OAuthRefreshLockException
{
    public function __construct(
        public readonly string $key,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("The OAuth token for key [{$key}] was revoked: {$reason}. The user must connect again.", 0, $previous);
    }
}
