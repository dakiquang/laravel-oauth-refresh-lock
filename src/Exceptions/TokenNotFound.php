<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Exceptions;

final class TokenNotFound extends OAuthRefreshLockException
{
    public static function forKey(string $key): self
    {
        return new self("No OAuth token is stored for key [{$key}].");
    }
}
