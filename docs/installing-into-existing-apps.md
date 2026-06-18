# Installing Into Existing Apps

Installing Laravel Talkto into an application that already has local messaging tables, routes, jobs, or config requires an opt-in transition.

## Main Risks

- Duplicate migrations for `talkto_messages`, `talkto_attempts`, and `talkto_events`
- Duplicate route name `talkto.receive`
- Duplicate `/api/talkto/receive` endpoint
- Duplicate `config/talkto.php`
- Overlapping local models, services, and queued jobs

## Safe Transition Flags

Keep package routes and migrations disabled until the host application has a clear migration plan:

```env
TALKTO_MIGRATIONS_ENABLED=false
TALKTO_ROUTES_ENABLED=false
```

## Recommended First Install Mode

- Install the package with migrations and routes disabled.
- Confirm package autoload and provider boot.
- Keep existing host messaging code active.
- Run the host application's focused Talkto tests.
- Move one generic component at a time behind a compatibility layer.

## Migration Strategy

Do not run package migrations while host migrations for the same tables exist. Either keep host migrations permanently and disable package migration loading, or switch to package migrations only after confirming the migration table state.

## Route Strategy

Do not enable package routes while the host app already defines the same receive endpoint or route name. Enable package routes only after removing or replacing the local route.

## Config Strategy

Published host config overrides package defaults. Keep service names, peer URLs, secrets, allowed commands, handler classes, and write flags in the host application.

## What Not To Do

- Do not delete host classes before package and host regression tests pass.
- Do not enable package routes and duplicate host routes at the same time.
- Do not run package migrations over existing Talkto tables without a migration plan.
- Do not move business flows into the package during installation.
