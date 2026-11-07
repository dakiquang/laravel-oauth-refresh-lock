# Support policy

## Full support

Laravel versions that still receive security fixes from the Laravel team, on the PHP versions Laravel supports for them. Every one of them runs in CI, and a failure blocks a release.

## Best-effort support

Laravel versions that no longer receive security fixes, today 10 and 11. They also run in CI and bugs on them are fixed, but:

- CI installs them only by ignoring a fixed list of known advisories (`.github/legacy-advisories.json`). A new advisory on those versions fails the build on purpose, and is a moment to decide whether to keep them.
- A version is dropped when its test tooling (Orchestra Testbench, PHPUnit) no longer installs on a supported PHP, when keeping it would mean weakening the code for newer versions, or at the latest before v1.0.0.
- Dropping a version is announced in the changelog at least one release before it happens, and happens in a minor 0.x release (or a major release after v1.0.0), never in a patch.

## Not supported

Laravel 9 and older, PHP 8.0 and older.

## Installing on Laravel 10 and 11

Composer 2.9 and later refuse to install package versions with security advisories by default. What we observed with Composer 2.10.1, in a Laravel 10.50.4 app and a Laravel 11.57.1 app whose `composer.lock` already existed and with that blocking on: `composer require` for this package installed it (with a warning listing the framework's advisories), while `composer update laravel/framework` was refused. Your result can differ with another Composer version or without a lock file. This package does not change or ship any advisory configuration.
