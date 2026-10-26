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
- [ ] Set institution name/address and payment rules; check they appear on a receipt and KHS PDF
- [ ] Find a change in the Audit log (who, old → new)

## Admin
- [ ] Study program, classes, subjects, grade scale, academic year
- [ ] Import the real mahasantri list from Excel (check the error rows, then import); hand out the credentials sheet
- [ ] Add muhadhir and wali santri; link a wali to two siblings
- [ ] Classes per semester level, Putra and Putri; placing a mahasantri in a class of the other gender is refused
- [ ] Place mahasantri in classes; add course offerings with muhadhir and schedules; activate the semester
- [ ] Open the next semester: copying rosters moves everyone up one semester into the same-gender class
- [ ] Verify and reject an uploaded document (the owner sees the note)
- [ ] Verify a cash handover (reject one payment in it) and a transfer
- [ ] Assign a pembimbing akademik to a class of mahasantri; change one mahasantri's supervisor (the old one stays in the history)
- [ ] Close the semester; check that it is read-only
- [ ] A mahasantri who retook a subject: the transcript lists it once with the best grade and the IPK counts only that; the old semester's KHS is unchanged

## Admin Keuangan
- [ ] Fee types and rates (one class-specific); copy rates to next year
- [ ] Award a scholarship; generate bills; check amounts against the manual calculation
- [ ] Record cash and transfer payments; try breaking the rules (4th instalment, below Rp 200.000, overpaying)
- [ ] Hand over cash; print the provisional slip and, after verification, the official receipt
- [ ] Verify one transfer confirmation from a mahasantri and reject another with a reason
- [ ] Receive a cash handover from the putri payment collector and verify one of her transfers

## Muhadhir
- [ ] Add meetings (one online) and take attendance
- [ ] Materials, an assignment with grading, a quiz with an essay question
- [ ] Grade components, enter scores by hand and by Excel import, copy assignment/quiz scores, finalise
- [ ] As pembimbing akademik: the supervisee list matches; open one supervisee's record; post in the group forum

## Petugas Pembayaran (a putri mahasantri)
- [ ] Switch to the role; only putri bills are listed; a putra bill cannot be opened
- [ ] Record a cash and a transfer payment; hand the cash over; nothing can be verified from this role

## Pemantau Akademik
- [ ] Dashboard shows the right attendance percentages and the below-minimum list
- [ ] Record pesantren activities; the wali sees them, the KHS does not

## Mahasantri (on a phone)
- [ ] First login, password change, document upload
- [ ] Join an online meeting, submit an assignment, take the quiz until the time runs out
- [ ] KHS and transcript (and PDFs) after grades are final; bills with pending and verified payments
- [ ] Pay a bill by transfer and send the confirmation with a photo of the receipt

## Wali santri (on a phone)
- [ ] Sees only their own children; switches child and semester
- [ ] Grades, attendance, activities, bills and receipts are correct and read-only

## Notifications (any role)
- [ ] A new forum message, a new assignment and a confirmed transfer each show on the bell of the right people
- [ ] Clicking a notification opens the right page; "Tandai semua dibaca" clears the bell
- [ ] With the scheduler running, an assignment due tomorrow and a bill due in 7 days send one reminder each

## Sign-off
| Role | Tester | Date | Result |
| --- | --- | --- | --- |
| | | | |
