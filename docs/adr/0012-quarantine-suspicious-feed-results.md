# 0012. Quarantine suspicious feed results instead of applying them

- Status: Accepted (planned for 0.3.0)
- Date: 2026-10-24
- Supersedes: [0005](0005-empty-feed-protection.md)

## Context

The relay calendar is shared with whole groups. A single bad fetch can wipe or gut it:
an empty calendar during maintenance, a truncated export, or a token that suddenly returns
only a subset. ADR 0005 only covered the "completely empty" case, and in 0.2.x it treats
that case as an error, so a legitimately empty feed ends up paused. Mass disappearance
of events was not covered at all.

## Decision

**Suspicious result.** A successfully fetched and parsed feed result is suspicious when:

- it contains no usable `VEVENT` while the relay calendar is not empty, or
- it would delete at least 50 % of the relay objects **and** at least 10 objects.

The thresholds are global settings.

**Quarantine.** While a feed is in quarantine:

- Additions and updates from the feed are applied as usual.
- **Deletions are held.**
- The feed shows the status "quarantine" with the reason and counts. Admins get a
  notification when it starts.
- Quarantine is not an error: the error counter is untouched and no auto-pause happens.
- The held result is identified by a hash of the incoming UID set.

**Leaving quarantine:**

- *Confirmed automatically:* the same UID set (same hash) was fetched in at least 2
  consecutive syncs **and** at least 24 hours after quarantine began. The held deletions
  are then applied.
- *Different result:* if the next result is not suspicious, quarantine ends without
  deleting anything. If it is suspicious but different, quarantine restarts with the new
  hash.
- *Admin decision:* in the UI or via `occ calendarbridge:quarantine`, an admin can
  **apply** the result early or **reject** it. A rejected hash is never applied
  automatically; the feed keeps its events until the source changes.

**Plausibility checks** (these fail the sync as an error, they do not quarantine):

- The size limit is enforced while streaming the response, not after downloading it.
- At most `max_events` `VEVENT`s per feed (default 20 000).
- A feed that contains `VEVENT`s but none with a usable UID.
- HTTP 429/503 honour `Retry-After`.
- Repeated errors back off exponentially, up to 24 h.

## Consequences

- No single fetch can remove a large part of a shared calendar. Genuine large changes
  take about a day, or one admin click.
- New columns are needed: `quarantine_since`, `quarantine_count`, `quarantine_hash`,
  `quarantine_reason`, `rejected_hash`. `last_status` gets the value 3 = quarantine.
- Admins have to understand the new state. The UI must explain it in one sentence and
  offer both actions.
