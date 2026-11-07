# Laravel OAuth Refresh Lock

[![ci](https://github.com/dakiquang/laravel-oauth-refresh-lock/actions/workflows/ci.yml/badge.svg)](https://github.com/dakiquang/laravel-oauth-refresh-lock/actions/workflows/ci.yml)

> **Work in progress.** Not released yet. The API below may change before v0.1.0.

Some OAuth2 providers rotate refresh tokens: every refresh returns a new refresh token and retires the old one. When two queue workers or requests notice an expired access token at the same time, both try to refresh. One wins; the other sends a refresh token that was just retired, gets `invalid_grant`, and the app often treats that as "the user disconnected". Someone then has to reconnect by hand.

This package makes refresh safe under concurrency:

- No lock while the access token is still fresh.
- One lock per token when it is about to expire.
- The token is read again inside the lock, so a worker that waited uses the token the winner just stored.
- Only a definite `invalid_grant` marks the token revoked. Timeouts, network errors and 5xx responses keep the token so the next attempt can succeed.

## Requirements

- A cache store that supports atomic locks (Redis, Memcached, DynamoDB or database for multi-server setups)

| Laravel | PHP | Support |
|---|---|---|
| 13 | 8.3–8.5 | Full |
| 12 | 8.2–8.5 | Full |
| 11 (11.44.1 or later) | 8.2–8.4 | Best-effort, see below |
| 10 (10.48.29 or later) | 8.1–8.3 | Best-effort, see below |

Tested on SQLite, MySQL and PostgreSQL.

### Laravel 10 and 11

Laravel 10 and 11 no longer get security fixes. Every release of both has open security advisories, including a critical one: [CVE-2026-114509](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6), an authentication bypass in `TokenGuard`. This package does not use the affected features, but your application runs on that framework. Supporting it here does not make it safe. Plan the upgrade to Laravel 12 or 13.

Support for 10 and 11 is best-effort: they are tested in CI and bugs are fixed, but support may be dropped in a later 0.x release, announced in the changelog one release ahead. See [docs/support-policy.md](docs/support-policy.md).

About installing: Composer 2.9 and later refuse to install package versions with security advisories by default. What we observed with Composer 2.10.1, in a Laravel 10.50.4 app and a Laravel 11.57.1 app whose `composer.lock` already existed and with that blocking on: `composer require` for this package installed it (with a warning listing the framework's advisories), while `composer update laravel/framework` was refused. Your result can differ with another Composer version or without a lock file. This package does not change or ship any advisory configuration.

## Installation

```bash
composer require dakiquang/laravel-oauth-refresh-lock
php artisan vendor:publish --tag=oauth-refresh-lock-migrations
php artisan migrate
```

Optionally publish the config (`--tag=oauth-refresh-lock-config`). The defaults: refresh when the access token expires within 120 seconds, hold the lock for at most 30 seconds, wait at most 10 seconds for another worker's refresh. Set `OAUTH_REFRESH_LOCK_STORE` to a shared cache store (Redis, Memcached, DynamoDB, database) when more than one server runs workers.

## Usage

Each token has a key you choose, for example `quickbooks:{realmId}` or `xero:{tenantId}`. The key names the stored token and its lock, so use it for nothing else.

### Store the token when the user connects

Write it under the same lock, so a refresh that is running for an older connection cannot overwrite it:

```php
use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Facades\OAuthRefreshLock;
use Dakiquang\OAuthRefreshLock\StoredToken;

$token = $provider->getAccessToken('authorization_code', ['code' => $request->query('code')]);

OAuthRefreshLock::for("quickbooks:{$realmId}")->withLock(function (TokenStore $store) use ($token, $realmId) {
    $store->put("quickbooks:{$realmId}", new StoredToken(
        accessToken: $token->getToken(),
        refreshToken: $token->getRefreshToken(),
        expiresAt: $token->getExpires() ? CarbonImmutable::createFromTimestamp($token->getExpires()) : null,
    ));
});
```

### Get a valid access token

```php
use Dakiquang\OAuthRefreshLock\Facades\OAuthRefreshLock;
use Dakiquang\OAuthRefreshLock\Refreshers\LeagueRefresher;

$accessToken = OAuthRefreshLock::for("quickbooks:{$realmId}")
    ->accessToken(new LeagueRefresher($provider));
```

`LeagueRefresher` works with any [league/oauth2-client](https://oauth2-client.thephpleague.com/) provider. For another SDK, pass a closure that receives the current `StoredToken` and returns a `RefreshedToken`:

```php
use Dakiquang\OAuthRefreshLock\RefreshedToken;
use Dakiquang\OAuthRefreshLock\StoredToken;

$accessToken = OAuthRefreshLock::for($key)->accessToken(function (StoredToken $current): RefreshedToken {
    $response = $sdk->refresh($current->refreshToken);   // throw what the SDK throws

    return RefreshedToken::fromExpiresIn($response->accessToken, $response->refreshToken, $response->expiresIn);
});
```

`token()` returns the whole `StoredToken` instead of the access token. `skew($seconds)` and `wait($seconds)` override the config for one call.

### Disconnect

```php
OAuthRefreshLock::for($key)->withLock(fn (TokenStore $store) => $store->forget($key));
```

### Errors

| Exception | Meaning | Token |
|---|---|---|
| `TokenNotFound` | Nothing stored for the key | — |
| `TokenRevoked` | The provider rejected the refresh token (`invalid_grant`), now or earlier. The user has to connect again | Kept, marked revoked |
| `TransientRefreshFailure` | Network error, timeout, HTTP 408/429/5xx, or `temporarily_unavailable` / `server_error`. Try again later | Kept |
| `RefreshLockTimeout` | Another worker held the lock longer than the wait time | Kept |
| anything else | What the SDK threw for an error the classifier does not recognise (for example `invalid_client`) | Kept |

This package's exception messages contain the key and a short error code, never token values or provider responses; the provider's error is available as `getPrevious()`. To classify errors differently, bind your own `Dakiquang\OAuthRefreshLock\Contracts\ErrorClassifier`.

### Events

`TokenRefreshed`, `TokenRefreshFailed` and `TokenRevokedByProvider` (in `Dakiquang\OAuthRefreshLock\Events`) are dispatched after the lock is released. They carry the key, version, expiry or error code, never token values. Check the stored token before acting on `TokenRevokedByProvider`: see [How it works](docs/how-it-works.md#a-refresh-that-outlives-the-lock).

### Your own storage

`EloquentTokenStore` keeps tokens in one table, encrypted with the application key. To keep them elsewhere, implement `Dakiquang\OAuthRefreshLock\Contracts\TokenStore` and bind it. `put()` with an expected version must be a compare-and-swap; the contract's docblock has the rules.

## How it works

See [docs/how-it-works.md](docs/how-it-works.md): the lock, the re-read, compare-and-swap writes, what happens when a refresh outlives its lock, and how errors are classified.

## Providers

Rotation rules differ a lot between providers: for some, a duplicate refresh can lock the user out; for others it is harmless. See [docs/providers.md](docs/providers.md), with links to each provider's documentation.

## Limits

- The lock serialises refreshes within one application that shares one cache store. Two different applications using the same OAuth connection are not coordinated.
- The lock is a lease. Keep the refresh call's HTTP timeout well below `lock_seconds`; if a refresh outlives the lease, the package detects it and restores the valid token, but another worker may already have seen `TokenRevoked`.
- `file` and `array` cache locks only work within one server.

## Status

See [CHANGELOG.md](CHANGELOG.md).

## About

A personal open source project. It is not affiliated with, or supported by, any company or OAuth provider.

## License

MIT. See [LICENSE](LICENSE).
