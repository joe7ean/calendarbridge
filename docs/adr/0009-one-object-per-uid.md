# 0009. One calendar object per UID, permanent deletes

- Status: Accepted
- Date: 2026-10-24

## Context

In iCalendar, a recurring event is one master `VEVENT` plus zero or more override
`VEVENT`s that share its `UID` and carry a `RECURRENCE-ID`. CalDAV requires all of them to
live in **one** calendar object resource. Up to 0.2.0, every `VEVENT` was synced
separately and keyed by UID, so overrides overwrote the master or each other.

Deleting an object without `forceDeletePermanently` moves it to the trash bin and renames
its URI to `<name>-deleted.ics`. On Nextcloud 30 to early 33.x, a trashed row blocks
re-creating the same UID, and a second soft delete of the same URI throws `Forbidden`.
That aborts the whole sync.

## Decision

- Incoming `VEVENT`s are grouped by UID. Each group is written as one `VCALENDAR` that
  contains every `VTIMEZONE` referenced by any of its events.
- Change detection compares a hash of the serialised object, ignoring `DTSTAMP` lines.
- Relay objects are always deleted permanently. Trashed rows left behind by older
  versions are purged at the start of each sync.

## Consequences

Series with exceptions render correctly. Existing broken objects heal on the next sync
because their hash differs. The service user's trash bin stays empty, which is fine: the
feed is the source of truth, not the relay calendar.
