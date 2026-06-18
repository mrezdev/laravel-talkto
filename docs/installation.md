# Installation

Install the package through Composer and publish only the assets your host application needs.

```bash
composer require mrezdev/laravel-talkto
php artisan vendor:publish --tag=laravel-talkto-config
php artisan vendor:publish --tag=laravel-talkto-migrations
```

Package routes and migrations are disabled by default. Existing applications should keep them disabled until they have checked for duplicate tables and duplicate receive endpoints.

## First Install Checklist

- Publish the config.
- Set `TALKTO_SERVICE` to a stable service name.
- Configure outgoing peers and incoming source allowlists.
- Add secrets through environment variables, not committed files.
- Decide whether the host or the package owns migrations.
- Decide whether the host or the package owns the receive route.
- Run package smoke tests and focused host compatibility tests.
