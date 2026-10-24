# Contributing

Thanks for helping! Start with [AGENTS.md](AGENTS.md) — it is written for coding agents
but doubles as the developer guide (architecture, invariants, definition of done) — and
the [architecture decision records](docs/adr/).

## Development setup

Requirements: Node.js 20+, PHP 8.2+, Composer.

```bash
git clone https://github.com/joe7ean/calendarbridge.git
cd calendarbridge
composer install    # dev only: OCP stubs, PHPUnit
npm ci
npm run build       # js/ is a build artifact and not committed
composer test
```

`make deploy` rsyncs the app to a test instance (`NC_HOST`, `NC_APPS_DIR` in the
`Makefile`, override on the command line). `scripts/setup-build-env.sh` prepares an
Ubuntu-based workstation for `make release`.

## Pull requests

- Conventional Commits, one logical change per commit.
- Tests for every bug fix (a failing test first, if possible).
- Changelog entry under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
- An ADR for decisions about architecture, data model or behaviour.

## Versioning

The app follows [Semantic Versioning](https://semver.org/). While in `0.x`:

| Change | Bump | Example |
|---|---|---|
| Bug fix, no database migration, no behaviour change for admins | patch | 0.2.0 → 0.2.1 |
| New feature, database migration, changed default or behaviour | minor | 0.2.1 → 0.3.0 |
| First App Store release | major | → 1.0.0 |

From 1.0.0 on, a breaking change for admins (removed setting, required manual step,
dropped Nextcloud major) is a major bump.

The version lives in three places that must match: `appinfo/info.xml` (authoritative),
`package.json` and the newest released heading in `CHANGELOG.md`. `make version-check`
verifies this.

## Supported Nextcloud versions

At least the last four Nextcloud majors, with Nextcloud 32 as the floor. The floor is
only raised when a breaking change forces it ([ADR 0011](docs/adr/0011-compatibility-window.md)).
`min-version`/`max-version` in `info.xml` must only cover versions that passed the tests.

## Release checklist

Every release goes through three stages ([ADR 0013](docs/adr/0013-staged-rollout.md)).

**Stage 1 – test instance**

1. `composer test` is green.
2. `make deploy` (or the tarball), then `make e2e` → `ALL SCENARIOS PASSED`.
3. Check the Nextcloud log for warnings or errors from `calendarbridge`.

**Stage 2 – production instance, test scope**

4. Set the version to `x.y.z-beta.N` in `info.xml` and `package.json`, build with
   `make release`, and tag `vx.y.z-beta.N`.
5. Install it on the production instance. Only dedicated test feeds and a test group of
   informed users may be used. Keep it running for at least one full sync cycle of every
   test feed (≥ 24 h if sync or quarantine logic changed).

**Stage 3 – final**

6. Move the `[Unreleased]` entries to a new `## [x.y.z] - YYYY-MM-DD` section.
7. Set the final version, run `make version-check`, and check `min-`/`max-version`.
8. Commit `chore(release): x.y.z`, tag `vx.y.z`, push the branch and the tag.
9. `make release` → `dist/calendarbridge-x.y.z.tar.gz`; attach it to the GitHub release.
10. Update the production instance: replace the app directory, do not unpack over it
    (see README). Watch the log for a day.
