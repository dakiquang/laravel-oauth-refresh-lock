<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Support;

use Illuminate\Contracts\Cache\Lock;

final class HookedLock implements Lock
{
    public function __construct(private readonly Lock $inner, private readonly SpyLockProvider $spy) {}

    public function get($callback = null)
    {
        return $this->inner->get($callback === null ? null : $this->hooked($callback));
    }

    public function block($seconds, $callback = null)
    {
        if ($this->spy->acquireErrors !== []) {
            throw array_shift($this->spy->acquireErrors);
        }

        return $this->inner->block($seconds, $callback === null ? null : $this->hooked($callback));
    }

    public function release()
    {
        return $this->inner->release();
    }

    public function owner()
    {
        return $this->inner->owner();
    }

    public function forceRelease()
    {
        $this->inner->forceRelease();
    }

    private function hooked(callable $callback): callable
    {
        return function () use ($callback) {
            if ($this->spy->afterAcquire !== null) {
                ($this->spy->afterAcquire)();
            }

            return $callback();
        };
    }
}
