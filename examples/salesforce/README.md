# Salesforce OAuth refresh token rotation in Laravel: `invalid_grant`, `INVALID_SESSION_ID` and no `expires_in`

A Laravel example for the Salesforce REST API: connect an org, run a SOQL query, and refresh the token when the API answers **401 `INVALID_SESSION_ID`**, with one refresh even when many workers get the 401 at once.

> **Tested against a local stand-in for Salesforce, not yet against a real org.** The stand-in mirrors the documented behaviour: no `expires_in`, a new refresh token on every refresh, reuse of a retired refresh token answered with `invalid_grant` and the current tokens revoked, and 401 `INVALID_SESSION_ID` for a stale access token. Result: connect and query work; `salesforce:race --workers=6`, three rounds, 18 API calls answered 401, 3 refreshes, 0 refresh-token reuses. If something differs with a real org, please open an issue.

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

## What you need

- A Laravel 10–13 app (PHP 8.1+) with a cache store that supports locks.
- A Salesforce org (a free Developer Edition works) and an **External Client App** with OAuth enabled: callback `http://localhost:8000/salesforce/callback`, scopes **Manage user data via APIs (`api`)** and **Perform requests at any time (`refresh_token`)**.

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
