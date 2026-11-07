<?php

declare(strict_types=1);

namespace App\Xero;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Facades\OAuthRefreshLock;
use Dakiquang\OAuthRefreshLock\Refreshers\LeagueRefresher;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Illuminate\Support\Facades\Http;
use League\OAuth2\Client\OptionProvider\HttpBasicAuthOptionProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Everything Xero-specific in one place. One stored token per Xero
 * organisation (tenant), under the key "xero:{tenantId}".
 */
final class Xero
{
    public static function key(string $tenantId): string
    {
        return "xero:{$tenantId}";
    }

    public function provider(): GenericProvider
    {
        return new GenericProvider([
            'clientId' => config('xero.client_id'),
            'clientSecret' => config('xero.client_secret'),
            'redirectUri' => config('xero.redirect_uri'),
            'urlAuthorize' => 'https://login.xero.com/identity/connect/authorize',
            'urlAccessToken' => 'https://identity.xero.com/connect/token',
            'urlResourceOwnerDetails' => 'https://identity.xero.com/connect/userinfo',
            // offline_access gives the refresh token; accounting.settings.read covers the Organisation endpoint.
            'scopes' => ['openid', 'profile', 'email', 'offline_access', 'accounting.settings.read'],
            'scopeSeparator' => ' ',
        ], [
            // Xero's token endpoint takes the client credentials as HTTP Basic auth.
            'optionProvider' => new HttpBasicAuthOptionProvider,
        ]);
    }

    /**
     * The organisations a new token can access, from Xero's connections endpoint.
     *
     * @return list<array{tenantId: string, tenantName: string|null}>
     */
    public function tenants(AccessTokenInterface $token): array
    {
        return Http::withToken($token->getToken())->acceptJson()->get('https://api.xero.com/connections')->throw()->json() ?? [];
    }

    /**
     * Store the token from the OAuth callback, under the refresh lock.
     */
    public function connect(string $tenantId, AccessTokenInterface $token): void
    {
        OAuthRefreshLock::for(self::key($tenantId))->withLock(function (TokenStore $store) use ($tenantId, $token): void {
            $store->put(self::key($tenantId), new StoredToken(
                accessToken: $token->getToken(),
                refreshToken: $token->getRefreshToken(),
                expiresAt: $token->getExpires() ? CarbonImmutable::createFromTimestamp($token->getExpires()) : null,
            ));
        });
    }

    /**
     * A valid access token. Xero lets the previous refresh token be reused for
     * 30 minutes, which hides duplicate refreshes; the lock avoids them.
     */
    public function accessToken(string $tenantId): string
    {
        return OAuthRefreshLock::for(self::key($tenantId))->accessToken(new LeagueRefresher($this->provider()));
    }

    /**
     * @return array<string, mixed>
     */
    public function organisation(string $tenantId): array
    {
        return Http::withToken($this->accessToken($tenantId))
            ->withHeaders(['xero-tenant-id' => $tenantId])
            ->acceptJson()
            ->get('https://api.xero.com/api.xro/2.0/Organisation')
            ->throw()
            ->json('Organisations.0') ?? [];
    }

    /**
     * Forget the stored token. This does not remove the connection at Xero; see the README.
     */
    public function disconnect(string $tenantId): void
    {
        OAuthRefreshLock::for(self::key($tenantId))->withLock(fn (TokenStore $store) => $store->forget(self::key($tenantId)));
    }
}
