# 0018. Run sync jobs as time-sensitive

- Status: Accepted (0.4.1)
- Date: 2026-11-22

## Context

Up to 0.4.0, `SyncJob` was registered as `IJob::TIME_INSENSITIVE`, as a courtesy to busy
servers. If an instance sets `maintenance_window_start`, Nextcloud's cron starts
time-insensitive jobs only inside that four-hour window. A feed with a 6-hour interval
then synced once a night. The health check reported every feed as "Overdue … Is cron
running?" each evening, although cron was fine (issue #1). This also broke the promise of
[ADR 0003](0003-one-timed-job-per-feed.md) that cron wakes each job every 15 minutes.

Nextcloud stores the flag in `oc_jobs.time_sensitive` when a job runs, and only ever sets
it to 0. Changing the class alone does not fix jobs that already exist.

## Decision

- `SyncJob` keeps the `TimedJob` default, time-sensitive.
- The post-migration repair step re-creates every existing sync job once (`IJobList::remove()`
  + `add()`, public API only). The new row gets the default `time_sensitive = 1`. A flag
  in app config (`sync_jobs_time_sensitive`) prevents doing this again on later upgrades.

## Consequences

Feeds sync at their configured interval on instances with a maintenance window, and the
false "Overdue" warning goes away. The extra cost outside the window is one primary-key
lookup per feed every 15 minutes (ADR 0003). A re-created job runs at the next cron tick,
but does not fetch early, because `run()` checks the feed's own last sync time.

Rejected: keeping time-insensitive and teaching the health check about the window. Feed
freshness is what users see, and a once-a-night sync is not what admins configured.
