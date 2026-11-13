# 0017. Publish via the Nextcloud App Store

- Status: Proposed
- Date: 2026-11-13

## Context

Calendar Bridge is distributed as a GitHub release tarball that is unpacked by hand
([README](../../README.md)). That works for instances with shell access, such as the
YunoHost production instance, but not for managed Nextcloud offerings (for example Hetzner
Storage Share): there is no SSH, `occ` is limited to a vendor allow-list, and apps can
only be installed from the App Store. It also blocks upgrade tooling that refuses a major
Nextcloud upgrade while an enabled app is missing from the target version's catalog.

The App Store requires a registered app id, a developer certificate issued through
[nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests),
and a release archive carrying `appinfo/signature.json`, created with
`occ integrity:sign-app`. The signing key is the app's identity: whoever holds it can ship
code to every installation.

## Decision

From 1.0.0 on, the App Store is the primary distribution channel. GitHub releases remain
the channel for pre-releases ([ADR 0013](0013-staged-rollout.md)) and a mirror of every
store release.

- **Key custody.** The private key stays with the maintainer, offline and backed up. It is
  never committed, never stored in CI, and never handled by coding agents.
- **Release flow.** CI builds and tests an *unsigned* tarball. The maintainer signs it
  locally, verifies it with `occ integrity:check-app` on the test instance, attaches it to
  the GitHub release, and uploads it to the App Store with its signature.
- **Channels.** Betas are uploaded with the App Store's pre-release flag only once the
  staged rollout is done; stable releases follow ADR 0013 unchanged.
- **Prerequisites for 1.0.0.** `img/app.svg` icon, translations via Transifex (German
  first), REUSE/SPDX headers, and a CI workflow (unit tests, build, `make version-check`,
  tarball artifact; e2e matrix when feasible).
- **Versioning.** The first store release is 1.0.0 (see CONTRIBUTING). Releases before it
  stay on GitHub.
- **Existing installation.** The manually installed production app is replaced by the
  store version; settings and tables are untouched because the app id and migrations are
  the same.

## Consequences

Managed hosts and upgrade tooling work, and admins install and update from the UI. The
price is a one-time certificate request, a manual signing step per release, and a
dependency on the App Store review and on one key. Losing the key means a new certificate
request and a changed identity, so the backup is part of the release checklist.
Rejected: shipping an unsigned app or relying on `occ app:install` from a URL, which
managed hosts do not allow.
