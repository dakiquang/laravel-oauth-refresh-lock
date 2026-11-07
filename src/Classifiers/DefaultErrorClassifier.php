<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Classifiers;

use Dakiquang\OAuthRefreshLock\Classification;
use Dakiquang\OAuthRefreshLock\Contracts\ErrorClassifier;
use Dakiquang\OAuthRefreshLock\Exceptions\UnreadableTokenResponse;
use GuzzleHttp\Exception\BadResponseException as GuzzleBadResponseException;
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
 * Order matters:
 * 1. Network errors, HTTP 408, 429, 5xx and token responses that cannot be
 *    read are transient, whatever the body says — a gateway or a struggling
 *    provider can return odd bodies.
 * 2. Only an OAuth `error` code listed in $revokedErrors revokes the token.
 * 3. Everything else is unexpected and keeps the token, because wrongly
 *    revoking forces the user to reconnect.
 */
final class DefaultErrorClassifier implements ErrorClassifier
{
    /**
     * @param  list<string>  $revokedErrors  OAuth2 `error` codes that mean the refresh token is dead (RFC 6749 §5.2).
     */
    public function __construct(
        private readonly array $revokedErrors = ['invalid_grant'],
    ) {}

    public function classify(Throwable $error): Classification
    {
        if ($this->isNetworkError($error)) {
            return Classification::transient('network_error');
        }

        if ($error instanceof UnreadableTokenResponse) {
            return Classification::transient('unreadable_response');
        }

        [$status, $oauthError] = $this->response($error);

        if ($status !== null && ($status === 408 || $status === 429 || $status >= 500)) {
            return Classification::transient("http_{$status}");
        }

        if ($oauthError !== null && in_array($oauthError, $this->revokedErrors, true)) {
            return Classification::revoked($oauthError);
        }

        return Classification::unexpected($oauthError ?? ($status !== null ? "http_{$status}" : 'unexpected'));
    }

    private function isNetworkError(Throwable $error): bool
    {
        // Guzzle's ConnectException implements the PSR-18 interface. Guzzle 8 also
        // reports a timeout while reading the body as ResponseTransferException;
        // compare by name because that class does not exist in Guzzle 7.
        return $error instanceof NetworkExceptionInterface
            || $error instanceof LaravelConnectionException
            || is_a($error, 'GuzzleHttp\\Exception\\ResponseTransferException');
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

        // BadResponseException (4xx/5xx) has a response in both Guzzle 7 and 8.
        if ($error instanceof GuzzleBadResponseException) {
            $response = $error->getResponse();

            return [$response->getStatusCode(), $this->errorCode((string) $response->getBody())];
        }

        return [null, null];
    }

    /**
     * Read the `error` member of an OAuth2 error response given as an array,
     * a JSON string, or the bare error code that some SDKs use as the message.
     * Only a short lowercase code is accepted, so nothing else can leak through.
     */
    private function errorCode(mixed $body): ?string
    {
        if (is_string($body)) {
            $trimmed = trim($body);
            if ($this->isCode($trimmed)) {
                return $trimmed;
            }
            $body = json_decode($trimmed, true);
        }

        if (is_array($body) && isset($body['error']) && is_string($body['error']) && $this->isCode($body['error'])) {
            return $body['error'];
        }

        return null;
    }

    private function isCode(string $value): bool
    {
        return preg_match('/^[a-z_]{1,64}$/', $value) === 1;
    }
}
