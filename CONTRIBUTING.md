# Contributing

Thanks for helping. Issues and pull requests are welcome.

## Before you open a pull request

1. Open an issue first for anything larger than a small fix, so we can agree on the approach.
2. Add or update tests for the change.
3. Run the checks locally:

```bash
composer install
composer test          # PHPUnit
composer analyse       # PHPStan
composer format:check  # Laravel Pint
```

4. Add a line under `[Unreleased]` in `CHANGELOG.md`.

## Guidelines

- Keep the public API small. New options need a clear use case.
- Never put real tokens, client IDs or secrets in tests, fixtures or examples.
- Support every PHP and Laravel version in the CI matrix, or explain why not.
