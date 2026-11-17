# After-review plan

Source: `docs/req/after-review.md`. Answers given on 2026-11-17 are recorded as decisions D54–D60.

## What I found

- **KHS shows E for previous-year grades (reproduced in the dev data).** 480 grades imported from the archive
  are E; the 48 imported later follow the scale (A, A-, B+ …). The scale used now was saved at 16:23:14 on
  2026-11-08; every E grade was imported before that, every correct one after. At import time the scale table
  was empty, and an empty scale gives E. The letter is fixed when a grade is imported (final grades never
  change with the scale, by design), so those 480 stayed E, and the IPS with them.
- **Instalments.** One bill has one instalment limit for the whole bill (`bills.max_installments`, default
  from `payment.max_installments`); payments pay the bill as a whole, not fees.
- **Monthly fees** are always billed 6 months per semester (`FeeFrequency::MONTHS_PER_SEMESTER`).
- **The grid** ("Rekap angsuran") shows a tick per instalment of the whole bill; it has no filter by fee.
- **"Sudah punya tagihan untuk semester ini."** comes from the *bills* archive import, not placements: on
  2026-11-11 the bills of 2025/2026 Ganjil were made with *Buat tagihan* (UKT, Daftar Ulang, tabungan), and an
  archive import refuses a mahasantri who already has a bill that semester (one bill per mahasantri and
  semester). The import into Genap worked because Genap had no bills yet.

## Decisions

- **D54 Imported grades follow the scale.**
  - Grades cannot be imported while the grade scale is empty (the page says to set the scale first).
  - The letter is worked out when the row is imported, not when the file is checked, so a scale saved in
    between is used.
  - *Terapkan skala nilai* on the previous-year data page (Admin) re-applies the current scale to grades that
    were imported **with a score and no letter** (grades with a letter from the archive keep it; grades
    entered in classes are never touched). It shows how many change before confirming, is written to the audit
    log, and fixes the 480 E grades in one go. KHS, transcript and IPS/IPK follow at once.
- **D55 Each fee is paid on its own (answer: "Each fee paid on its own").**
  - Every fee type gets **maks. angsuran** (e.g. Pendaftaran 3, SPP 1; default from the current
    `payment.max_installments`). A bill line takes the limit of its fee when the bill is made.
  - A payment covers one or more fees of the bill, with an amount for each (**payment allocations**). Per fee:
    at most its number of payments, each at least the minimum instalment unless it settles that fee, never
    more than what is left of it, and the last allowed payment settles it. The bill is paid when all its fees
    are.
  - Scholarship discounts are spread over the bill's fees in proportion to their amounts (rounded so the sum
    is exact); each fee's remaining amount is after its share.
  - Superadmin can raise the limit of one fee on one bill (as today for the whole bill).
  - Payments that do not say which fees (a payer's transfer confirmation, the API, the archive import) are
    spread automatically: fee by fee in the bill's order, within each fee's limits. Staff can still choose.
  - Existing payments are spread the same way when this is installed, so every past payment has its
    allocations and nothing changes in the amounts paid. Receipts list the fees paid.
- **D56 Months per monthly fee (answer: "Per monthly fee").** Each monthly fee type has **bulan per semester**
  (default 6), e.g. Syahriah 5 (10 a year). The bill line is "Syahriah (5 bulan)" = rate × 5, and remembers its
  months. Bills already made keep their months.
- **D57 Paying chosen fees on the bill page.** The *Catat pembayaran* form lists the bill's fees with what is
  left of each and its instalments used / allowed; ticking a fee fills its amount with what is left (it can be
  lowered, or for a monthly fee set by months); **Jumlah** is the sum, read-only. The rules of D55 are checked
  per fee with the message under that fee.
- **D58 Grid by fee (answer: "Months for monthly fees").** The grid gets a **Jenis tagihan** filter:
  - *a monthly fee*: one tick column per month of the bill line (ticked when paid: what was paid on that fee
    ÷ the monthly rate; a clock while a payment waits for verification), then what is left;
  - *another fee*: no ticks, just paid, left, and instalments used / allowed;
  - *all fees* (default): per mahasantri each fee as paid / left, and the total left.
  The Excel export and print follow the chosen view.
- **D59 Archive bills replace unpaid generated bills (answer: "Replace unpaid bills").** When the mahasantri's
  bill of that semester has no payments, an archive row voids it (note: "Diganti data arsip") and creates the
  archive bill; bills with payments are still reported, with their number, not changed.
- **D60 API.** Bill responses get the fees with what is left, instalments and allocations; recording a payment
  takes optional `allocations` (fee line id → amount; without them, D55's automatic spread); fee types take
  `max_installments` and `months_per_semester`; the grid endpoint takes the fee filter.

## Steps

### Step 1 — Grades follow the scale (small) — done 2026-11-17
- No grade import without a scale; letter resolved at import; *Terapkan skala nilai* with preview count,
  audit entry; tests; the dev data's 480 E grades fixed by using it.

### Step 2 — Archive bills replace unpaid bills (small) — done 2026-11-17
- `BillHistory`: void an unpaid bill of the semester and create the archive one; report bills with payments by
  number; tests.

### Step 3 — Fee settings (small–medium) — done 2026-11-17
- `fee_types.max_installments` and `months_per_semester`; `bill_lines.max_installments` and `months`; the fee
  form and list; `GenerateBills` and *Tambah biaya ke tagihan* use them; tests.

### Step 4 — Payments per fee (large) — done 2026-11-17
- `payment_allocations` (payment, bill line, amount); discount shares per line; the rules per fee in
  `RecordPayment`; automatic spread for payments without allocations (transfer confirmation, archive, API);
  migration spreading existing payments; per-line limit raise for Superadmin; receipts list the fees; voiding
  and rejecting payments release their allocations; tests (rules, spread, migration, discounts).

### Step 5 — Bill page payment form (medium) — done 2026-11-17
- Fee checklist with left / instalments, amounts, months for monthly fees, auto *Jumlah*, errors per fee;
  payment history shows the fees of each payment; tests.

### Step 6 — Grid by fee (medium) — done 2026-11-17
- *Jenis tagihan* filter; month ticks for monthly fees; paid / left for other fees and for all; export and
  print; the Petugas Pembayaran sees the same for their mahasantri; tests.

### Step 7 — API and docs (small–medium) — done 2026-11-17
- D60 endpoints and fields, OpenAPI export; DESIGN, UAT, guides (keuangan, admin), OPERATIONS if needed.

## Size and order

Steps 1 and 2 are small fixes and come first. Steps 3 → 4 → 5 → 6 build on each other (the form and the
grid need the allocations). Step 4 is the largest: it changes how every payment is counted, so it migrates
existing payments and has the most tests.
