# SIAKAD Mahad Aly — UAT checklist

User acceptance testing with the institution's real staff and a copy of real data. Each person works
through their role's list with the matching guide in [`panduan/`](panduan/README.md) and notes anything
that is wrong, unclear or missing. Run it on a staging copy, not the live server.

**Before starting:** `php artisan migrate:fresh --force` on staging (no demo seed), create the first
Superadmin with `php artisan siakad:superadmin`, then let the testers build everything below through
the app, using a real class and real fee amounts. Take a `php artisan siakad:backup` before and after.

Mark each line ✅ / ❌ and write the problem next to ❌.

## Superadmin
- [ ] Create accounts for Admin, Admin Keuangan and Pemantau Akademik; each logs in and must change the temporary password
- [ ] Give one muhadhir the Pemantau Akademik role; they get the role picker and can switch roles
- [ ] Confirm an Admin cannot reset that muhadhir's password
- [ ] In Identitas, fill in the institution details and upload the app logo, the institution logo and the name in Arabic calligraphy; the logo shows in the sidebar, on the login page and as the tab icon; Pratinjau kop surat shows the letterhead
- [ ] In Tanda tangan & stempel, add the Mudir and the Bendahara with their signature images and two stamps; set the transcript to the Mudir + institution stamp and the receipt to the Bendahara + finance stamp
- [ ] As Admin, print a transcript with images, then without (blank space for a wet signature), then signed by someone else; as a mahasantri, print it and get the default
- [ ] Set the payment rules; switch light / dark / system and check the pages you use in dark mode
- [ ] Find a change in the Audit log (who, old → new)
- [ ] Turn maintenance mode on with a reason: the English banner shows on staff pages (not for muhadhir/mahasantri); as Admin, add a previous academic year (both semesters closed) and place mahasantri in a class there; turn it off: the semester is read-only again; the session lists the changes

## Admin
- [ ] Study program, classes, subjects, grade scale, academic year
- [ ] Import the real mahasantri list from Excel (check the error rows, then import); hand out the credentials sheet
- [ ] Import the subject list from Excel (no credentials sheet); an existing code is refused
- [ ] Letters: enter the institution's numbering format and the starting number of this year (Jenis surat), check the example; adjust the templates; write a Surat Tugas and an undangan, print one with and one without signature images; cancel one with a reason (the next letter does not reuse its number)
- [ ] Record an incoming letter with a scan and a disposition; mark it done; export the agenda to Excel
- [ ] With maintenance mode on, add the previous academic year; in Data tahun sebelumnya import its class placements, then its final grades; open a mahasantri's KHS and transcript (IPK includes them); correct one grade with a reason; turn maintenance off: the pencil buttons disappear
- [ ] Add muhadhir and wali santri; link a wali to two siblings
- [ ] Classes per semester level, Putra and Putri; placing a mahasantri in a class of the other gender is refused
- [ ] Place mahasantri in classes; add course offerings with muhadhir and schedules; activate the semester
- [ ] Try to book a muhadhir, a class and a room twice at the same time (refused); print the timetable of a class
- [ ] Add putra and putri exam rooms and a UTS period; schedule the exams (a clashing class or invigilator is refused; two classes may share a room); choose the period's rooms and shuffle the seats once: each mahasantri has the same room and seat on every exam; publish
- [ ] As a mahasantri with an unpaid bill, open Kartu ujian (not printable; the bill is named); grant a dispensation as Admin and print the card (the letter is on it)
- [ ] Open the next semester: copying rosters moves everyone up one semester into the same-gender class
- [ ] Verify and reject an uploaded document (the owner sees the note)
- [ ] Verify a cash handover (reject one payment in it) and a transfer
- [ ] Assign a pembimbing akademik to a class of mahasantri; change one mahasantri's supervisor (the old one stays in the history)
- [ ] Close the semester; check that it is read-only
- [ ] A mahasantri who retook a subject: the transcript lists it once with the best grade and the IPK counts only that; the old semester's KHS is unchanged

## Admin Keuangan
- [ ] Fee types and rates (one class-specific); copy rates to next year
- [ ] Award a scholarship; generate bills; check amounts against the manual calculation
- [ ] After billing, add a new fee with a class-specific rate and use "Add to existing bills": amounts follow the class rate, the 50% scholarship grows, a paid bill becomes partly paid, and running it again adds nothing
- [ ] Record cash and transfer payments; try breaking the rules (4th instalment, below Rp 200.000, overpaying)
- [ ] Hand over cash; print the provisional slip and, after verification, the official receipt
- [ ] Verify one transfer confirmation from a mahasantri and reject another with a reason
- [ ] Receive a cash handover from the putri payment collector and verify one of her transfers
- [ ] Unverified payments: cash not handed over, a handover and a transfer each show where they wait, with whom and for how many days; Remind notifies the holder once a day
- [ ] Cash book: add Kas and the bank account with their opening balance and set the defaults; record a cash payment into Kas, verify it, and see it in the Kas ledger; record an expense with a receipt photo, an infaq, and a deposit from Kas to the bank; void one entry with a reason; check the balances against the real ones
- [ ] Close the books through last month; an entry dated in it is refused (allowed only in maintenance mode); print the cash report for the semester with the Bendahara signature
- [ ] Rekap angsuran: pick a semester; one column per instalment (raise one bill to 5 and see 5 columns); a verified payment is ticked, an unverified one shows a clock; click an empty cell to reach the bill; the arrears from last semester match; filter a class and Putri; export to Excel and print
- [ ] In maintenance mode, import last year's bills & payments and income & expenses (Data tahun sebelumnya); the semester's bills show lunas/sisa and the cash report includes them

## Muhadhir
- [ ] Add meetings (one online) and take attendance
- [ ] Materials, an assignment with grading, a quiz with an essay question
- [ ] Grade components, enter scores by hand and by Excel import, copy assignment/quiz scores, finalise
- [ ] As pembimbing akademik: the supervisee list matches; open one supervisee's record; post in the group forum
- [ ] My documents: upload the S1 ijazah with its gelar, add another ijazah for S2; the same level twice is refused; the Admin sees "Ijazah S2 · institution · year · gelar"
- [ ] Classes: create "Putra A" starting in the next semester at Semester II; carry the class placements forward (each class keeps its mahasantri); activate the next semester and see "Semester II" become "Semester III" while last semester's KHS keeps the old class name
- [ ] Download the RPS and journal templates on the Dokumen tab: they open in Word with the letterhead, the class details and (journal) the meetings filled in
- [ ] As Admin, on Templates: download the mahasantri import template (green header, example row, Petunjuk sheet), upload a changed copy and see the import page download it; a copy without the nim column is refused; Use built-in restores it
- [ ] Bimbingan: in your writing group send a message with a file, reply to one, add a note, mute a mahasantri until tomorrow and lock the discussion; the mahasantri sees why they cannot post
- [ ] LPPPK: post in Penelitian, reply to a mahasantri's question (only they are notified), see unread counts on channels you never opened
- [ ] Articles: approve one mahasantri article and ask for changes on another (with a note); publish your own article directly, edit it and publish again
- [ ] With the CMS staging values in .env: publish an article, see "Diterima editor website" within a minute or two; after the CMS editor approves it, "Periksa status" shows the website link; withdraw it and it leaves the website
- [ ] Tanya Hukum (with the CMS staging values): forward a question from the CMS; it appears in LPPPK → Tanya Hukum with its discussion. Write the answer, ask for approval; two other muhadhir approve; the CMS shows it answered. Correct it after publication: the CMS holds the correction for its moderator
- [ ] Fill the journal fields of a few meetings, print the journal, upload the RPS and the signed journal recap (Dokumen tab); after the Admin returns the RPS with a note, upload a new version
- [ ] Add the putri class of a subject at the same time, muhadhir and room as the putra class: SIAKAD asks "joint class or mistake"; choose joint class and see the badge, one timetable block, and one RPS for both. Another subject at that time is still refused
- [ ] Letters: download the Word starting point for Surat Keterangan Aktif on Templates, adjust the layout, upload it; issue a letter and open it (PDF with LibreOffice, else Word) with number, name and signature in place; replace the template and see the earlier letter unchanged
- [ ] As Admin: add an LPPPK channel, lock another; on Moderasi mute a mahasantri for a day with a reason (they can read, not post, and are told), close access for another (LPPPK disappears for them), then lift both
- [ ] As Admin: join the putra and putri classes of a subject taught together, accept one document and return another (Dokumen mengajar); the monitor sees the same list without the review buttons

## Petugas Pembayaran (a putri mahasantri)
- [ ] Switch to the role; only putri bills are listed; a putra bill cannot be opened
- [ ] Record a cash and a transfer payment; hand the cash over; nothing can be verified from this role
- [ ] Rekap angsuran lists only putri mahasantri, with no Putra/Putri filter

## Pemantau Akademik
- [ ] Dashboard shows the right attendance percentages and the below-minimum list
- [ ] Record pesantren activities; the wali sees them, the KHS does not
- [ ] Evaluasi muhadhir: see the results and comments of a class with 3+ answers, and nothing for a class with fewer; export to Excel; no setup tab

## Mahasantri (on a phone)
- [ ] First login, password change, document upload (also a 3–9 MB scan; a file over 10 MB is refused with a clear message); the ijazah asks for level, school and graduation year
- [ ] Join an online meeting, submit an assignment, take the quiz until the time runs out
- [ ] KHS and transcript (and PDFs) after grades are final; bills with pending and verified payments
- [ ] Pay a bill by transfer and send the confirmation with a photo of the receipt
- [ ] Request a Surat Keterangan Aktif Kuliah; after the Admin issues it, download it from Surat saya
- [ ] Request a letter choosing the first letter type in the list (it must not say no letter was chosen)
- [ ] Artikel: write an article with a list and a quote, check the preview, send it for review; after a muhadhir asks for changes, revise and send again; after two approvals it shows Published
- [ ] Evaluasi muhadhir (period open): the dashboard reminds you; evaluate one class (every statement needs a score); it shows Done and cannot be answered again. With "Wajib sebelum KHS", the KHS waits until all classes are evaluated
- [ ] Bimbingan: open your group from the menu (unread count shown), read the muhadhir's message, answer with a file; a message from someone else appears within seconds

## Wali santri (on a phone)
- [ ] Sees only their own children; switches child and semester
- [ ] Grades, attendance, activities, bills and receipts are correct and read-only

## Evaluasi muhadhir
- [ ] As Admin, copy the statements from the previous semester (or get the defaults), add one, turn one off, reorder; set the period from today for a week
- [ ] After three mahasantri answer a class, the results show averages, the 1–5 spread and comments; with two answers nothing shows
- [ ] Try to reword a statement that has answers: refused; turn it off instead
- [ ] After the period closes, the muhadhir sees their own classes only under Evaluasi saya

## Kritik & saran
- [ ] As a mahasantri, a wali and a muhadhir, each send a message (one anonymous); each sees it under "Pesan saya" as Baru
- [ ] As Admin: the menu count shows the new messages; filter by sender role and category; the anonymous one shows "Anonim", and searching the sender's name does not find it
- [ ] Set one to Diproses, reply to another; the senders are notified and see the status and reply; export to Excel
- [ ] As Superadmin, open the anonymous message and see the sender's name

## Notifications (any role)
- [ ] A new forum message, a new assignment and a confirmed transfer each show on the bell of the right people
- [ ] Clicking a notification opens the right page; "Tandai semua dibaca" clears the bell
- [ ] On the HTTPS server: turn on "Aktifkan notifikasi di perangkat ini" in Chrome and on an Android phone; a new forum message pops up with SIAKAD closed, and clicking it opens the notification
- [ ] On an iPhone (iOS 16.4+): add SIAKAD to the Home Screen, turn pop-ups on there, receive one
- [ ] Log out on a shared computer: the next notification for that person no longer pops up there
- [ ] With the scheduler running, an assignment due tomorrow and a bill due in 7 days send one reminder each

## Sign-off
| Role | Tester | Date | Result |
| --- | --- | --- | --- |
| | | | |
