# Smoke Tests

Run these checks in a non-production host application after installing or upgrading Laravel Talkto.

1. Install or update the package.
2. Publish config and migrations if the host owns the package config/tables.
3. Review migrations, then run them safely in a test database.
4. Configure one outgoing target with a URL, endpoint, and test secret.
5. Configure one incoming source with a matching test secret and allowed command.
6. Register one incoming handler through config or `TalktoIncomingHandlerRegistryContract`.
7. Send one outgoing message and confirm a `talkto_messages` row is created.
8. Receive one incoming message and confirm the handler runs once.
9. Send the same `message_id` twice and confirm duplicate execution is prevented.
10. Force one temporary outgoing failure and confirm `failed_retryable` plus `next_retry_at`.
11. Force max attempts or a final failure and confirm a dead letter when DLQ is enabled.
12. Run `php artisan talkto:dlq-reprocess --dry-run`.
13. Run `php artisan talkto:retry-failed --dry-run`.
14. Run `php artisan talkto:report --json`.
15. Confirm v2 signing works by default and v1 works only when explicitly accepted.
16. Test v2 only after both peers are configured to send or accept v2.

Use throwaway services, queues, test databases, and non-production secrets. Do not paste real secrets, signatures, or payloads into reports or tickets.
