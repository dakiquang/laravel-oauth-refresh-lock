<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Feature;

use Dakiquang\OAuthRefreshLock\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_default_config_is_merged(): void
    {
        $this->assertSame(120, config('oauth-refresh-lock.skew_seconds'));
        $this->assertSame(30, config('oauth-refresh-lock.lock_seconds'));
        $this->assertSame(10, config('oauth-refresh-lock.wait_seconds'));
        $this->assertSame('oauth-refresh-lock:', config('oauth-refresh-lock.lock_prefix'));
    }

    public function test_config_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'oauth-refresh-lock-config', '--force' => true])
            ->assertSuccessful();

        $this->assertFileExists(config_path('oauth-refresh-lock.php'));
    }
}
