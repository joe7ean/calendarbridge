# Calendar Bridge

A Nextcloud app that lets administrators subscribe to external iCal/WebCal feeds once, mirror them internally as **relay calendars**, and share them **read-only** with groups—so members do not have to add the same subscription link themselves.

| | |
|---|---|
| **App ID** | `calendarbridge` (folder name under `apps/`) |
| **Display name** | Calendar Bridge |
| **Category** | Organization / groupware |
| **License** | AGPL-3.0 |
| **Source** | [github.com/joe7ean/calendarbridge](https://github.com/joe7ean/calendarbridge) |

---

## Naming: repository, app ID, and `nc` prefixes

- The **repository** is named `calendarbridge`, which matches how many Nextcloud apps are named: short, descriptive, no mandatory prefix.
- The **app ID** (`calendarbridge` in `appinfo/info.xml`) is Nextcloud’s technical key. Keep it stable once the app is in the store or installed at sites—renaming is painful.
- **`nc` or `nextcloud` in the name?** Official and common community apps often do **not** use that: examples include `calendar`, `contacts`, `deck`, `tables`. An `nc`-style prefix is more common for **GitHub repos** when a generic name is taken, or for forks—it is **not** an App Store requirement. Discoverability comes from the store listing (title and description), not the repo string alone.
- **Earlier working title:** *SubShare* (workspace was *ncSubShare*). The product name **Calendar Bridge** reflects what the app does: bridge an external feed into internal group calendars.

---

## What it does

1. An admin subscribes to an external iCal feed once in the app.
2. The app fetches the feed on a schedule via Nextcloud’s cron.
3. Events are written to an internal **relay calendar** owned by a service user.
4. That calendar is automatically shared **read-only** with the chosen Nextcloud groups.
5. Group members see events in the Nextcloud Calendar app—no per-user setup.

---

## Features

- Subscribe to external iCal/WebCal feeds and mirror them internally
- Background sync via Nextcloud cron (configurable interval, e.g. 15 minutes to 24 hours)
- Read-only sharing with any Nextcloud groups
- Multiple target groups per feed
- Optional HTTP Basic authentication for protected feeds
- ETag / Last-Modified: no redundant download when the feed is unchanged
- Diff-based sync: only changed `VEVENT`s are written
- Admin notifications on persistent fetch failures
- Privacy by default: strip `ATTENDEE` / `ORGANIZER` (email addresses) before storing
- All processing stays on your instance (no middleman service)

---

## Requirements

- Nextcloud **30** through **32** (see `appinfo/info.xml`)
- PHP 8.1+
- Reliable Nextcloud cron (recommended: systemd timer or crontab, not Ajax-only cron)
- **DAV** app enabled (default)

---

## Installation

### App Store (when published)

Once listed in the [Nextcloud App Store](https://apps.nextcloud.com): **Apps → Organization → Calendar Bridge → Enable**.

### Manual install from Git

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/joe7ean/calendarbridge.git calendarbridge
cd calendarbridge
composer install --no-dev
npm ci && npm run build
```

Then enable the app under **Apps**. The install directory **must** be named `calendarbridge` (same as the app ID).

---

## Configuration

### 1. Service user

See [Setting up the service user](#setting-up-the-service-user).

### 2. Global settings

**Settings → Administration → Calendar Bridge**

| Setting | Default | Description |
|---|---|---|
| Default service user | — | User that owns relay calendars |
| Default sync interval | 1 hour | How often feeds are fetched |
| Minimum sync interval | 15 minutes | Lower bound (reduces load on remote servers) |
| Max feed size | 10 MB | Download safety limit |
| HTTPS only | On | Reject plain HTTP feeds (recommended) |
| Strip ATTENDEE fields | On | Do not persist email addresses from the feed |
| HTTP timeout | 30 seconds | |

### 3. Create a feed

Admin panel → **Add feed**

- **Feed URL:** `https://…/remote.php/dav/public-calendars/TOKEN/` or `webcal://…`
- **Display name**, **color**, **target groups** (multi-select)
- **Sync interval**, optional **HTTP auth**, per-feed **strip ATTENDEE**

---

## Testing and manual sync

### Test connection (dry run)

Before saving: fetch without writing to the database or calendar. Shows event count, date range, calendar title from the feed, and encoding or structure issues. Limit: 5 tests per minute per admin.

### Sync now

For existing feeds; useful after creation or URL fixes. Response includes counts of added/updated/deleted events and duration.

**Rate limits:** at most 3 manual syncs in 5 minutes per admin (across all feeds); at least 5 minutes between manual syncs for the same feed—otherwise HTTP 429 with guidance.

---

## Sync behavior

| Scenario | Suggested interval |
|---|---|
| Club calendar, rare changes | 6–24 h |
| Schedules updated weekly | 1–2 h |
| Frequent updates | 30 min–1 h |
| “Real time” | iCal is not push—use something else |

Very short intervals may violate remote providers’ terms; the app enforces a **15 minute** minimum.

**Caching:** on `304 Not Modified`, no full re-download or re-parse.

**Diff:** match by `UID`, compare `LAST-MODIFIED`, delete events removed from the feed from the relay calendar.

---

## Error handling

When fetches keep failing (404, wrong content type, auth errors, …):

- Existing events in the relay calendar are **left unchanged**
- Error counter increases; after **3** consecutive failures: **admin notification**
- Details in the admin panel
- After **10** consecutive failures: feed is **paused**

**Fix:** correct the URL in the admin panel and run **Sync now**.

---

## Duplicates and portability

- **Same service user + same URL:** blocked by a database constraint.
- **Same URL, different service users:** allowed; the UI may warn.
- **One feed, many groups:** one feed entry, multiple target groups (recommended).

**Changing service user:** create a new feed with the new user, wait for sync, then disable/delete the old entry. If the user is deleted in Nextcloud, their calendars go away—migrate feeds first.

---

## Security

- Fetches use Nextcloud’s `IClientService` and `IRemoteHostValidator` (SSRF protections); plain HTTP is off by default.
- Basic-auth passwords are stored encrypted (`ICrypto`) and never returned in API responses.
- Configuration is admin-only (`#[AuthorizedAdminSetting]`); end users only see shared calendars read-only.

---

## Privacy

- By default, `ATTENDEE` / `ORGANIZER` emails are not stored.
- Event content is not used for analytics beyond synchronization.
- Deleting a feed removes its relay calendar.
- Logs use technical metadata only, not event bodies.

---

## Development

Prerequisites: Node.js 20+, PHP 8.1+, Composer, a Nextcloud instance (local or Docker).

```bash
git clone https://github.com/joe7ean/calendarbridge.git
cd calendarbridge

composer install
npm ci
npm run dev      # development build
npm run watch    # watch mode
npm run build    # production build
```

```bash
composer test    # PHPUnit
npm run test     # Jest
```

### Layout

```
calendarbridge/
├── appinfo/
├── lib/                 # AppInfo, BackgroundJob, Controller, Db, Migration, Service
├── src/                 # Vue 3
├── templates/
└── tests/
```

---

## Setting up the service user

Calendar Bridge needs a Nextcloud user as **owner** of relay calendars. Prefer an account nobody logs into as a person—otherwise the Calendar UI shows full controls (e.g. “remove share”) that fight what the app manages automatically.

### Create the account

```bash
php occ user:add \
  --display-name="Calendar Bridge Service" \
  --password-from-env \
  calbridge-service
```

Or interactively:

```bash
php occ user:add --display-name="Calendar Bridge Service" calbridge-service
```

Do **not** run `occ user:disable` on this user—disabled users are unsuitable for CalDAV operations the app must perform.

### Lock down login (keep the account enabled)

Set a random password nobody knows:

```bash
RNDPW=$(openssl rand -base64 48)
OC_PASS="$RNDPW" php occ user:resetpassword --password-from-env calbridge-service
unset RNDPW OC_PASS
```

### Optional hardening

```bash
php occ user:setting calbridge-service files quota "0 B"
```

### In the app

**Settings → Administration → Calendar Bridge:** set **Default service user** to `calbridge-service` (per-feed override is supported).

---

## Support and security

| Topic | Where |
|---|---|
| Bugs & features | [GitHub Issues](https://github.com/joe7ean/calendarbridge/issues) |
| General Nextcloud | [help.nextcloud.com](https://help.nextcloud.com) |
| Vulnerabilities | Do **not** file public issues; use [GitHub Security](https://github.com/joe7ean/calendarbridge/security) for private reporting, or contact the maintainer via GitHub |

---

## Screenshots (App Store)

`screenshots/admin-panel.png` is used by `appinfo/info.xml` for the [Nextcloud App Store](https://apps.nextcloud.com) listing (GitHub `raw` URL on the default branch).

## License

[AGPL-3.0-only](LICENSE)—same family of license as most Nextcloud apps.

---

*Maintainer: [joe7ean](https://github.com/joe7ean)*
