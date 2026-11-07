<?php

declare(strict_types=1);

namespace App\Google;

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
 * Google OAuth 2.0 for web server apps. One stored token per Google account,
 * under "google:{sub}".
 */
final class Google
{
    public static function key(string $subject): string
    {
        return "google:{$subject}";
    }

    public function provider(): GenericProvider
    {
        return new GenericProvider([
            'clientId' => config('google.client_id'),
            'clientSecret' => config('google.client_secret'),
            'redirectUri' => config('google.redirect_uri'),
            'urlAuthorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'urlAccessToken' => 'https://oauth2.googleapis.com/token',
            'urlResourceOwnerDetails' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes' => ['openid', 'email', 'profile'],
            'scopeSeparator' => ' ',
        ]);
    }

    /**
     * The authorisation URL and its state, from the same provider instance.
     * access_type=offline is what makes Google return a refresh token;
     * prompt=consent asks again, so a returning user also gets one.
     *
     * @return array{0: string, 1: string}
     */
    public function authorization(): array
    {
        $provider = $this->provider();
        $url = $provider->getAuthorizationUrl(['access_type' => 'offline', 'prompt' => 'consent']);

        return [$url, $provider->getState()];
    }

    /**
     * The account's stable ID ("sub"), from the userinfo endpoint called with the new token.
     */
    public function subject(AccessToken $token): string
    {
        $sub = Http::withToken($token->getToken())->acceptJson()->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json('sub');

        return is_string($sub) ? $sub : throw new RuntimeException('No subject from the userinfo endpoint.');
    }

    public function connect(string $subject, AccessToken $token): void
    {
        OAuthRefreshLock::for(self::key($subject))->withLock(function (TokenStore $store) use ($subject, $token): void {
            $store->put(self::key($subject), new StoredToken(
                accessToken: $token->getToken(),
                refreshToken: $token->getRefreshToken(),
                expiresAt: $token->getExpires() ? CarbonImmutable::createFromTimestamp($token->getExpires()) : null,
            ));
        });
    }

    /**
     * A valid access token. Google does not rotate refresh tokens: the refresh
     * response has no refresh_token, and LeagueRefresher keeps the stored one.
     */
    public function accessToken(string $subject): string
    {
        return OAuthRefreshLock::for(self::key($subject))->accessToken(new LeagueRefresher($this->provider()));
    }

    /**
     * @return array<string, mixed>
     */
    public function userinfo(string $subject): array
    {
        return Http::withToken($this->accessToken($subject))->acceptJson()
            ->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json() ?? [];
    }

    public function disconnect(string $subject): void
    {
        OAuthRefreshLock::for(self::key($subject))->withLock(fn (TokenStore $store) => $store->forget(self::key($subject)));
    }
}
