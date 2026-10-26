# SIAKAD Mahad Aly — Handover

**Date:** 2026-10-29 · **Project folder:** `D:\herd-laravel\ready` · **Local URL:** http://ready.test (Laravel Herd)

Read this first in a new session, then [`DESIGN.md`](DESIGN.md) (the full design, with "Phase N notes" at
the end recording what was actually built and every deviation from the plan). The original client brief
is [`../project.md`](../project.md).

> ⚠️ **The project is not under version control.** `D:\` is a git repository but this folder is
> *untracked* in it, so there is no history and no undo. First thing to do: see
> [Immediate next steps](#5-immediate-next-steps).

---

## 1. Executive summary — what is built

An academic information system (SIAKAD) for a Mahad Aly (Islamic higher education), built in phases.
Phases 0–4 of the 6-phase plan are **done and tested**; Phase 5 (hardening) has not started.

| Phase | Status | What exists |
| --- | --- | --- |
| 0 Foundation | ✅ | Login by ID number (NIM / NIDM / guardian code / staff username), 7 roles with a role picker for multi-role users, forced change of temporary passwords, deactivated accounts signed out, role-based areas + sidebar, audit log (Superadmin), system settings |
| 1 Academic core | ✅ | Academic years & semesters (planned → active → closed; closed = read-only; Superadmin reopen with reason), study programs, classes, subjects, mahasantri / ustadz / wali santri with full biodata and login accounts, KTP/KK/Ijazah upload + verification, Excel/CSV import & export, class rosters per semester (copy from previous semester with promotion), course offerings (subject × class × ustadz × schedule) with automatic course enrolment |
| 2 Finance | ✅ | Fee types & rates per academic year (most-specific rate wins; monthly ×6; once-only fees), idempotent bill generation, scholarships with quota (applied as discount lines), instalment rules (max 3, min Rp 200.000 unless settling), payment recording → cash handover → Admin verification (separation of duties), PDF receipts (provisional slip / official / void), finance dashboard, bill views for mahasantri and wali |
| 3 Teaching & grades | ✅ | Meetings (in class / online link) & attendance, grade components (weights = 100), score grid + Excel import, finalise (locks; Admin unlock with reason), editable grade scale, KHS & transcript (web + PDF) for mahasantri / wali / admin, academic monitor dashboard (attendance below minimum, grade status, distribution) |
| 4 LMS | ✅ | Materials, assignments + submissions + grading, quizzes (4 question types, server-side timer, auto-grading, essay grading), class forum (pin/lock/moderate), copy assignment/quiz scores into the grade sheet, pesantren activity log (monitor; shown to wali; never on KHS), missing-work count on the monitor dashboard |
| 5 Hardening | ⏳ | Not started — see §3 |

**Quality gates (all green at handover):** 158 Pest tests / 666 assertions pass on **both** SQLite and
PostgreSQL · Larastan 0 errors · Pint clean. Every phase was also checked in the browser.

### Roles and where they land

| Role | Login ID | Home | Main areas |
| --- | --- | --- | --- |
| Superadmin | username | `/superadmin` | everything incl. settings, audit log, finance, reopen semester, void payment |
| Admin | username | `/admin` | academic setup, people, documents, import, payment verification, monitor |
| Admin Payment | username | `/finance` | fees, bills, payments, cash handovers, scholarships |
| Academic Monitor | username | `/monitor` | attendance/grade monitoring, activity log |
| Ustadz (teacher) | NIDM | `/ustadz` | own classes: meetings, materials, assignments, quizzes, forum, grades |
| Mahasantri (student) | NIM | `/mahasantri` | my classes, KHS, transcript, bills, documents |
| Wali santri (guardian) | `WS-XXXXXXXX` | `/wali` | children's academic progress, activities, bills (read-only) |

### Demo data (`php artisan migrate:fresh --seed`, local only — every password is `password`)

`superadmin`, `admin`, `keuangan` (Admin Payment), `monitor`, ustadz `2026010001` (Fiqh) and `2026010002`
(Nahwu + Academic Monitor → role picker), mahasantri `2026110001`–`2026110003`, wali `ws-demo0001`.
Seeded: semester 2026/2027 Ganjil (active), Marhalah 1 A with 3 mahasantri, Fiqh (finalised grades,
4 meetings, LMS content, open quiz) and Nahwu (grades in progress), bills with one verified transfer
and one cash payment, scholarship, activity log entries.

> The current PostgreSQL dev database also contains one quiz attempt by Ahmad Rifqi (5/10, essay
> ungraded) made during browser testing. Re-seeding resets everything.

---

## 2. File & folder structure

Laravel 13 + Livewire 4 **single-file components** (the `⚡name.blade.php` files hold the PHP class and
the view together). Business rules live in **Action classes**; pages stay thin.

```
app/
  Actions/                 Business logic (validate → enforce rules → write), reused by pages, imports, seeder
    Academic/              SaveAcademicYear, SemesterLifecycle (activate/close/reopen)
    Documents/             UploadDocument, ReviewDocument
    Enrollment/            ManageClassEnrollments, ManageCourseOfferings, SyncOfferingEnrollments
    Finance/               GenerateBills, RecordPayment (instalment rules), ReviewPayments (handover/verify/void),
                           ManageScholarshipAwards, AdjustBill
    Fortify/               AuthenticateUser (ID-number login), ResetUserPassword
    Lms/                   ManageMaterials, ManageAssignments, ManageQuizzes, ManageForum, RecordActivity
    People/                Create/Update Student & Teacher, SaveGuardian, ResetUserPassword, *Rules traits
    Teaching/              ManageMeetings, ManageGrades, ImportScores, AcademicRecord (KHS/transcript/attendance)
    UpdateUserProfile.php
  Concerns/                Auditable (audit-log trait), IsPersonalDocument, validation-rule traits
  Console/Commands/        CreateSuperadmin  (php artisan siakad:superadmin)
  Enums/                   19 enums: Role (labels, areas, sidebar navigation!), statuses, types…
  Http/Controllers/        PrivateFileController (all private files + access rules), ReceiptController,
                           AcademicRecordPdfController, ImportTemplateController, ScoreTemplateController
  Http/Middleware/         EnsureUserIsActive, EnsurePasswordIsChanged, EnsureRoleIsSelected, EnsureRole
  Imports/                 Importer interface, Importers (reader + helpers), StudentImporter, TeacherImporter
  Listeners/               RecordSuccessfulLogin
  Livewire/                PersonForm (base for person forms), AcademicRecordPage (base for KHS pages),
                           Concerns/ManagesAccount (temp password / disable login)
  Models/                  41 Eloquent models (all audited via Auditable)
  Rules/                   UniqueUsername
  Support/                 ActiveRole, Credentials, CreatedAccount, Money (Rp + terbilang), NumberSequence,
                           OfferingAccess, SpreadsheetDownload
bootstrap/app.php          Middleware aliases + the `portal` middleware group
config/siakad.php          Setting defaults (institution, payment rules, attendance minimum)
database/
  migrations/              Starter-kit tables (users table modified) + 8 SIAKAD migrations, one per phase/domain
  factories/               Factories for people, academic structure, fee types
  seeders/DatabaseSeeder   Full demo data (refuses to run in production)
docs/                      DESIGN.md (design + phase notes), HANDOVER.md (this file)
resources/views/
  pages/                   Livewire pages, by area:
    admin/                 academic-years, semesters/show, study-programs, class-groups, subjects, grade-scale,
                           students|teachers|guardians/{index,form}, students/record, documents, imports, payments
    auth/                  login (ID number), change-password, select-role (+ Fortify views)
    finance/               dashboard, fees, scholarships, bills/{index,show}, handovers
    mahasantri/            classes, class, quiz-attempt, khs, transcript, bills
    monitor/               dashboard, activities
    profile/               documents (owner upload)
    settings/              starter-kit profile/security/appearance (profile adapted)
    shared/                forum (embedded in ustadz + mahasantri pages)
    superadmin/            settings, audit-logs
    ustadz/                dashboard, offerings/{meetings,materials,assignments,assignment,quizzes,quiz,forum,grades}
    wali/                  academic, bills
  components/              page-header, offering-header (ustadz tabs), account-panel, bill-card, khs-table,
                           transcript-table, user-menu-items + starter-kit components
  partials/                credentials-modal, documents-reminder
  receipts/, records/      dompdf templates (receipt, KHS, transcript)
  dashboard.blade.php      Generic role dashboard (admin, superadmin, mahasantri, wali)
routes/web.php             All SIAKAD routes, grouped per role area; settings.php for profile/security
tests/Feature/             Admin, Auth, Finance, Teaching, Lms, Superadmin, Settings (+ DashboardTest)
.claude/launch.json        Fallback dev server (`php artisan serve` on port 8010) when Herd is not running
```

---

## 3. Pending features & known issues

### Not built yet

- [ ] **User & role management screen** (Superadmin). There is *no UI* to create Admin / Admin Payment /
      Academic Monitor accounts or to give an existing user an extra role. Today only the seeder and
      `php artisan siakad:superadmin` can. **Highest priority before real use.**
- [ ] **Indonesian translation.** UI strings are English `__()` keys; `APP_LOCALE=en`; no `lang/id`
      (including validation messages). PDFs (receipts, KHS, transcript) are already in Indonesian.
- [ ] **Mobile polish.** Tables scroll horizontally on phones; the design called for stacked cards below `md`.
- [ ] **Backups** (daily `pg_dump` + `storage/app/private`), not configured.
- [ ] **User guide** for each role.
- [ ] **Institution logo upload** in settings (design mentions it; only name/address/phone exist).
- [ ] **Retaken subjects** are listed per attempt on the transcript, not merged (IPK counts both).
- [ ] **Real-time forum** (Laravel Reverb); currently polls every 15 s.
- [ ] **Queued imports / bill generation** — run synchronously in the request (fine for hundreds of rows).
- [ ] **Online payment gateway** — explicitly deferred (design Q7).
- [ ] **Notifications** (email/WhatsApp for bills, deadlines, verification) — not in scope so far.
- [ ] **UAT with real data** and production deployment (env, `QUEUE_CONNECTION`, mail for password reset).

### Known issues / housekeeping

- [ ] **No git** (see top). Nothing has ever been committed.
- [ ] `composer.json` has a `post-update-cmd` script `@php artisan boost:update` from the starter kit, but
      Laravel Boost is not installed → every `composer update` prints an error (harmless). Remove the line
      or install Boost.
- [ ] Unused starter-kit layout `resources/views/layouts/app/header.blade.php` still links to the starter
      kit repo/docs. Delete or adapt.
- [ ] Herd sometimes isn't running → `ready.test` refuses connections. Start Herd, or use the `siakad`
      entry in `.claude/launch.json` (http://localhost:8010).
- [ ] `.env` has leading spaces on the `DB_*` lines (lines 25–30). Laravel reads them fine; tidy if desired.
- [ ] `storage/logs/laravel.log` contains errors from an editor helper (`storage/framework/lsp-*.php`),
      unrelated to the app.
- [ ] Quiz "Start" / "Submit" use a native confirm dialog (`wire:confirm`); browser-pane automation can't
      click those, so those two buttons were exercised via Livewire calls, not clicks.
- [ ] Open design assumptions still unconfirmed by the client: Q1 (single study program), Q2 (SKS /
      4.00 scale), Q3 (Admin verifies payments), Q5 (guardian code + password). See DESIGN.md §1.3.

---

## 4. Technical decisions & tech stack

**Stack:** PHP 8.3+ (8.4 locally) · Laravel 13 · Livewire 4 (single-file components) · Flux UI 2 (free
edition) · Tailwind 4 · Alpine (via Livewire) · Fortify (auth, 2FA, passkeys) · **PostgreSQL 17** (dev DB
`siakad`, tests DB `siakad_test`) · Pest 5 · Larastan · Pint.
**Added packages:** `spatie/simple-excel ^3.11` (import/export), `barryvdh/laravel-dompdf ^3.1` (PDFs).

**Architecture & conventions**
- Pages are thin: validate input shape, call an **Action**, toast. Actions throw
  `ValidationException` with a key the page displays (e.g. `semester`, `payment`, `grades`).
- **Call `$this->resetErrorBag()` at the start of every page action that calls an Action** — action errors
  otherwise stay on screen after a successful retry.
- **Zero-overwrite rule:** everything is keyed to a semester; `Semester::assertOpen()` guards every write
  to semester data; values that can change later are snapshotted (subject name/SKS on offerings, class
  name on enrolments, fee amounts on bill lines, letter/point on finalised grades); no hard deletes of
  history (void/reject with a reason instead); enrolments with scores, attendance, submissions or quiz
  attempts can't be moved/removed (`CourseEnrollment::withRecords()`).
- **Audit:** every model uses the `Auditable` trait (who, old → new, IP); hidden attributes are masked.
- **Roles:** `App\Enums\Role` drives labels, URL areas, home routes and the sidebar menu
  (`Role::navigation()`); the active role is kept in the session (`App\Support\ActiveRole`); routes use
  `->middleware('role:admin,superadmin')`; the `portal` middleware group = auth + active + password changed
  + role selected; role checks are also persistent for Livewire update requests.
- **Money** is whole rupiah in `bigint` columns; format with `Money::format()`; `Money::inWords()` = terbilang.
- **Running numbers** (`INV/…`, `STR/…`, `KW/…`) come from `NumberSequence::next()` inside transactions.
- **Private files** (photos, documents, payment proofs, materials, submissions) are on the `local`
  (private) disk and served only through `PrivateFileController`, which holds the access rules.
- **Settings** via `Setting::get('key')` with defaults in `config/siakad.php`; Superadmin edits them.
- **PDF:** dompdf font subsetting + temp dir are set in `AppServiceProvider` (must be config, not
  `setOptions()`, which drops package defaults).

**Gotchas learned the hard way**
- `Model::preventLazyLoading()` is on outside production: eager-load (`with()`), or `loadMissing()`.
- Give models `$attributes` defaults that match DB column defaults (status / is_active), otherwise new
  instances have `null` until reloaded.
- Decimal columns need `decimal:2` casts — SQLite returns numbers, PostgreSQL strings.
- Blade `@error('x.*')` does not support wildcards → use `$errors->has('x.*')` / `$errors->first('x.*')`.
- Livewire `updatedFoo($value, $key)`: `$key` can be **null** when a whole array is replaced
  (checkbox groups).
- Larastan can't see code inside Blade single-file components: shared logic belongs in PHP classes
  (`app/Livewire/*`, Actions) — that's why `PersonForm` and `AcademicRecordPage` exist.
- PostgreSQL partial unique indexes enforce invariants in the DB too: one active semester, one live bill
  per student per semester.

---

## 5. Immediate next steps (prioritised)

1. **Put the project under git** (protects everything built so far):
   `cd D:\herd-laravel\ready && git init`, check `.gitignore` covers `.env`, `vendor`, `node_modules`,
   `storage/*.key`, `public/build`, then make the first commit. Decide whether it should leave the parent
   `D:\` repository.
2. **User & role management (Superadmin):** list users, create staff accounts (Admin, Admin Payment,
   Academic Monitor) with a temporary password, add/remove roles on any user (e.g. ustadz + monitor),
   enable/disable, reset password. Reuse `ManagesAccount`, `Credentials`, `UniqueUsername`; audit is automatic.
3. **Phase 5 — hardening**, in this order:
   1. Indonesian translation: `APP_LOCALE=id`, `lang/id.json` for all `__()` strings, `lang/id/validation.php`.
   2. Mobile polish: stacked cards for the main tables below `md`; check every page at 375 px.
   3. Performance pass: indexes for hot queries (bills by semester/status, attendances, submissions),
      check N+1 on large rosters, consider queued imports/bill generation.
   4. Backups: scheduled `pg_dump` + private storage, retention ≥ 30 days.
   5. User guide per role; then UAT with real data.
4. **Housekeeping:** remove the `boost:update` composer script (or install Boost), delete/adapt
   `layouts/app/header.blade.php`, tidy `.env` spacing.
5. **Later / optional:** transcript handling of retaken subjects, Reverb for the forum, institution logo,
   notifications, payment gateway.

### How to work on it

```bash
# run the app: start Laravel Herd → http://ready.test   (fallback: .claude/launch.json "siakad" → :8010)
php artisan migrate:fresh --seed     # reset dev DB (PostgreSQL) with demo data
php artisan test --compact           # fast suite (in-memory SQLite)
composer test:pgsql                  # same suite on PostgreSQL (siakad_test)
vendor/bin/pint --parallel           # format
vendor/bin/phpstan analyse --memory-limit=1G   # static analysis
npm run build                        # rebuild CSS after adding Tailwind classes
php artisan siakad:superadmin        # first account on a fresh/production install
```

**Definition of done used so far:** tests for the rules and the pages (both DBs) · Pint + Larastan clean
· seeder updated · checked in the browser · DESIGN.md "Phase N notes" updated.

### Suggested opening message for the new chat

> Continue the SIAKAD Mahad Aly project in `D:\herd-laravel\ready`. Read `docs/HANDOVER.md` and
> `docs/DESIGN.md` first. Start with step 1 (git) and step 2 (user & role management) from the handover's
> "Immediate next steps".
