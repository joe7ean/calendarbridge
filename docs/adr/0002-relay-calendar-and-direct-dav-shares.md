# 0002. Relay calendar owned by a service user, shares written to `dav_shares`

- Status: Accepted
- Date: 2026-10-24 (retroactive, decided 2026-04)

## Context

Nextcloud can subscribe to a webcal feed per user, but subscriptions cannot be shared
(nextcloud/server#1600). A regular calendar can be shared with groups. The public sharing
API needs an `IShareable` node, which cannot be constructed outside a Sabre request.

## Decision

- Each feed is mirrored into a regular calendar (the "relay calendar"), owned by a
  dedicated service user that nobody logs in as.
- Group shares are written directly into `dav_shares` with `access = 3` (read-only),
  mirroring the columns of `OCA\DAV\DAV\Sharing\SharingMapper::share()`.
- Sharing is reconciled on every sync, and only the difference is written.

## Consequences

- Users need to do nothing; the calendar appears for every group member.
- We depend on the `dav_shares` schema, an internal table. It has to be checked for each
  supported Nextcloud major, as part of raising `max-version`.
- Shares that someone removes in the Calendar app come back on the next sync. This is
  intentional and the UI says so.
