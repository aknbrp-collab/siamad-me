# v3 requirements — analysis and implementation plan

Source: `docs/req/v3.md`. Each item says what the code does today (checked), the answers to the
questions asked on 2026-10-28, and what to build. The decisions are in section 3.

---

## 1. What exists today

**Entering previous-year data.** Academic years and semesters can be created for any year. But a
semester is either *planned*, *active* or *closed*, and every change to a closed semester is refused
(`Semester::assertOpen()`, 33 places). Superadmin can reopen a closed semester with a reason. That
lets you fix one semester, but it is clumsy for loading a whole past year. There is also no bulk
input for class placements, grades, bills or payments of a past semester. Bulk import exists only for
**mahasantri** and **muhadhir** (Admin → Impor). **Subjects** have no import (asked for in v3).

**Exam seats** are shuffled **per subject** today (v2 Step 3), so a mahasantri can sit in a different room
and seat for every exam.

**Letters and expenses** do not exist. Finance today covers bills, payments, scholarships, handovers
and transfer confirmations, i.e. money coming *in*, and it has no idea of an account (Kas/bank).

**Settings** (Superadmin) hold the institution's name, address and phone, payment rules, the bank
accounts text, the receipt footer and the attendance minimum. There are **no images** (logos, stamp,
signature). The PDFs (KHS, transcript, exam card, receipt) print a text-only header.

**Colours and dark mode.** The palette is neutral grey with a near-black accent. **Dark mode already
exists** under *Settings → Appearance* (light / dark / system per user), but it is hard to find, and
some pages were never checked in dark.

---

## 2. Plan

### Step 1 — Identity, green theme and dark mode (medium) — ✅ done 2026-10-28
- **Settings → Identitas** (Superadmin), expanded:
  - **Text:** short name, full institution name, the foundation, address, phone, email, website, city
    (for letters), operating licence (SK) number, and the name of the head of the institution
    (*Mudir/Rektor*). No NIP.
  - **Images** (PNG with a transparent background, stored privately and re-sized on upload):
    - the app logo
    - the institution logo, for the letterhead
    - the **institution name in Arabic calligraphy**, for the letterhead
  - **Signatures and stamps (D11)** are kept as **lists**, not one of each, because different offices
    sign and stamp differently (e.g. the Mudir and the institution stamp, the finance office and its
    stamp):
    - **Signature:** name, position (e.g. *Mudir*, *Bendahara*), the signature image, and which
      office it belongs to.
    - **Stamp:** name (e.g. *Stempel Mahad Aly*, *Stempel Bagian Keuangan*) and the stamp image.
  - **Preview:** the PDF letterhead (logo, Arabic name, identity) and a signature block, as they will
    print.
- The app logo replaces the starter-kit logo in the sidebar, the login page and the PWA icons.
- **PDFs** (KHS, transcript, exam card, receipt, cash-book reports, letters) get the real letterhead.
- **Printing with or without a signature and stamp (D11):**
  - every printable document has a **default**, e.g. the transcript is signed by the Mudir with the
    institution stamp, and the receipt by the Bendahara with the finance stamp
  - the defaults are set in Settings → **Dokumen cetak**
  - when printing, the person is **asked**: print *with signature and stamp* or *without* (for a wet
    signature), and **which signature and which stamp**, with the defaults preselected
  - mahasantri and wali printing their own documents always get the default
- **Green theme (D5):** accent `#66BB6A` with tint `#A5D6A7` on a white base, with black and ash text.
  - It is used for primary buttons, the active menu item, links, focus rings, badges and the header
    strip of tables and cards.
  - **Note:** white text on `#66BB6A` is hard to read (contrast about 2.3:1). So buttons and links
    with text use a darker shade of the same green (`#2E7D32`) or dark text on the green, while
    `#66BB6A` and `#A5D6A7` carry backgrounds, highlights and icons. All of this is in CSS variables,
    so the exact shades are easy to adjust after you see it.
- **Dark mode:**
  - a light/dark/system switch in the header (next to the bell) and on the login page
  - every page and the timetable print checked in dark
  - dark greens tuned for contrast

### Step 2 — Data input mode and subject import (medium) — ✅ done 2026-10-28
- **Data input mode (D6), for entering previous-year data:**
  - **Switch:** Superadmin turns it on (with a reason) and off again in Settings. It stays on until
    switched off.
  - **While it is on:**
    - every staff page shows a yellow **maintenance banner**, worded in English, saying who turned it
      on, when, and why: *"Maintenance mode: previous-year data entry is open. Turned on by
      {name} on {date} — {reason}."*
    - Admin and Admin Keuangan may create and change records **in closed semesters and past dates**:
      the imports below, manual edits and past cash-book entries
    - every change made is tagged in the audit log as made in data input mode, with the reason
  - **When it is off:** everything is locked exactly as today.
  - Turning it on and off is itself audited, and the Superadmin dashboard lists the changes made
    in the last session.
- **Subject import** (Admin → Impor → Mata kuliah): code, name, kitab, SKS and program; validated like
  the mahasantri import, with a template download and a preview before importing.
- Past academic years and semesters can be created directly as **closed** (data mode only).

### Step 3 — Previous-year academic data: classes and grades (large) — ✅ done 2026-10-28
- **Import class placements** for a past semester: NIM × class. Gender and level are checked as
  today, with a warning only.
- **Import final grades (KHS)** for a past semester: NIM × subject code × final score (or letter),
  optionally with the muhadhir and the attendance count.
  - Course offerings that don't exist yet are created (subject × class).
  - Grades are stored as **final** with the grade scale *of that time*.
  - KHS, transcript and IPK then include them like any other semester.
- **Manual entry** (data mode): on a closed semester, Admin can add or adjust a single mahasantri's
  grade, with a reason.
- A report per imported semester: number of students, subjects and grades, and anything skipped,
  with the reason.

### Step 4 — Exam seats: one seat per mahasantri per period (medium; reworks v2 Step 3, D7) — ✅ done 2026-10-28
- Seating moves from each exam to the **exam period**:
  - the period chooses its rooms (putra and putri, as now)
  - **Acak nomor kursi** shuffles **all participants of the period once**, per gender into rooms of
    that gender
  - each mahasantri gets **the same room and seat number for every subject in the period**
- Exams keep their date, time and invigilator. Their rooms are now *derived* (wherever their
  mahasantri sit), so several subjects can share a room at the same time.
  - The clash check keeps **class** and **invigilator**.
  - The **room** clash check is dropped, because mixed seating is the point of fixed seats.
- The exam card shows the room and seat once, at the top, with the subject schedule below.
- Existing seats are migrated: a period whose seats were assigned asks for one new shuffle.

### Step 5 — Cash book: income, expenses, accounts (large, D8) — ✅ done 2026-10-28 (no approval step: the default)
- **Accounts** (Kas tunai, and each bank account), each with an opening balance and date. Admin
  Keuangan manages them; Superadmin sees all of them.
- **Income:**
  - **Tuition payments become income automatically** when verified, into the **account chosen on
    the payment (D9)**: the cashier, collector or reviewer picks Kas or a bank. Cash from a Petugas
    Pembayaran counts when its handover is received.
  - **Other income** (donations, *infaq*, grants) is entered by hand, with a category.
- **Expenses:** date, category (e.g. honorarium, ATK, listrik, konsumsi, perawatan), amount, account,
  paid to, a description and the **proof** (photo or PDF of the receipt/kuitansi).
- **Transfers between accounts** (e.g. depositing Kas into the bank).
- **Rules:**
  - nothing is deleted: a wrong entry is **voided with a reason** (zero-overwrite, as with payments)
  - entries dated in a **closed month** need data input mode
  - categories are kept in a master list
- **Reports:**
  - the cash book per account with a running balance
  - income vs expense per month and per category, per academic year
  - export to Excel and PDF with the letterhead
  - a summary on the finance dashboard
- **Previous-year finance (data mode):**
  - import past **bills and payments** (NIM, semester, fee, amount billed, amount paid, payment date,
    account), so *lunas/sisa* and student histories are complete
  - import past **expenses and other income** (date, category, amount, account, description)
  - set each account's **opening balance** at the start of the first year in the books

### Step 6 — Letters / persuratan (large, D10)
- **Outgoing letters (surat keluar):**
  - **Types:** each letter type (e.g. Surat Keterangan Aktif Kuliah, Surat Tugas, Surat Undangan,
    Surat Rekomendasi, Surat Keterangan Lulus) has a **code** and a **numbering format**.
  - **Numbering form (D12):** you enter the numbering format in a form (Superadmin → Jenis surat).
    The format is built from these parts, in this order:
    - `{nomor}`: the counter, zero-padded (e.g. `001`)
    - fixed text, e.g. `MA`
    - `{kode}`: the letter type's code (e.g. `SKA`)
    - `{bulan-romawi}`: the **Gregorian** (Masehi) month in Roman numerals (e.g. `X`)
    - `{tahun}`: the **Gregorian** year (e.g. `2026`)

    For example `{nomor}/MA/{kode}/{bulan-romawi}/{tahun}` gives **`001/MA/SKA/X/2026`**. The
    separator (`/`), the fixed text and the zero-padding width are typed in the form, and it shows a
    **live example** of the next number. No Hijri dates in the number.
  - **Numbers:** **one counter shared by all letter types**, so 001, 002, 003… run across every type
    (e.g. `001/MA/SKA/X/2026`, then `002/MA/ST/X/2026`). It restarts at 1 every Gregorian year
    (January) and is never reused, including for cancelled letters. A **starting number** for the
    year lets numbering continue from your offline register. The counter is taken inside a database
    lock, so two letters issued at the same moment never get the same number.
  - **Templates:** editable text with placeholders filled from SIAKAD, such as
    `{mahasantri.nama}`, `{mahasantri.nim}`, `{mahasantri.semester}`, `{prodi}`, `{tahun_akademik}`,
    `{muhadhir.nama}`, `{tanggal}` and `{mudir.nama}`.
  - **Writing a letter:** choose the type and the subject person (mahasantri, muhadhir or none),
    check the filled text, edit it if needed, and **issue** it. Issuing takes the number, saves the
    final text and makes the PDF (letterhead, number, body, signature block, with the stamp and
    signature images if the type uses them).
  - **Register:** every letter issued, with number, date, type, subject person, issued by and the
    PDF; searchable by year and type.
  - **Corrections:** an issued letter is never edited. It is **cancelled with a reason** (the number
    stays in the register as cancelled) and a new one is issued.
- **Incoming letters (surat masuk):**
  - **Log entry:** an agenda number per year, received date, sender, the sender's letter number and
    date, subject, a scan (PDF/photo), and a disposition (to whom, an instruction, done or not).
  - The register can be searched and exported to Excel.
- **Mahasantri requests:**
  - mahasantri request the letter types marked *requestable* (e.g. Surat Keterangan Aktif Kuliah,
    for a scholarship or a visa), with the purpose of the letter
  - Admin gets a notification, then approves (the letter is issued as above) or rejects it with a
    reason
  - the mahasantri is notified and downloads the PDF from **Surat saya**
- **Who uses it:** Admin, plus a new optional role **Petugas Surat** (if you want someone in the
  office who handles letters but not academics). Superadmin manages the letter types and templates.

---

## 3. Decisions (answered 2026-10-28)

| # | Question | Decision |
| --- | --- | --- |
| D5 | Green accent | Primary **#66BB6A**, tint **#A5D6A7**, base white, black/ash text (a darker #2E7D32 for text on white, for readability) |
| D6 | Data input mode for previous-year data | **Superadmin** turns it on (with a reason) and off; stays on until switched off; banner + audit |
| D7 | Exam seats | **One seat per mahasantri per exam period**, shuffled once per period (not per subject) |
| D8 | Expense tracking | **Full cash book**: income (tuition + other) and expenses, accounts with running balances, transfers between accounts |
| D9 | Which account tuition payments go into | **Chosen per payment** (Kas or a bank account) |
| D10 | Letters | **Inside SIAKAD**: outgoing letters with numbering and templates, incoming letters log, mahasantri requests |
| D11 | Signatures and stamps | **Several** of each (e.g. Mudir + institution stamp, Bendahara + finance stamp); each document has a default, and when printing staff choose with or without, and which signature and stamp |
| D12 | Letter numbering | Entered in a form: `{nomor}` (zero-padded) / fixed text (`MA`) / `{kode}` / Gregorian month in Roman numerals / Gregorian year, e.g. `001/MA/SKA/X/2026`; **one counter shared by all letter types**, restarting every Gregorian year, with a starting number; live example in the form |
| — | Previous-year data to enter | Students & classes, grades (KHS), bills & payments, expenses |

Still open (to settle at the start of the step, with defaults if no answer):
- **Step 5:** the expense categories to start with, and whether an expense above a set amount needs
  Superadmin approval. *Default: no approval.*
- **Step 6:** the first letter types and templates to create (you enter the numbering in the form,
  D12). *Default: the five types above, with an empty format to fill in.* Also whether a *Petugas
  Surat* role is wanted. *Default: Admin only.*

## 4. Size and order

| Step | Size | Depends on |
| --- | --- | --- |
| 1 Identity, green theme, dark mode | M | — (letterhead, stamp and signature used by Steps 5–6) |
| 2 Data input mode + subject import | M | — |
| 3 Previous-year classes and grades | L | 2 |
| 4 Exam seats per period | M | — |
| 5 Cash book (+ previous-year finance) | L | 2, 1 for report letterhead |
| 6 Letters | L | 1 |

Steps 1, 2 and 4 are independent and can start in any order. Step 3 needs Step 2. Step 5 needs
Step 2 (and uses Step 1's letterhead). Step 6 uses Step 1's images.
