# Local Talkto Readiness

Run Doctor from a configured Laravel application:

```bash
php artisan talkto:doctor
php artisan talkto:doctor --json
```

Doctor reads the effective configuration, registered routes, Composer package metadata, and actual model storage. It does not send messages, contact peers, execute handlers, dispatch jobs, run migrations, write data/config/cache/files, or repair anything. No dependency, config option, or environment variable is required for Doctor.

## Status And Exit Code

- **PASS**: the inspected local prerequisite is satisfied.
- **WARN**: the setting deserves attention; it does not fail the command.
- **FAIL**: a required local prerequisite is missing, invalid, or unavailable.
- **INFO**: context or an intentionally disabled optional feature.

Any FAIL causes exit `1` and `not_ready`. Otherwise exit `0` and `ready`, including warnings-only results. Review warnings even when the exit code is zero.

## Checks

| Category | Checks |
| --- | --- |
| Environment | PHP and Laravel versions against the installed package's Composer requirements; installed Talkto version or informational source checkout; service identity through Talkto's identifier validator. |
| Storage | Actual message model database connection using a read-only query; configured messages, attempts, events, dead letter and nonce tables; consistent model connections required for atomic writes. Custom Talkto models, tables, connection, and the legacy DLQ table fallback are respected. |
| Security | Outgoing signature version, accepted versions, required signatures, replay protection, v2 nonce requirement, global TLS verification and local CA bundle readability. Detailed auditing is available through `talkto:security-audit`. |
| Peers | Outgoing registry targets, identifier/receive URL/secret/header prerequisites and effective TLS settings; incoming source identifiers, secrets, effective allowlists, handler class/contract references and command drivers. Configured command/idempotency and peer counts are shown. Programmatic target and shared handler registrations are included. |
| Runtime | Queue connection/driver configuration, expected enabled package POST routes, and informational package migration mode. |
| Optional features | Callback command and reverse outgoing target prerequisites for automatic callbacks; expected panel route names and methods when the panel is enabled. Disabled callbacks and panel are INFO. |

Unsupported signature versions, invalid accepted versions, disabled required signatures/replay protection, unusable peers/handlers, missing storage, missing queue configuration, and missing enabled routes cause FAIL. Legacy v1, optional v2 nonces, effective incoming allow-all, disabled TLS verification, or an unavailable/local-uninspectable CA bundle cause WARN, following existing package security semantics.

The `sync` queue driver is INFO in local/testing environments and WARN in production/staging. A configured asynchronous driver passes the configuration check without contacting its backend. The `null` driver warns because it discards jobs. Doctor does not inspect workers or Horizon.

Incoming allow-all is effective only when `allowed_commands` is absent and `allow_all_commands` is exactly `true`. An explicit empty allowlist still rejects commands and fails readiness. List allowlists and map entries with `null` or `driver=none` retain their existing no-op handling semantics. Shared registered handlers take precedence over per-source drivers. A command with `idempotency=required` is counted as requiring a key; other values preserve optional idempotency. Doctor does not instantiate handlers or check their constructor dependencies.

Automatic callbacks require the existing reverse outgoing target for each incoming source, including a resolvable callback URL and signing secret. Doctor uses the target object's existing URL inference rules. When callbacks or automatic dispatch are disabled, the corresponding automatic-delivery prerequisites are not required. Package routes are checked only when enabled; the callback route is needed only when callbacks are enabled. Host-owned routes remain the host's responsibility.

Package migration auto-loading may remain disabled after publishing migrations into the host application. Doctor checks the actual tables and never runs migrations. Disabled migrations, package routes, and panel are not deployment problems by themselves.

## JSON Output

JSON mode emits one JSON object without decorative CLI text:

```json
{
  "status": "ready",
  "summary": {"pass": 1, "warn": 0, "fail": 0, "info": 0},
  "checks": [
    {
      "category": "environment",
      "key": "service_name",
      "status": "pass",
      "label": "Service name",
      "value": "website",
      "message": null
    }
  ]
}
```

This is a shortened structure example. Every check has these six fields, with string or integer values and a nullable message. Categories are `environment`, `storage`, `security`, `peers`, `runtime`, and `optional_features`. Status counts always match the checks. Scripts should use `key` and `status` rather than human labels. Future additive checks can change the counts.

Secrets, URLs, custom header values, database credentials, CA paths, and raw exception text are omitted. Existing Talkto text redaction also protects displayed identifiers. Malformed settings receive fixed diagnostic messages. CA bundle stream URLs are not inspected, so a configured URL cannot trigger a network probe.

## Scope

`talkto:doctor` checks local installation/configuration readiness. `talkto:security-audit` provides detailed security posture. Neither command is a repair tool.

Doctor does not prove remote reachability, matching peer secrets, remote command compatibility, queue backend availability, running workers, database write privileges, column/index correctness, handler dependency resolution, or host authorization behavior. These require host integration tests and deployment validation. Normal Talkto messaging and the Phase 1 testing fake are unchanged.
