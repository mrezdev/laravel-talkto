# Continuous Integration

The package includes `.github/workflows/tests.yml` for private repository pull requests and pushes to `main` or `master`.

## What CI Runs

- Checks out the package repository.
- Sets up supported PHP versions.
- Runs `composer validate --strict`.
- Runs `composer install --prefer-dist --no-interaction --no-progress`.
- Runs the package test suite with `vendor/bin/pest`.

These commands should match the local package workflow:

```bash
composer validate --strict
composer install --prefer-dist --no-interaction --no-progress
vendor/bin/pest
```

## What CI Does Not Do

- It does not deploy code.
- It does not publish packages.
- It does not enable production traffic.
- It does not run host business tests.
- It does not require host application paths.
- It does not use secrets or private credentials.

## Host Compatibility

Host applications should keep separate compatibility checks that install or load the package in the host app and run host-owned tests. Those checks should remain outside this generic package workflow unless a later phase deliberately adds a separate integration pipeline.
