# AGENTS.md

Guidance for coding agents (Claude Code, Codex, Cursor, …) and human contributors.
This file is the single source of truth; `CLAUDE.md` only imports it.

## What this app does

Calendar Bridge is a Nextcloud app. An admin subscribes to an external iCal/WebCal
feed once; the app mirrors it into a **relay calendar** owned by a dedicated service
user and shares that calendar **read-only** with Nextcloud groups. It exists because
Nextcloud cannot share subscriptions (upstream: nextcloud/server#1600, see
[ADR 0008](docs/adr/0008-separate-app-instead-of-upstream.md)).

Goal: publication in the Nextcloud App Store. A production instance runs it already,
so correctness and data safety come before features.

## Architecture map

```
FeedController ──► SyncService ──► FeedService      (HTTP fetch, SSRF check, parse, sanitize)
SyncJob (1/feed) ─┘      │
                         └──────► CalendarService  (relay calendar, diff sync, dav_shares)
NotificationService / Notifier   (admin notifications after repeated failures)
SettingsController               (IAppConfig-backed global settings, group/user lookup)
```

- `lib/Service/SyncService.php` – orchestrates one sync of one feed, error counting, auto-pause.
- `lib/Service/FeedService.php` – fetch + validation; never writes anything.
- `lib/Service/CalendarService.php` – all writes to CalDAV and `dav_shares`.
- `lib/BackgroundJob/SyncJob.php` – one `TimedJob` per feed, argument `['feedId' => int]`.
- `src/` – Vue 3 admin UI (rendered in *Administration → Calendar Bridge*).
- `docs/adr/` – architecture decision records. Read the relevant ones before changing behaviour.

## Commands

| Task | Command |
|---|---|
| Install dev deps | `composer install` · `npm ci` |
| PHP unit tests | `composer test` (or `make test`) |
| Build frontend | `npm run build` |
| Release tarball | `make release` → `dist/calendarbridge-<version>.tar.gz` |
| Deploy to test instance | `make deploy` (uses `NC_HOST`, `NC_APPS_DIR`) |
| Version consistency | `make version-check` |

No PHP on the host? Run tests in a container:
`podman run --rm -v "$PWD:/app" -w /app docker.io/library/composer:2 sh -c "composer install -q && composer test"`.

## Invariants – do not break

1. **Never delete relay events because of a failed, partial or suspicious fetch.** Only a
   successfully parsed feed is the source of truth. Empty results and mass deletions are
   held in quarantine ([ADR 0012](docs/adr/0012-quarantine-suspicious-feed-results.md)).
   When in doubt, keep data and tell the admin.
2. **One calendar object per UID.** A recurring series (master + `RECURRENCE-ID`
   overrides) is stored as one object ([ADR 0009](docs/adr/0009-one-object-per-uid.md)).
3. **Relay objects are deleted permanently** (`forceDeletePermanently = true`), never into
   the trash bin — trashed rows block re-creation of the same UID.
4. **Shares are written directly to `dav_shares`** mirroring `SharingMapper::share()`
   ([ADR 0002](docs/adr/0002-relay-calendar-and-direct-dav-shares.md)). Only diffs are applied.
5. **Privacy:** never log event content (titles, descriptions, attendees) or credentials.
   Log feed IDs, counts and technical error messages only.
6. **No runtime PHP dependencies.** `composer.json` is dev-only; Sabre/VObject comes from Nextcloud.
7. Only public `OCP\` APIs, plus `OCA\DAV\CalDAV\CalDavBackend` (wired in `Application`).
   **Compatibility:** at least the last four Nextcloud majors, with 32 as the floor
   ([ADR 0011](docs/adr/0011-compatibility-window.md)). Check every `CalDavBackend` method
   and internal table you use against each supported major; the stub in
   `tests/stubs/` must match the real signatures.
8. **Fail safe.** Every external input (HTTP response, feed content, admin input) is
   validated, every limit (size, event count, timeout) is enforced, and every error leaves
   the relay calendar unchanged and is visible to the admin (status, notification, log).

## Releases and testing on real instances

Releases go through three stages ([ADR 0013](docs/adr/0013-staged-rollout.md)): the LAN
test instance with `tests/e2e/run.sh`, then a `-beta.N` release on the production instance
for test feeds and informed test users only, then the final tag. Never deploy to the
production instance without the user's explicit go-ahead.

## Conventions

- Code, comments, log messages and UI/API strings in **English**. User-facing strings go
  through `IL10N::t()` (PHP) or `t('calendarbridge', …)` (JS).
- PHP: `declare(strict_types=1)`, constructor property promotion, readonly dependencies,
  4-space indentation. Vue/JS: tabs.
- Commits: [Conventional Commits](https://www.conventionalcommits.org/) (`fix(sync): …`,
  `feat(ui): …`, `docs: …`, `chore(release): x.y.z`). One logical change per commit.
- Branches: `main` is releasable. Work on `fix/…`, `feat/…` or `release/x.y.z` branches.

## Definition of done

- [ ] Unit tests cover the change and `composer test` is green.
- [ ] Entry under `## [Unreleased]` in `CHANGELOG.md` (Added/Changed/Fixed/Removed/Security).
- [ ] New ADR (or updated status of an old one) for any change in architecture,
      data model, external behaviour or a deliberate trade-off.
- [ ] Database change ⇒ new migration class `lib/Migration/VersionXXXXDateYYYYMMDD.php`
      **and** a minor version bump (see [CONTRIBUTING.md](CONTRIBUTING.md#versioning)).
- [ ] README updated if admins see or must do something differently.

## Things that are not in the repo

- `YUNOHOST.md` (gitignored): private notes for the production instance.
- `.cursor/`: editor-local, ignored.
- `featurelog`, `buglog` (gitignored): private scratch lists for spontaneous ideas and
  observed bugs. Append on request; not a commitment or roadmap.
