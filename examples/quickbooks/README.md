# QuickBooks Online OAuth 2.0 refresh token in Laravel: avoid `invalid_grant` and revoked tokens

A connect / call / disconnect flow for QuickBooks Online, plus a command that shows several workers refreshing one token at the same time.

QuickBooks is a good test case: when two refreshes use the same refresh token, the second gets `invalid_grant` and Intuit may revoke the refresh tokens, so the user has to authorise again ([Intuit's FAQ](https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/faq)). More in [docs/providers.md](../../docs/providers.md).

> **Verified on 07/11/2026 against a QuickBooks Online sandbox company** (Laravel 12, PHP 8.4, `database` cache locks): connect, CompanyInfo, and `quickbooks:race --workers=6` three times, each with one refresh and no `invalid_grant`. Error paths (wrong `state`, another company's `realmId`, Intuit unavailable, rejected refresh token) were tested against a local stand-in for Intuit. If something differs for you, please open an issue.

## What you need

- A Laravel 10–13 app (PHP 8.1+), with a cache store that supports locks. A new app's default `database` cache store works.
- An Intuit developer account, an app with the **Accounting** scope, and a sandbox company ([Create and test with a sandbox company](https://developer.intuit.com/app/developer/qbo/docs/develop/sandboxes/manage-your-sandboxes)).
- In the app's settings, the redirect URI `http://localhost:8000/quickbooks/callback`.

## Set up

```bash
composer require dakiquang/laravel-oauth-refresh-lock league/oauth2-client
php artisan vendor:publish --tag=oauth-refresh-lock-migrations
php artisan migrate
```

Copy the files from this folder into the app:

| From | To |
|---|---|
| `app/QuickBooks/QuickBooks.php` | `app/QuickBooks/QuickBooks.php` |
| `app/Http/Controllers/QuickBooksController.php` | `app/Http/Controllers/QuickBooksController.php` |
| `app/Console/Commands/QuickBooksCompany.php`, `QuickBooksRace.php` | `app/Console/Commands/` |
| `config/quickbooks.php` | `config/quickbooks.php` |
| `routes/quickbooks.php` | `routes/quickbooks.php` |

Add the routes to `routes/web.php`:

```php
require __DIR__.'/quickbooks.php';
```

Add the variables from `.env.example` to your environment file and fill in the client ID and secret (Development keys) of your Intuit app. Never commit them.

## Try it

```bash
php artisan serve
```

1. Open `http://localhost:8000/quickbooks/connect`, sign in to Intuit and choose the sandbox company.
2. You are sent back to `/quickbooks/company`, which shows the company's details from the CompanyInfo API.
3. Note the company's realm ID (in the callback URL, or in the sandbox company settings) and run:

```bash
php artisan quickbooks:race <realmId> --workers=6
```

The command moves the stored access token's expiry into the past and starts six processes that all need a token at once. Expected output: one process refreshed the token, the others waited for the lock and used the token it stored. The command fails if a process failed or the token was not refreshed exactly once.

```text
pid 82927: used the stored token, company "…"
pid 82928: used the stored token, company "…"
pid 82931: REFRESHED the token, company "…"
...
```

## How the example is built

- `QuickBooks::provider()` is league/oauth2-client's `GenericProvider` with Intuit's endpoints. Intuit's token endpoint accepts the client credentials in the request body or as HTTP Basic (`client_secret_post`, `client_secret_basic` in its [discovery document](https://developer.api.intuit.com/.well-known/openid_sandbox_configuration)), so no Intuit-specific provider package is needed.
- One token per company, under the key `quickbooks:{realmId}`.
- The `realmId` in the callback URL comes from the browser, so the callback first calls CompanyInfo for that realm with the new access token. A token only works for the company it was issued for, so a tampered `realmId` is rejected before anything is stored.
- The callback stores the token under the refresh lock (`withLock`), so a refresh that is still running for an older connection cannot overwrite it.
- Every API call takes its token from `accessToken()` with a `LeagueRefresher`.
- When Intuit rejects the refresh token, `/quickbooks/company` sends the user back to connect. When the token refresh fails temporarily, it answers 503 and keeps the token; when the CompanyInfo call itself fails, it answers 502.
- The connected company is kept in the session to keep the example short. A real app stores the realm ID with its own account.
- `disconnect` forgets the stored token. It does not revoke it at Intuit; to do that, call Intuit's revocation endpoint (listed in the discovery document) before forgetting it.

## Sources

- [Set up OAuth 2.0](https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-2.0): authorisation URL, the `com.intuit.quickbooks.accounting` scope, the `code`, `state` and `realmId` redirect parameters.
- [Discovery document](https://developer.api.intuit.com/.well-known/openid_sandbox_configuration): authorisation and token endpoints.
- [Create and test with a sandbox company](https://developer.intuit.com/app/developer/qbo/docs/develop/sandboxes/manage-your-sandboxes): sandbox base URL `https://sandbox-quickbooks.api.intuit.com/v3`.
- [CompanyInfo API](https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities/companyinfo): `GET /v3/company/<realmID>/companyinfo/<realmID>`, production base URL `https://quickbooks.api.intuit.com`.
