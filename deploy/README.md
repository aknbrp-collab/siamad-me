# Deploying SIAKAD to a VPS

A fresh **Ubuntu 24.04** server, set up once with `server-setup.sh`, then updated with `deploy.sh` (zero
downtime: every deploy is a new folder under `releases/`, and `current` switches to it when it is ready).
For what SIAKAD needs from the server and why, see [SERVER-REQUIREMENTS.md](../docs/SERVER-REQUIREMENTS.md)
and [OPERATIONS.md](../docs/OPERATIONS.md).

| File | What it is |
| --- | --- |
| `server-setup.sh` | One-time setup as root: PHP 8.4, PostgreSQL 17, nginx, Supervisor, certbot, Node.js, Composer, cron, firewall, the app's `.env` |
| `deploy.sh` | Every deploy, as `www-data`: clone, build, back up, migrate, cache, switch, restart |
| `nginx.conf` | The site for nginx (one domain; certbot adds HTTPS) |
| `queue.conf` | The queue worker for Supervisor |

```
/var/www/siakad/
  releases/20261125120000/   one checkout per deploy (the last 5 are kept)
  shared/.env                the settings, kept across releases
  shared/storage/            private files, backups, logs
  current -> releases/...    what nginx, cron and the queue worker use
```

## 0. Before you start

- **A VPS** with Ubuntu 24.04 and root access over SSH. Size: demo 2 GB RAM / 2 vCPU / 40 GB; production
  for ~500 mahasantri 8 GB RAM / 4 vCPU / 160 GB plus separate backup storage (see SERVER-REQUIREMENTS.md).
- **A domain** you can add a DNS record to, e.g. `siakad.mahadaly.ac.id`.
- **This repository** on GitHub (`aknbrp-collab/siamad-me`), with admin rights to add a deploy key.
- On your Windows computer: PowerShell has `ssh` and `scp` built in.

In the commands below replace `203.0.113.10` with your server's IP and `siakad.mahadaly.ac.id` with your domain.

## 1. Point the domain at the server

At your DNS provider add an **A record**: `siakad.mahadaly.ac.id` → `203.0.113.10`. It can take a few
minutes to an hour to work everywhere; HTTPS (step 6) needs it. Check from PowerShell:

```powershell
nslookup siakad.mahadaly.ac.id
```

## 2. Set up the server (once, about 10 minutes)

From PowerShell in the project folder, copy the script to the server and log in:

```powershell
scp deploy/server-setup.sh root@203.0.113.10:
ssh root@203.0.113.10
```

On the server:

```bash
sed -i 's/\r$//' server-setup.sh
DOMAIN=siakad.mahadaly.ac.id bash server-setup.sh
```

Options, before `bash`: `MODE=demo` for a demo server (demo data, 7 days of backups), `LIBREOFFICE=1` to
turn letters made from Word templates into PDF (only with 4 GB RAM or more). Example:
`DOMAIN=demo-siakad.example MODE=demo bash server-setup.sh`.

It ends with a **deploy key** (a line starting `ssh-ed25519`) and the next steps. The database password is
in `/root/siakad-credentials.txt` and already in `/var/www/siakad/shared/.env`. Running it again is safe.

## 3. Give the server read access to GitHub

GitHub → the repository → **Settings → Deploy keys → Add deploy key**: title `siakad server`, paste the
`ssh-ed25519 …` line, leave *Allow write access* off, **Add key**.

Check on the server:

```bash
sudo -u www-data ssh -T git@github.com
```

It answers *"Hi aknbrp-collab/siamad-me! You've successfully authenticated"* (the exit code 1 is normal).

## 4. Check the settings

```bash
sudo -u www-data nano /var/www/siakad/shared/.env
```

Already filled in: `APP_URL`, `APP_KEY`, the database, `APP_TIMEZONE=Asia/Jakarta`, backups. Fill in now
or later:
- `MAIL_*`: an SMTP account, only for password-reset emails (`MAIL_MAILER=smtp`).
- `CMS_*` and `SIAKAD_API_KEY`: when the CMS team gives them (OPERATIONS.md §2).
- `BACKUP_PATH`: a folder on a separate disk for production (step 9).

Save with Ctrl+O, Enter, Ctrl+X. After changing `.env` on a running site: `cd /var/www/siakad/current &&
sudo -u www-data php artisan optimize`.

## 5. First deploy and the first login

```bash
sudo -u www-data git clone --depth 1 git@github.com:aknbrp-collab/siamad-me.git /tmp/siakad
sudo -u www-data bash /tmp/siakad/deploy/deploy.sh main
rm -rf /tmp/siakad
```

It takes a few minutes (Composer, npm build). It warns that `/up` does not answer yet: nginx comes next.

**Production:** create the Superadmin; everything else is set up in the app.

```bash
cd /var/www/siakad/current
sudo -u www-data php artisan siakad:superadmin
```

**Demo server:** load the demo data instead (every password is `password`; logins `superadmin`, `admin`,
`keuangan`, `monitor`, mahasantri `2026110001`):

```bash
cd /var/www/siakad/current
sudo -u www-data php artisan db:seed --force
```

## 6. nginx and HTTPS

```bash
cp /var/www/siakad/current/deploy/nginx.conf /etc/nginx/sites-available/siakad
sed -i 's/siakad\.example/siakad.mahadaly.ac.id/g' /etc/nginx/sites-available/siakad
ln -sf /etc/nginx/sites-available/siakad /etc/nginx/sites-enabled/siakad
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
certbot --nginx -d siakad.mahadaly.ac.id --redirect -m you@example.com --agree-tos -n
```

certbot gets a free Let's Encrypt certificate, adds HTTPS and renews it by itself. Open
`https://siakad.mahadaly.ac.id` and log in.

## 7. Queue worker

```bash
cp /var/www/siakad/current/deploy/queue.conf /etc/supervisor/conf.d/siakad-queue.conf
supervisorctl reread && supervisorctl update
supervisorctl status siakad-queue
```

`RUNNING` is right. The scheduler (cron, set up in step 2) runs backups at 02:00, reminders hourly and the
CMS check daily; list them with `cd /var/www/siakad/current && sudo -u www-data php artisan schedule:list`.

## 8. Web Push keys (pop-up notifications)

Once per server, and keep the keys: a new pair stops pop-ups on every device that turned them on.

```bash
cd /var/www/siakad/current
sudo -u www-data php artisan siakad:vapid
```

Copy the three printed lines over the empty `VAPID_*` lines in `.env` (step 4), set `VAPID_SUBJECT` to a
real `mailto:` address, then `sudo -u www-data php artisan optimize`.

Push to the mobile app (later, optional): put the Firebase key at `/etc/siakad/firebase.json`
(`chown root:www-data`, `chmod 640`), set `FIREBASE_CREDENTIALS=/etc/siakad/firebase.json`, then
`php artisan optimize` (OPERATIONS.md §2).

## 9. Check it works

- Log in as Superadmin: the dashboard shows no *Unggah berkas tidak berfungsi* warning.
- **Sistem → Pengaturan**: the bank accounts, receipt footer, identity and logo.
- **Data & cadangan → Buat cadangan sekarang**: a backup appears a moment later. Download it once.
- `sudo -u www-data php /var/www/siakad/current/artisan about` shows `production`, debug off, caches on.

**Backups off the server (production).** A backup on the same disk does not survive a disk failure. Mount
the separate storage (e.g. a block volume at `/mnt/backup`), then
`mkdir -p /mnt/backup/siakad && chown www-data:www-data /mnt/backup/siakad`, set
`BACKUP_PATH=/mnt/backup/siakad` in `.env` and `php artisan optimize`. Also turn on the provider's server
snapshots. Test a restore once per semester (OPERATIONS.md §4).

## 10. Updating

After pushing to `main`:

```bash
ssh root@203.0.113.10
sudo -u www-data bash /var/www/siakad/current/deploy/deploy.sh main
```

It backs up the database and files first, then migrates and switches; users notice nothing. If the new
release misbehaves, switch back to the previous one:

```bash
ls -1t /var/www/siakad/releases            # the second line is the previous release
ln -sfn /var/www/siakad/releases/<previous> /var/www/siakad/current
systemctl reload php8.4-fpm
```

If that release ran migrations the old code does not know, restore the backup made just before it
(Data & cadangan → Pulihkan) instead.

## Troubleshooting

| Problem | Where to look |
| --- | --- |
| Error page / 500 | `tail -n 50 /var/www/siakad/shared/storage/logs/laravel-$(date +%F).log` |
| 502 Bad Gateway | `systemctl status php8.4-fpm`, `tail /var/log/nginx/error.log` |
| Deploy stops at `git clone` | the deploy key (step 3) |
| Deploy stops at `npm run build` (killed) | too little RAM: the setup adds 2 GB swap on small servers; check `swapon --show` |
| Backups not made | `crontab` is in `/etc/cron.d/siakad`; `php artisan schedule:list`; `siakad:backup` by hand shows the error |
| Times 7 hours off | `APP_TIMEZONE=Asia/Jakarta` in `.env`, then `php artisan optimize` |
| CMS calls refused | the clock: `timedatectl` must say *synchronized: yes* |
