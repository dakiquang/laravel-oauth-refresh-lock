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
     * @return iterable<string, array{0: Throwable, 1: RefreshFailure}>
     */
    public static function errors(): iterable
    {
        $request = new Request('POST', 'https://identity.example.test/connect/token');
        $json = fn (int $status, array $body): Response => new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));

        yield 'league invalid_grant' => [
            new IdentityProviderException('invalid_grant', 400, ['error' => 'invalid_grant']),
            RefreshFailure::Revoked,
        ];
        yield 'league invalid_grant as JSON string body' => [
            new IdentityProviderException('Bad Request', 400, '{"error":"invalid_grant","error_description":"token used"}'),
            RefreshFailure::Revoked,
        ];
        yield 'league invalid_client is a config error, not a revoke' => [
            new IdentityProviderException('invalid_client', 401, ['error' => 'invalid_client']),
            RefreshFailure::Unexpected,
        ];
        yield 'league 503' => [
            new IdentityProviderException('Service Unavailable', 503, '<html>maintenance</html>'),
            RefreshFailure::Transient,
        ];
        yield 'league 429' => [
            new IdentityProviderException('Too Many Requests', 429, []),
            RefreshFailure::Transient,
        ];
        yield 'guzzle connect timeout' => [
            new ConnectException('cURL error 28: Operation timed out', $request),
            RefreshFailure::Transient,
        ];
        yield 'guzzle 400 invalid_grant' => [
            new ClientException('Bad Request', $request, $json(400, ['error' => 'invalid_grant'])),
            RefreshFailure::Revoked,
        ];
        yield 'guzzle 500' => [
            new ServerException('Server Error', $request, new Response(500)),
            RefreshFailure::Transient,
        ];
        yield 'laravel connection error' => [
            new ConnectionException('cURL error 6: Could not resolve host'),
            RefreshFailure::Transient,
        ];
        yield 'laravel 400 invalid_grant' => [
            new RequestException(new LaravelResponse($json(400, ['error' => 'invalid_grant']))),
            RefreshFailure::Revoked,
        ];
        yield 'laravel 502' => [
            new RequestException(new LaravelResponse(new Response(502))),
            RefreshFailure::Transient,
        ];
        yield 'laravel 401 unauthorized_client' => [
            new RequestException(new LaravelResponse($json(401, ['error' => 'unauthorized_client']))),
            RefreshFailure::Unexpected,
        ];
        yield 'unknown exception' => [
            new RuntimeException('something else'),
            RefreshFailure::Unexpected,
        ];
    }

    #[DataProvider('errors')]
    public function test_classifies(Throwable $error, RefreshFailure $expected): void
    {
        $this->assertSame($expected, (new DefaultErrorClassifier)->classify($error));
    }

    public function test_revoked_error_codes_are_configurable(): void
    {
        $classifier = new DefaultErrorClassifier(revokedErrors: ['invalid_grant', 'invalid_token']);

        $this->assertSame(
            RefreshFailure::Revoked,
            $classifier->classify(new IdentityProviderException('invalid_token', 400, ['error' => 'invalid_token'])),
        );
    }
}
