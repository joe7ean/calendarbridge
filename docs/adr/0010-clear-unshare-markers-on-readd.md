# 0010. Clear "unshare" markers when a group is (re-)added

- Status: Accepted
- Date: 2026-10-24 (retroactive, decided 2026-08)

## Context

When a group member uses "Unshare from me", Nextcloud stores a per-user row with
`access = 5` in `dav_shares`. That row survives removing and re-adding the group share, so
the calendar stays hidden for that user (upstream bug
[nextcloud/server#45783](https://github.com/nextcloud/server/issues/45783)).

## Decision

When a group is added to a feed's target groups, delete the `access = 5` rows of that
group's members for this calendar. Ordinary syncs leave these rows alone.

## Consequences

Re-adding a group restores the calendar for everybody. A user's own "unshare" choice is
respected as long as the group assignment does not change. This workaround can be dropped
once #45783 is fixed in all supported Nextcloud versions.
