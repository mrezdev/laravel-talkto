# Talkto Scaffolding Generators

Laravel Talkto includes Artisan generators that create host-app scaffolding for outgoing and incoming command flows. The generators give each integration a predictable starting shape while leaving business rules, payload mapping, validation, config review, and rollout decisions in the host application.

The commands are intentionally conservative:

- They generate files under a direction-first `app/Talkto` structure.
- They do not edit `config/talkto.php`.
- They do not enable routes, migrations, queues, or traffic.
- They do not call remote services while generating files.

## Overview

Use the generators when a host app is adding a Talkto integration with another service.

```bash
php artisan talkto:make-outgoing inventory verify-invoice
php artisan talkto:make-outgoing inventory verify-invoice --transactional

php artisan talkto:make-incoming inventory website.invoice-verified

php artisan talkto:make-integration inventory verify-invoice --outgoing
php artisan talkto:make-integration inventory verify-invoice --outgoing --transactional
php artisan talkto:make-integration inventory website.invoice-verified --incoming
```

For outgoing commands, pass the remote target service and a short command name such as `verify-invoice`. The generated command value becomes `inventory.verify-invoice`.

For incoming commands, pass the remote source service and the full command value such as `website.invoice-verified`. The final dotted segment is used for class and folder names.

## Directory Structure

Generated files are grouped by direction first, then service, then command:

```text
app/Talkto/
  Outgoing/
    {Service}/
      {Service}TalktoClient.php
      {Service}OutgoingCommand.php
      Commands/
        {Command}/
          Send{Command}To{Service}.php
          {Command}PayloadBuilder.php
          Prepare{Command}SourceAction.php

  Incoming/
    {Service}/
      {Service}IncomingCommand.php
      Commands/
        {Command}/
          {Command}Handler.php
          Handle{Command}From{Service}.php
          {Command}PayloadValidator.php
```

`Prepare{Command}SourceAction.php` is generated only for transactional outgoing scaffolds.

This layout is direction-first because `Outgoing/{Service}` shows which remote services the current app sends messages to, while `Incoming/{Service}` shows which remote services the current app receives messages from. Each command gets its own folder so a service with many commands stays readable. The service-level client and enum files stay centralized.

## Normal Outgoing Generator

Normal outgoing is for a source record or business object that already exists. The generated client method accepts that source object and delegates payload mapping to the generated payload builder.

```bash
php artisan talkto:make-outgoing inventory verify-invoice
```

Example host usage:

```php
app(\App\Talkto\Outgoing\Inventory\InventoryTalktoClient::class)
    ->verifyInvoice($record);
```

Generated files:

- `{Service}TalktoClient.php` exposes a small host-facing method.
- `{Service}OutgoingCommand.php` centralizes outgoing command values for the service.
- `Send{Command}To{Service}.php` builds and runs the Talkto outgoing flow.
- `{Command}PayloadBuilder.php` maps the host source object into a transport payload.

The host app should edit the payload builder to return the command payload the receiving service expects.

## Transactional Outgoing Generator

Transactional outgoing is for source-side work that must create or update a local business record and create the Talkto outbox message in the same local database transaction.

```bash
php artisan talkto:make-outgoing inventory verify-invoice --transactional
```

Example host usage:

```php
app(\App\Talkto\Outgoing\Inventory\InventoryTalktoClient::class)
    ->verifyInvoiceTransactionally($data);
```

The generated transactional method runs a local transaction for the source-side action and Talkto message creation. The remote service is not called inside the database transaction. Delivery happens after commit through the existing Talkto flow and queue behavior. If delivery fails later, Talkto retry and recovery tools handle the message lifecycle.

The generated `Prepare{Command}SourceAction.php` is where the host app creates or updates the local source record. Keep remote HTTP calls out of that action.

See [transactional-outgoing.md](transactional-outgoing.md) for the focused transactional guide.

## Incoming Generator

Incoming scaffolding is for commands this host receives from another service.

```bash
php artisan talkto:make-incoming inventory website.invoice-verified
```

Generated files:

- `InventoryIncomingCommand.php` centralizes incoming command values from the source service.
- `InvoiceVerifiedHandler.php` is the configured Talkto handler entry point.
- `HandleInvoiceVerifiedFromInventory.php` contains host-owned business handling.
- `InvoiceVerifiedPayloadValidator.php` validates and normalizes the incoming payload.

Incoming command names may be dotted full command values. The generator uses the last segment, such as `invoice-verified`, for class and command folder names.

## Integration Shortcut Generator

The integration shortcut delegates to the outgoing or incoming generator. It is useful when onboarding a service and you want one command name for both directions.

```bash
php artisan talkto:make-integration shipping create-label --outgoing
php artisan talkto:make-integration shipping create-label --outgoing --transactional
php artisan talkto:make-integration billing website.payment-captured --incoming
```

Choose exactly one direction with `--outgoing` or `--incoming`. `--transactional` is valid only with `--outgoing`.

## Dry Run And Force Behavior

Run dry-run first to inspect intended paths without writing files:

```bash
php artisan talkto:make-outgoing inventory verify-invoice --dry-run
php artisan talkto:make-incoming inventory website.invoice-verified --dry-run
```

Use `--force` only when you want command-specific generated files to be regenerated. Service-level files contain markers for generated insert points; if those markers are missing, the generator leaves the file untouched and prints a manual update note instead of overwriting host code.

## Config Snippets For Incoming

The incoming generator prints a config snippet for manual review. It does not edit `config/talkto.php`.

After generation, copy the snippet into the source service entry under `incoming`, review the shared secret environment variable, and confirm the handler class and idempotency policy.

## Suggested Workflow For Adding A New Service Integration

1. Configure the peer service identity, URL, endpoint, and secret names in `config/talkto.php`.
2. Run the desired generator with `--dry-run`.
3. Run the generator without `--dry-run`.
4. For incoming commands, copy and review the printed config snippet.
5. Fill in payload mapping, source action logic, validator rules, and handler action logic.
6. Add focused tests in the host app for send, receive, idempotency, retry, and callback behavior.
7. Run a local smoke test before enabling non-local traffic.

## What The Generated Files Are Responsible For

- Client: gives host code a small method for sending a command.
- Outgoing enum: keeps command values consistent per target service.
- Send action: connects host payload mapping to the Talkto outgoing flow.
- Payload builder: maps a host source object into a payload.
- Transactional source action: performs local source-side writes inside the local transaction.
- Incoming enum: keeps allowed incoming command values visible per source service.
- Handler: adapts the Talkto handler contract to host validation and action classes.
- Payload validator: validates and normalizes incoming payloads.
- Handler action: performs host-owned business work for the incoming command.

## What The Package Handles Vs What The Host App Handles

The package handles signing, envelope verification, message persistence, retry state, attempts, dead letters, idempotency checks, handler dispatch, result objects, callbacks, reporting, and tracing.

The host app handles command naming, payload contracts, model lookup, source writes, validation rules, permissions, callback side effects, config review, tests, monitoring, and rollout decisions.

## Safety Notes

- Do not put remote HTTP calls inside a local database transaction.
- Do not rely on generated stubs as finished business logic.
- Do not commit real shared secrets.
- Use stable command names and idempotency keys.
- Keep incoming command allowlists explicit.
- Review generated config snippets before copying them into `config/talkto.php`.
- Run `--dry-run` before writing files in an existing app.

## Manual Smoke Test Commands

```bash
php artisan talkto:make-outgoing inventory verify-invoice --dry-run
php artisan talkto:make-outgoing inventory verify-invoice --transactional --dry-run
php artisan talkto:make-incoming inventory website.invoice-verified --dry-run
php artisan talkto:make-integration inventory verify-invoice --outgoing --dry-run
php artisan talkto:make-integration inventory verify-invoice --outgoing --transactional --dry-run
php artisan talkto:make-integration inventory website.invoice-verified --incoming --dry-run
```
