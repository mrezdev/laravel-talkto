# First Private Release Tag

This plan describes the first private tag after the package has been committed to the owner-approved private repository. P.49A2 does not create the tag.

## Pre-Tag Checks

Run these from the private package repository root:

```bash
composer validate --no-check-publish
composer install --prefer-dist --no-interaction --no-progress
vendor/bin/pest
```

Also confirm:

- `CHANGELOG.md` describes the private package seed.
- The repository is private.
- CI passes on the default branch.
- Host-only domain rules are not embedded in package source or docs.
- No generated files or dependency folders are committed.
- The license remains proprietary/private until the owner approves a different license.

## First Tag

Create the first private tag only after validation and CI pass:

```bash
git tag -a v0.1.0 -m "Private package seed v0.1.0"
git push origin v0.1.0
```

Hosts should require an exact private tag in a later migration phase.
