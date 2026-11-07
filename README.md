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

- PHP 8.2 or later
- Laravel 12 or 13
- A cache store that supports atomic locks (Redis, Memcached, DynamoDB or database for multi-server setups)

## Status

See [CHANGELOG.md](CHANGELOG.md). Usage documentation will be added before the first release.

## About

A personal open source project. It is not affiliated with, or supported by, any company or OAuth provider.

## License

MIT. See [LICENSE](LICENSE).
