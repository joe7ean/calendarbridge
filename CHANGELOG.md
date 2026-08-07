# Changelog

All notable changes to Calendar Bridge are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
