<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Unit;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Exceptions\RefreshLockTimeout;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenNotFound;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenRevoked;
use Dakiquang\OAuthRefreshLock\Exceptions\TransientRefreshFailure;
use Dakiquang\OAuthRefreshLock\RefreshCoordinator;
use Dakiquang\OAuthRefreshLock\RefreshedToken;
use Dakiquang\OAuthRefreshLock\RefreshFailure;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Dakiquang\OAuthRefreshLock\Stores\ArrayTokenStore;
use Dakiquang\OAuthRefreshLock\Tests\Support\FixedClassifier;
use Dakiquang\OAuthRefreshLock\Tests\Support\SpyLockProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RefreshCoordinatorTest extends TestCase
{
    private const KEY = 'provider:tenant-1';

    private CarbonImmutable $now;

    private ArrayTokenStore $store;

    private SpyLockProvider $locks;

    private int $refreshCalls = 0;

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-11-07 10:00:00');
        CarbonImmutable::setTestNow($this->now);
        $this->store = new ArrayTokenStore;
        $this->locks = new SpyLockProvider;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
    }

    public function test_fresh_token_is_returned_without_taking_a_lock(): void
    {
        $this->seed(expiresIn: 3600);

        $token = $this->coordinator()->for(self::KEY)->token($this->refresher());

        $this->assertSame('access-0', $token->accessToken);
        $this->assertSame(0, $this->refreshCalls);
        $this->assertSame(0, $this->locks->locksRequested);
    }

    public function test_expiring_token_is_refreshed_once_and_stored(): void
    {
        $this->seed(expiresIn: 60);

        $access = $this->coordinator()->for(self::KEY)->accessToken($this->refresher());

        $this->assertSame('access-1', $access);
        $this->assertSame(1, $this->refreshCalls);
        $this->assertSame(['oauth-refresh-lock:'.self::KEY], $this->locks->lockNames);

        $stored = $this->store->get(self::KEY);
        $this->assertSame('access-1', $stored?->accessToken);
        $this->assertSame('refresh-1', $stored?->refreshToken);
        $this->assertSame(1, $stored?->version);
        $this->assertTrue($stored?->expiresAt?->equalTo($this->now->addHour()));
    }

    public function test_waiter_uses_the_token_another_process_refreshed_meanwhile(): void
    {
        $this->seed(expiresIn: 60);
        // Simulate: by the time we hold the lock, someone else stored a fresh token.
        $this->locks->afterAcquire = function (): void {
            $this->store->put(self::KEY, new StoredToken('access-other', 'refresh-other', $this->now->addHour(), version: 1), expectedVersion: 0);
        };

        $access = $this->coordinator()->for(self::KEY)->accessToken($this->refresher());

        $this->assertSame('access-other', $access);
        $this->assertSame(0, $this->refreshCalls, 'the stale refresh token must not be sent again');
    }

    public function test_revoked_failure_marks_token_revoked_and_keeps_the_record(): void
    {
        $this->seed(expiresIn: 60);

        try {
            $this->coordinator(RefreshFailure::Revoked)->for(self::KEY)->token($this->failingRefresher(new RuntimeException('invalid_grant')));
            $this->fail('TokenRevoked was not thrown');
        } catch (TokenRevoked $revoked) {
            $this->assertSame(self::KEY, $revoked->key);
            $this->assertSame('invalid_grant', $revoked->reason);
        }

        $stored = $this->store->get(self::KEY);
        $this->assertTrue($stored?->isRevoked());
        $this->assertSame('refresh-0', $stored?->refreshToken);
        $this->assertSame(1, $stored?->version);
    }

    public function test_revoked_token_is_rejected_without_refreshing(): void
    {
        $this->store->put(self::KEY, (new StoredToken('a', 'r', $this->now->subMinute()))->withRevoked('invalid_grant', $this->now));

        $this->expectException(TokenRevoked::class);

        try {
            $this->coordinator()->for(self::KEY)->token($this->refresher());
        } finally {
            $this->assertSame(0, $this->refreshCalls);
        }
    }

    public function test_transient_failure_keeps_the_token(): void
    {
        $this->seed(expiresIn: 60);

        try {
            $this->coordinator(RefreshFailure::Transient)->for(self::KEY)->token($this->failingRefresher(new RuntimeException('connection timed out')));
            $this->fail('TransientRefreshFailure was not thrown');
        } catch (TransientRefreshFailure $failure) {
            $this->assertSame(self::KEY, $failure->key);
            $this->assertInstanceOf(RuntimeException::class, $failure->getPrevious());
        }

        $stored = $this->store->get(self::KEY);
        $this->assertFalse($stored?->isRevoked());
        $this->assertSame('refresh-0', $stored?->refreshToken);
        $this->assertSame(0, $stored?->version);
    }

    public function test_unexpected_failure_is_rethrown_and_keeps_the_token(): void
    {
        $this->seed(expiresIn: 60);
        $error = new LogicException('client misconfigured');

        try {
            $this->coordinator(RefreshFailure::Unexpected)->for(self::KEY)->token($this->failingRefresher($error));
            $this->fail('the original exception was not rethrown');
        } catch (LogicException $caught) {
            $this->assertSame($error, $caught);
        }

        $this->assertSame(0, $this->store->get(self::KEY)?->version);
    }

    public function test_lock_held_elsewhere_times_out_without_changes(): void
    {
        $this->seed(expiresIn: 60);
        $this->assertTrue($this->locks->store->lock('oauth-refresh-lock:'.self::KEY, 30, 'other-process')->get());

        try {
            $this->coordinator()->for(self::KEY)->wait(0)->token($this->refresher());
            $this->fail('RefreshLockTimeout was not thrown');
        } catch (RefreshLockTimeout $timeout) {
            $this->assertSame(self::KEY, $timeout->key);
        }

        $this->assertSame(0, $this->refreshCalls);
        $this->assertSame(0, $this->store->get(self::KEY)?->version);
    }

    public function test_lost_compare_and_swap_returns_what_is_stored(): void
    {
        $this->seed(expiresIn: 60);
        // The refresher succeeds, but a reconnect wrote a new token while it ran.
        $refresher = function (StoredToken $token): RefreshedToken {
            $this->store->put(self::KEY, new StoredToken('access-reconnected', 'refresh-reconnected', $this->now->addHour(), version: 7));

            return RefreshedToken::fromExpiresIn('access-ours', 'refresh-ours', 3600);
        };

        $token = $this->coordinator()->for(self::KEY)->token($refresher);

        $this->assertSame('access-reconnected', $token->accessToken);
        $this->assertSame('access-reconnected', $this->store->get(self::KEY)?->accessToken);
    }

    public function test_missing_token_throws(): void
    {
        $this->expectException(TokenNotFound::class);

        $this->coordinator()->for(self::KEY)->token($this->refresher());
    }

    public function test_skew_controls_when_refresh_happens(): void
    {
        $this->seed(expiresIn: 300);

        $this->assertSame('access-0', $this->coordinator()->for(self::KEY)->skew(120)->accessToken($this->refresher()));
        $this->assertSame('access-1', $this->coordinator()->for(self::KEY)->skew(600)->accessToken($this->refresher()));
    }

    public function test_with_lock_runs_callback_with_the_store(): void
    {
        $result = $this->coordinator()->for(self::KEY)->withLock(function (TokenStore $store): string {
            $store->put(self::KEY, new StoredToken('connected', 'refresh', null));

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame('connected', $this->store->get(self::KEY)?->accessToken);
        $this->assertSame(['oauth-refresh-lock:'.self::KEY], $this->locks->lockNames);
    }

    private function coordinator(RefreshFailure $failure = RefreshFailure::Unexpected): RefreshCoordinator
    {
        return new RefreshCoordinator($this->store, $this->locks, new FixedClassifier($failure));
    }

    private function seed(int $expiresIn): void
    {
        $this->store->put(self::KEY, new StoredToken('access-0', 'refresh-0', $this->now->addSeconds($expiresIn)));
    }

    /**
     * @return \Closure(StoredToken): RefreshedToken
     */
    private function refresher(): \Closure
    {
        return function (StoredToken $token): RefreshedToken {
            $n = ++$this->refreshCalls;

            return RefreshedToken::fromExpiresIn("access-{$n}", "refresh-{$n}", 3600);
        };
    }

    /**
     * @return \Closure(StoredToken): RefreshedToken
     */
    private function failingRefresher(\Throwable $error): \Closure
    {
        return function (StoredToken $token) use ($error): RefreshedToken {
            $this->refreshCalls++;

            throw $error;
        };
    }
}
