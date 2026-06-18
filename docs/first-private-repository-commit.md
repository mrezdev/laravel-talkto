# First Private Repository Commit

This plan describes the first manual commit after the owner creates an empty private repository. P.49A2 does not create the repository, add a remote, push, tag, or publish anything.

## Owner Action

Create an empty private repository manually using the owner-approved namespace and repository name.

## Local Setup

Use placeholders only. Do not paste credentials or private access values into committed files.

```bash
mkdir laravel-talkto-private
cd laravel-talkto-private
unzip <package-seed-zip> -d .
git init
git status --short
composer validate --no-check-publish
composer install --prefer-dist --no-interaction --no-progress
vendor/bin/pest
git add .
git commit -m "Initial private Laravel Talkto package"
git branch -M main
git remote add origin <private-repository-url>
git push -u origin main
```

## Before Pushing

- Confirm the repository is private.
- Confirm the package root contains `composer.json` directly at the top level.
- Confirm `vendor/`, `composer.lock`, generated test caches, build output, and local environment files are absent.
- Confirm the license is still proprietary/private unless the owner approved a change.
- Confirm docs and metadata contain placeholders only.
