<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Refreshers;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Contracts\Refresher;
use Dakiquang\OAuthRefreshLock\RefreshedToken;
use Dakiquang\OAuthRefreshLock\StoredToken;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use LogicException;

/**
 * Refreshes through any league/oauth2-client provider with the
 * `refresh_token` grant. Errors are left to the ErrorClassifier: the default
 * one reads the provider's IdentityProviderException and Guzzle's exceptions.
 *
 * league/oauth2-client does not keep the HTTP status when the token endpoint
 * answers with something that is not JSON (an error page) and throws an
 * UnexpectedValueException. Without the status there is no telling an outage
 * from a misconfigured URL, so that error stays unexpected: the token is kept
 * and the error reaches the caller.
 */
final class LeagueRefresher implements Refresher
{
    /**
     * @param  array<string, mixed>  $options  extra token request parameters, for example `scope`.
     */
    public function __construct(
        private readonly AbstractProvider $provider,
        private readonly array $options = [],
    ) {}

    public function refresh(StoredToken $token): RefreshedToken
    {
        if ($token->refreshToken === null) {
            // Not a provider rejection, so the token is not marked revoked: the app decides.
            throw new LogicException('No refresh token is stored for this token; the user has to connect again.');
        }

        $new = $this->provider->getAccessToken('refresh_token', [...$this->options, 'refresh_token' => $token->refreshToken]);

        return new RefreshedToken(
            accessToken: $new->getToken(),
            refreshToken: $new->getRefreshToken(),
            expiresAt: $new->getExpires() === null ? null : CarbonImmutable::createFromTimestamp($new->getExpires()),
            scopes: $this->scopes($new),
        );
    }

    /**
     * Scopes granted by the response (RFC 6749 §3.3: space-delimited), or null
     * to keep the stored ones when the provider does not return them.
     *
     * @return list<string>|null
     */
    private function scopes(AccessTokenInterface $token): ?array
    {
        $scope = $token->getValues()['scope'] ?? null;

        return is_string($scope) && trim($scope) !== '' ? array_values(array_filter(explode(' ', $scope), fn (string $scope): bool => $scope !== '')) : null;
    }
}
