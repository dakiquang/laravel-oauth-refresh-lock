<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Unit;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\RefreshedToken;
use Dakiquang\OAuthRefreshLock\StoredToken;
use PHPUnit\Framework\TestCase;

class StoredTokenTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-11-07 10:00:00');
    }

    public function test_expires_within_compares_against_now_plus_skew(): void
    {
        $token = new StoredToken('access', 'refresh', $this->now->addSeconds(100));

        $this->assertFalse($token->expiresWithin(60, $this->now));
        $this->assertTrue($token->expiresWithin(100, $this->now));
        $this->assertTrue($token->expiresWithin(120, $this->now));
    }

    public function test_already_expired_token_expires_within_zero_seconds(): void
    {
        $token = new StoredToken('access', 'refresh', $this->now->subMinute());

        $this->assertTrue($token->expiresWithin(0, $this->now));
    }

    public function test_token_without_expiry_never_expires(): void
    {
        $token = new StoredToken('access', 'refresh', null);

        $this->assertFalse($token->expiresWithin(PHP_INT_MAX >> 32, $this->now));
    }

    public function test_with_refreshed_rotates_refresh_token_and_bumps_version(): void
    {
        $token = new StoredToken('old-access', 'old-refresh', $this->now, ['read'], ['tenant' => 't1'], version: 4);

        $next = $token->withRefreshed(new RefreshedToken('new-access', 'new-refresh', $this->now->addHour(), ['read', 'write']));

        $this->assertSame('new-access', $next->accessToken);
        $this->assertSame('new-refresh', $next->refreshToken);
        $this->assertTrue($next->expiresAt?->equalTo($this->now->addHour()));
        $this->assertSame(['read', 'write'], $next->scopes);
        $this->assertSame(['tenant' => 't1'], $next->meta);
        $this->assertSame(5, $next->version);
        $this->assertSame(4, $token->version, 'the original instance is not changed');
    }

    public function test_with_refreshed_keeps_refresh_token_and_scopes_when_provider_returns_none(): void
    {
        $token = new StoredToken('old-access', 'kept-refresh', $this->now, ['read']);

        $next = $token->withRefreshed(new RefreshedToken('new-access', null, null));

        $this->assertSame('kept-refresh', $next->refreshToken);
        $this->assertSame(['read'], $next->scopes);
        $this->assertNull($next->expiresAt);
    }

    public function test_with_revoked_records_reason_and_time(): void
    {
        $token = new StoredToken('access', 'refresh', $this->now, version: 2);

        $revoked = $token->withRevoked('invalid_grant', $this->now);

        $this->assertTrue($revoked->isRevoked());
        $this->assertSame('invalid_grant', $revoked->revokedReason);
        $this->assertTrue($revoked->revokedAt?->equalTo($this->now));
        $this->assertSame(3, $revoked->version);
        $this->assertFalse($token->isRevoked());
    }

    public function test_refreshed_token_from_expires_in(): void
    {
        $refreshed = RefreshedToken::fromExpiresIn('a', 'r', 1800, $this->now);

        $this->assertTrue($refreshed->expiresAt?->equalTo($this->now->addSeconds(1800)));
        $this->assertNull(RefreshedToken::fromExpiresIn('a', null, null, $this->now)->expiresAt);
    }
}
