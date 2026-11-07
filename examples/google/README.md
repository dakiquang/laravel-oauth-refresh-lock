# Google OAuth refresh token in Laravel: `access_type=offline`, `invalid_grant` and concurrent refreshes

A Laravel example for Google OAuth 2.0 (web server apps): sign in, call the OpenID Connect userinfo endpoint, and a command that shows several workers refreshing one token with exactly one refresh.

> **Written from Google's documentation and checked to boot in a Laravel 12 app; not yet run against a real Google account.** If something differs, please open an issue.

## What Google does with refresh tokens

- Google does **not rotate** refresh tokens: the refresh response has no `refresh_token`, and you keep using the one you have ([Google: web server apps](https://developers.google.com/identity/protocols/oauth2/web-server)). `LeagueRefresher` then keeps the stored refresh token. There is no rotation race; the lock still saves duplicate calls.
- A refresh token is only returned with `access_type=offline`. Google returns it on the first consent; `prompt=consent` asks again so a returning user gets one too.
- A refresh token stops working after 6 months unused, when the user revokes access, or when an account has more than 100 refresh tokens for the same client (the oldest is invalidated). Apps in "Testing" with external users get refresh tokens that expire after 7 days ([Google: OAuth 2.0](https://developers.google.com/identity/protocols/oauth2)).
- An expired or invalidated refresh token returns `invalid_grant` ("the token may have expired or has been invalidated", in Google's words). The `error_description` that people search for, `Token has been expired or revoked.`, is quoted in many write-ups but not in Google's documentation. The default classifier marks the token revoked on `invalid_grant`.

## The Google-specific parts

```php
new GenericProvider([
    'urlAuthorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'urlAccessToken' => 'https://oauth2.googleapis.com/token',
    'scopes' => ['openid', 'email', 'profile'],
    'scopeSeparator' => ' ',
    // ...
]);

$url = $provider->getAuthorizationUrl(['access_type' => 'offline', 'prompt' => 'consent']);
```

- The account's stable ID (`sub`) comes from `https://openidconnect.googleapis.com/v1/userinfo` with the new token; one stored token per account, under `google:{sub}`.

## Set up and try it

1. In the Google Cloud console, create an OAuth client of type **Web application** with the authorised redirect URI `http://localhost:8000/google/callback`.
2. `composer require dakiquang/laravel-oauth-refresh-lock league/oauth2-client`, publish and run the migration, copy `app/`, `config/`, `routes/`, add `require __DIR__.'/google.php';` to `routes/web.php`, and the variables from `.env.example` to your environment file.
3. `php artisan serve`, open `http://localhost:8000/google/connect`, then `/google/userinfo`.
4. `php artisan google:race <sub> --workers=6`: expect one refresh.

See also: [providers.md#google](../../docs/providers.md#google)
