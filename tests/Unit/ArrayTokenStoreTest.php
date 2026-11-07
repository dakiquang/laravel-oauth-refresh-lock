<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Unit;

use Dakiquang\OAuthRefreshLock\StoredToken;
use Dakiquang\OAuthRefreshLock\Stores\ArrayTokenStore;
use PHPUnit\Framework\TestCase;

class ArrayTokenStoreTest extends TestCase
{
    public function test_unconditional_put_and_get(): void
    {
        $store = new ArrayTokenStore;

        $this->assertNull($store->get('k'));
        $this->assertTrue($store->put('k', new StoredToken('a', 'r', null)));
        $this->assertSame('a', $store->get('k')?->accessToken);
    }

    public function test_compare_and_swap_succeeds_on_matching_version(): void
    {
        $store = new ArrayTokenStore;
        $store->put('k', new StoredToken('a1', 'r1', null, version: 3));

        $this->assertTrue($store->put('k', new StoredToken('a2', 'r2', null, version: 4), expectedVersion: 3));
        $this->assertSame('a2', $store->get('k')?->accessToken);
    }

    public function test_compare_and_swap_fails_on_stale_version(): void
    {
        $store = new ArrayTokenStore;
        $store->put('k', new StoredToken('a2', 'r2', null, version: 4));

        $this->assertFalse($store->put('k', new StoredToken('stale', 'stale', null, version: 4), expectedVersion: 3));
        $this->assertSame('a2', $store->get('k')?->accessToken);
    }

    public function test_compare_and_swap_fails_when_token_is_missing(): void
    {
        $store = new ArrayTokenStore;

        $this->assertFalse($store->put('k', new StoredToken('a', 'r', null, version: 1), expectedVersion: 0));
        $this->assertNull($store->get('k'));
    }

    public function test_forget(): void
    {
        $store = new ArrayTokenStore;
        $store->put('k', new StoredToken('a', 'r', null));

        $store->forget('k');

        $this->assertNull($store->get('k'));
    }
}
