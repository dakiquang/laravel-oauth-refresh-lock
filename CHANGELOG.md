# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Package skeleton: service provider, CI matrix for PHP 8.2–8.5 and Laravel 12–13.
- Events `TokenRefreshed`, `TokenRefreshFailed` and `TokenRevokedByProvider`, dispatched through the application's event dispatcher after the lock is released. They carry the key, version, expiry or classifier code, never token values.
- Best-effort support for Laravel 10 and 11 (PHP 8.1–8.4). Both are end of life; see the README.
- CI on PostgreSQL for the database token store.
- Waiting for the refresh lock survives a deadlock of Laravel's database lock on MySQL (seen on Laravel 10–12 under contention): the worker keeps waiting instead of throwing. Errors after the refresh has started are never retried.
