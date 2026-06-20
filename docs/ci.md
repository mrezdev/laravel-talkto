# Continuous Integration

The package includes `.github/workflows/tests.yml` for package repository pull requests and pushes to `main` or `master`.

## What CI Runs

- Checks out the package repository.
- Sets up supported PHP and Laravel/Testbench matrix entries.
- Removes any local `composer.lock` before dependency resolution.
- Runs `composer validate --strict`.
- Applies explicit matrix constraints for every `illuminate/*` component required by the package.
- Applies the matching `orchestra/testbench` constraint.
- Runs `composer update --prefer-dist --no-interaction --no-progress --with-all-dependencies`.
- Runs `composer audit`.
- Runs `vendor/bin/pint --test`.
- Runs `vendor/bin/phpstan analyse`.
- Runs the package test suite with `vendor/bin/pest`.

The Linux package test matrix is:

- PHP 8.2 with Laravel 12 components and Orchestra Testbench 10.
- PHP 8.3 with Laravel 12 components and Orchestra Testbench 10.
- PHP 8.4 with Laravel 12 components and Orchestra Testbench 10.
- PHP 8.3 with Laravel 13 components and Orchestra Testbench 11.
- PHP 8.4 with Laravel 13 components and Orchestra Testbench 11.

CI also includes a focused `windows-pint` job on `windows-latest` to catch CRLF and Laravel Pint formatting issues on Windows.

This library package does not commit `composer.lock`. CI resolves dependencies per PHP and Laravel-compatible matrix entry so each supported dependency set is checked independently.

These commands should match the local package workflow:

```bash
rm -f composer.lock
composer validate --strict
composer update --prefer-dist --no-interaction --no-progress --with-all-dependencies
composer audit
vendor/bin/pint --test
vendor/bin/phpstan analyse
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
