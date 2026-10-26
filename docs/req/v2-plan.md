# v2 requirements — analysis and implementation plan

Source: `mbuh.md` (project root, untracked). Each item says what the code does today (checked), the
answer to any question in the request, and what to build. Decisions marked **❓** need the client's
answer before that step starts.

---

## 1. What exists today

**"Where can I set muhadhir, subject, class and day/time to make a schedule?"** — It exists. A *course
offering* is exactly that: subject × class × muhadhir × **day × start–end time × room**. Admin →
**Tahun akademik** → open the semester → tab **Kelas kuliah** → **Tambah kelas kuliah** (or *Salin kelas
kuliah* from last semester and adjust). Mahasantri see it as a timetable in **Kelas saya**; muhadhir see
their classes ordered by day.
What is missing: **no clash check** (the same muhadhir, class or room can be booked twice at the same
time) and **no weekly timetable view** for the academic office. Step 2 adds both.

**Payment collector.** Today a mahasantri account cannot hold any staff role (a deliberate rule), and
Admin Keuangan sees and records every bill. So she needs a new, narrower role (Step 1).

**Exams and push notifications** do not exist yet (Steps 3–4).

---

## 2. Plan

### Step 1 — Petugas pembayaran (payment collector), limited to one gender (medium) — ✅ done 2026-10-27
- New role **Petugas Pembayaran** with a **scope: Putra or Putri**, given by Superadmin under
  Pengguna & peran. It is the one staff-like role a mahasantri may hold (the existing rule otherwise
  stays); she switches between Mahasantri and Petugas Pembayaran with the role picker.
- What she can do, only for bills of mahasantri of her scope's gender (enforced in the actions, not
  only the pages):
  - see those bills (list, arrears filter, bill page), record **cash** and **transfer** payments under
    the normal instalment rules, print the provisional slip;
  - hand her cash over to the Admin in a **setoran** (the existing handover flow).
- What she cannot do: verify or reject anything, see the other gender's bills, fees, scholarships,
  the finance overview, or change bills (void, instalment limit).
- Verification of her entries: **Admin Keuangan or Admin** (D1).

### Step 2 — Schedule: clash check and timetable (small–medium)
- Saving a course offering is refused when, in the same semester and overlapping time on the same day,
  the **muhadhir**, the **class** or the **room** is already booked (message names the clash). Copying
  offerings from another semester reports clashes instead of failing silently.
- **Jadwal** page for Admin: weekly grid (Monday–Sunday × time) filterable by class, muhadhir or room;
  printable. Muhadhir get the same grid for their own classes.

### Step 3 — Exams: schedule, exam card, seat numbers (large)
- **Exam periods** per semester: UTS and UAS (name, dates, published or not).
- **Exam schedule**: per course offering in a period — date, start–end, room(s), invigilator; with the
  same clash checks as Step 2 (class, room, invigilator).
- **Rooms** as master data (name, capacity, Putra/Putri/both) so seating can be planned.
- **Exam card (kartu ujian)** PDF per mahasantri per period: name, NIM, photo, class, the subjects with
  date/time/room/**seat number**.
  - The mahasantri can print it only when the requirements are met (**D2**: payment and attendance).
    The page shows which requirement is not met yet.
  - Admin can print it for anyone; for a mahasantri who does not meet the requirements this needs a
    **dispensation**: the rector's letter number, date and a note, recorded and audited, and shown on
    the card.
- **Seat numbers**: Admin clicks **Acak nomor kursi** per exam session; the system shuffles the
  mahasantri and assigns seats room by room up to capacity, **keeping putra and putri apart** (**D3**).
  Re-shuffling is allowed until the period is published; after that seats are fixed.

### Step 4 — Notifications outside the app (medium)
- **Web Push**: the same notifications as now also pop up on the desktop (Chrome, Edge, Firefox) and on
  Android phones, even when SIAKAD is not open. Each person turns it on per device ("Aktifkan
  notifikasi di perangkat ini"). SIAKAD becomes installable as an app (PWA) — required for pop-ups on
  iPhone/iPad (iOS 16.4+, after "Add to Home Screen").
- Needs HTTPS on the server and a one-time key pair (VAPID) in `.env`; no paid service.
- Email (for users with an address) can be the second channel for the same notifications.

---

## 3. Decisions (answered 2026-10-27)

| # | Question | Decision |
| --- | --- | --- |
| D1 | Who verifies payments recorded by a Petugas Pembayaran | **Admin Keuangan or Admin** |
| D2 | Exam card requirements | Semester bill **lunas**, and attendance **at least the minimum (75%) in each subject**; a subject below it is left off the card |
| D3 | Seating by gender | **Separate rooms**: rooms are marked Putra or Putri |
| D4 | Notification channels outside the app | **Web Push now**; email/WhatsApp later |

## 4. Size and order

| Step | Size | Depends on |
| --- | --- | --- |
| 1 Petugas pembayaran | M | — |
| 2 Schedule clash check + timetable | S–M | — |
| 3 Exams (schedule, card, seats) | L | uses Step 2's clash check |
| 4 Push notifications | M | — |

All decisions are made; every step can start in order.
