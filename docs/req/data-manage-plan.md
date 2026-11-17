# Data management plan

Source: `docs/req/data-manage.md` ("add import, export, destroy all data which can only access by
superadmin"). Answers given on 2026-11-08 are recorded as decisions D50–D53.

## What I found

- **Backups exist, but only on the server.** `php artisan siakad:backup` runs every day at 02:00 and writes a
  folder in `storage/app/backups` with `database.dump` (pg_dump, custom format) and `files.zip` (everything
  in `storage/app/private`: photos, documents, proofs, materials, letters, templates, article pictures).
  Folders older than 30 days are removed. Restoring is a manual `pg_restore` (OPERATIONS.md §4).
- **No Superadmin screen** lists, downloads or restores backups, and nothing deletes data in bulk.
- Existing imports and exports (Admin's Impor page, previous-year data, the Excel exports on each list)
  each cover one kind of data and stay as they are.

## Decisions

- **D50 Export = a full backup to download (answer: "Backup & restore").** A new Superadmin page **Data**
  (`/superadmin/data`) lists every backup (the daily ones and those made by hand) with its date, size, the
  SIAKAD version and row counts, and lets the Superadmin:
  - **make a backup now** (the same `siakad:backup`, run in the background; the page shows when it is ready);
  - **download** a backup as one `.zip` (`manifest.json`, `database.dump`, `files.zip`);
  - **delete** a backup made by hand.
  Every backup gets a `manifest.json`: when, by whom, SIAKAD version, the last migration, row counts per
  table and checksums of the two files.
- **D51 Import = restore a backup (replaces everything).**
  - **Source:** a backup from the list, or an uploaded `.zip` exported by SIAKAD. Large archives may be
    bigger than the web upload limit: the page says the limit (`SIAKAD_RESTORE_MAX_MB`, default 512 MB,
    needs matching `upload_max_filesize` / `post_max_size`); bigger ones are copied into
    `storage/app/backups` on the server and then appear in the list.
  - **Checks before anything changes:** the archive has the manifest and both files with matching
    checksums; it was made by a SIAKAD with the same or an older database version (a newer one is refused);
    the database server tools (`pg_restore`) are present.
  - **Safety:** the Superadmin re-enters their password and types `PULIHKAN`. A fresh backup of the current
    state is made first; if that fails, nothing is restored.
  - **How it runs:** `php artisan siakad:restore {backup}` started in the background. SIAKAD goes into
    maintenance (everyone sees "sedang dipulihkan"); the database is restored with `pg_restore --clean`,
    the private files are replaced, newer migrations are run, caches cleared, and SIAKAD comes back. The
    Data page shows the progress and the result; a failure is reported and the pre-restore backup is named
    so it can be restored.
  - **Afterwards** every account is as it was in the backup, including the Superadmin's own (they may have
    to sign in again). The restore is written to the audit log and kept in `storage/logs`.
- **D52 Destroy by groups (answer: "Choose groups each time").** On the Data page, tab **Hapus data**:
  - **Groups** (with the number of records each holds now):
    1. *Orang*: mahasantri, muhadhir, wali santri and their accounts, documents and photos.
    2. *Akademik*: placements, classes kuliah, meetings, attendance, materials, assignments, quizzes, grades,
       exams and seats, evaluations, teaching documents, supervision, activity log.
    3. *Keuangan*: bills, payments, scholarship awards, handovers, cash book entries and their proofs.
    4. *Surat*: letters issued, letter requests, incoming letters, dispensation requests.
    5. *Artikel & diskusi*: articles, bimbingan / LPPPK / forums, Tanya Hukum, kritik & saran.
    6. *Data dasar*: academic years and semesters, study programs, subjects, classes (rombel), rooms, fee
       types and rates, scholarships, letter types, signatories and stamps, uploaded templates.
    7. *Akun staf*: Admin, Admin Keuangan, Pemantau Akademik and Petugas Pembayaran accounts (not Superadmin).
    8. *Notifikasi & log*: notifications, audit log, push devices, CMS request log.
  - **Dependencies are ticked for you:** deleting *Orang* also deletes what belongs to people (*Akademik*,
    *Keuangan*, *Surat*, *Artikel & diskusi*); *Data dasar* also needs *Akademik* and *Keuangan*. The page
    shows this before the Superadmin confirms.
  - **Always kept:** Superadmin accounts, institution identity, app appearance, settings, and the backups.
  - **Safety:** a summary of what will go (groups and counts), the Superadmin's password, typing
    `HAPUS DATA`, and an automatic backup first (if it fails, nothing is deleted). Deletion runs in one
    database transaction; files that belonged to deleted records are removed afterwards. One audit entry
    records who deleted which groups and the counts (kept even when *Notifikasi & log* is deleted).
- **D53 Superadmin only, web only.** The page, the commands it starts and the downloads check the Superadmin
  role (and the password for restore and delete). Nothing of this is in the mobile API (as v6 D41 keeps
  backups web-only). The menu item sits under Superadmin → *Sistem*.

## Steps

### Step 1 — Data page: backups to download (medium) — done 2026-11-08
- `manifest.json` in every backup (`siakad:backup`), a `Backups` support class (list, read manifest, zip for
  download, delete hand-made ones).
- `/superadmin/data` with the backup list, "Buat cadangan sekarang" (background run with status), download
  and delete; menu item; tests (backup creation faked as in `BackupTest`, access only for Superadmin).

### Step 2 — Restore (large) — done 2026-11-08
- `Restores`: checks (manifest, checksums, last migration known to this SIAKAD, pg_restore present), taking in
  an uploaded .zip (exactly the three parts) as a backup made by hand, and the state of a restore
  (`.restore.json` next to the backups).
- `siakad:restore`: backup of the current state, `artisan down`, `DatabaseRestore` (drop tables the dump does
  not have, then `pg_restore --clean --if-exists --single-transaction`), files unpacked and swapped,
  `migrate --force`, sessions and jobs emptied, cache cleared, audit entry, `artisan up`; works into an empty
  database too.
- Data page: *Pulihkan* per backup (check, password, `PULIHKAN`), *Pulihkan dari berkas* (upload), progress
  read from `/restore-status/{token}` (outside the web middleware, open during maintenance).
- Tests with the database restore and pg_dump faked (`RestoreTest`); a real round trip on PostgreSQL 17 with a
  scratch database: row counts as in the backup, a newer table dropped, files replaced.

### Step 3 — Destroy by groups (medium–large) — done 2026-11-08
- `DataGroups`: the tables of each of the 8 groups, what each needs, the accounts deleted (profiles for *Data
  orang*, staff-only accounts for *Akun staf*, never Superadmin), counts; tables never touched (`KEPT`).
- `WipeData`: deletion order from the foreign keys, self-references cleared first, references from kept rows to
  deleted accounts given to the Superadmin (memberships and sanctions deleted instead), tokens and sessions of
  deleted accounts removed, files of deleted rows (every `*_path` column) removed after the commit, one audit
  entry kept even when the log is deleted.
- `siakad:wipe`: backup of the current state first, state in `.wipe.json`; the *Hapus data* card with counts,
  needed groups ticked, summary, password and `HAPUS DATA`, progress.
- Tests (`WipeTest`): every table grouped or kept and every group's needs checked against the schema's foreign
  keys; each group deleted on its own from the full demo data with foreign keys enforced; staff records kept;
  files; backup first; the page.

### Step 4 — Docs and checks (small) — done 2026-11-08
- OPERATIONS (backups from the web, restore, deleting data), UAT, DESIGN; Indonesian texts; both test
  suites, Larastan, Pint; a browser check of the Data page.

## Size and order

Step 1 first (the others need its backups). Step 3 does not depend on Step 2, but both use the
"backup first" of Step 1. Restore is the riskiest part and gets the most checks.
