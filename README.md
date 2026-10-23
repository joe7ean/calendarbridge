# Calendar Bridge

A Nextcloud app that allows administrators to subscribe to external iCal/WebCal feeds and mirror them internally as **relay calendars**. These calendars are automatically shared **read-only** with Nextcloud groups, eliminating the need for individual users to subscribe to the feeds themselves.

## Project Status

Calendar Bridge is functionally complete and under active maintenance. The `0.x`
version number reflects limited exposure to different setups so far, not an
unfinished feature set.
It is **not yet available** in the official Nextcloud App Store.
Publishing to the official Nextcloud App Store is the target.

Contributions, optimizations, and feedback are highly welcome!

## Features

- **Centralized Management:** Subscribe to external iCal/WebCal feeds once and mirror them internally.
- **Automated Sync:** Background synchronization via Nextcloud cron.
- **Group Sharing:** Automatic read-only sharing with any Nextcloud groups.
- **Efficient:** Diff-based sync and ETag/Last-Modified support prevent redundant downloads.
- **Privacy:** Optional stripping of `ATTENDEE`/`ORGANIZER` email addresses before storage.
- **Security:** Support for HTTP Basic Auth and strict SSRF protections.

## Requirements

- Nextcloud 30 – 34
- PHP 8.2+ (8.5 recommended on Nextcloud 34)
- Reliable Nextcloud cron (systemd timer or crontab recommended)
- DAV app enabled

## Installation

### Nextcloud App Store

Once published: **Apps → Organization → Calendar Bridge → Enable**.

### Manual Installation

Two things to know before you start:

- `js/` is a build artifact and is not committed. A bare `git clone` into `apps/`
  therefore yields an app whose admin panel stays empty — the assets must be built.
- Composer is **not** required at runtime. The app has no PHP dependencies beyond
  Nextcloud itself; `composer install` only pulls in dev tooling (OCP stubs, PHPUnit),
  and Nextcloud maps `OCA\CalendarBridge\` to `lib/` on its own.

#### Variant A: build on the server (needs Node.js 20+)

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/joe7ean/calendarbridge.git calendarbridge
cd calendarbridge
npm ci && npm run build
chown -R www-data:www-data .
```

#### Variant B: build on a workstation, ship a tarball (recommended for production)

On the workstation:

```bash
make release                                    # → dist/calendarbridge-<version>.tar.gz
scp dist/calendarbridge-*.tar.gz root@server:/tmp/
```

On the server:

```bash
cd /var/www/nextcloud/apps                      # adjust to your apps directory
tar -xzf /tmp/calendarbridge-*.tar.gz           # creates apps/calendarbridge/
chown -R www-data:www-data calendarbridge
```

Then enable the app in the Nextcloud interface or with `occ app:enable calendarbridge`.

Note that the file ownership above (`www-data`) and the location of the `apps`
directory depend on the distribution or hosting stack. Use whatever user your
Nextcloud installation already runs as.

### Updating

No state is kept in the app directory — feeds, relay calendars, and settings live in the
database — so replace the directory instead of unpacking over it, which would leave
files of the previous version behind:

```bash
occ app:disable calendarbridge
rm -rf apps/calendarbridge
# unpack the new tarball and fix ownership as above
occ app:enable calendarbridge
```

Database migrations are applied when the app is enabled. If a release ships one and the
instance ends up in a half-migrated state, `occ upgrade` finishes the job.

### Before a Nextcloud major upgrade

Calendar Bridge is not in the Nextcloud App Store yet, so upgrade tooling cannot look
it up. Some of it — YunoHost's `yunohost app upgrade nextcloud`, for instance — refuses
to run a major upgrade while an app that is missing from the target version's catalog
is enabled, regardless of whether that app would actually work. Disable the app first:

```bash
occ app:disable calendarbridge
# ... perform the Nextcloud major upgrade ...
occ app:enable calendarbridge
```

Upgrade paths generally leave third-party directories in `apps/` alone, but verify it
afterwards — if `apps/calendarbridge/` is gone, unpack the tarball again before
enabling. Also check that `max-version` in `appinfo/info.xml` covers the new Nextcloud
release.

## Setup

`occ` must run as the user that owns the Nextcloud installation, typically
`sudo -u www-data php occ …`.

### 1. Create a Service User

The app requires a dedicated Nextcloud user to own the relay calendars. This account should not be used for regular logins.

```bash
# Create the user
php occ user:add --display-name="Calendar Bridge Service" calbridge-service

# Secure the login (set a random password)
RNDPW=$(openssl rand -base64 48)
OC_PASS="$RNDPW" php occ user:resetpassword --password-from-env calbridge-service
unset RNDPW OC_PASS
```

`sudo` drops the environment, so when going through it, hand `OC_PASS` over with `env`:

```bash
sudo -u www-data env OC_PASS="$(openssl rand -base64 48)" \
    php occ user:add --password-from-env \
    --display-name="Calendar Bridge Service" calbridge-service
```

### 2. Configure the App

Go to **Settings → Administration → Calendar Bridge**:

- **Default service user:** Enter the newly created user (e.g., `calbridge-service`).
- Adjust other global settings like the sync interval as needed.

### 3. Add a Feed

In the admin panel under **Add feed**:

- Enter the URL (`https://...` or `webcal://...`).
- Set the name, color, and target groups.


### Screenshot

The Calendar Bridge Admin Panel UI:

![Calendar Bridge Admin Panel](screenshots/admin-panel.png)

## Known limitations

- Only `VEVENT`s are mirrored; tasks (`VTODO`) and journals are ignored.
- A feed that is empty is not applied: existing events stay until the feed delivers events
  again (see [ADR 0005](docs/adr/0005-empty-feed-protection.md)).
- Group shares are restored on every sync. Members can hide the calendar for themselves
  with "Unshare from me".
- Sharing a subscription natively is not possible in Nextcloud
  ([nextcloud/server#1600](https://github.com/nextcloud/server/issues/1600)); this app is
  the workaround until that changes ([ADR 0008](docs/adr/0008-separate-app-instead-of-upstream.md)).

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md), [AGENTS.md](AGENTS.md) and the
[architecture decision records](docs/adr/).

## License

[AGPL-3.0-only](LICENSE)
