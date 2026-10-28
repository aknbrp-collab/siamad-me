# v4 plan

Source: `docs/req/v4.md`. Answers given on 2026-10-28 are recorded as decisions D17–D24.

## What I found

### Bug: the first dropdown choice is "not chosen"

Reproduced on **Surat saya**. The page shows the first letter type as selected, but the form value is
still empty, so sending fails with "pilih surat yang dapat dimohon". Picking the shown item again sends
no change, so nothing is stored. Picking another item and then coming back works.

The cause: when a `wire:model` value is empty, Livewire unselects every option. The Flux placeholder
option is `disabled`, so the browser falls back to the first *real* option and shows it as chosen. Selects
with no placeholder at all have the same problem. There are about 70 such `flux:select`s, and the bug
appears wherever the property starts empty.

*/ i think this bug is appears in many places when there is dropdown form submit, because i found in many places also /*

Also found: the label "Letter" is translated as **Huruf** (alphabet letter). It should be **Surat**.

### Bug: document uploads fail

Reproduced on **Dokumen saya** at `ready.test`. A small file uploads fine, but a 3 MB file is refused
before SIAKAD sees it, with "uploads.ktp gagal diunggah.". The cause is Herd's PHP setting
`upload_max_filesize = 2M` (and `post_max_size = 8M`). Transfer proofs work because phone screenshots
are small; ijazah scans, assignments and RPS files are usually larger. Our own limits disagree as well:
documents allow 2 MB, assignments and teaching documents 10 MB, and the Livewire temporary upload 12 MB.
The error message also shows a raw key (`uploads.ktp`) instead of a readable name.

### Answer to "why is the new fee not on the bills?"

Bills are **snapshots**. **Buat tagihan** reads the fee rates once and writes the lines. A fee added
afterwards does nothing to bills that already exist, and mahasantri who already have a bill that
semester are skipped when bills are generated again. Today the only way to include it is to void the
bill (if nothing is paid yet) and generate it again. Step 6 adds a proper "add this fee to existing
bills" action (D23).

## Decisions

- **D17** Dropdowns: an empty value always shows the placeholder ("Pilih…"), never a real option. The
  Flux placeholder option is no longer `disabled` (published stub), and every select whose value can
  start empty gets a placeholder. Required selects still block sending in the browser.
- **D18** Uploads: one limit for every upload, **10 MB** (the RPS and assignment limit), set in one
  config value. PHP must allow it: Herd's `php.ini` gets `upload_max_filesize = 12M` and
  `post_max_size = 16M`, and the same goes in the deployment notes. Files that are too large are stopped
  in the browser with a clear message ("File lebih dari 10 MB"). Error messages name the field, not the
  key.
- **D19** Letter templates (answer: *Word in, PDF out*): Admin uploads a `.docx` per letter type, with
  placeholders such as `${nama}`, `${nim}`, `${nomor}`, `${tanggal}`, `${perihal}`. SIAKAD fills it with
  `phpoffice/phpword` and converts it to PDF with **LibreOffice** (`soffice --headless --convert-to pdf`).
  The filled `.docx` is also downloadable. A type without an uploaded template keeps today's built-in
  PDF. LibreOffice is **not installed** on this PC yet; while it is missing, SIAKAD offers the `.docx`
  and says so. Server requirement added to `HANDOVER.md`.
- **D20** Classes are cohorts (answer: *Class moves up*): a class records its **entry semester**, and its
  level for any semester is counted from there (ganjil → genap = +1, a new academic year continues the
  count). The displayed name is built as "Semester {roman} {name}", e.g. "Semester III Putra A", so the
  roman numeral changes on its own. Each semester's class placement keeps a snapshot of the level, so
  KHS, transcripts and fee rates of earlier semesters do not change. The copy step becomes "carry the
  same classes forward" by default. Classes past the last level are marked finished, not deleted.
- **D21** Schedule clashes are a question, not a refusal. Saving a clashing offering shows what it
  clashes with and asks **Kelas gabungan** or **Salah input**:
  - **Kelas gabungan** is allowed only when the other offering has the same subject and muhadhir. It
    saves the offering and joins the two for RPS and journal (D14), as Admin's join does today.
  - **Salah input** goes back to the form.
  - Clashes that cannot be a joint class (another muhadhir, or another subject in the same room) stay
    refusals.
- **D22** Ijazah details (answer: *keep gelar separate*): each ijazah entry has the institution, the
  graduation year and a level. Mahasantri have one ijazah. Muhadhir can add several, one per level
  (MA/SMA, S1, S2, S3, or pesantren/other), each with its gelar. The printed title stays the hand-typed
  field.
- **D23** Adding a fee to existing bills (answer: *Yes*): Finance chooses a fee and a semester, previews
  the bills that would get it at the matching rate (class, entry year, scholarship), and adds it as a new
  line. Paid bills become partly paid again. Voided bills and bills that already have that fee are
  skipped. The change is audited and the mahasantri is notified.
- **D24** Templates are managed by staff. Every downloadable template is a **template slot**: the RPS,
  the jurnal mengajar, every Excel import template and every letter type. SIAKAD ships a designed
  default for each (coloured header row, column widths, frozen header, an instruction sheet; Word
  defaults with the letterhead and tables). Admin, Superadmin and the academic monitor can download the
  current file and upload a replacement, or go back to the default.

## Steps

### Step 1 — Dropdown and upload fixes (small–medium) — ✅ done 2026-10-28

- Publish the Flux select stub, remove `disabled` from the placeholder option, and add a placeholder (or
  a real default) to every select whose value can start empty. Fix the "Huruf" translation.
- One upload limit config (`siakad.upload_max_kb`) used by every upload rule and by Livewire's
  `temporary_file_upload.rules`. Check the size in the browser before uploading. Use readable attribute
  names in the errors.
- Raise Herd's PHP limits (`php.ini`), and document them in `HANDOVER.md`.
- Tests: a select regression test (the rendered placeholder is selectable and not disabled); upload rule
  tests at the limit. Browser check of letter request, Dokumen saya and assignment upload with a 3 MB
  file.

### Step 2 — Grades form layout (small) — ✅ done 2026-10-28

- Grade components: the name field gets most of the row and the weight field gets a narrow fixed width.
  The score table keeps the full component name in the header, wrapping when long.

### Step 3 — Ijazah details (medium)

- A new `education_records` table (owner, level, institution, graduation year, gelar, file, review
  status). It replaces the single ijazah document. Existing ijazah uploads are migrated into it.
- **Dokumen saya**: mahasantri fill in one ijazah; muhadhir can add an entry per level, each with its
  gelar. Admin's document review and the "incomplete documents" counts include it.

### Step 4 — Schedule clashes: joint class or mistake (medium)

- `ManageCourseOfferings::save()` returns the clashes instead of throwing when they could be a joint
  class. The page asks the question (D21), and **Kelas gabungan** saves the offering and calls
  `ManageTeachingDocuments::join()`. Copying offerings keeps joint classes joined.
- The timetable and the offering list show joined classes as one block.

### Step 5 — Classes move up each semester (large)

- Migration: `class_groups.entry_semester_id`, a name without the level, and `finished_at`.
  `class_enrollments.level` is a snapshot, back-filled from today's data. Existing classes get their
  entry semester from their current level.
- `ClassGroup::levelIn(Semester)` and `nameIn(Semester)` are used everywhere a class name is shown
  (rosters, KHS, cards, letters, exports).
- New semester: **carry the classes forward** puts every class's active mahasantri back into the same
  class, now one level higher. The old per-class mapping stays for exceptions (repeating or changing
  class). Fee rates by class use the level for that semester.
- This is the riskiest step. Its tests cover KHS, transcripts and bills of earlier semesters staying
  unchanged.

### Step 6 — Add a fee to existing bills (medium) — ✅ done 2026-10-28

- `AdjustBill::addFee(FeeType, Semester)`: preview, then add. Lines use the same rate matching and
  scholarship rules as `GenerateBills`. Bill status is recalculated, and the change is audited and
  notified.
- A button on **Biaya** ("Tambahkan ke tagihan yang sudah ada").

### Step 7 — Managed templates with a designed look (medium–large)

- `templates` table (slot key, file, uploaded by, version) and a **Template** page (Admin, Superadmin,
  academic monitor). The page lists every slot with **Unduh**, **Unggah pengganti** and **Kembalikan
  bawaan**.
- Designed defaults:
  - Excel imports via OpenSpout styles: header colour, widths, frozen row, example row, petunjuk sheet.
  - RPS and jurnal `.docx` via PhpWord: letterhead, identity table, weekly plan or meeting table.
- The muhadhir **Dokumen** tab gets **Unduh template RPS** and **Unduh template jurnal**. The jurnal
  template is pre-filled with the class's meetings when possible.

### Step 8 — Letter templates in Word (medium–large)

- `letter_types.template_path`, uploaded on the letter type page through the template slots (Step 7).
  The page lists the placeholders, and checks the upload for unknown ones.
- Issuing a letter fills the `.docx` (`LetterTemplate::fillDocx()`), stores it, and converts it to PDF
  when LibreOffice is configured (`siakad.libreoffice_path`), otherwise it offers the `.docx`. The
  conversion runs in a queued job with a timeout.
- Tests: fill and placeholder checks with PhpWord. The PDF conversion is tested behind a fake converter.

### Step 9 — Where unverified payments are stuck (medium) — ✅ done 2026-10-28

- On the finance dashboard, the "Menunggu verifikasi" figure opens a tracking list. Each unverified
  payment shows its stage and the person holding it:
  - **Transfer belum diperiksa**: waiting for Keuangan.
  - **Tunai belum diserahkan**: received by X, not yet in a handover.
  - **Serah terima belum diverifikasi**: handed over by X to Keuangan on date D.
- The list also shows how many days each payment has waited. It has filters by stage and person, and a
  reminder notification to the person holding it.

## Size and order

Steps 1–2 first, because they fix bugs people hit today. Then come 9, 6, 3 and 4. Step 5 is the largest
and touches the most screens, so it goes on its own. Steps 7 and 8 come last because they add
dependencies: `phpoffice/phpword` for both, and LibreOffice for the PDF conversion in Step 8.
