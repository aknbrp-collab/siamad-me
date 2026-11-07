# SIAKAD API (for the mobile app)

The API lets a mobile app do what each role does on the web. It is generated from the code: the full list
of endpoints, fields and errors is at **`/docs/api`** on the server (Superadmin, or anyone when
`API_DOCS_PUBLIC=true`) and in [`docs/api/openapi.json`](api/openapi.json) (OpenAPI 3.1, exported with
`php artisan scramble:export` after every API change). This file explains how the pieces fit together and
which screens of the app use which endpoints.

Decisions: v6 D37–D41 in [req/v6-plan.md](req/v6-plan.md).

## Basics

| | |
| --- | --- |
| Base URL | `{APP_URL}/api/v1` |
| Format | JSON (`Accept: application/json`); uploads as `multipart/form-data` |
| Login | `POST /auth/login` → `token`; then `Authorization: Bearer {token}` on every call |
| Role | `X-Role: student` (or `teacher`, `guardian`, `admin`, …) on every call by users with several roles; users with one role may leave it out |
| Language | Messages are in the server language (Indonesian) |
| Versions | Breaking changes go to `/api/v2`; `/api/v1` keeps working for one academic year after that (D40) |

**Same rules as the web.** The API calls the same Action classes as the pages, so validation, who may do
what, "zero overwriting", the audit log and notifications are identical. A role the user does not hold is
refused; a page a role cannot open on the web is refused in the API too.

**Tokens.** One token per device, named after the device (`device_name`, e.g. "Samsung A54"). A token
expires when the device has not been used for `SANCTUM_TOKEN_DAYS` (365). It ends when the user logs out on
that device, signs it out (app: `DELETE /auth/devices/{id}`; web: Pengaturan → Perangkat aplikasi), changes
the password (other devices), when the Admin resets the password, or when the account is deactivated.

## Errors

| HTTP | Body | When |
| --- | --- | --- |
| 401 | `{"message"}` | No or expired token. With `"code": "account_inactive"`: the account was deactivated (the token is deleted) |
| 403 | `{"message", "code"?}` | Not allowed. `code`: `role_not_held` (X-Role), `password_change_required` (temporary password: only `GET /me`, `PUT /password`, `POST /auth/logout` and `GET /app` work) |
| 404 | `{"message"}` | Not found, or not visible to this user |
| 422 | `{"message", "errors": {field: [messages]}}` | Validation. Login with 2FA: `"code": "two_factor_required"` |
| 429 | `{"message"}` | Too many requests: login 5 a minute per ID number and address, 60 a minute per token |

## Login flow

1. `GET /app` (no login): app name, logo, login texts and background, the institution.
2. `POST /auth/login` with `username` (NIM, NIDM, kode wali or staff ID), `password`, `device_name`,
   `platform` (`android`/`ios`/`web`/`other`).
3. If the answer is `422` with `code: "two_factor_required"`, ask for the 6-digit code and send the same
   login again with `code` (or `recovery_code`).
4. Keep `token` in the phone's secure storage. `user.must_change_password: true` → show "Ganti kata sandi"
   and call `PUT /password` before anything else.
5. `user.roles` has more than one role → let the user pick one (like "Pilih peran" on the web) and send it as
   `X-Role` from then on. `GET /me` with that header confirms `active_role`.
6. `PUT /auth/push-token` with the Firebase token of the device (push messages start once the server has
   its Firebase keys, D39; until then poll `GET /notifications` when the app opens and every few minutes).

## Files

Photos and documents never come as paths. A response carries a **signed link** (`…_url`) that works
without the token and expires after 10 minutes; open it in the app or the phone's browser. Access is
checked again when it is opened, with the web rules. Ask for the item again for a fresh link. PDFs (KHS,
transcript, receipts, exam card, letters, teaching journal) and templates (score import, RPS / jurnal) are
downloaded the same way (D41).

## Endpoints by screen

### All roles (Step 5)

| Screen | Endpoints |
| --- | --- |
| Splash / login | `GET /app`, `POST /auth/login` |
| Start, role picker | `GET /me` |
| Ganti kata sandi | `PUT /password` |
| Profil saya | `GET /profile` (fields and which are `editable` for the role), `PATCH /profile` (multipart for `photo`) |
| Notifikasi | `GET /notifications` (`?unread=1`, `meta.unread` for the badge), `POST /notifications/{id}/read`, `POST /notifications/read-all` |
| Perangkat | `GET /auth/devices`, `DELETE /auth/devices/{id}`, `POST /auth/logout` |
| Push | `PUT /auth/push-token` |

Notifications carry `link`, the SIAKAD page they are about (e.g. `/mahasantri/khs`); the app maps known
paths to its own screens and opens others on the web.

| Screen | Endpoints |
| --- | --- |
| Kritik & saran | `GET /feedback` (my messages, replies, categories), `POST /feedback` |

### Mahasantri (Step 6)

The mahasantri's own student id is in `GET /me` (`student.id`); records use it.

| Screen | Endpoints |
| --- | --- |
| Beranda | `GET /student/dashboard`: today's classes, assignments due in 7 days, open quizzes, amount owed, classes to evaluate, documents to upload, unread notifications |
| Jadwal, Kelas saya | `GET /student/classes` (timetable order; `day_of_week` 1 = Monday) |
| Kelas | `GET /student/classes/{id}`: meetings with my attendance and the link of online meetings, materials (`file_url`), assignments with my submission, quizzes with my attempts |
| Tugas | `POST /student/assignments/{id}/submission` (multipart `text`, `file`; replaceable until graded) |
| Kuis | `POST /student/quizzes/{id}/attempts` (start or continue), `GET /student/quiz-attempts/{id}`, `PUT /student/quiz-attempts/{id}/answers/{question}` (`answer`: option id, list of ids, or text) for each answer, `POST /student/quiz-attempts/{id}/submit`. Answers save at once; at `deadline_at` the attempt is submitted automatically. Correct answers are never sent; after submitting only the score |
| KHS | `GET /students/{id}/khs?semester=` (grades, attendance per subject, IPS, `pdf_url`; `waits_for_evaluation` when the evaluation must come first) |
| Transkrip | `GET /students/{id}/transcript` (`pdf_url`) |
| Tagihan | `GET /students/{id}/bills` (lines, payments, `receipt_url`, `transfer` limits, `bank_accounts`), `POST /bills/{id}/transfers` (multipart `amount`, `paid_on`, `reference`, `proof`) |
| Kartu ujian | `GET /student/exams` (schedule, seat, eligibility per subject, `card_url` when eligible) |
| Surat saya | `GET /student/letters`, `POST /student/letter-requests` (`letter_type_id`, `purpose`) |
| Dokumen saya | `GET /documents`, `POST /documents/{id}` (multipart `file`; ijazah also `education_level`, `institution`, `graduation_year`) |
| Pembimbing akademik | `GET /student/supervision` |
| Evaluasi muhadhir | `GET /student/evaluations` (classes, statements, closing question), `POST /student/evaluations/{class}` (`scores`: {statement id: 1–5}, `comment`) |

| Forum kelas | `GET/POST /forums/class/{class}/threads`, `GET /forums/class/{class}/threads/{id}`, `POST …/{id}/posts` |

Bimbingan, LPPPK, articles and Tanya Hukum: see "Discussions, articles, Tanya Hukum" below.

### Wali santri (Step 6)

| Screen | Endpoints |
| --- | --- |
| Anak saya | `GET /guardian/children` (class, amount owed, photo) |
| Perkembangan akademik | `GET /students/{id}/khs`, `GET /students/{id}/transcript`, `GET /students/{id}/activities` |
| Tagihan | `GET /students/{id}/bills`, `POST /bills/{id}/transfers` |

A wali reaches only their own children; another student id gives 403.

### Muhadhir (Step 7)

Every class route starts with `/teacher/classes/{class}` and checks that the muhadhir teaches the class.

| Screen | Endpoints |
| --- | --- |
| Kelas saya, jadwal | `GET /teacher/classes?semester=` (timetable order, counts, grades final, joint classes) |
| Pertemuan dan presensi | `GET …/meetings` (meetings and the attendance summary per mahasantri), `POST …/meetings`, `PUT …/meetings/{id}` (with the journal: `material`, `method`, `journal_note`), `DELETE …/meetings/{id}` (only without attendance), `GET` / `PUT …/meetings/{id}/attendance` (`statuses`: {enrollment id: hadir/izin/sakit/alpa}, `notes`) |
| Materi | `GET …/materials`, `POST …/materials` (multipart `title`, `body`, `url`, `file`), `POST …/materials/{id}` (change), `DELETE …/materials/{id}` |
| Tugas | `GET …/assignments`, `POST`, `PUT …/assignments/{id}`, `DELETE`, `GET …/assignments/{id}/submissions`, `PUT …/assignments/{id}/submissions/{submission}/grade` (`score`, `feedback`), `POST …/assignments/{id}/copy-to-grades` |
| Kuis | `GET …/quizzes`, `POST`, `GET …/quizzes/{id}` (questions with correct options, attempts with answers), `PUT`, `DELETE`, `POST …/quizzes/{id}/questions`, `PUT`/`DELETE …/questions/{q}`, `PUT …/quizzes/{id}/publish` (`published`), `PUT …/quizzes/{id}/answers/{answer}/points` (essays), `POST …/quizzes/{id}/copy-to-grades` |
| Nilai | `GET …/grades` (components, scores, live final score and letter, `import_template_url`), `PUT …/grades/components` (weights add up to 100), `PUT …/grades/scores` ({enrollment id: {component id: score}}), `POST …/grades/import` (multipart `file`; `apply=1` to save), `POST …/grades/finalize` |
| Dokumen mengajar | `GET …/teaching-documents` (versions and reviews per RPS / jurnal, `template_url`, `journal_pdf_url`), `POST …/teaching-documents` (multipart `kind`, `file`) |
| Forum | `/forums/class/{class}/threads…` as above, plus `POST …/{id}/pin`, `POST …/{id}/lock`, `DELETE …/{id}`, `DELETE …/threads/posts/{post}` |
| Mahasantri bimbingan | `GET /teacher/supervisees` (class, attendance, IPK); their `GET /students/{id}/khs`, `/transcript`, `/activities` (not bills); the group forum `/forums/supervision/{teacher id}/threads…` |
| Dokumen saya | `GET /documents`, `POST /documents/{id}` (ijazah with `degree_title`) |

### Discussions, articles, Tanya Hukum (Step 8)

For mahasantri and muhadhir (and the staff who oversee). Access is checked per room and per article, as on
the web.

| Screen | Endpoints |
| --- | --- |
| Bimbingan, LPPPK | `GET /discussions?kind=bimbingan` or `lpppk` (rooms with `unread` and the latest message) |
| Ruang diskusi | `GET /discussions/{id}` (members, `moderator`, `cannot_post` with the reason), `GET /discussions/{id}/messages` (`after_id` to poll every few seconds while open, `before_id` to scroll back; reading marks the room read), `POST /discussions/{id}/messages` (multipart `body`, up to five `files[]`, `reply_to_id`), `DELETE …/messages/{m}`, `GET …/files`, `GET`/`POST …/notes`, `PUT`/`DELETE …/notes/{n}` |
| Moderasi ruang | `POST`/`DELETE …/members/{member}/mute` (`until` or null), `PUT …/lock` (`locked`) |
| Artikel saya | `GET /articles/mine`, `POST /articles` (`title`, `summary`, `body` in Markdown, `keywords` comma separated), `GET /articles/{id}` (`body_html`, reviews, `website` status, `can` = the actions allowed now), `PUT`, `DELETE` (a never-sent draft), `POST …/submit` (mahasantri), `POST …/publish` (muhadhir), `POST …/withdraw`, `POST …/website/resend`, `POST …/website/check` |
| Tinjau artikel | `GET /articles/review` (muhadhir), `POST /articles/{id}/review` (`approve`, `note`) |
| Tanya Hukum | `GET /tanya-hukum?show=open|approval|answered|all`, `GET /tanya-hukum/{id}` (open answer with approvals, sent versions, `can`), `PUT …/draft` (`answer`, `references`), `POST …/submit`, `POST …/approve`, `POST …/reopen`, `POST …/correction`, `POST …/resend`; the question's room is `discussion_id` |

### Admin akademik and pemantau (Step 9)

Under `/admin`, for Admin and Superadmin; the academic monitor reaches the monitoring, activity, teaching
document, bimbingan and LPPPK endpoints and the evaluation results (writes there that the web keeps for
Admin answer 403). Staff also read any mahasantri's records under `/students/{id}/…`.

| Screen | Endpoints |
| --- | --- |
| Tahun akademik | `GET /admin/academic-years` (with semesters), `POST`, `PUT /admin/academic-years/{id}`, `POST /admin/semesters/{id}/activate`, `POST …/close` (`confirmed: true` to close; without it the number of classes with grades not final), `POST …/reopen` (Superadmin, `reason`) |
| Program studi, mata kuliah, ruang, kelas | `GET`/`POST /admin/study-programs`, `DELETE /admin/study-programs/{id}`; `GET`/`POST /admin/subjects`; `GET`/`POST /admin/rooms`; `GET`/`POST /admin/classes`, `DELETE /admin/classes/{id}`; `GET /admin/grade-scale` (a `POST` without `id` adds, with `id` changes) |
| Penempatan kelas | `GET /admin/semesters/{id}/placements?class=` (classes, roster), `GET …/placements/candidates?search=&class=`, `POST …/placements` (`class_id`, `student_ids`), `DELETE …/placements/{id}`, `POST …/placements/copy` (`from_semester_id`, `class_map`) |
| Kelas kuliah, jadwal | `GET /admin/semesters/{id}/classes`, `POST …/classes` (with `id` to change; `joint: true` after a `joint_class` 422), `DELETE …/classes/{id}`, `POST …/classes/copy` |
| Ujian | `GET`/`POST /admin/semesters/{id}/exam-periods`, `GET /admin/exam-periods/{id}` (schedule, rooms, capacity), `POST …/sessions`, `DELETE …/sessions/{id}`, `PUT …/rooms`, `POST …/seats`, `PUT …/publish`, `GET …/participants`, `POST`/`DELETE …/dispensations/{student}` |
| Mahasantri, muhadhir, wali | `GET /admin/students` (`search`, `status`, `program`), `GET /admin/students/{id}`, `POST /admin/students` (returns the temporary `password` once), `POST /admin/students/{id}` (change, multipart for `photo`); the same for `/admin/teachers`; `GET`/`POST /admin/guardians` (`student_ids`); `POST /admin/users/{id}/reset-password`, `PUT /admin/users/{id}/active` |
| Dokumen | `GET /admin/documents?owner=students|teachers&status=`, `POST /admin/documents/{id}/review` (`owner`, `verify`, `note`) |
| Pembimbing akademik | `GET /admin/supervision?teacher=`, `POST /admin/supervision` (`teacher_id`, `student_ids`), `DELETE /admin/supervision/{id}` |
| Catatan kegiatan | `GET`/`POST /admin/activities`, `DELETE /admin/activities/{id}` |
| Persuratan | `GET /admin/letters/overview` (types, waiting requests), `GET /admin/letters`, `POST /admin/letters/draft` (filled template), `POST /admin/letters` (issue; `letter_request_id` answers a request), `POST /admin/letters/{id}/cancel`, `POST /admin/letter-requests/{id}/reject`, `GET`/`POST /admin/incoming-letters` |
| Pemantauan | `GET /admin/monitor?semester=`, `POST /admin/classes/{id}/unlock-grades` (Admin) |
| Dokumen mengajar | `GET /admin/teaching-documents?filter=`, `POST /admin/teaching-documents/{id}/review`, `POST /admin/teaching-documents/join` (`class_ids`, lead first), `DELETE /admin/teaching-documents/join/{lead id}` |
| Kelompok bimbingan, LPPPK | `GET`/`POST /admin/bimbingan-groups`, `POST /admin/bimbingan-groups/{id}/members`, `DELETE …/members/{member}`, `PUT /admin/discussions/{id}/closed`, `POST /admin/lpppk-channels`, `GET /admin/lpppk-sanctions`, `POST`/`DELETE /admin/lpppk-sanctions/{user}` |
| Kritik & saran | `GET /admin/feedback`, `PUT /admin/feedback/{id}` (`reply`, `status`) |
| Evaluasi muhadhir | `GET /admin/evaluations?semester=` (results), `GET`/`PUT /admin/semesters/{id}/evaluation`, `POST …/evaluation/prepare`, `POST …/evaluation/statements` |

Web only: Excel imports, previous-year data (maintenance mode) and changing the grade scale; payment
verification comes with finance (Step 10).

### Per role

Added step by step (v6 plan):

| Role | Step | Screens |
| --- | --- | --- |
| Mahasantri, wali santri | 6 | Done: see above |
| Muhadhir | 7 | Done: see above |
| Discussions | 8 | Done: see above |
| Admin, pemantau | 9 | Done: see above |
| Keuangan, petugas | 10 | Tagihan, pembayaran, setoran, transfer, buku kas, rekap angsuran |
| Superadmin | 11 | Pengguna, pengaturan, identitas dan tampilan, tanda tangan, jenis surat, log audit |

Not in the API (D41): maintenance mode, backups and the CMS page (web only).
