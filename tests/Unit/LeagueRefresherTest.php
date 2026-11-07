<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Unit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Classifiers\DefaultErrorClassifier;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenRevoked;
use Dakiquang\OAuthRefreshLock\Exceptions\TransientRefreshFailure;
use Dakiquang\OAuthRefreshLock\RefreshCoordinator;
use Dakiquang\OAuthRefreshLock\Refreshers\LeagueRefresher;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Dakiquang\OAuthRefreshLock\Stores\ArrayTokenStore;
use Dakiquang\OAuthRefreshLock\Tests\Support\SpyLockProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use UnexpectedValueException;

class LeagueRefresherTest extends TestCase
{
    private const KEY = 'provider:tenant-1';

    private MockHandler $http;

    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    private ArrayTokenStore $store;

    protected function setUp(): void
    {
        $now = CarbonImmutable::parse('2026-11-07 10:00:00');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        AccessToken::setTimeNow($now->getTimestamp());
        $this->http = new MockHandler;
        $this->store = new ArrayTokenStore;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        AccessToken::resetTimeNow();
    }

    public function test_sends_the_refresh_token_grant_and_maps_the_response(): void
    {
        $this->http->append($this->json(200, [
            'access_token' => 'access-new',
            'refresh_token' => 'refresh-new',
            'expires_in' => 1800,
            'scope' => 'accounting.transactions  offline_access',
            'token_type' => 'Bearer',
        ]));

        $refreshed = $this->refresher(['scope' => 'offline_access'])->refresh(new StoredToken('access-old', 'refresh-old', null));

        $this->assertSame('access-new', $refreshed->accessToken);
        $this->assertSame('refresh-new', $refreshed->refreshToken);
        $this->assertTrue($refreshed->expiresAt?->equalTo(CarbonImmutable::now()->addSeconds(1800)));
        $this->assertSame(['accounting.transactions', 'offline_access'], $refreshed->scopes);

        parse_str((string) $this->sent[0]['request']->getBody(), $body);
        $this->assertSame('refresh_token', $body['grant_type'] ?? null);
        $this->assertSame('refresh-old', $body['refresh_token'] ?? null);
        $this->assertSame('offline_access', $body['scope'] ?? null);
        $this->assertSame('https://identity.example.test/connect/token', (string) $this->sent[0]['request']->getUri());
    }

    public function test_the_stored_refresh_token_wins_over_options(): void
    {
        $this->http->append($this->json(200, ['access_token' => 'a', 'expires_in' => 60]));

        $this->refresher(['refresh_token' => 'from-options'])->refresh(new StoredToken('a', 'refresh-stored', null));

        parse_str((string) $this->sent[0]['request']->getBody(), $body);
        $this->assertSame('refresh-stored', $body['refresh_token'] ?? null);
    }

    public function test_a_provider_that_does_not_rotate_keeps_the_stored_refresh_token_and_scopes(): void
    {
        $this->seed();
        $this->http->append($this->json(200, ['access_token' => 'access-new', 'expires_in' => 3600]));

        $token = $this->coordinator()->for(self::KEY)->token($this->refresher());

        $this->assertSame('access-new', $token->accessToken);
        $this->assertSame('refresh-old', $token->refreshToken);
        $this->assertSame(['read'], $token->scopes);
    }

    public function test_invalid_grant_revokes_the_token(): void
    {
        $this->seed();
        $this->http->append($this->json(400, ['error' => 'invalid_grant', 'error_description' => 'token reused']));

        try {
            $this->coordinator()->for(self::KEY)->token($this->refresher());
            $this->fail('TokenRevoked was not thrown');
        } catch (TokenRevoked $revoked) {
            $this->assertSame('invalid_grant', $revoked->reason);
        }

        $this->assertTrue($this->store->get(self::KEY)?->isRevoked());
    }

    public function test_a_json_temporarily_unavailable_answer_keeps_the_token(): void
    {
        $this->seed();
        // GenericProvider drops the HTTP status; the OAuth error code still says "later".
        $this->http->append($this->json(503, ['error' => 'temporarily_unavailable']));

        try {
            $this->coordinator()->for(self::KEY)->token($this->refresher());
            $this->fail('TransientRefreshFailure was not thrown');
        } catch (TransientRefreshFailure $failure) {
            $this->assertSame('temporarily_unavailable', $failure->errorCode);
        }

        $this->assertTokenKept();
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function unreadableAnswers(): iterable
    {
        yield 'gateway HTML page' => [new Response(502, ['Content-Type' => 'text/html'], '<html><body>Bad Gateway</body></html>')];
        yield 'plain text 503' => [new Response(503, [], 'Service Unavailable')];
        yield 'HTML 404 from a wrong token URL' => [new Response(404, ['Content-Type' => 'text/html'], '<html><body>Not Found</body></html>')];
    }

    /**
     * league/oauth2-client loses the HTTP status of a non-JSON answer, so an
     * outage cannot be told from a misconfiguration: the error reaches the
     * caller as is and the token is kept.
     */
    #[DataProvider('unreadableAnswers')]
    public function test_an_unreadable_answer_reaches_the_caller_and_keeps_the_token(Response $response): void
    {
        $this->seed();
        $this->http->append($response);

        try {
            $this->coordinator()->for(self::KEY)->token($this->refresher());
            $this->fail('UnexpectedValueException was not thrown');
        } catch (UnexpectedValueException) {
            $this->assertTokenKept();
        }
    }

    public function test_a_token_without_refresh_token_fails_without_calling_the_provider(): void
    {
        $this->expectException(LogicException::class);

        try {
            $this->refresher()->refresh(new StoredToken('access-old', null, null));
        } finally {
            $this->assertSame([], $this->sent);
        }
    }

    private function assertTokenKept(): void
    {
        $stored = $this->store->get(self::KEY);
        $this->assertFalse($stored?->isRevoked());
        $this->assertSame('refresh-old', $stored?->refreshToken);
        $this->assertSame('access-old', $stored?->accessToken);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function refresher(array $options = []): LeagueRefresher
    {
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->sent));

        $provider = new GenericProvider([
            'clientId' => 'test-client',
            'clientSecret' => 'test-secret',
            'redirectUri' => 'https://app.example.test/callback',
            'urlAuthorize' => 'https://identity.example.test/connect/authorize',
            'urlAccessToken' => 'https://identity.example.test/connect/token',
            'urlResourceOwnerDetails' => 'https://identity.example.test/connect/userinfo',
        ], ['httpClient' => new Client(['handler' => $stack])]);

        return new LeagueRefresher($provider, $options);
    }

    private function coordinator(): RefreshCoordinator
    {
        return new RefreshCoordinator($this->store, new SpyLockProvider, new DefaultErrorClassifier);
    }

    private function seed(): void
    {
        $this->store->put(self::KEY, new StoredToken('access-old', 'refresh-old', CarbonImmutable::now()->addSeconds(30), ['read']));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
