# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-07

First release.

### Added

- `OAuthRefreshLock::for($key)->accessToken($refresher)`: no lock while the access token is fresh; one cache lock per token key when it is about to expire; the token is read again inside the lock; compare-and-swap writes.
- `rejected($accessToken)`: refresh after an API call answered 401, whatever the expiry, for providers that return no `expires_in` (Salesforce); workers reporting the same rejected token cause one refresh.
- `withLock()` for connect and disconnect under the same lock.
- `EloquentTokenStore`: tokens in one table, encrypted with the application key, single-statement writes, store-controlled versions so an older read never overwrites a newer connection. `ArrayTokenStore` for tests.
- `LeagueRefresher` for any league/oauth2-client provider; a closure works for any other SDK.
- `DefaultErrorClassifier`: only `invalid_grant` revokes; recognised network errors, HTTP 408/429/5xx, `temporarily_unavailable`, `server_error` and Salesforce's `concurrent_request` keep the token and throw `TransientRefreshFailure`.
- Recovery when a refresh outlives its lock lease, when the provider still accepts the newest token.
- Waiting for the lock survives deadlocks of Laravel's database lock on MySQL.
- Events `TokenRefreshed`, `TokenRefreshFailed` and `TokenRevokedByProvider`, dispatched after the lock is released; no token values in events or exception messages.
- Laravel 12 and 13 (PHP 8.2–8.5); best-effort Laravel 10.48.29+ and 11.44.1+ (PHP 8.1–8.4), both end of life.
- Examples for QuickBooks Online, Xero, Salesforce, Microsoft Graph and Google; QuickBooks, Xero and Salesforce verified against real accounts.
- Documentation: README, `docs/how-it-works.md`, `docs/providers.md`, `docs/support-policy.md`.

[Unreleased]: https://github.com/dakiquang/laravel-oauth-refresh-lock/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/dakiquang/laravel-oauth-refresh-lock/releases/tag/v0.1.0
