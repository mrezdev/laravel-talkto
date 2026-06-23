# Talkto Panel

## Overview

The Talkto Panel is an optional operations UI for Laravel Talkto. It helps operators inspect local Talkto messages, view traces, retry eligible messages, reprocess dead letters, and review passive and optional active connection health.

The panel is disabled by default. It does not replace host-owned monitoring, alerting, or domain dashboards, and it does not own business logic.

## Enabling The Panel

Publish and review the package config, then enable the panel intentionally:

```dotenv
TALKTO_PANEL_ENABLED=true
TALKTO_PANEL_PREFIX=admin/talkto
TALKTO_PANEL_AUTHORIZATION_ENABLED=true
TALKTO_PANEL_GATE=viewTalktoPanel
TALKTO_PANEL_SHOW_PAYLOAD=false
TALKTO_PANEL_SHOW_RESPONSE=false
TALKTO_PANEL_ACTIVE_HEALTH_CHECKS_ENABLED=false
```

The package message tables must exist for message dashboards, detail pages, trace, retry, and dead-letter actions to show real data. If migrations have not been published or run, dashboard/message lists degrade to empty states where practical and connection pages still show config-based connection information.

## Security Defaults

The panel defaults are conservative:

- Disabled unless `TALKTO_PANEL_ENABLED=true`.
- Protected by the configured route middleware, including POST action routes.
- Authorization gate checks are enabled by default.
- Payload and response display are disabled by default.
- Tailwind CDN is disabled by default.
- Active health checks are disabled by default.
- Retry and dead-letter reprocess actions dispatch existing package jobs; they do not directly call remote services from the UI.

## Route Customization

The panel route prefix defaults to `talkto`.

```dotenv
TALKTO_PANEL_PREFIX=admin/talkto
```

The route name prefix defaults to `talkto.panel.`.

```dotenv
TALKTO_PANEL_ROUTE_NAME=admin.talkto.
```

With the examples above, the dashboard URL is `/admin/talkto`.

## Middleware Customization

Panel routes use the configured panel middleware. The default config uses Laravel web and auth middleware:

```php
'panel' => [
    'route' => [
        'middleware' => ['web', 'auth'],
    ],
],
```

Use host middleware for authentication, session handling, IP restrictions, or admin-only access. Keep the panel behind authentication in production.

Do not expose the panel publicly. If the middleware stack is empty or only contains `web`, the package cannot know who is allowed to inspect or mutate local Talkto rows. Run `php artisan talkto:audit-security` before enabling the panel in shared or production environments; it warns when the panel is enabled without auth-like middleware.

## Authorization Gate

The panel calls a Laravel Gate before serving panel pages or actions when authorization is enabled.

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewTalktoPanel', function ($user) {
    return $user->is_admin;
});
```

Configure the gate name with:

```dotenv
TALKTO_PANEL_GATE=viewTalktoPanel
```

## View Publishing

Panel views are namespaced as `talkto::panel.*` and are publishable:

```bash
php artisan vendor:publish --tag=talkto-panel-views
```

Publish views when the host app needs branding, spacing, copy, or layout changes.

## Language Publishing

The package ships English panel language lines under the `talkto::panel.*` translation namespace. Host apps may publish and override them without publishing the Blade views:

```bash
php artisan vendor:publish --tag=laravel-talkto-translations
php artisan vendor:publish --tag=talkto-translations
```

Only English panel translations are included for now.

## Tailwind-Only UI Behavior

The built-in views use Blade and Tailwind utility classes only. The panel does not require Livewire, Alpine, Vue, React, Inertia, Filament, or required frontend JavaScript.

The Tailwind CDN is opt-in only:

```dotenv
TALKTO_PANEL_TAILWIND_CDN=false
```

If the host app already compiles Tailwind, keep the CDN disabled and style published views through the host build.

## Message Dashboard

The dashboard shows recent local Talkto messages and passive connection health. It reads local database records only. It does not send commands, dispatch jobs, or call remote services.

Dashboard and message list queries use a small list-safe column set. They intentionally avoid loading payloads, response bodies, large error text, lock fields, or full heavy records that are only needed by detail, trace, or action flows.

Incoming-only services may show `unknown` when there is no recent local traffic. That means the panel has no local evidence yet; it is not a confirmed outage.

When multiple services share the same Talkto database, the panel shows only rows involving the current configured service by default:

```dotenv
TALKTO_PANEL_CURRENT_SERVICE_ONLY=true
```

Disable this only for a trusted central observer panel. Read-only pages can then inspect all rows, but retry and dead-letter reprocess actions still honor `TALKTO_ENFORCE_CURRENT_SERVICE_STORAGE_SCOPE` and will not mutate another service's rows by default.

## Message Detail / Trace

Message detail pages show status, attempts, events, dead-letter information, and hidden payload/response placeholders by default.

When a message has local callback context, the detail page can show a read-only Check Callback action. It opens a local callback status summary for the message, related callback message, attempts, events, and dead-letter state. The action does not resend, requeue, recreate callbacks, or call remote services.

Trace pages show the local message timeline, related messages, attempts, events, and dead letters. Trace payloads are hidden unless payload display is explicitly enabled and the trace request asks for payload output.

## Retry Action

The Retry action is a safe manual retry helper for eligible retryable messages. It:

- Checks panel authorization.
- Checks retry action configuration.
- Checks the retry policy.
- Makes the message due now by clearing the scheduled wait and lock fields.
- Dispatches the existing Talkto send/process job.
- Records panel-specific events.

It does not increment `retry_count` directly and does not call remote services from the HTTP request.

## Dead-Letter Reprocess Action

The dead-letter reprocess action:

- Checks panel authorization.
- Checks dead-letter action configuration.
- Claims the dead letter for reprocess.
- Prepares the original message for the existing queued pipeline.
- Dispatches the existing Talkto job.
- Marks failed reprocess if dispatch fails.

It will not reprocess a terminal successful original message.

## Connection Health

Connection health has two layers:

- Passive health from local Talkto records.
- Optional active health checks against configured health URLs.

These are separate signals. Passive health can say whether local message history looks healthy, degraded, failing, misconfigured, or unknown. Active health can say whether a configured health endpoint responded.

## Passive Health Vs Active Health

Passive health is always local. It looks at local message records, retry backlog, recent failures, and dead letters. It does not call remote services.

In a shared database, passive health only counts traffic between the current service and the configured peer. It ignores third-party rows that happen to use the same peer service name.

Active health is optional and disabled by default. When enabled, it calls only explicitly configured health URLs. It never sends Talkto commands and never attaches the configured Talkto shared secret.

Incoming-only services usually cannot be actively checked unless an explicit health URL is safely configured, so they may show `not_applicable`.

## Active Health Check Configuration

Configure a safe health endpoint per outgoing service:

```php
'outgoing' => [
    'target-service' => [
        'url' => env('TALKTO_TARGET_SERVICE_URL'),
        'secret' => env('TALKTO_TO_TARGET_SERVICE_SECRET'),
        'endpoint' => '/api/talkto/receive',
        'health' => [
            'url' => env('TALKTO_TARGET_SERVICE_HEALTH_URL'),
            'method' => 'GET',
            'timeout' => 3,
        ],
    ],
],
```

Fallback keys are also supported:

```php
'health_url' => env('TALKTO_TARGET_SERVICE_HEALTH_URL'),
'health_endpoint' => '/health',
```

Only `GET` and `HEAD` are allowed. Unsupported methods are reported as misconfigured. Active checks use cached results unless a user presses the Check now form, which forces a fresh check.

## Payload / Response Visibility

Payloads and responses are hidden by default:

```dotenv
TALKTO_PANEL_SHOW_PAYLOAD=false
TALKTO_PANEL_SHOW_RESPONSE=false
```

Only enable these in environments where operators are allowed to see the data. Even when enabled, Blade output is escaped.

## Redaction And Sensitive Data Notes

The panel avoids exposing configured shared secrets. Payloads, responses, attempts, events, trace output, and active health URLs redact common sensitive keys such as `authorization`, `cookie`, `x-api-key`, `x-talkto-signature`, `x-talkto-secret`, `token`, `secret`, and `password` when rendered or returned in JSON.

Redaction is a safety layer, not a replacement for access control. Keep the panel behind trusted admin middleware even when payload and response display are disabled.

Avoid placing secrets in health URLs. Prefer unauthenticated, low-risk health endpoints that return minimal status.

## Suggested Production Setup

- Keep the panel disabled unless operators need it.
- Require authenticated middleware such as `web`, `auth`, or a stricter app-specific admin middleware.
- Require a narrow authorization gate.
- Keep payload and response display off unless temporarily needed.
- Treat payload and response visibility as sensitive operational access.
- Keep Tailwind CDN off and use the host asset pipeline.
- Enable active health checks only for safe, explicit health endpoints.
- Avoid secrets in health URLs.
- Run `php artisan talkto:audit-security` after config changes.
- Publish views for host-specific branding or wording.

## Manual Smoke Commands

```bash
php artisan route:list --name=talkto.panel
php artisan vendor:publish --tag=talkto-panel-views --force
php artisan talkto:report --hours=24 --direction=all --limit=20
php artisan talkto:trace <message-id>
php artisan talkto:retry-failed --dry-run
```

Then open the configured panel route in a browser while signed in as a user allowed by the panel gate.
