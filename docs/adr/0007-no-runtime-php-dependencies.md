# 0007. No runtime PHP dependencies

- Status: Accepted
- Date: 2026-10-24 (retroactive, decided 2026-08)

## Context

Apps that ship `vendor/` can conflict with Nextcloud's own libraries, and the dev-only OCP
stubs must never reach a production instance. Nextcloud already provides everything the
app needs: Sabre/VObject, Guzzle (via `IClientService`) and Doctrine (via `IDBConnection`).

## Decision

`composer.json` contains only `require-dev` (OCP stubs, PHPUnit, Sabre/VObject for tests).
Release tarballs contain no `vendor/`; Nextcloud autoloads `OCA\CalendarBridge\` from `lib/`.

## Consequences

No dependency conflicts and smaller releases. Adding any new library would require a
scoped vendor directory (PHP-Scoper/Mozart) and a new ADR.
