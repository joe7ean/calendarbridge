# 0014. Self-healing registration of sync jobs

- Status: Accepted
- Date: 2026-10-24

## Context

The production instance stopped syncing all feeds for about four weeks without any
visible error. The cause:

- Before a Nextcloud major upgrade, the app has to be disabled (YunoHost refuses to
  upgrade otherwise, see README).
- While it is disabled, Nextcloud's cron cannot load `SyncJob` and **deletes the job**
  (`JobList::buildJob()`: "Remove job from disabled app or old version of an app").
- `occ app:enable` with an unchanged app version neither re-adds jobs nor runs repair
  steps. The app's own event listeners are not registered at that moment either.

Reproduced on the test instance on 2026-10-24: one job before, zero after one cron run
with the app disabled, still zero after re-enabling (0.3.0-beta.1).

## Decision

- `JobRegistrar::ensureJobs()` registers a `SyncJob` for every feed that lacks one.
- It runs from `Application::boot()`, throttled to once per hour via the app config
  value `jobs_checked_at`. Every request and every cron run boots enabled apps, so the
  jobs return at the latest one hour after re-enabling, without any admin action. Errors
  are caught and logged; boot must never break a request.
- It also runs as an install and post-migration repair step, so upgrades restore the
  jobs immediately.
- The health check keeps reporting missing jobs as an error, in case the self-check
  cannot run.

## Consequences

One cached config read per request, and one query per feed per hour. Jobs of deleted
feeds still remove themselves (ADR 0003). The README no longer has to rely on admins
remembering a manual step after re-enabling the app.
