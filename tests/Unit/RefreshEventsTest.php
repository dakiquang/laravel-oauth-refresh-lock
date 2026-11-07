<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Unit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Events\TokenRefreshed;
use Dakiquang\OAuthRefreshLock\Events\TokenRefreshFailed;
use Dakiquang\OAuthRefreshLock\Events\TokenRevokedByProvider;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenRevoked;
use Dakiquang\OAuthRefreshLock\Exceptions\TransientRefreshFailure;
use Dakiquang\OAuthRefreshLock\RefreshCoordinator;
use Dakiquang\OAuthRefreshLock\RefreshedToken;
use Dakiquang\OAuthRefreshLock\RefreshFailure;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Dakiquang\OAuthRefreshLock\Stores\ArrayTokenStore;
use Dakiquang\OAuthRefreshLock\Tests\Support\FixedClassifier;
use Dakiquang\OAuthRefreshLock\Tests\Support\SpyLockProvider;
use Illuminate\Events\Dispatcher;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RefreshEventsTest extends TestCase
{
    private const KEY = 'provider:tenant-1';

    private CarbonImmutable $now;

    private ArrayTokenStore $store;

    private SpyLockProvider $locks;

    private Dispatcher $events;

    /** @var list<object> */
    private array $dispatched = [];

    /** @var list<bool> whether the refresh lock was free while each event was dispatched */
    private array $lockFreeOnDispatch = [];

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-11-07 10:00:00');
        Carbon::setTestNow($this->now);
        CarbonImmutable::setTestNow($this->now);
        $this->store = new ArrayTokenStore;
        $this->locks = new SpyLockProvider;
        $this->events = new Dispatcher;
        $this->events->listen('*', function (string $name, array $payload): void {
            $this->dispatched[] = $payload[0];
            $probe = $this->locks->store->lock('oauth-refresh-lock:'.self::KEY, 5, 'probe');
            $this->lockFreeOnDispatch[] = $probe->get();
            $probe->release();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_a_refresh_dispatches_token_refreshed_after_the_lock_is_released(): void
    {
        $this->seed(expiresIn: 60);

        $token = $this->coordinator()->for(self::KEY)->token(fn (): RefreshedToken => RefreshedToken::fromExpiresIn('access-new', 'refresh-new', 3600));

        $this->assertCount(1, $this->dispatched);
        $event = $this->dispatched[0];
        $this->assertInstanceOf(TokenRefreshed::class, $event);
        $this->assertSame(self::KEY, $event->key);
        $this->assertSame($token->version, $event->version);
        $this->assertTrue($event->expiresAt?->equalTo($this->now->addHour()));
        $this->assertSame([true], $this->lockFreeOnDispatch);
        $this->assertStringNotContainsString('access-new', serialize($event));
        $this->assertStringNotContainsString('refresh-new', serialize($event));
    }

    public function test_no_event_when_nothing_was_refreshed(): void
    {
        $this->seed(expiresIn: 3600);
        $this->coordinator()->for(self::KEY)->token(fn (): RefreshedToken => throw new LogicException('not called'));

        // Another process refreshed while this one waited for the lock.
        $this->seed(expiresIn: 60);
        $this->locks->afterAcquire = fn () => $this->store->put(self::KEY, new StoredToken('access-other', 'refresh-other', $this->now->addHour()));
        $this->coordinator()->for(self::KEY)->token(fn (): RefreshedToken => throw new LogicException('not called'));

        $this->assertSame([], $this->dispatched);
    }

    public function test_a_temporary_failure_dispatches_token_refresh_failed(): void
    {
        $this->seed(expiresIn: 60);

        try {
            $this->coordinator(RefreshFailure::Transient, 'http_503')->for(self::KEY)->token(fn (): RefreshedToken => throw new RuntimeException('provider says: secret-body'));
            $this->fail('TransientRefreshFailure was not thrown');
        } catch (TransientRefreshFailure) {
        }

        $this->assertEquals([new TokenRefreshFailed(self::KEY, RefreshFailure::Transient, 'http_503')], $this->dispatched);
        $this->assertSame([true], $this->lockFreeOnDispatch);
    }

    public function test_an_unexpected_failure_dispatches_token_refresh_failed_and_rethrows(): void
    {
        $this->seed(expiresIn: 60);

        try {
            $this->coordinator(RefreshFailure::Unexpected, 'invalid_client')->for(self::KEY)->token(fn (): RefreshedToken => throw new RuntimeException('bad client'));
            $this->fail('RuntimeException was not thrown');
        } catch (RuntimeException $error) {
            $this->assertSame('bad client', $error->getMessage());
        }

        $this->assertEquals([new TokenRefreshFailed(self::KEY, RefreshFailure::Unexpected, 'invalid_client')], $this->dispatched);
    }

    public function test_a_revocation_dispatches_token_revoked_by_provider(): void
    {
        $this->seed(expiresIn: 60);

        try {
            $this->coordinator(RefreshFailure::Revoked, 'invalid_grant')->for(self::KEY)->token(fn (): RefreshedToken => throw new RuntimeException('invalid_grant'));
            $this->fail('TokenRevoked was not thrown');
        } catch (TokenRevoked) {
        }

        $this->assertEquals([new TokenRevokedByProvider(self::KEY, 'invalid_grant')], $this->dispatched);
        $this->assertSame([true], $this->lockFreeOnDispatch);
    }

    public function test_reading_an_already_revoked_token_dispatches_nothing(): void
    {
        $this->store->put(self::KEY, (new StoredToken('a', 'r', $this->now->addSeconds(60)))->withRevoked('invalid_grant', $this->now));

        try {
            $this->coordinator()->for(self::KEY)->token(fn (): RefreshedToken => throw new LogicException('not called'));
            $this->fail('TokenRevoked was not thrown');
        } catch (TokenRevoked) {
        }

        $this->assertSame([], $this->dispatched);
    }

    public function test_without_a_dispatcher_nothing_breaks(): void
    {
        $this->seed(expiresIn: 60);

        $coordinator = new RefreshCoordinator($this->store, $this->locks, new FixedClassifier(RefreshFailure::Unexpected, 'x'));

        $this->assertSame('access-new', $coordinator->for(self::KEY)->accessToken(fn (): RefreshedToken => RefreshedToken::fromExpiresIn('access-new', null, 3600)));
    }

    private function coordinator(RefreshFailure $failure = RefreshFailure::Unexpected, string $code = 'test_code'): RefreshCoordinator
    {
        return new RefreshCoordinator($this->store, $this->locks, new FixedClassifier($failure, $code), events: $this->events);
    }

    private function seed(int $expiresIn): void
    {
        $this->store->put(self::KEY, new StoredToken('access-0', 'refresh-0', $this->now->addSeconds($expiresIn)));
    }
}
