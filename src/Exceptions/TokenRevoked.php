<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Exceptions;

use Throwable;

final class TokenRevoked extends OAuthRefreshLockException
{
    /**
     * @param  string  $reason  A short code such as `invalid_grant`; never a provider message.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("The OAuth token for key [{$key}] was revoked ({$reason}). The user must connect again.", 0, $previous);
    }
}
