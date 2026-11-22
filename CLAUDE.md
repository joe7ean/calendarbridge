@AGENTS.md

## Claude Code specifics

- Host is WSL2 (Ubuntu) with native PHP, Composer, `make`, `rsync`, Node and rootless
  Podman, so the commands in AGENTS.md run directly. Keep the clone on the Linux
  filesystem, not under `/mnt/c`. `make e2e-matrix` starts Nextcloud containers via Podman.
- The test Nextcloud lives in the LAN (Proxmox), see `NC_HOST` in the `Makefile`.
- The user communicates in German; repository content stays English.
