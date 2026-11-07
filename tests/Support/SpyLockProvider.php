<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Wraps the array cache's real locks, counts lock() calls, and can run a hook
 * right after the lock is acquired — used to simulate another process that
 * refreshed the token while this one was waiting.
 */
final class SpyLockProvider implements LockProvider
{
    public int $locksRequested = 0;

    /** @var list<string> */
    public array $lockNames = [];

    public ?Closure $afterAcquire = null;

    public function __construct(public readonly ArrayStore $store = new ArrayStore) {}

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        $this->locksRequested++;
        $this->lockNames[] = $name;

        return new HookedLock($this->store->lock($name, $seconds, $owner), $this);
    }

    public function restoreLock($name, $owner): Lock
    {
        return $this->store->restoreLock($name, $owner);
    }
}
