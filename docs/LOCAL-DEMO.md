# SIAKAD on another computer, for a local demo

How to move SIAKAD from a developer's Windows computer (Laravel Herd) to another Windows computer and open it from
phones and laptops on the same network, working as it does on the developer's computer. For a real server, see
[OPERATIONS.md](OPERATIONS.md) and [SERVER-REQUIREMENTS.md](SERVER-REQUIREMENTS.md).

Herd's `ready.test` address only works on the computer that runs Herd, so other devices open SIAKAD through
`php artisan serve` and the computer's network address instead.

## 1. What to copy

Copy the whole project folder (e.g. `D:\herd-laravel\ready`), or clone the repository if every change is
committed.

| Copy | Why |
|---|---|
| `.env` | Settings, `APP_KEY` (needed to read encrypted data such as 2FA secrets) and the Web Push keys. Not in git. |
| `vendor\` | PHP packages; or run `composer install` on the new computer. |
| `storage\app\private\` | Uploaded photos, documents, proofs, templates, article pictures. Not in git. |
| A backup folder from `storage\app\backups\` | The data. Make a fresh one first: Superadmin → *Data & cadangan* → *Buat cadangan sekarang*, or `php artisan siakad:backup`. |

Leave out `node_modules\` (reinstall only when rebuilding CSS/JS). **Delete `public\hot`** on the new computer if
it came along: it exists while the Vite dev server runs, and with it the pages load their styles from the old
computer and show unstyled.

## 2. Software on the new computer

1. **Laravel Herd** with PHP 8.4 (gives `php` and `composer`).
2. **PostgreSQL 17** (free Herd does not include it): create the database `siakad` with the user and password
   from `.env`, and add `C:\Program Files\PostgreSQL\17\bin` to PATH so `pg_dump` / `pg_restore` are found (or
   set `BACKUP_PG_DUMP` and `BACKUP_PG_RESTORE` to their full paths).
3. **PHP settings** (Herd → PHP → php.ini): `upload_max_filesize = 12M` and `post_max_size = 16M`, or 10 MB uploads
   fail.
4. **Node.js 22+** only if `public\build` is missing or the styles change: `npm install`, then `npm run build`.
5. **LibreOffice** (optional): Word-template letters and printouts as PDF; without it they download as Word.

## 3. Set up and load the data

In the project folder:

```bash
php artisan migrate --force
php artisan siakad:restore 2026-11-17_103000
```

Use the name of the copied backup folder (it must be in `storage\app\backups\`). The restore brings back every
record, account and uploaded file; it works into an empty database.

## 4. Open it from other devices

1. Find the computer's address: `ipconfig` → *IPv4 Address*, e.g. `192.168.1.20`. Ask the router to keep it
   (DHCP reservation), or the link changes.
2. In `.env`: `APP_URL=http://192.168.1.20:8000`, then `php artisan config:clear`.
3. Start SIAKAD for the network and keep the window open:

   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```

4. Allow port 8000 in Windows Firewall for **Private** networks (or click *Allow* when Windows asks about
   `php.exe`), and make sure the Wi-Fi is set as a private network.
5. On any device on the same Wi-Fi: `http://192.168.1.20:8000`.

On the computer itself, Herd's `http://ready.test` keeps working too.

## What differs on a plain-http demo

- **Works:** everything in the app: logins, uploads (`artisan serve` passes `TEMP` on, v7 D42), PDFs, Word
  templates, backups, restore and deleting data (these start `php artisan …` processes, so `php` must be on PATH).
- **Needs HTTPS (or `localhost`):** passkeys, pop-up notifications (Web Push) and installing SIAKAD as an app.
  Browsers allow them only on secure origins, so they do not work from other devices over plain http.
- **One request at a time:** `php artisan serve` on Windows answers one request after another: fine for a few
  people, slow for a whole class at once.
- **Scheduled tasks** (daily backup, reminders) do not run by themselves; for the demo run
  `php artisan schedule:work` in a second window, or leave them.
- **Email** goes to `storage\logs\laravel.log` (`MAIL_MAILER=log`), so password-reset mails are not sent.
