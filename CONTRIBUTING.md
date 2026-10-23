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

## Release checklist

1. Move the `[Unreleased]` entries to a new `## [x.y.z] - YYYY-MM-DD` section.
2. Bump `appinfo/info.xml` and `package.json`; run `make version-check`.
3. Check `max-version` in `info.xml` against the Nextcloud versions you actually tested.
4. Commit `chore(release): x.y.z`, tag `vx.y.z`, push branch and tag.
5. `make release` → `dist/calendarbridge-x.y.z.tar.gz`; attach it to the GitHub release.
6. Update installation: replace the app directory, do not unpack over it (see README).
