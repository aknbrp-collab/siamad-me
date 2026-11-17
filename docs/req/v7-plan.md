# v7 plan

Source: `docs/req/v7.md` (with your notes of 2026-11-08). Answers given on 2026-11-08 are recorded as
decisions D42–D49.

## What changed since the first draft

| v7.md note | Before | Now |
|---|---|---|
| Upload: "the Unggah button cannot be clicked" | Guessed a file-type check | **Cause found and reproduced** (D42): the file never reaches SIAKAD, so the button stays greyed |
| Letter: dispensation letter for mahasantri who have not finished paying | — | New, D47 and Step 3 |
| Template: also the dispensation letter | 5 printouts | 6 printouts (D44) |
| Article: picture (optional) sent to the CMS | — | New, D48 and Step 5 |
| Student list, sidebar, confirmation popup | — | Unchanged (D43, D45, D46) |

## What I found

- **Upload button (reproduced).** Choosing a file sends it to Livewire's temporary upload first; the
  "Unggah" button stays disabled until that finishes. On `http://ready.test:8000` (the `APP_URL`, i.e.
  `php artisan serve`) that request fails with **422** and PHP warns *"File upload error – unable to
  create a temporary file"*. Reason: `artisan serve` starts PHP with only a few environment variables
  (`ServeCommand::$passthroughVariables`); on Windows `TEMP`/`TMP` are not among them, so PHP falls back
  to `C:\Windows` (not writable) and every upload fails with PHP error 6. Checked side by side: the same
  PHP with `TEMP` passed accepts the file, and Herd (`http://ready.test`, port 80) accepts it too.
  So it is **every** upload screen, not only /templates and Dokumen mengajar; the v4 fix worked because it
  was tested through Herd.
- **Upload button, second cause (found in Step 1).** On /templates, Dokumen mengajar and My documents the
  button called `upload(…)`, which Livewire 4 treats as its own built-in `$wire.upload(name, file)`: the
  click threw an error in the browser and never reached SIAKAD, on every server. The page methods are
  renamed; a test stops any page method from taking a Livewire built-in's name again.
- **Student pickers:** six pickers only list mahasantri after 2+ letters are typed and then show
  10–15 matches: bimbingan groups, semester placements, the wali form, scholarships, monitor activities
  and LPPPK moderation. The Pembimbing akademik page (`admin/⚡supervision`) already lists every active
  mahasantri with class filters and is the model.
- **Dispensation today:** the exam card needs the semester bill paid (lunas). Admin can record an exam
  dispensation (rector's letter number and date) on the exam period, which lifts that. There is no way
  for the mahasantri to produce the letter themselves.
- **Printouts:** KHS, transcript, exam card, receipt and cash report are dompdf PDFs from Blade views
  (`resources/views/records/*`, `receipts/payment`). Letters already work from Word templates
  (`LetterDocuments` + `DocxToPdf`, v4 D19), and /templates lists the template slots.
- **Articles to the CMS:** the CMS spec (v1.1, §3.1) has no picture field; `body_html` is sent and the CMS
  removes only unsafe markup. Article pictures therefore need a public link the CMS can load.
- **Sidebar:** the theme switch and user menu are at the bottom of the same scrolling sidebar as the menu.
- **Confirmations:** 42 `wire:confirm` uses in 32 files show the browser's plain `confirm()` box. Livewire
  calls one hook for all of them (`el.__livewire_confirm`), so one replacement styles them all.

## Decisions

- **D42 Uploads reach SIAKAD again.**
  - The upload buttons call the page's own method (`uploadFile`), not Livewire's built-in `upload`; a test
    keeps page methods from taking a Livewire built-in's name.
  - The app adds `TEMP`, `TMP` and `TMPDIR` to the variables `artisan serve` passes to PHP, so PHP has a
    writable temporary folder on Windows (no change needed on Herd or Linux servers).
  - When a temporary upload fails anyway, a toast says so and the field shows "File gagal diunggah…"; the
    Superadmin dashboard warns when PHP cannot store uploads (and why).
  - Checked and kept: the `mimes:` rules accept Word/Excel files saved by Word, LibreOffice and PhpWord in any
    zip order; the v4 select override still covers every select that starts empty.
- **D43 Student pickers list everyone (answer: "Yes, list all").** With nothing typed, a picker lists all
  active mahasantri straight away, filterable by class, Putra/Putri and a name/NIM search, like
  Pembimbing akademik. Pages of 50 with "Muat lagi". Students already chosen are marked or hidden, as
  each screen does now. The API candidate endpoints get the same filters, without requiring a search.
- **D44 Word templates for printouts (answer: "Word, like letters").** /templates gets six new slots: KHS,
  Transkrip, Kartu ujian, Kwitansi pembayaran, Laporan kas and Surat dispensasi.
  - Each has a starting Word file with placeholders (`${nama}`, `${nim}`, `${ip}`…) and one table row that
    repeats per subject / exam / bill / cash line. Logo, signature and stamp are images at their placeholder.
  - With a template uploaded, the printout is filled from it and turned into PDF with LibreOffice, or
    downloaded as Word when LibreOffice is missing (as letters do).
  - Without an upload the built-in PDF stays; "Use built-in" returns to it. Web and API follow the same rule.
  - Templates are managed by Admin, Superadmin and the Pemantau Akademik on /templates (as all templates), also
    the receipt and cash report. Only a verified receipt uses the template (a pending slip keeps its
    watermark); the dispensation request never prints signature or stamp images (the Mudir signs by hand).
- **D45 Sidebar footer stays put.** The menu scrolls on its own; the theme switch and user menu stay fixed at
  the bottom of the sidebar on desktop and in the mobile drawer.
- **D46 Styled confirmations.** One dialog (Flux modal look: title, message, red button for deleting,
  Batal / Ya) replaces the browser box for every `wire:confirm`, without changing the 42 uses.
  `wire:confirm.prompt` (type a word) works in the same dialog. Escape/Batal cancels; keyboard and dark mode work.
- **D47 Dispensation letter ("Surat permohonan dispensasi").** "Not complete their admission" is read as
  *the semester's administration (bill) not yet paid off*:
  - **Who:** a mahasantri whose bill for the active semester is not lunas. The request sits on **Tagihan**
    (a card at the top); **Ujian** links to it where the exam card is blocked for payment.
  - **What they fill in:** the reason (10 to 1,000 characters). The date is the day it was written; writing
    again replaces the reason (one request per semester).
  - **The letter (PDF):** kop surat, student data (name, NIM, programme, class, Putra/Putri, semester, wali,
    phone), the bills still to pay plus the semester's own (total, paid with the number of payments,
    remaining, and the sum), the reason, place and date, and two signature places: the mahasantri and the
    Mudir. The Mudir's place is left empty for a wet signature, with the name of the default signatory of
    "Permohonan dispensasi" (Superadmin → Tanda tangan & stempel) or else the Mudir from Identitas. There is
    no NIY field in SIAKAD, so none is printed. No letter number: it is a request.
  - **Record:** each request is saved (reason, amount still owed at the time). Admin is told about a new
    request; on the exam period's participants a "Diminta" link opens the letter, and "Dispensation" opens
    the existing form with the reason in the note (Admin enters the letter number and date once the Mudir
    has signed). The request then shows as "Disetujui".
  - The wali can see and print their child's request; the API gets the same (create, list, PDF link).
- **D48 Article picture (optional).** An article may have one picture (JPG/PNG/WebP, up to the upload limit,
  shrunk to at most 1600 px wide), with an optional caption.
  - It shows at the top of the article in SIAKAD and is part of the version that reviewers approve.
  - Sent to the CMS as `image_url` (a public link that works only for published articles, changing when the
    picture changes) **and** as an `<img>` at the top of `body_html`, so the website shows it even before the
    CMS reads the new field. `image_url` is added to the shared spec as v1.2; the CMS team needs to know.
  - Changing or removing the picture of a published article marks it "changes pending" like a text change.
- **D49 Order.** The upload fix comes first (it blocks work today), then the small items, the templates last.

## Steps

### Step 1 — Upload fix (small) — done 2026-11-08
- Renamed the clashing `upload()` methods (Templates, Dokumen mengajar, My documents) to `uploadFile()`.
- `TEMP`/`TMP`/`TMPDIR` passed through `artisan serve`; a toast when a temporary upload fails; field errors
  on the cashbook proof and discussion files; upload warning on the Superadmin dashboard; OPERATIONS/UAT notes.
- Checked in the browser under `artisan serve` (temporary upload 200, button reaches the server, error shown
  under the field) and the `mimes:` rules against Word/LibreOffice/PhpWord files.
- Tests: `UploadFixTest` (passthrough, built-in names, folder check, php.ini sizes, dashboard warning).

### Step 2 — Student pickers list everyone (medium) — done 2026-11-08
- `StudentPicker` (query, filters, classes, API rules) and the `PicksStudents` page trait with
  `<x-student-picker.filters>` / `<x-student-picker.more>`.
- Bimbingan groups, semester placements (gender of the class, "Belum punya kelas"), wali form (any status),
  scholarships, monitor activities and LPPPK moderation (mahasantri and muhadhir, "who" filter).
- API: placement and scholarship candidates without `search`, 50 a page; `GET /admin/students` with class and
  gender for the other pickers. Tests: `StudentPickerTest`.

### Step 3 — Dispensation letter (medium) — done 2026-11-08
- Table `dispensation_requests`, `ManageDispensationRequests` (who may ask, bills, submit, granted, Admin
  notification); card and form on Tagihan, link from Ujian; read-only card on the wali's Tagihan.
- Built-in PDF `records/dispensation` (A4) at `/dispensations/{id}/letter`; `PrintDocument::Dispensation`
  (default signatory, never signature or stamp images).
- Admin exam period: "Diminta" link and the reason in the dispensation note.
- API: `GET /students/{id}/dispensation`, `POST /student/dispensation`, signed `letter_url`,
  `dispensation_request` in the participants list. Tests: `DispensationRequestTest`.

### Step 4 — Word templates for printouts (large) — done 2026-11-08
- `PrintTemplates`: six slots `print.{khs, transcript, exam_card, receipt, cash_report, dispensation}`
  ("Cetakan" on /templates, with each slot's placeholders), designed starting Word files, the values and
  repeated rows of each printout, filling with TemplateProcessor (`cloneRow`, `deleteRow` when empty,
  images, several-line text) and `DocxToPdf::respond()` (PDF, or Word without LibreOffice or with
  `?format=docx`), also used by letters now.
- The six controllers use the template when one is uploaded, else the built-in PDF; the API's signed links
  go through them. Only a verified receipt uses it (a pending slip keeps its watermark); the dispensation
  request never prints signature or stamp images.
- Starting files and letter/teaching templates: cells get explicit widths (LibreOffice collapsed cells
  without one, which hid the letterhead text). Tests: `PrintTemplateTest`.

### Step 5 — Article picture (small–medium) — done 2026-11-08
- `articles` and `article_versions` get `image_path` / `image_caption`; `ArticleImages` stores a JPEG at most
  1600 px wide under a new name each time and deletes files no working copy or version uses.
- Edit page: upload with preview, caption, remove; article page shows it above the text.
- `/articles/{id}/image` for readers (the working copy, like the text); public `/artikel/{code}/gambar/{hash}`
  for the CMS (the published version's picture; 404 when withdrawn or replaced).
- CMS: `image_url` and `image_caption`, and a `<figure>` at the top of `body_html`; shared spec now 1.2 (tell
  the CMS team).
- API: `image_url` (signed) and `image_caption` in the article, `POST`/`DELETE /articles/{id}/image`.
  Tests: `ArticleImageTest`.

### Step 6 — Appearance (small) — done 2026-11-08
- Sidebar: only the menu scrolls (thin bar); the logo stays at the top, the theme switch and user menu at the
  bottom. On phones these two are in the top bar already.
- `<x-confirm-dialog>` in both app layouts and `confirm-dialog.js`: replaces Livewire's confirm hook for every
  `wire:confirm` (no use changed); first sentence as title, rest as explanation; red with a warning icon for
  removing / withdrawing / signing out / ending, else the green question style; `data-confirm-tone` overrides;
  `.prompt` asks to type the word. Checked in light and dark mode, on a phone and with a short window.

## Size and order

- **Step 1** first and small: the cause is known.
- **Steps 2, 3, 5 and 6** are independent.
- **Step 4** is the largest and comes after Step 3, because it also covers the dispensation letter; it needs
  LibreOffice on the server for PDFs (as letters do).
