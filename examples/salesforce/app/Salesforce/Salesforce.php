<?php

declare(strict_types=1);

namespace App\Salesforce;

use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Facades\OAuthRefreshLock;
use Dakiquang\OAuthRefreshLock\Refreshers\LeagueRefresher;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use RuntimeException;

/**
 * Everything Salesforce-specific in one place. One stored token per org,
 * under "salesforce:{orgId}".
 *
 * Salesforce's token response has no expires_in (the session timeout is set
 * in Salesforce), so the token is stored without an expiry and refreshed when
 * an API call answers 401 INVALID_SESSION_ID, with rejected().
 */
final class Salesforce
{
    public static function key(string $orgId): string
    {
        return "salesforce:{$orgId}";
    }

    public function provider(): GenericProvider
    {
        $login = rtrim((string) config('salesforce.login_url'), '/');

        return new GenericProvider([
            'clientId' => config('salesforce.client_id'),
            'clientSecret' => config('salesforce.client_secret'),
            'redirectUri' => config('salesforce.redirect_uri'),
            'urlAuthorize' => $login.'/services/oauth2/authorize',
            'urlAccessToken' => $login.'/services/oauth2/token',
            'urlResourceOwnerDetails' => $login.'/services/oauth2/userinfo',
            'scopes' => ['api', 'refresh_token'],
            'scopeSeparator' => ' ',
        ]);
    }

    /**
     * The org ID is the second-to-last segment of the identity URL in the token response ("id").
     */
    public function orgId(AccessToken $token): string
    {
        $id = $token->getValues()['id'] ?? null;
        $segments = is_string($id) ? explode('/', rtrim($id, '/')) : [];

        return count($segments) >= 2 ? $segments[count($segments) - 2] : throw new RuntimeException('No identity URL in the token response.');
    }

    /**
     * Store the token from the OAuth callback, under the refresh lock. The
     * instance URL goes in the token's meta, which refreshes keep.
     */
    public function connect(string $orgId, AccessToken $token): void
    {
        $instanceUrl = $token->getValues()['instance_url'] ?? throw new RuntimeException('No instance_url in the token response.');

        OAuthRefreshLock::for(self::key($orgId))->withLock(function (TokenStore $store) use ($orgId, $token, $instanceUrl): void {
            $store->put(self::key($orgId), new StoredToken(
                accessToken: $token->getToken(),
                refreshToken: $token->getRefreshToken(),
                expiresAt: null,
                meta: ['instance_url' => $instanceUrl],
            ));
        });
    }

    /**
     * Run a SOQL query. On 401 INVALID_SESSION_ID the token is reported as
     * rejected and the call is retried once: workers that get the same 401 cause
     * one refresh. Other errors are not a reason to rotate the refresh token.
     *
     * @return array<string, mixed>
     */
    public function query(string $orgId, string $soql): array
    {
        $pending = OAuthRefreshLock::for(self::key($orgId));
        $refresher = new LeagueRefresher($this->provider());

        $token = $pending->token($refresher);
        $response = $this->send($token, $soql);

        if ($this->sessionExpired($response)) {
            $token = $pending->rejected($token->accessToken)->token($refresher);
            $response = $this->send($token, $soql);
        }

        return $response->throw()->json() ?? [];
    }

    public function disconnect(string $orgId): void
    {
        OAuthRefreshLock::for(self::key($orgId))->withLock(fn (TokenStore $store) => $store->forget(self::key($orgId)));
    }

    /**
     * Salesforce answers an expired or invalid access token with HTTP 401 and
     * [{"message": "Session expired or invalid", "errorCode": "INVALID_SESSION_ID"}].
     */
    private function sessionExpired(Response $response): bool
    {
        return $response->status() === 401 && $response->json('0.errorCode') === 'INVALID_SESSION_ID';
    }

    private function send(StoredToken $token, string $soql): Response
    {
        $instanceUrl = $token->meta['instance_url'] ?? throw new RuntimeException('No instance URL stored.');

        return Http::withToken($token->accessToken)
            ->acceptJson()
            ->get(rtrim((string) $instanceUrl, '/').'/services/data/'.config('salesforce.api_version').'/query', ['q' => $soql]);
    }
}
