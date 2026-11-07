# v6 plan

Source: `docs/req/v6.md` and the sample `docs/req/row-tagihan-style.docx`. Answers given on 2026-11-07
are recorded as decisions D33–D41.

## What I found

- **Bills:** the Tagihan page lists one bill per row, with amounts and status. The sample wants a grid
  instead: NIM, name, a tick for each instalment (1, 2, 3, 4, 5), and the amount still owed from earlier
  semesters. Each bill already has its own instalment limit (`bills.max_installments`, by default the
  `payment.max_installments` setting, and Superadmin can raise it per bill). The number of columns can
  therefore differ between semesters.
- **Appearance:** the Identity page (Superadmin) mixes institution data (name, address, Mudir, licence,
  logos for the letterhead) with the app's own look (the app logo used as the browser and home-screen
  icon). The app name comes from `APP_NAME` in `.env`.
- **API:** SIAKAD has no API for people yet; there is only the CMS endpoint (v5), and no token
  authentication package. All business rules live in Action classes, so an API can reuse them, the same
  as the pages do.

## Decisions

- **D33 Bills grid** ("Rekap angsuran" on the Tagihan page, next to the current list):
  - **Rows and columns:** one row per mahasantri of the chosen semester (NIM, name, class). There is one
    column per instalment, up to the highest instalment limit among that semester's bills (3 normally,
    5 if someone was allowed 5), then the amount still owed from earlier semesters
    ("Semester sebelumnya").
  - **Ticks:** a cell is ticked when that payment is verified, shows a clock while the payment waits
    for verification, and is empty when not yet paid. A row is marked "Lunas" once the bill is paid.
    Columns beyond a bill's own limit are greyed out.
  - **Links:** an empty cell links to the bill, where the payment is recorded. Name and arrears also link.
  - **Filters and output:** filters by class and Putra/Putri, a search, and an Excel export and print in
    the same layout. The Petugas Pembayaran sees the grid for the mahasantri they serve.
- **D34 Kritik & saran (answer: both, part 1).** Every user (mahasantri, wali, muhadhir and staff) can
  send a review or suggestion:
  - **What they send:** a category (layanan akademik, keuangan, aplikasi SIAKAD, sarana, lainnya), a
    subject and a message.
  - **Anonymous messages** do not store the sender at all, so nobody can see who sent them, and they
    cannot get a reply. */ record sender name */
  - **Handling:** Admin and Superadmin read the messages, reply (the sender is notified) and mark them
    *Baru → Diproses → Selesai*. The sender sees their own messages and the replies.
- **D35 Muhadhir evaluation (answer: both, part 2).** An end-of-semester evaluation of each class kuliah
  by its mahasantri, like EDOM:
  - **Admin sets it up:** the questions (score 1–5, grouped, e.g. *Penguasaan materi*, *Kedisiplinan*),
    one closing open comment, and the period it is open per semester.
  - **Mahasantri answer** once per class they follow, anonymously: the answers are stored without the
    mahasantri, and only a "done" marker is kept so nobody answers twice.
  - **Results:** average per question and per muhadhir, shown only when at least 3 mahasantri answered,
    so a single answer cannot be traced. Admin and the academic monitor see every result and the
    comments, with an Excel export. Each muhadhir sees their own results after the period closes.
  - **Optional nudge:** the dashboard reminds mahasantri while the period is open. Whether it is
    required for the KHS is a setting, off by default.
- **D36 App appearance (answer: name and short name, logo and icon, login page).** A new Superadmin
  page, **Tampilan aplikasi**, separate from **Identitas lembaga**:
  - **App name and short name:** used in the browser tab, sidebar, login page, the installed app
    (manifest), notifications and emails. By default they are the current `APP_NAME` and the
    institution's short name.
  - **App logo and icon:** the sidebar and login logo and the browser and home-screen icon. The current
    app logo moves here; institution logos stay on Identitas lembaga for letterheads.
  - **Login page:** a welcome title and text, and an optional background image.
  - **Not changed:** theme colours stay green (you did not choose them). The institution name, logos and
    address keep being used on documents (KHS, letters, receipts).
- **D37 API for the mobile app (answer: everything, all roles).** A REST API under `/api/v1`, JSON:
  - **Tokens:** Laravel Sanctum personal access tokens. Login is with username and password plus a
    device name, and two-factor accounts send their code. Logging out ends that device's token, and the
    user sees and ends their devices in Settings.
  - **Same rules as the web:** the API calls the same Action classes as the pages, so validation, access
    rules, zero-overwrite, audit and notifications are identical. The active role is sent per request
    (`X-Role` header), checked as on the web.
  - **Responses:** API Resources with stable field names, paginated lists, and errors as
    `{"message", "errors"}` with the usual HTTP codes. Rate limits: login 5/min and 60/min per token.
  - **Files:** uploads use multipart, and downloads are signed links that expire after a few minutes, so
    the app never handles private paths.
- **D38 API documentation.** The "list and detail" of every endpoint is generated from the code with
  **Scramble** (`dedoc/scramble`, OpenAPI 3.1). It is browsable at `/docs/api` (Superadmin, or anyone
  when `API_DOCS_PUBLIC=true`), exported to `docs/api/openapi.json` on every step, and summarised per
  role in `docs/API.md` (what each screen of the app needs). Each API step adds feature tests for its
  endpoints.
- **D39 Notifications in the app.** The API lists, opens and marks notifications. Native push (Firebase
  Cloud Messaging) needs a Firebase project and keys from you, so the API only stores an FCM device
  token, and sending through FCM is switched on once the keys are in `.env` (Step 12). Until then the
  app checks for notifications when opened and every few minutes.
- **D40 API versioning.** Breaking changes go to `/api/v2`; `/api/v1` keeps working for one academic
  year after a new version, so older app versions on phones still work.
- **D41 What the API does not do.** It does not print. PDFs (KHS, transcript, receipts, exam cards,
  letters, cash report) come as the same PDF downloads. Maintenance mode, backups and Superadmin
  system pages stay web-only. Admin and finance work in the app is possible through the API, but the
  web stays the main tool for them.

## Steps

### Step 1 — Bills grid "Rekap angsuran" (medium) — done 2026-11-07

- A grid mode on the Tagihan page per D33 (query: the semester's bills with verified and pending payment
  counts, plus arrears per student in one query each; no N+1).
- Excel export and print in the same layout; the Petugas Pembayaran sees only their mahasantri.
- Tests: columns follow the highest limit, ticks and clocks, arrears, links, the collector's scope, the
  query count.

### Step 2 — Kritik & saran (small–medium)

- `feedback` table (sender nullable, role, category, subject, message, status, reply, replied_by/at).
- **Kritik & saran** for everyone: send, my messages with replies.
- The Admin and Superadmin list: filters, reply, status, Excel export.
- Notifications both ways.

### Step 3 — Muhadhir evaluation (medium–large)

- Tables: `evaluation_questions` (per semester, copied from the previous one), `evaluation_periods`,
  `evaluation_responses` (offering, question, score, no student), `evaluation_comments` and
  `evaluation_done` (student × offering).
- **Mahasantri form:** one page per class, all questions, then submit, with a dashboard reminder.
- **Results:** for Admin and the monitor, per muhadhir and per class, with averages, distribution,
  comments and Excel. Each muhadhir sees their own results after the period closes; at least 3 answers
  are needed to show results (D35).

### Step 4 — App appearance (small–medium)

- `Tampilan aplikasi` page and settings `app.name`, `app.short_name`, `app.logo`, `app.icon`,
  `app.login_title`, `app.login_text`, `app.login_background`. The current app logo moves from the
  Identity page, with a migration.
- Used in the layout title, sidebar, login, manifest, notification titles and emails. PDFs keep using the
  institution.

### Step 5 — API foundation (medium–large)

- Sanctum, `routes/api.php` `/v1`, token login/logout (with 2FA), devices list, `X-Role`, error format,
  rate limits, signed file downloads.
- Scramble set up with `docs/API.md`.
- Common endpoints: me, roles, profile and photo, password change, notifications (list, read, read all,
  FCM token), and settings needed by the app (app name, logo, institution).

### Step 6 — API: mahasantri and wali (large)

- Dashboard summary, schedule, classes with materials, assignments (submit with files), quizzes (start,
  answer, submit, results), meetings and attendance, KHS, transcript, exams and exam card, bills
  (transfer confirmation with proof), letters (request, download), documents (upload), profile,
  bimbingan akademik.
- Wali: children, their progress and bills.

### Step 7 — API: muhadhir (large)

- Schedule, class kuliah, meetings (create, attendance), materials, assignments (create, grade),
  quizzes (build, results), forum, grades (components, scores, import template, finalise), teaching
  documents (templates, upload), supervisees.

### Step 8 — API: discussions, articles, Tanya Hukum (medium)

- Bimbingan and LPPPK rooms (messages with polling `after_id`, files, notes, moderation), articles (write,
  send, review, publish, withdraw, website status) and Tanya Hukum (questions, drafts, approvals).

### Step 9 — API: academic admin and monitor (large)

- Academic years and semesters, classes, subjects, class kuliah and schedule, placements, exams and
  rooms, people (mahasantri, muhadhir, wali), documents review, supervision, letters, incoming letters,
  teaching documents review, monitor dashboard, activity log, bimbingan groups, LPPPK moderation,
  feedback and evaluation results.

### Step 10 — API: finance and payment collector (large)

- Fees and rates, scholarships, bills (generate, add a fee, void), payments (record, verify, reject,
  handovers, transfers), cash book and accounts, the bills grid, unverified payments, reports.

### Step 11 — API: Superadmin (medium)

- Users and roles, settings, identity and appearance (read, change), signatures and stamps, letter types
  and templates, audit log (read).
- Not included: maintenance mode, backups and the CMS page (D41).

### Step 12 — Mobile push (small, when Firebase keys are given)

- Send notifications to the stored FCM tokens next to Web Push; remove invalid tokens.

## Size and order

- **Steps 1–4** are independent web features and come first, in this order (the bills grid is what the
  finance office uses daily).
- **Steps 5–11** are the API. Step 5 must come first; after it, Steps 6–8 give the mobile app everything
  mahasantri, wali and muhadhir need, and Steps 9–11 add the staff work.
- After Step 8 a first mobile app can be built and tested against `docs/api/openapi.json`.
- **Step 12** waits for the Firebase project.
