# Architecture Decision Records

Short records of decisions that shape Calendar Bridge, in a trimmed
[MADR](https://adr.github.io/madr/) style. They explain *why* the code looks the way it
does, so that neither humans nor agents "fix" a deliberate trade-off.

- New decision → copy [`0000-template.md`](0000-template.md) to the next free number.
- Accepted ADRs are not rewritten. A change of mind gets a new ADR, and the old one is
  marked `Superseded by NNNN`.
- Status values: `Proposed`, `Accepted`, `Deprecated`, `Superseded by NNNN`.

| # | Decision | Status |
|---|---|---|
| [0001](0001-record-architecture-decisions.md) | Record architecture decisions | Accepted |
| [0002](0002-relay-calendar-and-direct-dav-shares.md) | Relay calendar owned by a service user, shares written to `dav_shares` | Accepted |
| [0003](0003-one-timed-job-per-feed.md) | One background job per feed, interval gated in `run()` | Accepted |
| [0004](0004-strip-attendees-by-default.md) | Strip ATTENDEE/ORGANIZER by default | Accepted |
| [0005](0005-empty-feed-protection.md) | Protect relay calendars from empty feeds | Superseded by 0012 |
| [0006](0006-auto-pause-after-repeated-errors.md) | Auto-pause a feed after 10 consecutive errors | Accepted |
| [0007](0007-no-runtime-php-dependencies.md) | No runtime PHP dependencies | Accepted |
| [0008](0008-separate-app-instead-of-upstream.md) | Separate app instead of an upstream change (for now) | Accepted |
| [0009](0009-one-object-per-uid.md) | One calendar object per UID, permanent deletes | Accepted |
| [0010](0010-clear-unshare-markers-on-readd.md) | Clear "unshare" markers when a group is (re-)added | Accepted |
| [0011](0011-compatibility-window.md) | Support at least the last four Nextcloud majors, with 32 as the floor | Accepted |
| [0012](0012-quarantine-suspicious-feed-results.md) | Quarantine suspicious feed results instead of applying them | Accepted (0.3.0) |
| [0013](0013-staged-rollout.md) | Staged rollout with pre-release tags | Accepted |
| [0014](0014-self-healing-job-registration.md) | Self-healing registration of sync jobs | Accepted |
