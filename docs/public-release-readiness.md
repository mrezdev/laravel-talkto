# Public Release Readiness

The package is not ready for public release by default. It should remain private until the owner explicitly approves the release model.

## Public Release Blockers

- License decision.
- Security disclosure contact.
- Maintainer identity.
- Public support policy.
- Packagist decision.
- CI pass on supported PHP versions.
- Complete installation, upgrade, security, and release docs.
- Stable public API review.
- Host business leakage review.
- Secret and credential review.

## Private Checklist

- Repository is private.
- License remains proprietary.
- Git tag versioning is used.
- `composer.json` has no static version field.
- Package tests pass.
- Host compatibility is checked separately.

## Public Checklist

- Owner approves public visibility.
- Owner approves license.
- Maintainers publish security and support contacts.
- Public package name is final.
- Packagist or another public distribution decision is documented.
- Documentation is reviewed for private details.
