<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Facades;

use Dakiquang\OAuthRefreshLock\PendingRefresh;
use Dakiquang\OAuthRefreshLock\RefreshCoordinator;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingRefresh for(string $key)
 *
 * @see RefreshCoordinator
 */
class OAuthRefreshLock extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RefreshCoordinator::class;
    }
}
