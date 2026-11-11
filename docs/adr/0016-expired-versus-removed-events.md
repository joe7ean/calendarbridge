# 0016. Distinguish expired from removed events

- Status: Accepted (0.4.0-beta.1)
- Date: 2026-11-11

## Context

Nobody can delete events in a relay calendar: it is read-only and mirrors the feed.
An event disappears only because its UID is no longer in the source. Two very
different reasons look identical to the sync:

- the source **stopped listing old events** (a rolling window such as "only the last
  six months"), or
- an upcoming or ongoing event was **removed or cancelled** in the source.

Treating both as "deleted" made the daily summary (ADR 0015) misleading and could
trigger a false quarantine (ADR 0012) when a window moves or shrinks.

## Decision

- When an object is absent from the feed, `CalendarService` inspects its stored data
  with Sabre's `EventIterator`. The object is **expired** if it is bounded, every
  occurrence is inspected (at most 2000) and the last one ended more than 24 hours
  ago. Everything else is **removed**: upcoming, ongoing, unbounded series,
  unparseable or too long to inspect. When in doubt it is removed.
- Sync results carry `deleted` (removed) and `expired` separately. Both are deleted
  permanently as before (invariant 3).
- Quarantine thresholds count removed objects only, measured against the objects that
  were not expired. Expired objects never make a result suspicious by themselves.
  A quarantine still holds expired objects together with the removed ones, and an
  empty feed holds everything, so the held count covers both.
- Users never see expired events: the activity summary counts only added, updated and
  removed (upcoming) objects, and a day with only expiries is silent. Administrators
  see the number in the manual sync result, `occ calendarbridge:sync` and the log.
  It is not persisted, so no migration is needed.

## Consequences

- No event content is read beyond what the sync already handles, and nothing new is
  logged beyond counts.
- A relay calendar slowly drops past events whenever the source does. Keeping past
  events regardless of the source is a separate option and not part of this decision.
- Series with more than 2000 occurrences or without an end are never expired.
