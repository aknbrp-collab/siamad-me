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
transcript, receipts, exam card, letters) are downloaded the same way (D41).

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

### Per role

Added step by step (v6 plan):

| Role | Step | Screens |
| --- | --- | --- |
| Mahasantri, wali santri | 6 | Dashboard, jadwal, kelas (materi, tugas, kuis, pertemuan), KHS, transkrip, ujian, tagihan, surat, dokumen, bimbingan akademik; wali: anak, perkembangan, tagihan |
| Muhadhir | 7 | Jadwal, kelas kuliah, pertemuan dan presensi, materi, tugas, kuis, forum, nilai, dokumen mengajar, mahasantri bimbingan |
| Discussions | 8 | Bimbingan, LPPPK, artikel, Tanya Hukum |
| Admin, pemantau | 9 | Data akademik, orang, dokumen, surat, pemantauan, kritik & saran, evaluasi |
| Keuangan, petugas | 10 | Tagihan, pembayaran, setoran, transfer, buku kas, rekap angsuran |
| Superadmin | 11 | Pengguna, pengaturan, identitas dan tampilan, tanda tangan, jenis surat, log audit |

Not in the API (D41): maintenance mode, backups and the CMS page (web only).
