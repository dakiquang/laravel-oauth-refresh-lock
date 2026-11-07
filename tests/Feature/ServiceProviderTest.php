<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Feature;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Classifiers\DefaultErrorClassifier;
use Dakiquang\OAuthRefreshLock\Contracts\ErrorClassifier;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Exceptions\LockStoreNotSupported;
use Dakiquang\OAuthRefreshLock\Facades\OAuthRefreshLock;
use Dakiquang\OAuthRefreshLock\PendingRefresh;
use Dakiquang\OAuthRefreshLock\RefreshCoordinator;
use Dakiquang\OAuthRefreshLock\RefreshedToken;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Dakiquang\OAuthRefreshLock\Stores\ArrayTokenStore;
use Dakiquang\OAuthRefreshLock\Stores\EloquentTokenStore;
use Dakiquang\OAuthRefreshLock\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_default_config_is_merged(): void
    {
        $this->assertSame(120, config('oauth-refresh-lock.skew_seconds'));
        $this->assertSame(30, config('oauth-refresh-lock.lock_seconds'));
        $this->assertSame(10, config('oauth-refresh-lock.wait_seconds'));
        $this->assertSame('oauth-refresh-lock:', config('oauth-refresh-lock.lock_prefix'));
        $this->assertSame('oauth_refresh_tokens', config('oauth-refresh-lock.table'));
    }

    public function test_config_and_migration_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'oauth-refresh-lock-config', '--force' => true])->assertSuccessful();
        $this->artisan('vendor:publish', ['--tag' => 'oauth-refresh-lock-migrations', '--force' => true])->assertSuccessful();

        $this->assertFileExists(config_path('oauth-refresh-lock.php'));
        $this->assertNotEmpty(glob(database_path('migrations/*_create_oauth_refresh_tokens_table.php')));

        foreach (glob(database_path('migrations/*_create_oauth_refresh_tokens_table.php')) ?: [] as $file) {
            unlink($file);
        }
    }

    public function test_default_bindings(): void
    {
        $this->assertInstanceOf(EloquentTokenStore::class, $this->app->make(TokenStore::class));
        $this->assertInstanceOf(DefaultErrorClassifier::class, $this->app->make(ErrorClassifier::class));
        $this->assertSame($this->app->make(RefreshCoordinator::class), $this->app->make(RefreshCoordinator::class));
    }

    public function test_app_can_replace_the_token_store(): void
    {
        $this->app->forgetInstance(RefreshCoordinator::class);
        $store = new ArrayTokenStore;
        $this->app->instance(TokenStore::class, $store);
        $store->put('k', new StoredToken('a', 'r', CarbonImmutable::now()->addHour()));

        $token = OAuthRefreshLock::for('k')->token(fn (StoredToken $t): RefreshedToken => throw new \LogicException('must not refresh'));

        $this->assertSame('a', $token->accessToken);
    }

    public function test_facade_returns_a_pending_refresh(): void
    {
        $this->assertInstanceOf(PendingRefresh::class, OAuthRefreshLock::for('k'));
    }

    public function test_null_cache_store_is_rejected_because_it_disables_locking(): void
    {
        config(['cache.stores.none' => ['driver' => 'null'], 'oauth-refresh-lock.lock_store' => 'none']);
        $this->app->forgetInstance(RefreshCoordinator::class);

        $this->expectException(LockStoreNotSupported::class);

        $this->app->make(RefreshCoordinator::class);
    }

    public function test_configured_lock_store_is_used(): void
    {
        config(['oauth-refresh-lock.lock_store' => 'array', 'oauth-refresh-lock.lock_prefix' => 'custom:']);
        $this->app->forgetInstance(RefreshCoordinator::class);
        $this->app->instance(TokenStore::class, $store = new ArrayTokenStore);
        $store->put('k', new StoredToken('old', 'r', CarbonImmutable::now()->subMinute()));

        $access = OAuthRefreshLock::for('k')->accessToken(fn (StoredToken $t): RefreshedToken => RefreshedToken::fromExpiresIn('new', 'r2', 3600));

        $this->assertSame('new', $access);
    }
}
