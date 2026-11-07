# Provider notes: refresh token rotation and `invalid_grant` in QuickBooks, Salesforce, Xero, Microsoft Graph and Google

How each provider handles refresh tokens, from its own documentation (checked 7 November 2026), what the error looks like when two workers refresh at once, a Laravel code example, and what was tested. Providers change these rules; follow the links before relying on them. Where the documentation does not say, this page says so instead of guessing.

## Summary

| Provider | New refresh token on refresh | Old refresh token afterwards | A duplicate refresh | Example |
|---|---|---|---|---|
| [QuickBooks Online](#intuit-quickbooks-online) | the value changes every 24 hours | expires when a new one is issued | `invalid_grant`; Intuit may revoke the tokens | [examples/quickbooks](../examples/quickbooks) |
| [Salesforce](#salesforce) | every time (Refresh Token Rotation) | invalid at once | reuse revokes the current tokens too | [examples/salesforce](../examples/salesforce) |
| [Xero](#xero) | every time | usable for 30 minutes | succeeds within the grace period | [examples/xero](../examples/xero) |
| [Microsoft identity platform](#microsoft-identity-platform) | every time | not revoked when used | an extra call | [examples/microsoft](../examples/microsoft) |
| [Google](#google) | no | stays valid | an extra call | [examples/google](../examples/google) |

The package also saves duplicate provider calls when the risk is low, but the reason it exists is the first two rows. How the lock, the re-read and the compare-and-swap work together: [how-it-works.md](how-it-works.md).

## Intuit QuickBooks Online

### Rotation behaviour

- The refresh token value changes every 24 hours; store the latest value from every response. When a new value is issued, the previous one expires.
- Refresh tokens have a rolling expiry of 100 days, with a hard limit of 5 years. Access tokens last 3,600 seconds.
- If two refreshes use the same value, the first succeeds and the second gets `invalid_grant`, and Intuit may treat this as a security issue and revoke the refresh tokens, so the user has to authorise again. Intuit advises refreshing one at a time.
- Not confirmed by Intuit's documentation: a 24-hour grace period for the previous refresh token (mentioned in community threads; the FAQ says the previous value expires).
- Sources: [OAuth 2.0 FAQ](https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/faq), [Set up OAuth 2.0](https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-2.0).

### Error response

`invalid_grant` from `https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer`, for a refresh token that was already rotated or revoked.

### Example

```php
$provider = new GenericProvider([
    'clientId' => config('quickbooks.client_id'),
    'clientSecret' => config('quickbooks.client_secret'),
    'redirectUri' => config('quickbooks.redirect_uri'),
    'urlAuthorize' => 'https://appcenter.intuit.com/connect/oauth2',
    'urlAccessToken' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
    'urlResourceOwnerDetails' => 'https://sandbox-accounts.platform.intuit.com/v1/openid_connect/userinfo',
    'scopes' => ['com.intuit.quickbooks.accounting'],
    'scopeSeparator' => ' ',
]);

$accessToken = OAuthRefreshLock::for("quickbooks:{$realmId}")->accessToken(new LeagueRefresher($provider));
```

Full example, including a callback that checks the `realmId` against the new token: [examples/quickbooks](../examples/quickbooks).

### What was tested

A real QuickBooks Online sandbox company (7 November 2026): six processes with an expired token at the same moment, three rounds, one refresh per round, no `invalid_grant`. No run without the lock: it could revoke the sandbox connection; that risk comes from Intuit's FAQ, not from our test.

## Salesforce

### Rotation behaviour

- With Refresh Token Rotation on, every refresh returns a new refresh token and the previous one is invalidated at once. It is on by default, and cannot be turned off, for new External Client Apps since 22 October 2026.
- Reusing an invalidated refresh token revokes the current refresh token and its access tokens, so the client must authenticate again.
- **No `expires_in`.** The token response has `access_token`, `refresh_token`, `instance_url`, `id`, `token_type`, `issued_at`, `signature` and `scope`; the session timeout is set in Salesforce. Store the token without an expiry and refresh when an API call answers 401, with `rejected()`.
- Sources: [Refresh Token Rotation](https://help.salesforce.com/s/articleView?id=005316711&type=1), [External Client App defaults](https://help.salesforce.com/s/articleView?id=005390912&type=1), [Web Server Flow](https://help.salesforce.com/s/articleView?id=sf.remoteaccess_oauth_web_server_flow.htm&type=5), [REST API status codes](https://developer.salesforce.com/docs/platform/api-rest/guide/errorcodes.html).

### Error responses

- Token endpoint, refresh token no longer valid (the article's example):

  ```json
  {"error": "invalid_grant", "error_description": "expired access/refresh token"}
  ```

- REST API, access token no longer valid: HTTP 401 with `errorCode` `INVALID_SESSION_ID` ("Session expired or invalid").

### Example

```php
$pending = OAuthRefreshLock::for("salesforce:{$orgId}");
$refresher = new LeagueRefresher($provider);

$token = $pending->token($refresher);
$response = Http::withToken($token->accessToken)->get($token->meta['instance_url'].'/services/data/v60.0/query', ['q' => $soql]);

if ($response->status() === 401 && $response->json('0.errorCode') === 'INVALID_SESSION_ID') {
    // Refresh only if the stored token is still the one Salesforce rejected.
    $token = $pending->rejected($token->accessToken)->token($refresher);
    $response = Http::withToken($token->accessToken)->get($token->meta['instance_url'].'/services/data/v60.0/query', ['q' => $soql]);
}
```

Full example, with the org ID taken from the token response and `instance_url` kept in the token's `meta`: [examples/salesforce](../examples/salesforce).

### What was tested

Not yet against a real org. Against a local stand-in that mirrors the documented behaviour (no `expires_in`, rotation, reuse revokes, 401 `INVALID_SESSION_ID`): six processes, three rounds, all 18 API calls answered 401, one refresh per round, no refresh token reused. The package's concurrency test also runs eight forked workers that report the same rejected token, with database and Redis locks.

## Xero

### Rotation behaviour

- Every refresh returns a new refresh token. The previous one can still be used for a grace period of 30 minutes; after that it no longer works.
- Refresh tokens are valid for up to 60 days. Access tokens last 30 minutes.
- The token endpoint takes the client credentials as HTTP Basic auth.
- Not stated by Xero: the error for a refresh token used after the grace period, and whether reuse is detected.
- Sources: [Token types](https://developer.xero.com/documentation/guides/oauth2/token-types), [Auth flow](https://developer.xero.com/documentation/guides/oauth2/auth-flow), [Scopes](https://developer.xero.com/documentation/guides/oauth2/scopes).

### Error response

Within the grace period, a duplicate refresh does not fail at all, which is why the race goes unnoticed with Xero. Xero's documentation does not name the error for a refresh token used after the grace period; OAuth 2.0 (RFC 6749, section 5.2) defines `invalid_grant` for a refresh token that is invalid, expired or revoked, and that is what the default classifier treats as revoked.

### Example

```php
$provider = new GenericProvider([
    'clientId' => config('xero.client_id'),
    'clientSecret' => config('xero.client_secret'),
    'redirectUri' => config('xero.redirect_uri'),
    'urlAuthorize' => 'https://login.xero.com/identity/connect/authorize',
    'urlAccessToken' => 'https://identity.xero.com/connect/token',
    'urlResourceOwnerDetails' => 'https://identity.xero.com/connect/userinfo',
    'scopes' => ['openid', 'profile', 'email', 'offline_access', 'accounting.settings.read'],
    'scopeSeparator' => ' ',
], ['optionProvider' => new HttpBasicAuthOptionProvider]);

$accessToken = OAuthRefreshLock::for("xero:{$tenantId}")->accessToken(new LeagueRefresher($provider));

Http::withToken($accessToken)->withHeaders(['xero-tenant-id' => $tenantId])->get('https://api.xero.com/api.xro/2.0/Organisation');
```

Full example, with the tenant from the connections endpoint: [examples/xero](../examples/xero).

### What was tested

A real Xero demo organisation (7 November 2026): with the package, six processes, several rounds, one refresh per round. Control run without any lock: six processes refreshed at the same moment with the same refresh token, all six succeeded, and Xero issued a different new refresh token to each. Not measured: whether those sibling tokens stay valid after 30 minutes.

## Microsoft identity platform

### Rotation behaviour

- Refresh tokens replace themselves with a new one on every use; the old one is not revoked, and the client should discard it.
- Refresh tokens last 90 days by default (24 hours for single-page apps). Access tokens last 60–90 minutes.
- Sources: [Refresh tokens](https://learn.microsoft.com/en-us/entra/identity-platform/refresh-tokens), [Auth code flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [Error codes](https://learn.microsoft.com/en-us/entra/identity-platform/reference-error-codes).

### Error response

`invalid_grant` with an `AADSTS` code, for example `AADSTS70008` (ExpiredOrRevokedGrant: "The refresh token has expired due to inactivity"), `AADSTS700082` (inactive refresh token), `AADSTS700084` (single-page app refresh token expired) or `AADSTS50173` (grant revoked, for example after a password reset).

### Example

```php
$base = 'https://login.microsoftonline.com/'.config('microsoft.tenant').'/oauth2/v2.0';

$provider = new GenericProvider([
    'clientId' => config('microsoft.client_id'),
    'clientSecret' => config('microsoft.client_secret'),
    'redirectUri' => config('microsoft.redirect_uri'),
    'urlAuthorize' => $base.'/authorize',
    'urlAccessToken' => $base.'/token',
    'urlResourceOwnerDetails' => 'https://graph.microsoft.com/oidc/userinfo',
    'scopes' => ['openid', 'profile', 'offline_access', 'User.Read'],
    'scopeSeparator' => ' ',
]);

$accessToken = OAuthRefreshLock::for("microsoft:{$userObjectId}")->accessToken(new LeagueRefresher($provider));
```

Full example: [examples/microsoft](../examples/microsoft).

### What was tested

Written from the documentation; the example boots in a Laravel 12 app. Not yet run against a real tenant.

## Google

### Rotation behaviour

- Refreshing does not return a new refresh token; keep using the one you have. `LeagueRefresher` then keeps the stored refresh token.
- A refresh token is only returned with `access_type=offline`.
- A refresh token stops working after 6 months unused, when the user revokes access, or when the account has more than 100 refresh tokens for the same client (the oldest is invalidated). Apps in "Testing" with external users get refresh tokens that expire after 7 days.
- Sources: [OAuth 2.0 for web server apps](https://developers.google.com/identity/protocols/oauth2/web-server), [Using OAuth 2.0](https://developers.google.com/identity/protocols/oauth2).

### Error response

`invalid_grant`: in Google's words, "the token may have expired or has been invalidated". Many write-ups quote the `error_description` `Token has been expired or revoked.`; it is not in Google's documentation.

### Example

```php
$provider = new GenericProvider([
    'clientId' => config('google.client_id'),
    'clientSecret' => config('google.client_secret'),
    'redirectUri' => config('google.redirect_uri'),
    'urlAuthorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'urlAccessToken' => 'https://oauth2.googleapis.com/token',
    'urlResourceOwnerDetails' => 'https://openidconnect.googleapis.com/v1/userinfo',
    'scopes' => ['openid', 'email', 'profile'],
    'scopeSeparator' => ' ',
]);

$authorizationUrl = $provider->getAuthorizationUrl(['access_type' => 'offline', 'prompt' => 'consent']);

$accessToken = OAuthRefreshLock::for("google:{$sub}")->accessToken(new LeagueRefresher($provider));
```

Full example: [examples/google](../examples/google).

### What was tested

Written from the documentation; the example boots in a Laravel 12 app. Not yet run against a real account.

## Notes on league/oauth2-client

- `GenericProvider` fills `IdentityProviderException`'s code from a field of the response body (the `responseCode` option, unset by default), not from the HTTP status. The default classifier therefore treats the code as an HTTP status only between 100 and 599. If you configure `responseCode` and the provider puts a value in that range there that is not the HTTP status, classification may be wrong; bind your own classifier in that case.
- Some provider packages attach the PSR-7 response to the exception; then the real HTTP status is used.
- When the token endpoint answers with something that is not JSON (a gateway's error page, or a wrong URL), league/oauth2-client throws an `UnexpectedValueException` without the HTTP status. There is no telling an outage from a misconfiguration, so the error reaches your code as is and the token is kept.
