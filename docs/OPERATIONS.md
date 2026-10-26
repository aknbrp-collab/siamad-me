# SIAKAD Mahad Aly — Operations

How to run SIAKAD on a server: the scheduler, backups and restoring them. For the design see
[`DESIGN.md`](DESIGN.md); for the state of the project see [`HANDOVER.md`](HANDOVER.md).

---

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

Notifications themselves need no queue worker: they are written to the database while the request runs.
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
