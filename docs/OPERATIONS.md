# SIAKAD Mahad Aly — Operations

How to run SIAKAD on a server: the scheduler, backups and restoring them. For the design see
[`DESIGN.md`](DESIGN.md); for the state of the project see [`HANDOVER.md`](HANDOVER.md).

---

Server sizes (demo and production, minimum / ideal / maximum): see [SERVER-REQUIREMENTS.md](SERVER-REQUIREMENTS.md).

## 1. First installation

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env            # then fill in APP_URL, DB_*, MAIL_* (see below)
php artisan key:generate
php artisan migrate --force
php artisan siakad:superadmin    # the first login; everything else is created in the app
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Required `.env` values in production: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`,
`APP_LOCALE=id`, `DB_CONNECTION=pgsql` with `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`. `MAIL_*` is
only needed for users who reset their own password by email; everyone else gets a temporary password
from the academic office.

**Web Push (pop-up notifications on computers and phones).** Run `php artisan siakad:vapid` **once**
and put the three printed lines (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` with a real
`mailto:` address) in `.env`, then `php artisan config:cache`. Keep the pair (it is part of the `.env`
backup): a new pair silently stops pop-ups on every device that turned them on, and people must turn
them on again. Browsers allow push only over **HTTPS** (localhost is the exception), so serve SIAKAD
with a TLS certificate (e.g. Let's Encrypt). The server must be able to reach the push services on the
internet (Google, Mozilla, Apple, Microsoft) over HTTPS. Without keys, notifications stay in the bell.
On iPhone/iPad, pop-ups work only after SIAKAD is added to the Home Screen (iOS 16.4+).

**Windows servers / development:** PHP's OpenSSL needs a configuration file to make the keys used by
Web Push. If `siakad:vapid` says *Unable to create the key*, set the environment variable
`OPENSSL_CONF` to PHP's `openssl.cnf` (with Herd: `%USERPROFILE%\.config\herd\bin\php84\extras\ssl\openssl.cnf`)
for the web server and the scheduler, and restart them. Linux servers need nothing.

## 2. Scheduler (required for backups)

Laravel runs scheduled tasks from one cron entry. Add it for the web server user (`crontab -e`):

```
* * * * * cd /path/to/siakad && php artisan schedule:run >> /dev/null 2>&1
```

On Windows use Task Scheduler: every minute, `php artisan schedule:run` in the project folder.
Check what is scheduled with `php artisan schedule:list`. Scheduled jobs:

| Job | When | What |
| --- | --- | --- |
| `siakad:backup` | daily 02:00 | database + private files (section 3) |
| `siakad:send-reminders` | hourly | in-app reminders: work due within 24 h, bills due in 7 days / today (each once per person) |
| `siakad:cms-check` | daily 06:00 | asks the website CMS for the review status and link of every article sent (v5) |
| `queue:work --stop-when-empty` | every minute | works the queue: calls to the CMS and their retries (v5) |

**Queue (v5).** Calls to the website CMS run on the database queue and are retried (1, 5, 15, 60 minutes)
when the CMS is busy or down. The scheduler starts a worker every minute, so nothing else is needed. On a
server with Supervisor a permanent worker is better (then the scheduled one finds nothing to do):

```
[program:siakad-queue]
command=php /path/to/siakad/artisan queue:work --sleep=3 --tries=5 --max-time=3600
autostart=true
autorestart=true
user=www-data
```

**CMS integration (v5).** In `.env`: `CMS_WEBHOOK_URL` (e.g. `https://admin.pondok.example/api/webhooks/siakad`),
`CMS_WEBHOOK_SECRET` (the same value as `SIAKAD_WEBHOOK_SECRET` in the CMS, 32+ random characters,
`openssl rand -hex 32`), `SIAKAD_API_KEY` (the Bearer token the CMS sends to SIAKAD) and `CMS_ARTICLE_TYPE`
(`article`; `research` until the CMS knows the article type). Keep the server clock in sync (NTP): signed
calls older than 5 minutes are refused. Superadmin → **Integrasi CMS** checks the settings, tests the
connection and lists every call.

The CMS forwards Tanya Hukum questions to `POST {APP_URL}/api/cms/legal-questions`; in the CMS `.env`
set `SIAKAD_DRIVER=http` and `SIAKAD_BASE_URL={APP_URL}/api/cms`. SIAKAD checks the Bearer key
(`SIAKAD_API_KEY`), the signature and the clock, accepts answers only for callbacks on the CMS host, and
rate-limits the endpoint to 60 calls a minute.

**Mobile app API (v6).** The API lives at `{APP_URL}/api/v1` and needs HTTPS. In `.env`:
`SANCTUM_TOKEN_DAYS` (default 365: a device not used that long must log in again; expired tokens are removed
daily by the scheduler) and `API_DOCS_PUBLIC` (default `false`: the API documentation at `/docs/api` is
for Superadmin only; set `true` while a mobile developer builds the app). The documentation page loads its
viewer from unpkg.com, so the browser opening it needs internet access. After changing the API, run
`php artisan scramble:export` and commit `docs/api/openapi.json`; see [API.md](API.md).

Notifications themselves need no queue worker: they are written to the database while the request runs,
and Web Push pop-ups are sent right after the response (a slow push service never delays a page).
Set `APP_URL` to the address people open; notification links are stored as paths, so a wrong
`APP_URL` does not break them, but password-reset emails still use it.

## 3. Backups

`php artisan siakad:backup` runs **every day at 02:00** (`BACKUP_TIME`). Each run writes one folder:

```
storage/app/backups/2026-10-30_020000/
    database.dump    pg_dump, custom format (compressed)
    files.zip        everything in storage/app/private: photos, KTP/KK/Ijazah, payment proofs,
                     materials, submissions
```

- Folders older than **30 days** (`BACKUP_RETENTION_DAYS`) are deleted after each successful run.
- A failed run deletes its half-written folder, exits with an error and writes `SIAKAD backup failed`
  to `storage/logs/laravel.log`.
- `pg_dump` must be on the PATH or set `BACKUP_PG_DUMP` to its full path
  (Windows: `C:\Program Files\PostgreSQL\17\bin\pg_dump.exe`). Use the `pg_dump` of the same major
  version as the server, or newer.

> ⚠️ **A backup on the same disk does not survive a disk failure.** Set `BACKUP_PATH` to another disk
> or a mounted network share, or copy `storage/app/backups` off the server every day (rsync, rclone to
> cloud storage, or an external drive). Test a restore at least once per semester.

Run one by hand at any time (e.g. before an upgrade):

```bash
php artisan siakad:backup
```

## 4. Restoring

Restore into an **empty** database. Never restore over the live database without a fresh backup of it.

```bash
# 1. Database (replace values; PGPASSWORD avoids the prompt)
createdb -h 127.0.0.1 -U postgres siakad_restored
pg_restore -h 127.0.0.1 -U postgres --no-owner -d siakad_restored storage/app/backups/<folder>/database.dump

# 2. Private files
unzip storage/app/backups/<folder>/files.zip -d storage/app/private

# 3. Point DB_DATABASE in .env at siakad_restored, then
php artisan config:clear
```

`pg_restore --list database.dump` shows what a dump contains without restoring it. This procedure was
tested on 2026-10-26: a dump restored into a scratch database had the same row counts as the source.

## 5. Updating the application

```bash
php artisan siakad:backup
php artisan down
git pull
composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```
