# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Package skeleton: service provider, CI matrix for PHP 8.2–8.5 and Laravel 12–13.
- `LeagueRefresher`: refresh through any league/oauth2-client provider.
- Default classifier: reads the HTTP status from a PSR-7 response attached to `IdentityProviderException`, treats `temporarily_unavailable` and `server_error` as temporary, and ignores exception codes that are not HTTP statuses.
- Events `TokenRefreshed`, `TokenRefreshFailed` and `TokenRevokedByProvider`, dispatched through the application's event dispatcher after the lock is released. They carry the key, version, expiry or classifier code, never token values.
- Usage documentation in the README, `docs/how-it-works.md`, `docs/providers.md` (with links to each provider's documentation) and a QuickBooks Online example in `examples/quickbooks`, verified against a QuickBooks Online sandbox company.
- `rejected($accessToken)`: refresh after the provider rejected an access token (HTTP 401), whatever its expiry, under the lock; a token another worker already replaced is returned without a refresh. Needed for providers that return no `expires_in`, such as Salesforce.
- CI: gitleaks also scans the whole history on a manual run and weekly.
- Best-effort support for Laravel 10 and 11 (PHP 8.1–8.4). Both are end of life; see the README.
- CI on PostgreSQL for the database token store.
- Waiting for the refresh lock survives a deadlock of Laravel's database lock on MySQL (seen on Laravel 10–12 under contention): the worker keeps waiting instead of throwing. Errors after the refresh has started are never retried.
