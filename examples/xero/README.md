# Xero OAuth 2.0 refresh token in Laravel: no more `invalid_grant` races

A Laravel example for the Xero API: connect a Xero organisation, call the Organisation endpoint, and a command that shows several queue workers refreshing one Xero token at the same time with exactly one refresh.

> **Verified on 7 November 2026 against a real Xero demo organisation** (Laravel 12, PHP 8.4, `database` cache locks): connect, Organisation API, and `xero:race --workers=6` over several rounds, each with one refresh.

## Why Xero needs this

Xero rotates refresh tokens: every refresh returns a new refresh token. The previous one can still be used for a **grace period of 30 minutes**; after that it expires and the user has to authorise again ([Xero: auth flow](https://developer.xero.com/documentation/guides/oauth2/auth-flow)). Access tokens last 30 minutes, refresh tokens up to 60 days.

The grace period hides the race. In a control run without any lock, six processes refreshed at the same moment with the same refresh token: all six succeeded and Xero issued six different new refresh tokens. Nothing fails in testing, but the app now has six token generations, keeps whichever was written last, and Xero does not document what happens to the others after 30 minutes. The lock makes sure only one refresh happens.

### Symptoms

- Refreshes "randomly" fail under load, or a connection that looked fine stops working later.
- Many refresh tokens issued for one connection: each duplicate refresh within 30 minutes succeeds and returns another one.
- When a refresh token is no longer accepted, the token endpoint (`https://identity.xero.com/connect/token`) answers with an OAuth error. Xero's documentation does not name it for a token used after the grace period; OAuth 2.0 (RFC 6749, section 5.2) defines `invalid_grant` for an invalid, expired or revoked refresh token, and that is what the default classifier treats as revoked.

## What you need

- A Laravel 10–13 app (PHP 8.1+) with a cache store that supports locks.
- A Xero developer account (free) and the Demo Company.
- A Xero app (**Web app**) at [developer.xero.com/app/manage](https://developer.xero.com/app/manage) with the redirect URI `http://localhost:8000/xero/callback`.

## Set up

```bash
composer require dakiquang/laravel-oauth-refresh-lock league/oauth2-client
php artisan vendor:publish --tag=oauth-refresh-lock-migrations
php artisan migrate
```

Copy `app/`, `config/` and `routes/` from this folder into the app, add `require __DIR__.'/xero.php';` to `routes/web.php`, and add the variables from `.env.example` to your environment file (client ID and a generated client secret from the app's Configuration page). Never commit them.

## Try it

```bash
php artisan serve
```

1. Open `http://localhost:8000/xero/connect`, allow access and choose the Demo Company.
2. `/xero/organisation` shows the organisation from the Xero API.
3. Run the race with the tenant ID shown there:

```bash
php artisan xero:race <tenantId> --workers=6
```

Expected: one process refreshed the token, the others used the token it stored; the command fails if a process failed or the token was not refreshed exactly once.

## The Xero-specific parts

```php
new GenericProvider([
    'urlAuthorize' => 'https://login.xero.com/identity/connect/authorize',
    'urlAccessToken' => 'https://identity.xero.com/connect/token',
    'scopes' => ['openid', 'profile', 'email', 'offline_access', 'accounting.settings.read'],
    'scopeSeparator' => ' ',
    // ...
], [
    // Xero's token endpoint takes the client credentials as HTTP Basic auth.
    'optionProvider' => new HttpBasicAuthOptionProvider,
]);
```

- `offline_access` is what returns a refresh token. Since March 2026 new Web apps use Xero's granular scopes; `accounting.settings.read` covers the Organisation endpoint ([Xero: scopes](https://developer.xero.com/documentation/guides/oauth2/scopes)).
- The tenant ID comes from `GET https://api.xero.com/connections`, called with the new access token, and every API call sends it as the `xero-tenant-id` header.
- One stored token per organisation, under the key `xero:{tenantId}`. The callback stores it under the refresh lock (`withLock`).
- When Xero rejects the refresh token, `/xero/organisation` sends the user back to connect; when the refresh fails temporarily it answers 503 and keeps the token; when the Organisation call itself fails it answers 502.
- `disconnect` forgets the stored token. To remove the connection at Xero too, call `DELETE https://api.xero.com/connections/{id}` first.

See also: [provider notes](../../docs/providers.md#xero) · [how it works](../../docs/how-it-works.md) · [README: when the provider rejects the access token](../../README.md#when-the-provider-rejects-the-access-token)
