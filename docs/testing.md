# Testing

Package tests use Orchestra Testbench and Pest. The committed test entry points are:

- `phpunit.xml.dist`
- `tests/TestCase.php`
- `tests/Pest.php`

## Standalone Package Tests

Run tests from the package directory after package-local development dependencies are installed:

```bash
cd packages/laravel-talkto
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
6. Run or fake the source command `SendTalktoMessage` job.
7. Run or fake the destination `ProcessIncomingTalktoMessage` job.
8. Confirm the destination auto-creates an outgoing durable callback message.
9. Run or fake the destination callback `SendTalktoMessage` job.
10. Confirm the source receives the signed result callback.
11. Inspect queues and failed jobs before repeating the flow.

Do not assume `ResultCallbackSenderContract::sendResult()` sends callback HTTP immediately. It queues durable callback delivery; local E2E tests should run the queued callback `SendTalktoMessage` job when they want the source-side status to update in the same test.

The package provides the transport and lifecycle records. The host application verifies its own command handler behavior.
