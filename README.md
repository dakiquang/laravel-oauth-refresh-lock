# Laravel OAuth Refresh Lock

[![ci](https://github.com/dakiquang/laravel-oauth-refresh-lock/actions/workflows/ci.yml/badge.svg)](https://github.com/dakiquang/laravel-oauth-refresh-lock/actions/workflows/ci.yml)

> **Early release (0.x).** The API may still change before 1.0; changes are listed in the [changelog](CHANGELOG.md).

Prevents the OAuth2 refresh token race condition in Laravel: when several queue workers refresh the same token at once, providers that retire the old refresh token at once answer the losers with `invalid_grant`, and some revoke the connection. One refresh per expiry; the other workers wait and use the token it stored.

Works with any [league/oauth2-client](https://oauth2-client.thephpleague.com/) provider or any SDK, on Laravel 10, 11, 12 and 13, with queue workers, Laravel Horizon, scheduled commands and web requests. Examples for [QuickBooks Online](examples/quickbooks), [Xero](examples/xero), [Salesforce](examples/salesforce), [Microsoft Graph](examples/microsoft) and [Google](examples/google).

## The problem: `invalid_grant` when two workers refresh at once

Providers that rotate refresh tokens issue a new refresh token on every refresh and retire the old one. Two workers notice the same expired access token and both send the same refresh token. The first wins. The second sends a refresh token that was just retired and gets:

```json
{"error": "invalid_grant", "error_description": "..."}
```

What the description says depends on the provider, for example Salesforce's `expired access/refresh token`, Microsoft's `AADSTS70008` or Google's "the token may have expired or has been invalidated".

Apps often treat that as "the user disconnected", so someone has to reconnect by hand. With QuickBooks Online and Salesforce the duplicate refresh itself can revoke the tokens: Salesforce runs refresh token reuse detection and revokes the current tokens when a retired one is sent again. Xero hides the race with a 30-minute grace period. See [docs/providers.md](docs/providers.md).

This package makes the refresh safe under concurrency:

- No lock while the access token is still fresh.
- One atomic cache lock (`Cache::lock`) per token when it is about to expire, or when an API call answered 401.
- The token is read again inside the lock, so a worker that waited uses the token the winner just stored.
- The write is a compare-and-swap on a version, so a reconnect in the meantime is never overwritten.
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

Laravel 10 and 11 no longer get security fixes. Every release of both has open security advisories, including a critical one (as of 7 November 2026): [CVE-2026-114509](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6), an authentication bypass in `TokenGuard`. This package does not use the affected features, but your application runs on that framework. Supporting it here does not make it safe. Plan the upgrade to Laravel 12 or 13.

Support for 10 and 11 is best-effort: they are tested in CI and bugs are fixed, but support may be dropped in a later 0.x release, announced in the changelog one release ahead. See [docs/support-policy.md](docs/support-policy.md).

Installing into an existing Laravel 10 or 11 app with Composer 2.9+ (which blocks packages with security advisories by default): see [docs/support-policy.md](docs/support-policy.md#installing-on-laravel-10-and-11).

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

$accessToken = OAuthRefreshLock::for($key)->accessToken(function (StoredToken $current) use ($sdk): RefreshedToken {
    $response = $sdk->refresh($current->refreshToken);   // throw what the SDK throws

    return RefreshedToken::fromExpiresIn($response->accessToken, $response->refreshToken, $response->expiresIn);
});
```

`token()` returns the whole `StoredToken` instead of the access token. `skew($seconds)` and `wait($seconds)` override the config for one call.

### When the provider rejects the access token

Some providers do not say when access tokens expire (Salesforce, for example, returns no `expires_in`), and any provider can revoke an access token early. Then you learn it from a 401. Report the rejected token and retry once:

```php
$pending = OAuthRefreshLock::for($key);
$accessToken = $pending->accessToken($refresher);

$response = Http::withToken($accessToken)->get($url);
if ($response->status() === 401) {
    $accessToken = $pending->rejected($accessToken)->accessToken($refresher);
    $response = Http::withToken($accessToken)->get($url);
}
```

`rejected()` refreshes the token whatever its expiry, under the same lock. If another worker has already replaced that access token, the replacement is used like any stored token (returned as is unless it is about to expire), so many workers hitting the same 401 still cause one refresh. A completed refresh is recognised by the new access token value; a provider that returned the same value again would cause one refresh per caller that reported it.

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

Stack traces are another matter: with `zend.exception_ignore_args` off (the `php.ini-development` default), a trace of the SDK's exception can show the refresh token as a function argument. Keep it on in production, as `php.ini-production` does, and be careful with traces from local runs.

### Events

`TokenRefreshed`, `TokenRefreshFailed` and `TokenRevokedByProvider` (in `Dakiquang\OAuthRefreshLock\Events`) are dispatched after the lock is released. They carry the key, version, expiry or error code, never token values. Check the stored token before acting on `TokenRevokedByProvider`: see [How it works](docs/how-it-works.md#a-refresh-that-outlives-the-lock).

### Your own storage

`EloquentTokenStore` keeps tokens in one table, encrypted with the application key. To keep them elsewhere, implement `Dakiquang\OAuthRefreshLock\Contracts\TokenStore` and bind it. `put()` with an expected version must be a compare-and-swap; the contract's docblock has the rules.

## How it works

See [docs/how-it-works.md](docs/how-it-works.md): the lock, the re-read, compare-and-swap writes, what happens when a refresh outlives its lock, and how errors are classified.

## Providers and examples

Rotation rules differ a lot between providers: for some, a duplicate refresh can lock the user out; for others it is harmless. Each provider has a runnable Laravel example (connect, call the API, and a `race` command that starts several processes at once and checks there was exactly one refresh).

| Provider | Rotates refresh tokens | A duplicate refresh | Example | Tested against |
|---|---|---|---|---|
| QuickBooks Online (Intuit) | the value changes every 24 hours | `invalid_grant`; Intuit may revoke the tokens | [examples/quickbooks](examples/quickbooks) | real sandbox company |
| Salesforce | every refresh (Refresh Token Rotation) | reuse revokes the connection (observed after 90 seconds) | [examples/salesforce](examples/salesforce) | real Developer Edition org |
| Xero | every refresh | succeeds within the 30-minute grace period | [examples/xero](examples/xero) | real demo organisation |
| Microsoft identity platform (Graph) | every refresh, old one not revoked | an extra call | [examples/microsoft](examples/microsoft) | boot check only |
| Google | no | an extra call | [examples/google](examples/google) | boot check only |

Details, error strings (`invalid_grant`, `expired access/refresh token`, `INVALID_SESSION_ID`, `AADSTS70008`) and sources: [docs/providers.md](docs/providers.md).

## FAQ

**Why do I get `invalid_grant` randomly when refreshing an OAuth token?**
Usually two processes refreshed the same token at the same moment, and the provider rotated the refresh token. The one that lost sent a refresh token that was already retired. It shows up under load: queue workers, Horizon, scheduled commands overlapping with web requests. A real revocation (the user disconnected the app, an expired refresh token) gives the same error, which is why the package stores the token as revoked only on `invalid_grant` and reads it again under the lock first.

**Isn't Laravel's `WithoutOverlapping` job middleware enough?**
It serialises jobs that share a key, but it does not cover web requests, commands or other job classes that use the same token, it holds the key for the whole job rather than the refresh, and it does not re-read the token or protect the write. This package locks only around the refresh, wherever it happens.

**What does "refresh token already used" or "invalid refresh token" mean?**
That the refresh token you sent was already exchanged for a new one (rotation) or has been revoked. Providers word it differently, and most answer with the standard `invalid_grant`. If it happens when several processes run at once, it is the race this package prevents.

**Which cache store do I need?**
One that supports atomic locks and is shared by every server that runs workers: Redis, Memcached, DynamoDB or the database store. `file` locks only coordinate processes on one server (they share the disk); `array` locks live in one process's memory, so they cannot coordinate separate workers at all. A store without locks, such as `null`, is refused with `LockStoreNotSupported`.

**My provider does not return `expires_in` (Salesforce). How does it know when to refresh?**
Store the token without an expiry and report a 401 with `rejected($accessToken)`; see [When the provider rejects the access token](#when-the-provider-rejects-the-access-token).

**Does it work with Xero's 30-minute grace period?**
Yes. The grace period hides duplicate refreshes, but each one still issues another refresh token; the lock keeps it to one. See [examples/xero](examples/xero).

**Are tokens stored encrypted?**
`EloquentTokenStore` encrypts the access and refresh tokens with the application key. Events and the package's exception messages never contain token values.

## Testing

- CI runs PHP 8.1–8.5 with Laravel 10–13, PHPStan at max level, and the store tests on SQLite, MySQL and PostgreSQL.
- A concurrency test forks eight PHP processes that ask for the same expiring (or rejected) token at the same moment, three rounds per run, with the database lock and the Redis lock. A control run without the lock must show more than one refresh.
- The QuickBooks Online, Xero and Salesforce examples were run against real sandbox or developer accounts; see each example's README.

## Limits

- The lock serialises refreshes within one application that shares one cache store. Two different applications using the same OAuth connection are not coordinated.
- The lock is a lease. Keep the refresh call's HTTP timeout well below `lock_seconds`; if a refresh outlives the lease, another worker can refresh too. The compare-and-swap keeps the first result stored; if the other worker recorded a revocation of exactly the generation the slow worker rotated, the slow worker stores its tokens instead. Providers that punish reuse may already have revoked the connection, and the other worker's caller may have seen `TokenRevoked`. See [How it works](docs/how-it-works.md#a-refresh-that-outlives-the-lock).
- `file` cache locks only coordinate processes on one server; `array` locks only work inside one process (fine for tests, not for workers).

## Status

See [CHANGELOG.md](CHANGELOG.md).

## About

A personal open source project. It is not affiliated with, or supported by, any company or OAuth provider.

## License

MIT. See [LICENSE](LICENSE).
