# v1 requirements — analysis and implementation plan

Source: [`v1.md`](v1.md). Each item below says what the code does today (checked, not assumed), the
answer to any question in the request, and what to build. Decisions marked **❓** need the client's
answer before that part starts; the recommended option is listed first.

---

## 1. Answers to the questions in v1.md

**"Why can't I enter 2000000?"** — A bug. The amount field on the bill page is
`<input type="number" min="1" step="1000">` ([⚡show.blade.php:267](../../resources/views/pages/finance/bills/⚡show.blade.php)).
With `min="1"`, the browser only accepts 1, 1001, 2001 … 1,999,001, 2,000,001 — so every round amount
is refused. Fix: `step="1"` (any rupiah amount; the server rules for instalments are unchanged).

**"Admin Keuangan cannot record payments for a closed academic year"** — This already works: tested
on the demo data (close the semester → record a cash payment → hand over → Admin verifies → bill
becomes *Dibayar sebagian*). What gets in the way: (a) the amount bug above, and (b) the bills page
opens on the **active** semester, so old bills are only found by switching the semester dropdown.
Fix: an **"Unpaid, all semesters"** view (tunggakan), plus a regression test so it stays possible.

**"Rolling enrollment — is it already done?"** — Yes. On a semester page, *Salin dari semester lain*
copies every class roster into the new semester (active mahasantri only), promoting one level up when
the new semester is odd; course offerings are copied the same way. What changes is *how* levels are
counted — see §3.

**"Is tingkat (marhalah) the semester?"** — No, today it is the **year of study**: level 1 = semester
I and II, level 2 = semester III and IV, and mahasantri move up one level each odd semester. Also,
in Ma'had Aly regulations *marhalah* means the **programme level** (Marhalah Ula / Tsaniyah /
Tsalitsah, ≈ S1 / S2 / S3), so using it for a class year is misleading. Your suggestion fits better:
count the class level as **Semester I, II, III …** and move up every semester. See §3 and decision D1.

**"Assign ustadz to subject manually, not by keahlian"** — Already the case: nothing uses keahlian.
A course offering is always a manual choice of *subject × class × ustadz × schedule*
(Semester page → *Kelas kuliah*). *Salin kelas kuliah* copies last semester's choices as a starting
point; each one can be changed or removed. No work needed beyond the rename to Muhadhir.

---

## 2. Plan

Ordered so each step is usable on its own and later steps build on earlier ones. Every step ends
with tests (SQLite + PostgreSQL), Pint/Larastan, Indonesian strings, a 375 px check, and docs.

### Step 1 — Quick fixes and terms (small) — ✅ done 2026-10-26
- Amount fields: `step="1"` on the payment form, fee rates and settings.
- **Tunggakan view** on Tagihan: filter "Belum lunas — semua semester"; test paying a closed
  semester's bill end to end.
- **Ustadz → Muhadhir** everywhere visible: role label, menus, pages, PDFs, guides, import template
  headings, and the URL (`/ustadz` → `/muhadhir`, old URLs redirect). Internal names (`Teacher`,
  `teachers` table, NIDM) stay — renaming the database is risk without benefit.

### Step 2 — Self-service profiles (medium)
New page **Profil saya** for mahasantri, muhadhir and wali santri, reusing the admin form's sections:

| Who | Can edit | Read-only (shown, not editable) |
| --- | --- | --- |
| Mahasantri | **Pribadi** (tempat/tanggal lahir, telepon, alamat, foto), **Keluarga** (ayah, ibu, wali) | NIM, nama, jenis kelamin, **Akademik** (prodi, angkatan, status) |
| Muhadhir | nama, gelar, NIK, jenis kelamin, email, Pribadi, Profil akademik (keahlian, Scopus, Google Scholar) | **NIDM**, status kepegawaian |
| Wali santri | nama, hubungan, telepon, email | **kode wali**, linked mahasantri |

- A dedicated action validates only the editable fields, so a crafted request cannot change NIM,
  NIDM, code, academic data or status. Every change is in the audit log as today.
- Assumption: a mahasantri's **name and gender** stay with the academic office (they appear on
  official documents). Muhadhir keep name editable, as asked ("except nidm"); status is excluded
  because it is an employment decision.

### Step 3 — Classes by semester level and gender (medium–large)
- Class **level = Semester I, II, III …** (label "Semester" with roman numerals, e.g. *Semester III
  Putra*). Copying rosters into the next semester moves everyone **one level up every semester**
  (today: only on odd semesters). Existing demo classes are converted (level 1 → Semester I).
- Classes get a **gender**: Putra or Putri (D2). Existing classes need one set before the next roster copy.
- Placing mahasantri into a class only offers — and the server only accepts — mahasantri of the
  class's gender, and by default those whose next level matches the class (new mahasantri start at
  Semester I). The copy/promotion maps each class to the next-level class **of the same gender**.
- To avoid confusion with the academic *semester* (2026/2027 Ganjil), screens say "Semester III"
  for the class level and keep "2026/2027 · Ganjil" for the term.

### Step 4 — Payment by transfer from mahasantri and wali (medium)
- Superadmin sets the institution's **bank account(s)** in Pengaturan (bank, number, account name).
- On *Tagihan saya* (mahasantri) and *Tagihan* (wali): **Konfirmasi transfer** — amount, transfer
  date, sender name/bank reference, and the **receipt** (photo/PDF, max 2 MB). It becomes an
  *unverified* transfer on the bill, subject to the same instalment rules (max 3, min Rp 200.000,
  never more than what is left).
- The finance office sees these in a **"Dari mahasantri/wali"** queue and verifies or rejects with a
  reason; the payer sees the status and the reason, and can submit again. Verified → official receipt,
  as today. Separation of duties holds: the payer submitted it, staff verify it.

### Step 5 — Academic supervisor (Pembimbing Akademik) (medium)
- Admin assigns a muhadhir as **pembimbing akademik** to mahasantri (bulk, by class), with history
  (when the supervisor changes, the old assignment is closed, never overwritten).
- The muhadhir gets **"Mahasantri bimbingan"**: their list with each one's KHS, attendance, and
  activities (read-only), and a **forum for the supervision group** (the existing forum, extended so a
  thread belongs either to a course offering or to a supervision group).
- Mahasantri see their supervisor on the dashboard and can post in the group forum.
- Subject instructors: no change (already manual, see §1).

### Step 6 — Notifications (medium–large)
In-app notifications (D5; email can be added later without changing the events): a bell with the unread count in the header and a list page; each links to the
item. Events:

| Event | Who is notified |
| --- | --- |
| New forum message (class or supervision forum) | the other participants of that forum |
| New assignment / quiz published | mahasantri of the class |
| Work deadline in 24 h, not yet submitted / attempted | that mahasantri |
| Bill due in 7 days and on the due date, still unpaid | the mahasantri and their wali |
| Transfer confirmation submitted (Step 4) | Admin Keuangan / Admin |
| Transfer verified or rejected | the payer |

Reminders run from the scheduler (hourly) and are sent once per item per person. Forum
notifications are grouped per thread so a busy thread does not flood anyone.

---

## 3. Decisions (answered 2026-10-26)

| # | Question | Decision |
| --- | --- | --- |
| D1 | Class level | **Semester I–VIII, promotion every semester** |
| D2 | Class gender | **Putra / Putri only** — every class is single-gender |
| D3 | Who verifies transfers sent by mahasantri/wali | **Admin Keuangan or Admin**; staff-recorded transfers keep the Admin-only rule |
| D4 | Pembimbing akademik per mahasantri | One at a time, history kept (assumed; not asked) |
| D5 | Notification channels | **In-app now**; email later if wanted |

## 4. Size and order

| Step | Size | Depends on |
| --- | --- | --- |
| 1 Quick fixes + Muhadhir | S | — |
| 2 Self-service profiles | M | — |
| 3 Semester levels + gender | M–L | — |
| 4 Transfer from mahasantri/wali | M | — |
| 5 Pembimbing akademik + forum | M | — |
| 6 Notifications | M–L | hooks into 4 and 5 |

All decisions are made; every step can start in order.
