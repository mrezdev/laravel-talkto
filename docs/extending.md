# Extending Laravel Talkto

Laravel Talkto keeps transport, signing, message lifecycle, retries, and operator tooling inside the package while leaving host-specific business behavior in the host application. Prefer the extension points below over depending on internal pipeline classes.

## Outgoing HTTP Transport

Override `Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient` when a host needs custom proxy routing, TLS/certificate handling, tracing, circuit breakers, custom logging, or test doubles.

```php
use App\Talkto\CustomTalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;

public function register(): void
{
    $this->app->bind(TalktoHttpClient::class, CustomTalktoHttpClient::class);
}
```

The custom client receives the already-built URL, signed headers, envelope, and timeout. Keep it transport-only; envelope creation, signing, retry decisions, attempts, events, and status transitions remain package responsibilities. See [HTTP client extension](http-client.md).

## Outgoing Targets

Configure outgoing peers under `talkto.outgoing`, or register targets programmatically through `TalktoOutgoingTargetRegistryContract` when targets come from host-owned runtime configuration.

Programmatic targets override config targets with the same canonical name. Aliases remain available through `talkto.aliases`.

## Incoming Handlers

Register handlers in config:

```php
'incoming' => [
    'handlers' => [
        'domain.command' => App\Talkto\Handlers\DomainCommandHandler::class,
    ],
],
```

Or register them programmatically through `TalktoIncomingHandlerRegistryContract`. Incoming command allowlists remain fail-closed: a source must explicitly allow each command, or explicitly set `allow_all_commands => true` for trusted/internal development cases only.

## Result Callback Runtime

The callback runtime is exposed through `ResultCallbackSenderContract` and `ResultCallbackReceiverContract`. Bind replacements only when the host needs custom callback transport behavior, custom callback receipt policy, or deeper integration with host observability.

Keep callback business meaning in the host application; the package only signs, sends, receives, verifies, and records callback messages.

## Retry Configuration

Retry behavior is configurable by global defaults, direction, target/source peer, and command. Prefer config overrides for normal tuning. Replace package services only after config cannot express the policy and the host accepts the compatibility responsibility.

## Models, Tables, And Storage

Hosts can configure Talkto model classes through `talkto.models.*`, table names through `talkto.database.tables.*`, and the storage connection through `talkto.database.connection`.

When multiple services share one Talkto database, keep `TALKTO_SERVICE` stable and keep current-service storage enforcement enabled unless the host has a trusted central operator process.

## Panel Configuration

The panel is disabled by default. Enable it only behind host-owned auth/admin middleware and a narrow authorization gate. Payload and response visibility are disabled by default, and list views avoid loading heavy payload/response fields.

See [Talkto Panel](panel.md) and [Production hardening](production-hardening.md).

## Operator Commands

The package provides read-only and operator-safe commands such as:

- `talkto:audit-security`
- `talkto:security-audit`
- `talkto:report`
- `talkto:trace`
- `talkto:retry-failed`
- `talkto:dlq-reprocess`
- `talkto:recover-stale`
- `talkto:prune`

Hosts should wrap these in their own runbooks, scheduling, permissions, and alerting rather than modifying command internals.
