# 0004. Strip ATTENDEE/ORGANIZER by default

- Status: Accepted
- Date: 2026-10-24 (retroactive, decided 2026-04)

## Context

External feeds often contain the e-mail addresses of attendees and organisers. Mirrored
calendars are visible to whole groups, so this personal data would spread further than it
does in the source.

## Decision

`strip_attendees` is on by default, both as the global default and per feed. When it is
on, `ATTENDEE` and `ORGANIZER` are removed before the event is stored.

## Consequences

Privacy by design (GDPR data minimisation). Admins who need the attendee list can opt out
per feed. Toggling the option rewrites all objects on the next sync, because the content
hash changes.
