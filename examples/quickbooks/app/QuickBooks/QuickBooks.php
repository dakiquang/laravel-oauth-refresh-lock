<?php

declare(strict_types=1);

namespace App\QuickBooks;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Facades\OAuthRefreshLock;
use Dakiquang\OAuthRefreshLock\Refreshers\LeagueRefresher;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Illuminate\Support\Facades\Http;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Everything QuickBooks-specific in one place. One stored token per company
 * (realm), under the key "quickbooks:{realmId}".
 */
final class QuickBooks
{
    public static function key(string $realmId): string
    {
        return "quickbooks:{$realmId}";
    }

    public function provider(): GenericProvider
    {
        return new GenericProvider([
            'clientId' => config('quickbooks.client_id'),
            'clientSecret' => config('quickbooks.client_secret'),
            'redirectUri' => config('quickbooks.redirect_uri'),
            'urlAuthorize' => config('quickbooks.authorize_url'),
            'urlAccessToken' => config('quickbooks.token_url'),
            'urlResourceOwnerDetails' => config('quickbooks.userinfo_url'),
            'scopes' => ['com.intuit.quickbooks.accounting'],
            'scopeSeparator' => ' ',
        ]);
    }

    /**
     * Store the token from the OAuth callback. Under the refresh lock, so a
     * refresh still running for an older connection cannot overwrite it.
     */
    public function connect(string $realmId, AccessTokenInterface $token): void
    {
        OAuthRefreshLock::for(self::key($realmId))->withLock(function (TokenStore $store) use ($realmId, $token): void {
            $store->put(self::key($realmId), new StoredToken(
                accessToken: $token->getToken(),
                refreshToken: $token->getRefreshToken(),
                expiresAt: $token->getExpires() ? CarbonImmutable::createFromTimestamp($token->getExpires()) : null,
            ));
        });
    }

    /**
     * A valid access token. Intuit may revoke the refresh tokens when two
     * refreshes use the same value, so every caller goes through the lock.
     */
    public function accessToken(string $realmId): string
    {
        return OAuthRefreshLock::for(self::key($realmId))->accessToken(new LeagueRefresher($this->provider()));
    }

    /**
     * @return array<string, mixed>
     */
    public function companyInfo(string $realmId): array
    {
        return Http::withToken($this->accessToken($realmId))
            ->acceptJson()
            ->get(config('quickbooks.api_base')."/v3/company/{$realmId}/companyinfo/{$realmId}")
            ->throw()
            ->json('CompanyInfo') ?? [];
    }

    /**
     * Forget the stored token. This does not revoke it at Intuit; see the README.
     */
    public function disconnect(string $realmId): void
    {
        OAuthRefreshLock::for(self::key($realmId))->withLock(fn (TokenStore $store) => $store->forget(self::key($realmId)));
    }
}
