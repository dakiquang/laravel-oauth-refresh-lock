<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * A "lock" that never excludes anyone. Used only to prove that the
 * concurrency test detects the race when locking is missing.
 */
final class NoLockProvider implements LockProvider
{
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new class implements Lock
        {
            public function get($callback = null)
            {
                return $callback === null ? true : $callback();
            }

            public function block($seconds, $callback = null)
            {
                return $callback === null ? true : $callback();
            }

            public function release()
            {
                return true;
            }

            public function owner()
            {
                return 'nobody';
            }

            public function forceRelease()
            {
                //
            }
        };
    }

    public function restoreLock($name, $owner): Lock
    {
        return $this->lock($name);
    }
}
