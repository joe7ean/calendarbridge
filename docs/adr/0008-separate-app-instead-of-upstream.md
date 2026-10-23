# 0008. Separate app instead of an upstream change (for now)

- Status: Accepted
- Date: 2026-10-24

## Context

The ideal solution would be built into Nextcloud: "share a subscription with groups".
Status as of 2026-10:

- [nextcloud/server#1600](https://github.com/nextcloud/server/issues/1600) ("Allow
  (internal) sharing of subscribed webcal calendars") has been open since 2016. It is
  labelled "to develop" and has no assignee, milestone or pull request.
  nextcloud/calendar#3909 and #2590 ask for the same thing and point to server.
- The feature belongs in `apps/dav` (subscriptions, `RefreshWebcalJob`, the sharing
  backend), not in the Calendar app, which is only the frontend.
- Federated calendar sharing (read-only in Nextcloud 32, read/write in 33) shares with
  remote *users*, not groups, and needs Nextcloud on both sides. It does not solve
  distribution to local groups.

## Decision

Calendar Bridge stays a separate app and targets the App Store. In parallel, we post a
concrete design in #1600, based on what we learned here. `FeedService` and
`CalendarService` stay free of UI concerns, so the logic could move upstream later.
A Nextcloud-specific source adapter (CalDAV `sync-collection` instead of full ICS
downloads) is optional and comes after 1.0.

## Consequences

Admins get a working solution now, for Nextcloud 30 and later. In exchange we have to
track internal tables (`dav_shares`, `calendarobjects`) across Nextcloud majors. If
upstream ships the feature, the app gets a migration path and is deprecated.
