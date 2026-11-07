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

## Status

See [CHANGELOG.md](CHANGELOG.md). Usage documentation will be added before the first release.

## About

A personal open source project. It is not affiliated with, or supported by, any company or OAuth provider.

## License

MIT. See [LICENSE](LICENSE).
