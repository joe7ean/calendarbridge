# 0003. One background job per feed, interval gated in `run()`

- Status: Accepted
- Date: 2026-10-24

## Context

Feeds have individual sync intervals (15 min – 24 h). `TimedJob::start()` checks the
interval **before** `run()` is called, on a freshly constructed job object. Calling
`setInterval()` inside `run()` therefore has no effect. Up to 0.2.0, every feed was synced
hourly regardless of its setting.

## Decision

- One `SyncJob` per feed with the argument `['feedId' => id]`. It is registered on create
  and removed on delete. Parallel runs of the same job are not allowed.
- The job's own interval is fixed at the minimum (900 s). `run()` skips the sync until
  `lastSyncedAt + max(min_sync_interval, feed.syncInterval)` has passed.
- A job whose feed no longer exists removes itself.
- Manual sync and the job are serialised with a per-feed lock.

## Consequences

Cron wakes each job at most every 15 minutes; a skipped run costs one primary-key lookup.
We rejected a single job that iterates over all feeds: one slow feed would delay all the
others, and Nextcloud's per-job timing and logging would be lost.
