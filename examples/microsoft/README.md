# Microsoft Graph refresh token in Laravel: `invalid_grant`, `AADSTS70008` and concurrent refreshes

A Laravel example for the Microsoft identity platform (Entra ID, v2.0 endpoints) and Microsoft Graph: sign in, call `GET /v1.0/me`, and a command that shows several workers refreshing one token with exactly one refresh.

> **Written from Microsoft's documentation and checked to boot in a Laravel 12 app; not yet run against a real tenant.** If something differs, please open an issue.

> **Got here from `AADSTS70008`?** It means the refresh token expired from inactivity or was revoked. Microsoft does not revoke the old refresh token when it is used, so this error is not caused by two workers refreshing at once, and this package cannot prevent it. The default classifier reports it as `TokenRevoked`, so you can ask the user to sign in again.

## What Microsoft does with refresh tokens

- Refresh tokens replace themselves with a new one on every use, but the old one is **not revoked** when used; the client should discard it ([Microsoft: refresh tokens](https://learn.microsoft.com/en-us/entra/identity-platform/refresh-tokens)). A duplicate refresh costs an extra call, not the connection.
- Refresh tokens last 90 days by default (24 hours for single-page apps); access tokens 60–90 minutes.
- An expired or revoked refresh token returns `invalid_grant`, with codes such as `AADSTS70008` (ExpiredOrRevokedGrant: "The refresh token has expired due to inactivity"), `AADSTS700082` and `AADSTS50173` ([Microsoft: error codes](https://learn.microsoft.com/en-us/entra/identity-platform/reference-error-codes)). The default classifier marks the token revoked on `invalid_grant`.

### Symptoms

`invalid_grant` from `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`, with a description that starts with an `AADSTS` code, for example:

```text
AADSTS70008: ExpiredOrRevokedGrant - The refresh token has expired due to inactivity.
```

## The Microsoft-specific parts

```php
$base = 'https://login.microsoftonline.com/'.config('microsoft.tenant').'/oauth2/v2.0';

new GenericProvider([
    'urlAuthorize' => $base.'/authorize',
    'urlAccessToken' => $base.'/token',
    'scopes' => ['openid', 'profile', 'offline_access', 'User.Read'],
    'scopeSeparator' => ' ',
    // ...
]);

// Optional: name the scopes on refresh (same as or a subset of the original ones).
OAuthRefreshLock::for($key)->accessToken(new LeagueRefresher($provider, ['scope' => 'openid profile offline_access User.Read']));
```

- `offline_access` is what returns a refresh token. `scope` on the refresh request is optional ([Microsoft: auth code flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow)); it is passed here to show how `LeagueRefresher` options reach the token request.
- The user's object ID comes from `GET https://graph.microsoft.com/v1.0/me` with the new token; one stored token per user, under `microsoft:{id}`.

## Set up and try it

1. Register an app in the Microsoft Entra admin center: platform **Web**, redirect URI `http://localhost:8000/microsoft/callback`, a client secret, and the delegated permission `User.Read`.
2. `composer require dakiquang/laravel-oauth-refresh-lock league/oauth2-client`, publish and run the migration, copy `app/`, `config/`, `routes/`, add `require __DIR__.'/microsoft.php';` to `routes/web.php`, and the variables from `.env.example` to your environment file.
3. `php artisan serve`, open `http://localhost:8000/microsoft/connect`, then `/microsoft/me`.
4. `php artisan microsoft:race <userObjectId> --workers=6`: expect one refresh.

See also: [provider notes](../../docs/providers.md#microsoft-identity-platform) · [how it works](../../docs/how-it-works.md) · [README: when the provider rejects the access token](../../README.md#when-the-provider-rejects-the-access-token)
