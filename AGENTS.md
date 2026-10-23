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
   successfully parsed feed is the source of truth; empty feeds follow
   [ADR 0005](docs/adr/0005-empty-feed-protection.md).
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
   Check method signatures against the Nextcloud versions in `appinfo/info.xml`
   (currently 30–34) — the stub in `tests/stubs/` must match the real signatures.

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
