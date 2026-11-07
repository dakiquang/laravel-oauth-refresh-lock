<?php

declare(strict_types=1);

namespace App\Microsoft;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Facades\OAuthRefreshLock;
use Dakiquang\OAuthRefreshLock\Refreshers\LeagueRefresher;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Illuminate\Support\Facades\Http;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use RuntimeException;

/**
 * Microsoft identity platform (Entra ID, v2.0 endpoints) and Microsoft Graph.
 * One stored token per signed-in user, under "microsoft:{oid}".
 */
final class Microsoft
{
    private const SCOPES = ['openid', 'profile', 'offline_access', 'User.Read'];

    public static function key(string $userObjectId): string
    {
        return "microsoft:{$userObjectId}";
    }

    public function provider(): GenericProvider
    {
        $base = 'https://login.microsoftonline.com/'.config('microsoft.tenant').'/oauth2/v2.0';

        return new GenericProvider([
            'clientId' => config('microsoft.client_id'),
            'clientSecret' => config('microsoft.client_secret'),
            'redirectUri' => config('microsoft.redirect_uri'),
            'urlAuthorize' => $base.'/authorize',
            'urlAccessToken' => $base.'/token',
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/oidc/userinfo',
            'scopes' => self::SCOPES,
            'scopeSeparator' => ' ',
        ]);
    }

    /**
     * The user's object ID, from Microsoft Graph called with the new token.
     */
    public function userObjectId(AccessToken $token): string
    {
        $id = Http::withToken($token->getToken())->acceptJson()->get('https://graph.microsoft.com/v1.0/me')->throw()->json('id');

        return is_string($id) ? $id : throw new RuntimeException('No user id from Microsoft Graph.');
    }

    public function connect(string $userObjectId, AccessToken $token): void
    {
        OAuthRefreshLock::for(self::key($userObjectId))->withLock(function (TokenStore $store) use ($userObjectId, $token): void {
            $store->put(self::key($userObjectId), new StoredToken(
                accessToken: $token->getToken(),
                refreshToken: $token->getRefreshToken(),
                expiresAt: $token->getExpires() ? CarbonImmutable::createFromTimestamp($token->getExpires()) : null,
            ));
        });
    }

    /**
     * A valid access token. Microsoft returns a new refresh token on every refresh
     * and does not revoke the old one, so a duplicate refresh costs a call rather
     * than the connection; the lock still avoids it. The refresh request names
     * the scopes (optional on the v2.0 token endpoint; they must be the same as
     * or a subset of the original ones), which shows how options pass through.
     */
    public function accessToken(string $userObjectId): string
    {
        return OAuthRefreshLock::for(self::key($userObjectId))
            ->accessToken(new LeagueRefresher($this->provider(), ['scope' => implode(' ', self::SCOPES)]));
    }

    /**
     * @return array<string, mixed>
     */
    public function me(string $userObjectId): array
    {
        return Http::withToken($this->accessToken($userObjectId))->acceptJson()
            ->get('https://graph.microsoft.com/v1.0/me')->throw()->json() ?? [];
    }

    public function disconnect(string $userObjectId): void
    {
        OAuthRefreshLock::for(self::key($userObjectId))->withLock(fn (TokenStore $store) => $store->forget(self::key($userObjectId)));
    }
}
