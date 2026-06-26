# Laravel Talkto Documentation

This is the full public documentation map for Laravel Talkto. The root [README](../README.md) is the package home page and quick entry point; these pages go deeper by task and area.

## Start Here

- [Installation](installation.md) - install from Packagist, publish real package assets, run migrations, and perform first checks.
- [Configuration](configuration.md) - configure service names, routes, storage, peers, security, queues, callbacks, retry, DLQ, panel, and retention.
- [Security](security.md) - understand v2 signatures, nonce replay protection, safe defaults, and dangerous manual settings.
- [Production hardening](production-hardening.md) - checklist for a real Laravel host app before exposing Talkto traffic.
- [Troubleshooting](troubleshooting.md) - symptoms, likely causes, and safe fixes.

## Core Concepts

- [Architecture](architecture.md) - outgoing, incoming, callback, retry, and DLQ flows.
- [Sending commands](sending-commands.md) - source-side command creation.
- [Handling commands](handling-commands.md) - receiver-side handlers and results.
- [Result callbacks](result-callbacks.md) - signed callback runtime.
- [Extending Laravel Talkto](extending.md) - supported extension points.
- [Public API](PUBLIC_API.md) - supported public surface, extension points, and the internal boundary between stable host-facing contracts and package internals.

## Examples

- [Outgoing-only example](examples/outgoing-only.md)
- [Incoming-only example](examples/incoming-only.md)
- [Bidirectional callback example](examples/bidirectional-callback.md)
- [Command contract template](command-contract-template.md)
- [Callback contract template](callback-contract-template.md)
- [Host integration template](host-integration-template.md)

## Operations

- [Recovery and monitoring](recovery-monitoring-template.md)
- [Talkto Panel](panel.md)
- [Testing](testing.md)
- [Smoke tests](smoke-tests.md)
- [Production rollout template](production-rollout-template.md)
- [Release readiness](release-readiness.md)
- [Release process](release-process.md)
- [CI](ci.md)
- [Versioning](versioning.md)

## Package Development

- [Scaffolding generators](scaffolding.md)
- [Transactional outgoing](transactional-outgoing.md)
- [HTTP client extension](http-client.md)
- [Local HTTP end-to-end template](local-http-e2e-template.md)
- [Installing into existing apps](installing-into-existing-apps.md)
- [New service onboarding](new-service-onboarding.md)

## Upgrade And Support

- [Package upgrading notes](upgrading.md)
- [Root upgrade guide](../UPGRADE.md)
- [Root changelog](../CHANGELOG.md)
- [Security policy](../SECURITY.md)
- [Support policy](../SUPPORT.md)

## Maintainer Notes

Internal maintainer notes are kept in the repository only and are not part of the published package archive.
