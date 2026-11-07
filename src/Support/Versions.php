<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Support;

/**
 * @internal
 */
final class Versions
{
    /**
     * Starting version for a key that has no stored token. Derived from the
     * current time in microseconds, so a key that was forgotten and connected
     * again never starts at a version that a process still holds from before.
     */
    public static function initial(): int
    {
        return (int) floor(microtime(true) * 1_000_000);
    }
}
