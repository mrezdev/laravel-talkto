# Testing

Package tests use Orchestra Testbench and Pest. The committed test entry points are:

- `phpunit.xml.dist`
- `tests/TestCase.php`
- `tests/Pest.php`

## Standalone Package Tests

Run tests from the package directory after package-local development dependencies are installed:

```bash
cd packages/talkto-reliable
composer install
vendor/bin/pest
```

PHPUnit can also use the committed default config:

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

If `vendor/bin/pest` and `vendor/bin/phpunit` are missing, the package-local dependencies have not been installed. Host phases should record that state instead of running Composer install/update unless the phase explicitly allows it.

## Package Coverage

- provider boot and config merge
- install experience and safe defaults
- routes and migrations disabled by default
- model and service resolution
- configured model subclasses
- signing and verification
- deterministic payload hashing
- public contracts and exceptions
- source leakage checks for host business terms

## Host Compatibility Coverage

Host applications should add focused tests for their wrappers, handlers, callback senders, callback receivers, recovery commands, monitoring endpoints, and local HTTP end-to-end flows.

Use testing databases and local-only queues. Do not test against production traffic.

## Local End-To-End Strategy

1. Start two local Laravel services with testing configuration.
2. Use local URLs and non-production shared secrets.
3. Configure one outgoing peer and one incoming source.
4. Send a small generic command with an idempotency key.
5. Confirm the destination records the incoming message, attempts, and events.
6. Confirm the source receives a signed result callback.
7. Inspect queues and failed jobs before repeating the flow.

The package provides the transport and lifecycle records. The host application verifies its own command handler behavior.
