# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Package skeleton: service provider, CI matrix for PHP 8.2–8.5 and Laravel 12–13.
- `LeagueRefresher`: refresh through any league/oauth2-client provider. A token endpoint answer that is not an OAuth response (a gateway's 502/503 page) becomes `UnreadableTokenResponse`, classified as temporary, so the token is kept.
- Best-effort support for Laravel 10 and 11 (PHP 8.1–8.4). Both are end of life; see the README.
- CI on PostgreSQL for the database token store.
- Waiting for the refresh lock survives a deadlock of Laravel's database lock on MySQL (seen on Laravel 10–12 under contention): the worker keeps waiting instead of throwing. Errors after the refresh has started are never retried.
