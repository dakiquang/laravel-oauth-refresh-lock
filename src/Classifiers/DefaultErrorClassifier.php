<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Classifiers;

use Dakiquang\OAuthRefreshLock\Contracts\ErrorClassifier;
use Dakiquang\OAuthRefreshLock\RefreshFailure;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/**
 * Classifies refresh failures from league/oauth2-client, Guzzle, Laravel's
 * HTTP client and PSR-18 clients. None of those packages is required: an
 * `instanceof` check against a class that is not installed is simply false.
 *
 * Only an OAuth error code in $revokedErrors revokes the token. When in doubt
 * the token is kept, because wrongly revoking it forces the user to reconnect.
 */
final class DefaultErrorClassifier implements ErrorClassifier
{
    /**
     * @param  list<string>  $revokedErrors  OAuth2 `error` codes that mean the refresh token is dead (RFC 6749 §5.2).
     */
    public function __construct(
        private readonly array $revokedErrors = ['invalid_grant'],
    ) {}

    public function classify(Throwable $error): RefreshFailure
    {
        if ($this->isNetworkError($error)) {
            return RefreshFailure::Transient;
        }

        [$status, $oauthError] = $this->response($error);

        if ($oauthError !== null && in_array($oauthError, $this->revokedErrors, true)) {
            return RefreshFailure::Revoked;
        }

        if ($status !== null && ($status === 408 || $status === 429 || $status >= 500)) {
            return RefreshFailure::Transient;
        }

        return RefreshFailure::Unexpected;
    }

    private function isNetworkError(Throwable $error): bool
    {
        // Guzzle's ConnectException implements the PSR-18 interface.
        return $error instanceof NetworkExceptionInterface
            || $error instanceof LaravelConnectionException;
    }

    /**
     * The HTTP status and OAuth2 `error` code carried by the exception, when known.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function response(Throwable $error): array
    {
        if ($error instanceof IdentityProviderException) {
            $status = $error->getCode() >= 100 ? $error->getCode() : null;

            return [$status, $this->errorCode($error->getResponseBody()) ?? $this->errorCode($error->getMessage())];
        }

        if ($error instanceof LaravelRequestException) {
            return [$error->response->status(), $this->errorCode($error->response->body())];
        }

        if ($error instanceof GuzzleRequestException && $error->getResponse() !== null) {
            $response = $error->getResponse();

            return [$response->getStatusCode(), $this->errorCode((string) $response->getBody())];
        }

        return [null, null];
    }

    /**
     * Read the `error` member of an OAuth2 error response given as an array,
     * a JSON string, or the bare error code that some SDKs use as the message.
     */
    private function errorCode(mixed $body): ?string
    {
        if (is_string($body)) {
            $trimmed = trim($body);
            if (preg_match('/^[a-z_]+$/', $trimmed) === 1) {
                return $trimmed;
            }
            $body = json_decode($trimmed, true);
        }

        if (is_array($body) && isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }

        return null;
    }
}
