# 0006. Auto-pause a feed after 10 consecutive errors

- Status: Accepted
- Date: 2026-10-24 (retroactive)

## Context

The original implementation plan said that a failing feed should *not* be deactivated
automatically ("the admin decides"). The implementation pauses it after 10 consecutive
errors anyway, and this was never written down.

## Decision

Keep the auto-pause. Admins are notified after 3 consecutive errors; after 10 the feed is
paused and they are notified again. A paused feed keeps its relay calendar and events
untouched. When the admin re-activates it in the UI, the next successful sync resets the
counter.

## Consequences

Dead or moved feeds stop hammering remote servers and filling the log. The admin has to
re-enable a paused feed manually.
