# Salesforce OAuth refresh token rotation in Laravel: `invalid_grant`, `INVALID_SESSION_ID` and no `expires_in`

A Laravel example for the Salesforce REST API: connect an org, run a SOQL query, and refresh the token when the API answers **401 `INVALID_SESSION_ID`**, with one refresh even when many workers get the 401 at once.

> **Verified on 7 November 2026 against a real Salesforce Developer Edition org** (Laravel 12, PHP 8.4, `database` cache locks): connect with PKCE, SOQL query, and `salesforce:race` over four rounds, each with one refresh and the connection kept. Also observed: no `expires_in` in the token or refresh responses, and a new refresh token on every refresh. See [What a real org did](#what-a-real-org-did).

## Why Salesforce needs this

- **Refresh Token Rotation.** With rotation on (the default, and locked, for new External Client Apps since 22 October 2026), every refresh returns a new refresh token and the previous one is invalidated at once. Reusing an invalidated refresh token revokes the current refresh token and its access tokens too ([Salesforce: Refresh Token Rotation](https://help.salesforce.com/s/articleView?id=005316711&type=1)). Two workers refreshing at once can end the connection.
- **No `expires_in`.** The token response has `access_token`, `refresh_token`, `instance_url`, `id`, `issued_at`, `signature`, but no expiry: the session timeout is set in Salesforce ([Salesforce: web server flow](https://help.salesforce.com/s/articleView?id=sf.remoteaccess_oauth_web_server_flow.htm&type=5)). An app learns the access token is dead when an API call answers 401 with `INVALID_SESSION_ID` ("Session expired or invalid", [REST API status codes](https://developer.salesforce.com/docs/platform/api-rest/guide/errorcodes.html)).

### Symptoms

The token endpoint, for a refresh token that is no longer valid (Salesforce's own example):

```json
{"error": "invalid_grant", "error_description": "expired access/refresh token"}
```

The REST API, for an access token that is no longer valid (HTTP 401):

```json
[{"message": "Session expired or invalid", "errorCode": "INVALID_SESSION_ID"}]
```

## The 401-driven refresh

```php
$pending = OAuthRefreshLock::for(Salesforce::key($orgId));
$refresher = new LeagueRefresher($this->provider());

$token = $pending->token($refresher);
$response = $this->send($token, $soql);

if ($response->status() === 401 && $response->json('0.errorCode') === 'INVALID_SESSION_ID') {
    // Refresh only if the stored token is still the one Salesforce rejected.
    $token = $pending->rejected($token->accessToken)->token($refresher);
    $response = $this->send($token, $soql);
}
```

`rejected()` refreshes under the lock while the stored access token is still the rejected one. The first worker refreshes; the others find a different access token stored and retry with it.

## What a real org did

Two control experiments without the lock, on the same Developer Edition org:

| Experiment | Result |
|---|---|
| Six processes refresh at the same moment with the **same** refresh token | 5 succeeded, each with a different new refresh token; 1 got `concurrent_request`. All five new access tokens worked, and the old refresh token still refreshed a few seconds later. |
| Refresh once (R1 → R2), wait **90 seconds**, refresh with R1 again | `invalid_grant`, and R2 stopped working too (`invalid_grant`): the connection was revoked and needs a new authorisation. |

So Salesforce tolerates a reuse for a short moment, which Salesforce's article does not mention, and after that revokes the whole connection, as the article says. How long the short moment lasts was not measured (somewhere between a few seconds and 90 seconds). The lock keeps every refresh single, so neither case happens. The default classifier treats `concurrent_request` as temporary.

## What you need

- A Laravel 10–13 app (PHP 8.1+) with a cache store that supports locks.
- A Salesforce org (a free Developer Edition works) and an **External Client App** (Setup → App Manager → New External Client App) with **Enable OAuth**: callback `http://localhost:8000/salesforce/callback`, scopes **Manage user data via APIs (`api`)** and **Perform requests at any time (`refresh_token`, `offline_access`)**. Copy the Consumer Key and Secret from the app's Settings → OAuth Settings (Salesforce emails a verification code).
- New External Client Apps come with **Refresh Token Rotation**, **PKCE** and a **30-day idle refresh token limit** switched on and locked. The example sends PKCE (`pkceMethod` S256); without it the authorisation fails.
- Use the org's **My Domain** as the login URL (`https://<your-domain>.my.salesforce.com`). With a newly created app, `https://login.salesforce.com` answered `invalid_client_id` ("client identifier invalid") in our run, while My Domain worked at once.

## Set up

```bash
composer require dakiquang/laravel-oauth-refresh-lock league/oauth2-client
php artisan vendor:publish --tag=oauth-refresh-lock-migrations
php artisan migrate
```

Copy `app/`, `config/` and `routes/` into the app, add `require __DIR__.'/salesforce.php';` to `routes/web.php`, and add the variables from `.env.example` to your environment file (Consumer Key and Consumer Secret). Never commit them.

## Try it

```bash
php artisan serve
```

1. Open `http://localhost:8000/salesforce/connect` and allow access.
2. `/salesforce/organisation` runs `SELECT Name FROM Organization`.
3. Run the race with the org ID (the 15/18-character ID in the identity URL, also shown in Setup → Company Information):

```bash
php artisan salesforce:race <orgId> --workers=6
```

It replaces the stored access token with one Salesforce will reject, as after a session timeout, and starts six processes. Expected: every process gets a 401, one refreshes, the others use the new token.

## Notes

- The org ID comes from the `id` (identity URL) in Salesforce's token response, not from the browser. The `instance_url` is kept in the stored token's `meta`, which refreshes keep.
- One stored token per org, under `salesforce:{orgId}`.
- When Salesforce rejects the refresh token, `/salesforce/organisation` sends the user back to connect; when the refresh fails temporarily it answers 503; when the query fails it answers 502.

See also: [provider notes](../../docs/providers.md#salesforce) · [how it works](../../docs/how-it-works.md) · [README: when the provider rejects the access token](../../README.md#when-the-provider-rejects-the-access-token)
