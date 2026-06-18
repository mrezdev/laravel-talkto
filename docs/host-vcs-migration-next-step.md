# Host VCS Migration Next Step

P.49A2 creates the package extraction seed only. It does not change host Composer files and does not move either host application away from the local path repository.

## Current State

The host applications still reference the package from a local path:

```json
{
  "type": "path",
  "url": "packages/laravel-talkto"
}
```

## Future State

In a later phase, after the owner creates the private repository and a private tag exists, hosts can move to a private VCS repository reference:

```json
{
  "type": "vcs",
  "url": "<private-repository-url>"
}
```

Hosts should require an exact version tag such as `v0.1.0`, not an open-ended branch constraint.

## P.49A3 Migration Outline

1. Confirm the private repository exists and CI passes.
2. Confirm the first private tag exists.
3. Update one host at a time in a non-production branch.
4. Change the host repository entry from local path to private VCS.
5. Require the exact package tag.
6. Run `composer update mrezdev/laravel-talkto` only.
7. Run package tests and host compatibility tests.
8. Repeat for the second host.

## Rollback

If the private VCS install fails or host compatibility checks fail, restore the local path repository entry, run `composer update mrezdev/laravel-talkto` only, and rerun host compatibility tests.
