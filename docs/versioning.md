# Versioning Policy

Laravel Talkto follows semantic versioning once private tags begin.

## Patch Releases

Patch releases fix defects, clarify docs, or improve internals without changing public contracts, config names, database expectations, route behavior, or handler interfaces.

## Minor Releases

Minor releases add backward-compatible capabilities, optional config, new contracts with defaults, new docs, or additional test coverage.

## Major Releases

Major releases may change public contracts, configuration shape, migration expectations, route behavior, event shape, or callback behavior. Major releases require a migration note and host compatibility review.

## Backward Compatibility

Public contracts, service names, config keys, migration toggles, route names, message status semantics, and callback interfaces should remain stable inside a major version.

## Deprecation Policy

When possible, introduce a replacement first, document the deprecation in `CHANGELOG.md`, keep the old behavior working through at least one minor release, and remove it only in a later major release.
