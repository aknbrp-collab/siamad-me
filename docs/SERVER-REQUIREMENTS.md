# Server requirements

Two setups: a **demo** server for showing SIAKAD with the demo data, and a **production** server for one
Ma'had Aly with **15 muhadhir and about 500 mahasantri**. Each has a minimum, an ideal and a maximum.
- **Minimum:** it runs, but busy moments are slow and there is little room to grow.
- **Ideal:** comfortable for the expected use, with room for a few years. This is the recommendation.
- **Maximum:** the point beyond which more hardware gives no visible benefit at this size. Spend more only
  for availability (a second server), not for speed.

How to install and run it is in [OPERATIONS.md](OPERATIONS.md).

## Software (both setups)

| | Version | Notes |
| --- | --- | --- |
| OS | Ubuntu Server 24.04 LTS (or Debian 12) | Windows Server works too, but Linux is easier to run and cheaper |
| PHP | **8.4** (8.3 at least), PHP-FPM | Extensions: `pdo_pgsql`, `mbstring`, `intl`, `gd`, `zip`, `bcmath`, `exif`, `sodium`, `openssl`, `curl`, `fileinfo`, `xml`, `dom`; OPcache on |
| Web server | Nginx 1.24+ (or Apache 2.4 with PHP-FPM) | HTTPS is required: Web Push, passkeys and the CMS signatures need it |
| Database | **PostgreSQL 17** (16 at least) | `pg_dump` and `pg_restore` of the same version on the server, for backups and restoring from the web |
| Composer | 2.x | To install PHP packages |
| Node.js | 22 LTS | Only to build the CSS/JS (`npm run build`); can be done on another machine |
| Cron | `* * * * * php artisan schedule:run` | Backups, reminders, the queue (CMS calls) and the daily CMS check |
| LibreOffice | 7.6+ (optional) | Only to turn letters and printouts made from an uploaded Word template into PDF; without it they download as Word |
| Time sync | NTP (chrony / systemd-timesyncd) | Signed calls to and from the CMS are refused when clocks differ by more than 5 minutes |
| Email | Any SMTP account | Password-reset emails |

PHP settings: `upload_max_filesize = 12M`, `post_max_size = 16M` (uploads allow 10 MB; to upload backups for
restoring from the web, raise both and Nginx's `client_max_body_size` to the backup size, up to
`SIAKAD_RESTORE_MAX_MB`, or copy larger backups onto the server instead),
`memory_limit = 256M` (512M if large Excel imports are common), `max_execution_time = 60`.
Nginx: `client_max_body_size 16m`.

Backups, restoring and deleting data from the Superadmin's *Data & cadangan* page run `php artisan …` in a
process of their own: the PHP command line must be installed next to PHP-FPM, `exec` / `popen` must not be in
`disable_functions`, and the web server user must be able to write the backups folder and `storage/app`.
A restore needs free space for a second copy of the files and a fresh backup of the current state.

Outgoing HTTPS must be allowed to the CMS, the browser push services (Google, Mozilla, Apple) and the
email server. Incoming HTTPS is needed from the CMS (for Tanya Hukum) and from users.

## Demo server

A handful of people trying SIAKAD at once, demo data only. Everything (web, PHP, PostgreSQL) on one small
server.

| | Minimum | Ideal | Maximum |
| --- | --- | --- | --- |
| CPU | 1 vCPU | 2 vCPU | 2 vCPU |
| RAM | 1 GB + 2 GB swap | 2 GB | 4 GB (only with LibreOffice) |
| Disk | 20 GB SSD | 40 GB SSD | 60 GB SSD |
| Network | any VPS link | any VPS link | any VPS link |
| PHP-FPM workers (`pm.max_children`) | 4 | 8 | 10 |
| PostgreSQL `shared_buffers` | 128 MB | 256 MB | 512 MB |
| Typical cost | smallest VPS | small VPS | small VPS |

**Notes:**
- With 1 GB of RAM, building the assets (`npm run build`) may fail. Build them on your own PC and upload
  `public/build`.
- LibreOffice needs about 300–500 MB of RAM while it turns a letter into PDF. Leave it out on a 1–2 GB demo
  server unless you want to show the Word-template letters as PDF.
- Backups of the demo data are small; set `BACKUP_RETENTION_DAYS=7`.

## Production server (15 muhadhir, ~500 mahasantri)

### What the load looks like

**Accounts:** about 15 muhadhir, 500 mahasantri, up to 500 wali santri and 5–10 staff, so roughly
**1,000–1,100 accounts** in total.

**Normal days:** 30–60 people use SIAKAD at the same time.

**Busy moments:** grade release, the exam weeks (exam cards), payment deadlines, and a whole class taking a
timed quiz together. These reach **150–250 people at once**.

**What costs the most is the chat:** an open bimbingan, LPPPK or Tanya Hukum room asks the server for new
messages every 5 seconds. With 100 rooms open at once that is about 20 requests per second. Each request is
small (about 50–100 ms of PHP), but it sets how many PHP workers you need.

**Storage grows with uploaded files, not with the database:**

| What | Estimate |
| --- | --- |
| Database (grades, attendance, bills, discussions, audit log) | 0.5–1 GB in the first year, 2–4 GB after five years |
| Personal documents (KTP, KK, ijazah) | about 3–5 GB, once |
| Class materials, assignments, discussion files, teaching documents, letters | about 10–30 GB per year |
| **Backups** | Each night's backup is a **full copy** of the database and all files, kept 30 days (`BACKUP_RETENTION_DAYS`). Space needed ≈ 30 × (database + files). With 20 GB of files that is about 600 GB. |

Keep backups on a **separate disk or storage** (`BACKUP_PATH`), not the system disk. You can also lower the
retention, for example 14 days plus a weekly copy kept off site.

### Specification

| | Minimum | Ideal | Maximum |
| --- | --- | --- | --- |
| CPU | 2 vCPU | 4 vCPU | 8 vCPU |
| RAM | 4 GB | 8 GB | 16 GB |
| System disk | 80 GB SSD | 160 GB NVMe SSD | 320 GB NVMe SSD |
| Backup storage (separate) | 300 GB | 1 TB | 2 TB, or object storage |
| Network | 50 Mbit/s | 100 Mbit/s | 1 Gbit/s |
| Monthly traffic | 300 GB | 1 TB | 2 TB |
| Layout | one server (web + PHP + PostgreSQL) | one server | web server + separate PostgreSQL server |
| PHP-FPM workers (`pm.max_children`) | 10 | 24 | 48 |
| PostgreSQL `shared_buffers` / `effective_cache_size` | 512 MB / 1.5 GB | 2 GB / 5 GB | 4 GB / 10 GB |
| Queue worker | the scheduler's minute worker | a permanent worker under Supervisor | a permanent worker under Supervisor |
| LibreOffice | leave out (letters as Word) | installed | installed |

**What to expect:**
- **Minimum:** works for normal days. During a busy moment pages slow down, and a class-wide quiz or many
  open chat rooms can make requests wait for a free PHP worker. Fine for a first semester; plan to upgrade.
- **Ideal (recommended):** handles the busy moments with room left. It has enough memory to keep the whole
  database in cache for years, runs LibreOffice for letters, and leaves headroom for growth (more classes,
  a second study program).
- **Maximum:** splitting the database onto its own server and doubling the CPU only pays off if SIAKAD grows
  to several thousand mahasantri, or if many more tools run on the same machine. At 500 mahasantri it
  gives no visible speed-up.

**Availability:**
- **Uptime:** for anything higher than one server allows, add a second web server behind a load balancer
  and a managed or replicated PostgreSQL. This is not needed at this size.
- **Snapshots:** daily snapshots of the server at the hosting provider are a cheap extra safety net next
  to SIAKAD's own backups.

### Checklist before going live

- HTTPS certificate (e.g. Let's Encrypt), `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` set to the
  real address.
- `php artisan config:cache`, `route:cache`, `view:cache` after every update (OPERATIONS.md §5).
- Cron for the scheduler (§2). Supervisor for the queue worker, if used.
- `BACKUP_PATH` on the separate storage; a test restore once (OPERATIONS.md §4).
- PHP and Nginx upload limits as above.
- NTP running; the CMS values in `.env` once the CMS team has given them.
- `php artisan siakad:vapid` once, for Web Push, and keep the keys.
