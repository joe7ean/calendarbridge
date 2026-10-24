# Changelog

All notable changes to Calendar Bridge are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- End-to-end scenario script (`tests/e2e/run.sh`, `make e2e`) that exercises a deployed app
  on a real Nextcloud: recurring series, change/delete/re-add, trash-bin purge, empty
  feed, job interval and concurrent syncs.
- ADRs for the compatibility window (0011), quarantine of suspicious feed results (0012,
  replacing 0005) and the staged rollout (0013).

## [0.2.1] - 2026-10-24

### Added

- `AGENTS.md` (with a `CLAUDE.md` import), `CONTRIBUTING.md` with versioning and release
  rules, and architecture decision records in `docs/adr/`.
- `make version-check` verifies that `info.xml`, `package.json` and the changelog agree.

### Changed

- Manual syncs are limited to three per source server within five minutes, on top of
  the per-feed cooldown. A sync of a feed that is already running returns 409.
- `make release` no longer invokes Composer and no longer ships `vendor/`. The app has
  no runtime PHP dependencies, and bundling the dev-only OCP stubs into a release
  tarball was a hazard rather than a convenience.

### Fixed

- **Recurring events with modified or moved occurrences.** All `VEVENT`s sharing a UID
  are now stored as one calendar object; before, overrides overwrote the series (or
  each other). Affected objects are repaired on the next sync.
- **Events that left and re-entered a feed could not be created again** ("Calendar
  object with uid already exists"), and removing the same event twice aborted the sync.
  Relay objects are now deleted permanently, and objects left in the trash bin by
  earlier versions are purged.
- **The per-feed sync interval was ignored**; every feed synced hourly. The configured
  interval and the global minimum interval now apply.
- A valid but empty feed no longer wipes the relay calendar; the sync is skipped and
  reported as an error.
- A manual sync and the background job can no longer sync the same feed concurrently.
- An HTTP password that cannot be decrypted now fails the sync with a clear message
  instead of fetching without credentials.
- The connection test was no longer rate-limited after an unfinished refactoring.
- Time zones referenced by `DTEND`, `RECURRENCE-ID` or `EXDATE` are now copied along
  with the event, not only the one of `DTSTART`.
- UIDs containing characters that are unsafe in a URI get a hashed object URI.
- Background jobs of deleted feeds remove themselves.
- Manual installation instructions. `git clone` alone leaves the admin panel empty
  because `js/` is a build artifact, and `composer install` was never needed at
  runtime. Documented how to update the app and why it has to be disabled before a
  Nextcloud major upgrade while it is not in the App Store.

## [0.2.0] - 2026-08-07

First versioned release. 0.1.0 was never tagged or published, so this entry
covers everything since the initial implementation.

### Added

- Support for Nextcloud 33 and 34 (`max-version` raised from 32 to 34).
- Notice in the feed form explaining that sharing is re-applied on every sync.

### Changed

- **Minimum PHP version raised from 8.1 to 8.2.** Nextcloud 34 requires 8.2, and
  `info.xml` now declares it so incompatible instances get a clear error instead of
  a fatal at runtime.
- Group sharing is now diff-based: only groups that were actually added or removed
  are touched, instead of rewriting all shares on every sync.
- `CalendarService` depends on `IGroupManager` instead of `IUserManager`, matching
  what it actually resolves.
- Development stubs bumped to `nextcloud/ocp ^34.0`.
- `make` targets invoke `occ` through the generic `php` binary with a raised memory
  limit rather than a hardcoded `php83`.

### Fixed

- Admin error notifications crashed on Nextcloud 34. `\OC::$server->getURLGenerator()`
  was removed there; `IURLGenerator` is now injected into the notifier.
- `CalDavBackend` is resolved via the public `\OCP\Server::get()` instead of the
  private `\OC::$server->get()`.
- Calendars stayed hidden for users who had previously used "remove from my
  calendars". The leftover unshare marker is now cleared when a group is
  (re-)added, without overriding that choice on unrelated syncs.
- Deleting a calendar now removes all of its shares, not just group shares, so a
  failed delete cannot leave it shared.
- Relay calendar sync is hardened against duplicate database rows, with clearer
  messages when a duplicate is hit.
- Admin UI: the connection test button sits below the URL and calendar fields, is
  responsive, and reports errors inline.

## [0.1.0] - 2026-04-12

Initial implementation. Never tagged or published.

- Subscribe to iCal/WebCal feeds and relay them as read-only calendars shared with
  Nextcloud groups.
- Background sync with a configurable interval, ETag/Last-Modified caching, and
  diff-based event writes.
- Optional stripping of `ATTENDEE`/`ORGANIZER` fields.
- Admin notifications on persistent fetch failures, plus manual sync and connection
  test from the admin UI.
