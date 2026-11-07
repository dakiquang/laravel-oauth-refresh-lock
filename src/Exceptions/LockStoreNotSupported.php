<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Exceptions;

final class LockStoreNotSupported extends OAuthRefreshLockException
{
    public static function forStore(string $store): self
    {
        return new self("The cache store [{$store}] does not support atomic locks. Set oauth-refresh-lock.lock_store to redis, memcached, dynamodb or database.");
    }
}
