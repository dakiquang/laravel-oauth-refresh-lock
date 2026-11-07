<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock;

use Illuminate\Support\ServiceProvider;

class OAuthRefreshLockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/oauth-refresh-lock.php', 'oauth-refresh-lock');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/oauth-refresh-lock.php' => $this->app->configPath('oauth-refresh-lock.php'),
            ], 'oauth-refresh-lock-config');
        }
    }
}
