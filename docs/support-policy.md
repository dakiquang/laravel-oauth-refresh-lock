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
