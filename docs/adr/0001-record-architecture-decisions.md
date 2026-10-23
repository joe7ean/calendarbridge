# 0001. Record architecture decisions

- Status: Accepted
- Date: 2026-10-24

## Context

The first versions were written largely by coding agents from a single implementation
plan. Several decisions only lived in that plan or in commit messages, and some drifted
from it (see [0006](0006-auto-pause-after-repeated-errors.md)). Agents tend to "repair"
deliberate trade-offs when they cannot see the reason behind them.

## Decision

Keep architecture decision records in `docs/adr/`, linked from `AGENTS.md`. Every change
to architecture, data model, externally visible behaviour or a deliberate trade-off gets
an ADR in the same pull request.

## Consequences

Decisions are reviewable next to the code. ADRs 0002–0010 were written retroactively on
2026-10-24 and describe the state as of version 0.2.1.
