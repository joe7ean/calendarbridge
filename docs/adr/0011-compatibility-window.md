# 0011. Support at least the last four Nextcloud majors, with 32 as the floor

- Status: Accepted
- Date: 2026-10-24

## Context

The app touches internal Nextcloud tables (`dav_shares`, `calendarobjects`) and the
internal `CalDavBackend`, so every Nextcloud major is a compatibility risk. Instances are
upgraded slowly in practice. YunoHost, for example, refuses major upgrades while an app
is missing from the target catalog. Until 0.2.x the app declared 30–34 without testing
30–32.

## Decision

- Supported range: **at least N-3 to N**, where N is the newest stable Nextcloud major.
  As of 2026-10 that is 32–35.
- **Nextcloud 32 is the floor.** It is raised only when a breaking change in Nextcloud or
  a security reason forces it, never just because a version became old. Raising it is
  a minor release (major after 1.0) and needs a new ADR or an update to this one.
- `max-version` is raised once the new major passes the integration tests. A nightly CI
  run against Nextcloud `master` warns about breaking changes early.
- Code paths needed only for versions below the floor are removed when the floor moves.

## Consequences

- `info.xml` goes from `30–34` to `32–35` with 0.3.0, after tests on 32 and 35.
- The CI matrix must cover every major in the range (planned for 0.4/1.0, see the roadmap).
- Public OCP APIs added after Nextcloud 32 may only be used behind a version check.
