# Host Stubs

These files are copy/paste templates for a Laravel host application. They are not published automatically.

Before copying:

- Replace `<source-service>`, `<destination-service>`, `<local-test-secret>`, `<service-testing-db>`, and `http://127.0.0.1:<port>`.
- Rename classes and namespaces to match the host.
- Decide route ownership and migration ownership.
- Keep host business logic in the host application.
- Keep real secrets outside committed files.

The examples use neutral names only: `source-service`, `destination-service`, `example:sync-record`, `ExampleRecord`, and `ExamplePayload`.
