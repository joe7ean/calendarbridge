# 0013. Staged rollout with pre-release tags

- Status: Accepted
- Date: 2026-10-24

## Context

Unit tests mock `CalDavBackend` and the database, so they cannot catch mismatches with a
real Nextcloud. The 0.2.0 bugs (lost recurrence overrides, trash-bin conflicts, an ignored
interval) only showed up in production. Two installations are available for testing
before a production change: a LAN test instance and the production instance, where
informed test users can be used.

## Decision

Every release goes through three stages:

1. **Test instance (LAN).** Deploy with `make deploy`, then run the end-to-end scenario
   script: recurring series with overrides and EXDATE, change and delete/re-add,
   trash-bin purge, empty/suspicious feed, job interval, concurrent syncs, and log check.
2. **Production instance, test scope.** Release `x.y.z-beta.N`, installed with dedicated
   test feeds and a test group whose members know they are testing. Real feeds stay
   untouched for at least one full sync cycle of every test feed (≥ 24 h when quarantine
   logic changed).
3. **Final release.** Tag `vx.y.z`, enable for the real feeds, and watch the log for one
   day.

Pre-release versions use SemVer pre-release identifiers (`0.3.0-beta.1`), which
Nextcloud accepts in `info.xml`. They get a changelog section only as part of the final
version.

## Consequences

Releases take a day or two longer. Bugs that need a real Nextcloud are caught before they
reach real users. The end-to-end script lives in the repository (`tests/e2e/`) so it
can later run in CI against Nextcloud containers.
