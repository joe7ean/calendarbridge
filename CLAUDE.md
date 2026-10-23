@AGENTS.md

## Claude Code specifics

- Host is Windows: no PHP, Composer or `make` on PATH. Run PHP tests via Podman (see
  AGENTS.md → Commands) from PowerShell — Git Bash rewrites `/app` paths. If the Podman
  machine is stopped: `podman machine start`; use `--network=host` if netavark fails.
- The test Nextcloud lives in the LAN (Proxmox), see `NC_HOST` in the `Makefile`.
- The user communicates in German; repository content stays English.
