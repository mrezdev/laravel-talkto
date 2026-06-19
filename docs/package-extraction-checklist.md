# Package Extraction Checklist

Use this checklist when moving `packages/laravel-talkto` into its own private repository.

## Copy

- `composer.json`
- `README.md`
- `CHANGELOG.md`
- `LICENSE.md`
- `CONTRIBUTING.md`
- `SECURITY.md`
- `SUPPORT.md`
- `.gitignore`
- `.gitattributes`
- `.github/`
- `config/`
- `database/`
- `docs/`
- `routes/`
- `src/`
- `stubs/`
- `tests/`
- `phpunit.xml.dist`

## Do Not Copy

- Host application files.
- Host routes, config, database files, app code, resources, storage, or review exports.
- `.env` or local environment files.
- `vendor/`
- `composer.lock`
- `.phpunit.cache/`
- `.pest/`
- Coverage, build, or distribution output.

## Verify

- Sync the primary and secondary package copies before extraction.
- Run `composer validate --strict`.
- Install package dependencies in the extracted repository.
- Run package tests.
- Validate no host business leakage exists in package source, docs, metadata, stubs, or tests.
- Confirm repository metadata contains no secrets.
- Set the private remote only after the owner approves the destination.
- Create the first private tag only after CI passes.
