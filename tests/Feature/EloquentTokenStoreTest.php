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
        // A server database (OAUTH_TEST_PGSQL_HOST) outlives the test, unlike SQLite in memory.
        $migration->down();
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
        $this->assertGreaterThan(1_000_000_000_000_000, $loaded->version, 'a new key gets a time-based version, not the caller\'s 3');
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
        $this->store->put('k', new StoredToken('first', 'r', null));
        $v = $this->store->get('k')?->version ?? 0;
        $this->store->put('k', new StoredToken('reconnected', 'r2', null, version: 0));

        $this->assertSame('reconnected', $this->store->get('k')?->accessToken);
        $this->assertSame($v + 1, $this->store->get('k')?->version, 'unconditional writes move the version forward');
        $this->assertSame(1, DB::table('oauth_refresh_tokens')->count());
        $this->assertFalse($this->store->put('k', new StoredToken('stale', 's', null, version: $v + 1), expectedVersion: $v), 'an older read must not win');
    }

    public function test_compare_and_swap(): void
    {
        $this->store->put('k', new StoredToken('a1', 'r1', null));
        $v = $this->store->get('k')?->version ?? 0;

        $this->assertFalse($this->store->put('k', new StoredToken('stale', 'stale', null, version: $v + 1), expectedVersion: $v - 1));
        $this->assertSame('a1', $this->store->get('k')?->accessToken);

        $this->assertTrue($this->store->put('k', new StoredToken('a2', 'r2', null, version: $v + 1), expectedVersion: $v));
        $this->assertSame('a2', $this->store->get('k')?->accessToken);
        $this->assertSame($v + 1, $this->store->get('k')?->version);
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
        $this->assertSame(0, $loaded?->revokedFromVersion);
    }

    public function test_forget_then_reconnect_never_reuses_a_version(): void
    {
        $this->store->put('k', new StoredToken('a1', 'r1', null));
        $staleRead = $this->store->get('k')?->version ?? 0;

        $this->store->forget('k');
        usleep(2);
        $this->store->put('k', new StoredToken('reconnected', 'r2', null));

        $this->assertFalse($this->store->put('k', new StoredToken('stale', 's', null, version: $staleRead + 1), expectedVersion: $staleRead));
        $this->assertSame('reconnected', $this->store->get('k')?->accessToken);
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
