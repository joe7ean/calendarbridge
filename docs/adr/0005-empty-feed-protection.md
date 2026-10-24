# 0005. Protect relay calendars from empty feeds

- Status: Superseded by [0012](0012-quarantine-suspicious-feed-results.md)
- Date: 2026-10-24

## Context

A feed that suddenly returns a valid but empty `VCALENDAR` usually points to an upstream
glitch, such as a maintenance page or an expired token that returns an empty calendar.
Treating it as the source of truth would wipe the relay calendar for every group member.
On the other hand, some feeds are legitimately empty for a while (school holidays).

Since 0.2.0, an empty feed is rejected as an error. That protects the data, but a feed that
is legitimately empty then raises notifications after 3 runs, is auto-paused after 10, and
keeps showing stale events forever.

## Decision

- 0.2.x: an empty feed is rejected and nothing is deleted (current behaviour).
- From 0.3.0: an empty result is not an error. The first empty fetch only records
  `empty_since`. The relay calendar is emptied only once the feed has been empty for at
  least 24 hours **and** for at least two consecutive syncs. Any non-empty fetch resets
  the state.

## Consequences

Glitches never delete data, and genuine emptiness is applied with a delay of about a day.
The admin UI shows "empty since …" so the state is visible.
