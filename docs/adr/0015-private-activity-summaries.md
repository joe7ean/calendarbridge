# 0015. Replace relay item activities with private summaries and status transitions

- Status: Accepted (0.4.0-alpha.1; local testing only)
- Date: 2026-11-02

## Context

DAV emits activities for relay changes just as for human edits. These include event
titles and may be delivered to external mail systems. A generic deletion message
also suggests cancellation although the only fact known is absence from the feed.

## Decision

- No event titles, descriptions, attendees, UIDs, URLs, credentials, calendar names
  or raw errors in notification payloads. Existing admin error/quarantine notices
  become neutral sign-in prompts; details remain in the admin UI. Legacy stored
  notification parameters are ignored when rendering.
- Suppress DAV activities for managed relay calendars before **any activity consumer**
  receives them. Decorate `OCP\Activity\IManager` using the public boot context's
  server container; retain the original manager for registration and delivery.
  Install during app boot, before DAV's lazy activity listener is resolved. Do not
  stop calendar event propagation, disable DAV listeners, or modify Nextcloud code.
- A request-local scope protects app writes, including initial calendar creation
  before the new calendar ID is stored. Writes outside the app are identified by
  current relay calendar ID. Ordinary calendars and non-DAV activity pass through.
- Activity records contain a feed ID, operation counts and fixed reason codes only.
  For the stream, the provider resolves the current calendar name only after checking
  the recipient's current group membership. Deleted/inaccessible feeds show their ID.
  Counts represent applied object operations, not unique appointments. A series is
  one object. Aggregate for 24 hours starting at the first change, then publish on
  the next activity-job run. Do not publish empty summaries. No per-event details.
- Daily summaries are activity-stream only: mail and activity push are disabled and
  cannot be enabled through personal activity preferences. No summary notifications.
- Publish status activities once on quarantine entry, held removals being applied
  (distinguish admin approval and automatic confirmation), recovery after an announced
  outage, and administrative pause/removal. Repeated quarantined syncs are silent.
- An outage needs at least three failed attempts and 24 hours since the first failure.
  The independent job also checks auto-paused feeds. Recovery includes HTTP 304.
  Status notifications to current target-group members are generic sign-in prompts.
- Deduplicate recipients across groups and resolve current membership at publication;
  removed users do not receive pending summaries. Pending counts are not per-user
  history. New group members may receive aggregate counts spanning their join time.
- Store only counters and timestamps in nullable `activity_state` on the feed.
  A separate 15-minute job flushes independently of feed sync, backoff and pause.
  It uses the same per-feed lock. No new runtime dependency is introduced.
- Reporting failures are logged by feed ID/error class and must not turn a completed
  synchronization into a feed-fetch failure. Delivery and state persistence are not
  transactional; a crash during delivery may cause a duplicate, never deletion of events.

## Compatibility and validation

The extension uses public APIs available in Nextcloud 32. `bulkPublish` is available
since 32; the decorator's wider `setFormattingObject` argument also accepts NC 32's
integer IDs. The CalDavBackend methods and internal DAV table usage are unchanged.
Integration tests on every supported major must prove relay suppression, ordinary
calendar activities, continued calendar event propagation and count-only summaries.
This is essential because the decorator relies on DAV's lazy listener resolution.

Locally verified on 2026-11-02: the full E2E scenarios and privacy probes pass on
Nextcloud 32.0.15, 33.0.10, 34.0.5 and 35.0.2 (isolated SQLite containers).
The original E2E scenarios also pass after reverting to the preparation commit on
the already migrated Nextcloud 32 database, without removing the new column.

## Rollback and limits

Work is isolated on `feat/private-activity-summaries`. The schema preparation (nullable
column, matching Feed property, version bump) is a separate commit. Revert only the
following behavior commit to restore previous reporting, retaining the preparation
commit. Nextcloud's Entity hydration rejects unknown columns, so deploying the original
0.3 code against the extended table is **not** safe. Do not drop columns or delete
calendars to roll back. On an installed instance,
ship the reverted behavior with a **higher** version; Nextcloud does not support app
downgrades. Remove the new ActivityJob through `occ background-job:delete <job-id>`
if reverting an installed app without its class (list jobs first).

Git cannot recall already delivered mail/push, erase historical activities or undo
database migrations. This change does not scrub past DAV activities or queued mail.
It also does not control independently configured webhooks, calendar reminders,
client notifications, audit logging or third-party integrations. No automatic
deployment or production upgrade is part of this change (ADR 0013).
