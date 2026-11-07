<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Unit;

use Dakiquang\OAuthRefreshLock\Classifiers\DefaultErrorClassifier;
use Dakiquang\OAuthRefreshLock\RefreshFailure;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as LaravelResponse;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class DefaultErrorClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Throwable, 1: RefreshFailure, 2: string}>
     */
    public static function errors(): iterable
    {
        $request = new Request('POST', 'https://identity.example.test/connect/token');
        $json = fn (int $status, array $body): Response => new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));

        yield 'league response object, invalid_grant' => [
            new IdentityProviderException('Bad Request', 400, $json(400, ['error' => 'invalid_grant'])),
            RefreshFailure::Revoked, 'invalid_grant',
        ];
        yield 'league response object, 503 wins over the body' => [
            new IdentityProviderException('invalid_grant', 0, $json(503, ['error' => 'invalid_grant'])),
            RefreshFailure::Transient, 'http_503',
        ];
        yield 'league temporarily_unavailable without status' => [
            new IdentityProviderException('temporarily_unavailable', 0, ['error' => 'temporarily_unavailable']),
            RefreshFailure::Transient, 'temporarily_unavailable',
        ];
        yield 'league server_error without status' => [
            new IdentityProviderException('server_error', 0, ['error' => 'server_error']),
            RefreshFailure::Transient, 'server_error',
        ];
        yield 'league body code that is not an HTTP status' => [
            new IdentityProviderException('invalid_grant', 70001, ['error' => 'invalid_grant', 'code' => 70001]),
            RefreshFailure::Revoked, 'invalid_grant',
        ];
        yield 'league invalid_grant' => [
            new IdentityProviderException('invalid_grant', 400, ['error' => 'invalid_grant']),
            RefreshFailure::Revoked, 'invalid_grant',
        ];
        yield 'league invalid_grant as JSON string body' => [
            new IdentityProviderException('Bad Request', 400, '{"error":"invalid_grant","error_description":"token used"}'),
            RefreshFailure::Revoked, 'invalid_grant',
        ];
        yield 'league invalid_client is a config error, not a revoke' => [
            new IdentityProviderException('invalid_client', 401, ['error' => 'invalid_client']),
            RefreshFailure::Unexpected, 'invalid_client',
        ];
        yield 'league 503' => [
            new IdentityProviderException('Service Unavailable', 503, '<html>maintenance</html>'),
            RefreshFailure::Transient, 'http_503',
        ];
        yield 'league 429' => [
            new IdentityProviderException('Too Many Requests', 429, []),
            RefreshFailure::Transient, 'http_429',
        ];
        yield 'guzzle connect timeout' => [
            new ConnectException('cURL error 28: Operation timed out', $request),
            RefreshFailure::Transient, 'network_error',
        ];
        yield 'guzzle 400 invalid_grant' => [
            new ClientException('Bad Request', $request, $json(400, ['error' => 'invalid_grant'])),
            RefreshFailure::Revoked, 'invalid_grant',
        ];
        yield 'guzzle 500' => [
            new ServerException('Server Error', $request, new Response(500)),
            RefreshFailure::Transient, 'http_500',
        ];
        yield 'laravel connection error' => [
            new ConnectionException('cURL error 6: Could not resolve host'),
            RefreshFailure::Transient, 'network_error',
        ];
        yield 'laravel 400 invalid_grant' => [
            new RequestException(new LaravelResponse($json(400, ['error' => 'invalid_grant']))),
            RefreshFailure::Revoked, 'invalid_grant',
        ];
        yield 'laravel 502' => [
            new RequestException(new LaravelResponse(new Response(502))),
            RefreshFailure::Transient, 'http_502',
        ];
        yield 'laravel 401 unauthorized_client' => [
            new RequestException(new LaravelResponse($json(401, ['error' => 'unauthorized_client']))),
            RefreshFailure::Unexpected, 'unauthorized_client',
        ];
        yield 'unknown exception' => [
            new RuntimeException('something else'),
            RefreshFailure::Unexpected, 'unexpected',
        ];
    }

    #[DataProvider('errors')]
    public function test_classifies(Throwable $error, RefreshFailure $expected, string $code): void
    {
        $classification = (new DefaultErrorClassifier)->classify($error);

        $this->assertSame($expected, $classification->failure);
        $this->assertSame($code, $classification->code);
    }

    public function test_server_errors_win_over_an_oauth_error_in_the_body(): void
    {
        $response = new Response(503, [], '{"error":"invalid_grant"}');
        $error = new ServerException('Service Unavailable', new Request('POST', 'https://identity.example.test/token'), $response);

        $this->assertSame(RefreshFailure::Transient, (new DefaultErrorClassifier)->classify($error)->failure);
    }

    public function test_rate_limit_wins_over_an_oauth_error_in_the_body(): void
    {
        $error = new IdentityProviderException('Too Many Requests', 429, ['error' => 'invalid_grant']);

        $classification = (new DefaultErrorClassifier)->classify($error);

        $this->assertSame(RefreshFailure::Transient, $classification->failure);
        $this->assertSame('http_429', $classification->code);
    }

    public function test_only_short_codes_are_used_so_bodies_cannot_leak(): void
    {
        $error = new IdentityProviderException('Bad Request', 400, ['error' => 'refresh_token=SECRET-VALUE is not valid']);

        $classification = (new DefaultErrorClassifier)->classify($error);

        $this->assertSame(RefreshFailure::Unexpected, $classification->failure);
        $this->assertSame('http_400', $classification->code);
    }

    public function test_revoked_error_codes_are_configurable(): void
    {
        $classifier = new DefaultErrorClassifier(revokedErrors: ['invalid_grant', 'invalid_token']);

        $this->assertSame(
            RefreshFailure::Revoked,
            $classifier->classify(new IdentityProviderException('invalid_token', 400, ['error' => 'invalid_token']))->failure,
        );
    }
}
