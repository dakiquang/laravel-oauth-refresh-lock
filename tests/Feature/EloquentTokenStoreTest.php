<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Feature;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Dakiquang\OAuthRefreshLock\Stores\EloquentTokenStore;
use Dakiquang\OAuthRefreshLock\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class EloquentTokenStoreTest extends TestCase
{
    private EloquentTokenStore $store;

    protected function defineDatabaseMigrations(): void
    {
        $migration = require __DIR__.'/../../database/migrations/create_oauth_refresh_tokens_table.php.stub';
        $migration->up();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new EloquentTokenStore;
    }

    public function test_round_trip_keeps_every_field(): void
    {
        $expires = CarbonImmutable::parse('2026-11-07 10:30:00');
        $token = new StoredToken('access', 'refresh', $expires, ['read', 'write'], ['tenant' => 't1'], version: 3);

        $this->assertTrue($this->store->put('provider:t1', $token));
        $loaded = $this->store->get('provider:t1');

        $this->assertNotNull($loaded);
        $this->assertSame('access', $loaded->accessToken);
        $this->assertSame('refresh', $loaded->refreshToken);
        $this->assertTrue($loaded->expiresAt?->equalTo($expires));
        $this->assertSame(['read', 'write'], $loaded->scopes);
        $this->assertSame(['tenant' => 't1'], $loaded->meta);
        $this->assertSame(3, $loaded->version);
        $this->assertFalse($loaded->isRevoked());
    }

    public function test_tokens_are_encrypted_at_rest(): void
    {
        $this->store->put('k', new StoredToken('plain-access-value', 'plain-refresh-value', null));

        $row = DB::table('oauth_refresh_tokens')->where('key', 'k')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('plain-access-value', (string) $row->access_token);
        $this->assertStringNotContainsString('plain-refresh-value', (string) $row->refresh_token);
    }

    public function test_unconditional_put_overwrites_existing_row(): void
    {
        $this->store->put('k', new StoredToken('first', 'r', null, version: 5));
        $this->store->put('k', new StoredToken('reconnected', 'r2', null, version: 0));

        $this->assertSame('reconnected', $this->store->get('k')?->accessToken);
        $this->assertSame(6, $this->store->get('k')?->version, 'unconditional writes move the version forward');
        $this->assertSame(1, DB::table('oauth_refresh_tokens')->count());
        $this->assertFalse($this->store->put('k', new StoredToken('stale', 's', null, version: 6), expectedVersion: 5), 'an older read must not win');
    }

    public function test_compare_and_swap(): void
    {
        $this->store->put('k', new StoredToken('a1', 'r1', null, version: 1));

        $this->assertFalse($this->store->put('k', new StoredToken('stale', 'stale', null, version: 2), expectedVersion: 0));
        $this->assertSame('a1', $this->store->get('k')?->accessToken);

        $this->assertTrue($this->store->put('k', new StoredToken('a2', 'r2', null, version: 2), expectedVersion: 1));
        $this->assertSame('a2', $this->store->get('k')?->accessToken);
        $this->assertSame(2, $this->store->get('k')?->version);
    }

    public function test_compare_and_swap_on_missing_row_fails(): void
    {
        $this->assertFalse($this->store->put('missing', new StoredToken('a', 'r', null, version: 1), expectedVersion: 0));
        $this->assertNull($this->store->get('missing'));
    }

    public function test_revoked_state_round_trips(): void
    {
        $now = CarbonImmutable::parse('2026-11-07 10:00:00');
        $this->store->put('k', (new StoredToken('a', 'r', null))->withRevoked('invalid_grant', $now));

        $loaded = $this->store->get('k');

        $this->assertTrue($loaded?->isRevoked());
        $this->assertSame('invalid_grant', $loaded?->revokedReason);
        $this->assertTrue($loaded?->revokedAt?->equalTo($now));
    }

    public function test_times_with_another_offset_keep_the_same_instant(): void
    {
        $expires = CarbonImmutable::parse('2026-11-07 17:30:00', 'Asia/Ho_Chi_Minh');

        $this->store->put('k', new StoredToken('a', 'r', $expires));

        $this->assertTrue($this->store->get('k')?->expiresAt?->equalTo($expires));
    }

    public function test_forget(): void
    {
        $this->store->put('k', new StoredToken('a', 'r', null));
        $this->store->forget('k');

        $this->assertNull($this->store->get('k'));
    }
}
