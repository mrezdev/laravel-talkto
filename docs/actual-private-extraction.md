# Actual Private Extraction

Use this document when turning the local package copy into the root of a future private repository.

## Source Paths

The package currently lives inside the host applications at:

- Primary host package copy: `packages/laravel-talkto`
- Secondary host package copy: `packages/laravel-talkto`

Before extracting, confirm both package copies are synchronized. Treat the primary package copy as canonical only after that sync check passes.

## Repository Root Rule

Copy the contents of `packages/laravel-talkto` into the new repository root. Do not copy the parent `packages/` directory and do not nest the package under `packages/laravel-talkto` in the new repository.

The new repository root should contain files such as `composer.json`, `README.md`, `src/`, `config/`, `docs/`, and `tests/` directly at the top level.

## Copy

- `composer.json`
- `README.md`
- `CHANGELOG.md`
- `LICENSE.md`
- `SECURITY.md`
- `SUPPORT.md`
- `src/`
- `config/`
- `database/` when package migrations are present
- `routes/` when package routes are present
- `docs/`
- `tests/`
- `stubs/`
- `.github/`
- `.gitignore`
- `.gitattributes`
- `phpunit.xml.dist`

## Do Not Copy

- Host application files
- `vendor/`
- `composer.lock`
- `.phpunit.cache/`
- `.pest/`
- Coverage output
- Build or distribution output
- `storage/`
- Local environment files
- Local machine paths
- Review export folders

## Verification Commands

Run these commands from the extracted package repository root:

```bash
composer validate --strict
composer install --prefer-dist --no-interaction --no-progress
vendor/bin/pest
```

## Leakage Scan

Scan extracted source, docs, tests, stubs, and metadata for host-only domain language before the first commit. The package should describe service-to-service messaging, signing, receiving, callbacks, retries, recovery, and monitoring without embedding a host workflow.

## Secret Scan

Scan for committed credentials, private access values, local machine secrets, private repository credentials, and production endpoints. Documentation should use placeholders only.

## License Warning

The package license is MIT. Do not change the license during extraction unless the owner explicitly approves a different license.
