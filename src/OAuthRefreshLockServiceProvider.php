<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock;

use Dakiquang\OAuthRefreshLock\Classifiers\DefaultErrorClassifier;
use Dakiquang\OAuthRefreshLock\Contracts\ErrorClassifier;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Exceptions\LockStoreNotSupported;
use Dakiquang\OAuthRefreshLock\Stores\EloquentTokenStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use UnexpectedValueException;

class OAuthRefreshLockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/oauth-refresh-lock.php', 'oauth-refresh-lock');

        $this->app->singletonIf(TokenStore::class, EloquentTokenStore::class);
        $this->app->singletonIf(ErrorClassifier::class, DefaultErrorClassifier::class);

        $this->app->singleton(RefreshCoordinator::class, function (Application $app): RefreshCoordinator {
            /** @var Config $config */
            $config = $app->make('config');

            return new RefreshCoordinator(
                store: $this->resolve($app, TokenStore::class),
                locks: $this->lockProvider($this->resolve($app, CacheFactory::class), $config->get('oauth-refresh-lock.lock_store')),
                classifier: $this->resolve($app, ErrorClassifier::class),
                lockPrefix: $this->string($config->get('oauth-refresh-lock.lock_prefix'), 'oauth-refresh-lock:'),
                skewSeconds: $this->int($config->get('oauth-refresh-lock.skew_seconds'), 120),
                lockSeconds: $this->int($config->get('oauth-refresh-lock.lock_seconds'), 30),
                waitSeconds: $this->int($config->get('oauth-refresh-lock.wait_seconds'), 10),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/oauth-refresh-lock.php' => $this->app->configPath('oauth-refresh-lock.php'),
            ], 'oauth-refresh-lock-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_oauth_refresh_tokens_table.php.stub' => $this->app->databasePath('migrations/'.date('Y_m_d_His').'_create_oauth_refresh_tokens_table.php'),
            ], 'oauth-refresh-lock-migrations');
        }
    }

    /**
     * Laravel 10's container is untyped; check what it returns instead of trusting it.
     *
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T
     */
    private function resolve(Application $app, string $abstract): object
    {
        $instance = $app->make($abstract);
        if (! $instance instanceof $abstract) {
            throw new UnexpectedValueException("The container did not return a {$abstract}.");
        }

        return $instance;
    }

    private function lockProvider(CacheFactory $cache, mixed $storeName): LockProvider
    {
        $name = is_string($storeName) && $storeName !== '' ? $storeName : null;
        $store = $cache->store($name)->getStore();

        // NullStore hands out locks that are always acquired: it would silently disable locking.
        if (! $store instanceof LockProvider || $store instanceof NullStore) {
            throw LockStoreNotSupported::forStore($name ?? 'default');
        }

        return $store;
    }

    private function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function string(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
