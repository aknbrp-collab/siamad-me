# SIAKAD Mahad Aly — Operations

How to run SIAKAD on a server: the scheduler, backups and restoring them. For the design see
[`DESIGN.md`](DESIGN.md); for the state of the project see [`HANDOVER.md`](HANDOVER.md).
For a demo on another Windows computer, opened from devices on the same network, see
[LOCAL-DEMO.md](LOCAL-DEMO.md).

---

Server sizes (demo and production, minimum / ideal / maximum): see [SERVER-REQUIREMENTS.md](SERVER-REQUIREMENTS.md).

## 1. First installation

On a VPS (Ubuntu 24.04) use the scripts in [`deploy/`](../deploy/README.md): one-time server setup, zero-downtime
deploys, nginx, HTTPS and the queue worker, step by step. The commands below are what they do, for other servers.

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env            # then fill in APP_URL, DB_*, MAIL_* (see below)
php artisan key:generate
php artisan migrate --force
php artisan siakad:superadmin    # the first login; everything else is created in the app
php artisan config:cache && php artisan route:cache && php artisan view:cache
php -d memory_limit=1G artisan scramble:cache
```

Required `.env` values in production: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`,
`APP_LOCALE=id`, `APP_TIMEZONE=Asia/Jakarta` (times shown and the 02:00 backup follow it; the default is UTC), `DB_CONNECTION=pgsql` with `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`. `MAIL_*` is
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

**Uploads need a writable PHP temporary folder (v7).** PHP stores every uploaded file in its temporary folder
before SIAKAD reads it. If it cannot, nothing arrives and the Superadmin dashboard shows *Unggah berkas tidak
berfungsi di server ini* with the reason. `php artisan serve` on Windows used to start PHP without `TEMP`, so
PHP tried `C:\Windows`; SIAKAD now passes `TEMP`/`TMP`/`TMPDIR` on (restart `artisan serve` after updating).
Elsewhere set `upload_tmp_dir` in `php.ini` to a folder the web server can write, and keep
`upload_max_filesize` / `post_max_size` at least the upload limit (`SIAKAD_UPLOAD_MAX_KB`, 10 MB by default).

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
`php -d memory_limit=1G artisan scramble:export` and commit `docs/api/openapi.json`; see [API.md](API.md). Building
the document takes more memory than a normal request, so after every update also run
`php -d memory_limit=1G artisan scramble:cache`: `/docs/api` then serves the prepared document instead of
building it on each visit.

**Push to the mobile app (v6 Step 12).** Off until a Firebase project is set up:
1. In the Firebase console, create a project (or use the app developer's) and add the Android and iOS apps
   there; the app developer builds the app with that project's files.
2. Project settings → Service accounts → *Generate new private key*. Copy the JSON file to the server
   **outside** `public/` and outside the git checkout (e.g. `/etc/siakad/firebase.json`), readable by the web
   server user only.
3. In `.env`: `FIREBASE_CREDENTIALS=/etc/siakad/firebase.json`, then `php artisan config:cache`.
`GET /api/v1/app` then reports `push_enabled: true`, and every notification is also sent to each signed-in
device that registered its push token. Devices Firebase no longer knows are forgotten automatically. Keep
the key file secret like the `.env`; to revoke it, delete the key in the Firebase console and add a new one.

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

**From the web (data-manage D50).** Superadmin → *Data & cadangan* lists every backup (daily and made by
hand) with its time, who made it, the number of records and the size; *Buat cadangan sekarang* makes one
after the page answers; each backup downloads as one `.zip` (`database.dump`, `files.zip`,
`manifest.json`). Backups made by hand are not removed by the 30-day clean-up; delete them on that page.
Every backup now has a `manifest.json` (time, kind, who, last migration, rows per table, file checksums).
Downloads and deletions are in the audit log.

Run one by hand at any time (e.g. before an upgrade):

```bash
php artisan siakad:backup
```

## 4. Restoring

**From the web (data-manage D51).** Superadmin → *Data & cadangan* → *Pulihkan* next to a backup, or upload a
backup `.zip` under *Pulihkan dari berkas* first (up to `SIAKAD_RESTORE_MAX_MB`, 512 MB by default, and PHP's
`upload_max_filesize` / `post_max_size`; copy a larger backup folder into the backups folder instead). SIAKAD
checks the backup (complete, checksums, not from a newer SIAKAD), asks for the password and `PULIHKAN`, then
runs `php artisan siakad:restore <backup>` in its own process:
1. a backup of the current state (named on the page if anything fails);
2. maintenance mode (`artisan down`);
3. the database: tables the backup does not have are dropped, then `pg_restore --clean --if-exists
   --single-transaction` (all or nothing);
4. the private files: unpacked next to `storage/app/private` and swapped in;
5. `migrate --force`, sessions and queued jobs emptied (everyone signs in again), cache cleared, `artisan up`.
The page follows it through `/restore-status/{token}`, which works during maintenance. `pg_restore` is
found next to `BACKUP_PG_DUMP` or at `BACKUP_PG_RESTORE`. The same command works on the server, also into a
new, empty database (a new server): `php artisan siakad:restore 2026-11-08_020000`.

Checked on 2026-11-08 on PostgreSQL 17 with a scratch database: after restoring, every table had the backup's
row count (plus the restore's own audit entry, and no sessions); a table from a newer migration was
dropped and missing rows came back.

**By hand.** Restore into an **empty** database. Never restore over the live database without a fresh backup of it.

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

## 4a. Deleting data (data-manage D52)

Superadmin → *Data & cadangan* → *Hapus data*: tick groups (Akademik, Keuangan, Surat, Artikel & diskusi, Data
orang, Data dasar, Akun staf, Notifikasi & log); groups other data depends on are ticked with them (Data orang
needs Akademik, Keuangan, Surat and Artikel; Data dasar needs those and Data orang). After the password and
`HAPUS DATA`, `php artisan siakad:wipe <groups> --by=<id>` runs in its own process: a backup of the current
state first (if it fails, nothing is deleted), then one database transaction, then the files of the deleted
rows. Superadmin accounts, settings, identity, appearance, letter and receipt numbering, and the backups
always stay; records made by deleted staff accounts stay under the Superadmin who deleted. One audit entry
(`data.deleted`) records the groups and counts. To undo it, restore the backup named on the page.

## 5. Updating the application

```bash
php artisan siakad:backup
php artisan down
git pull
composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php -d memory_limit=1G artisan scramble:cache
php artisan up
```
