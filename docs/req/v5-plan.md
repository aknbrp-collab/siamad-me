# v5 plan

Source: `docs/req/v5.md`, with the CMS contract in `docs/req/siakad-api-spec.md` (v1.1) and
`docs/req/siakad-openapi.yaml`. Answers given on 2026-11-04 are recorded as decisions D25–D32.

## What exists today

- **Pembimbing akademik**: one muhadhir supervises many mahasantri (set by Admin), with a thread-style
  forum (`forum_threads.supervisor_id`). v5 does **not** change it (D25).
- **Forums** for class kuliah (threads and posts), notifications with Web Push, private files with access
  rules (`PrivateFileController`), the database queue (`QUEUE_CONNECTION=database`) and the scheduler
  (backups, reminders).
- Nothing yet for live discussion, articles, or talking to the CMS.

## Decisions

- **D25 Bimbingan groups (answer: separate groups, for research and writing only).** Admin and the academic
  monitor create groups for *bimbingan penelitian dan penulisan*. Each group is led by one muhadhir and has
  any number of mahasantri, and a mahasantri can be in several groups. Pembimbing akademik stays as it is.
- **D26 One discussion room engine.** Bimbingan groups and LPPPK use the same room:
  - **Chat:** messages with replies, and files attached to a message.
  - **Files tab:** every file shared in the room.
  - **Notes:** pinned notes that members write, and the author or the lead can edit.
  - **Unread counts.**
- **D27 Refresh every few seconds (answer).** The room refreshes about every 5 seconds while it is open
  (Livewire polling). New messages also send a notification and Web Push, grouped so a busy room sends at
  most one notification per person until they open it. Nothing extra to install.
- **D28 LPPPK** (Lembaga Penelitian, Pengembangan, Pengabdian Keilmuan):
  - **Members:** every active mahasantri and muhadhir.
  - **Rooms:** Admin creates channels such as *Penelitian*, *Pengembangan*, *Pengabdian* and *Umum*, plus
    the **Tanya Hukum** inbox (D30).
  - **Moderation** by Admin and the academic monitor:
    - **Mute** a person (they can read but not post), until a date or until lifted.
    - **Restrict** a person (no access to LPPPK).
    - **Delete** a message: it is hidden with "dihapus oleh moderator" and kept for the audit.
    - **Lock** a channel.
  - The same moderation works in bimbingan groups for the lead muhadhir.
- **D29 Articles: simple articles for the public website (client note, 2026-11-04).** Every muhadhir and
  mahasantri can write articles in SIAKAD. These are popular articles (opinion, kajian ringan, news-style
  writing), **not** journal or academic papers, which will have their own repository and system later.
  - **Fields:** title, author (the writer; muhadhir with their gelar), a short summary, keywords, and the
    body in Markdown (with a preview, turned into safe HTML). The year is the publication year. No
    article type to choose, no PDF and no DOI.
  - **CMS type (answer: ask the CMS to add "article"):** SIAKAD sends `type: "article"`. The CMS team is
    asked to add this type so these articles show apart from academic publications. Until they do, the CMS
    answers `422`, so the type is a setting (`CMS_ARTICLE_TYPE`, default `article`) that can be set to
    `research` meanwhile.
  - **Mahasantri articles** are sent for review. Any muhadhir can review: **approve**, or **request
    changes** with a note. The article is published after **2 approvals** from two different muhadhir. A
    revised article starts a new review round.
  - **Muhadhir articles** are published directly (answer).
  - **After publication** the article is sent to the CMS (D31), which shows it on the public website
    after its own editor approval. SIAKAD has no public page of its own, so `source_url` and `file_url`
    are not sent. A later edit sends it again, and withdrawing it calls the CMS withdraw.
  - **On the article page** the author sees the CMS review status, the reviewer's note and the website
    link (§3.2). SIAKAD checks it after every send and then daily.
- **D30 Tanya Hukum (answer: any muhadhir drafts, 2 approvals).**
  - **Receiving:** SIAKAD hosts `POST /api/cms/legal-questions`, which checks the Bearer API key, the
    signature and the clock, and uses `Idempotency-Key`. Each question becomes an item in the LPPPK
    **Tanya Hukum** inbox, with its own discussion that all members can join.
  - **Answering:** any muhadhir writes the answer (Markdown → HTML, plus kitab references). It is sent when
    **two other muhadhir** approve it. The sender name is a setting, by default "Dewan Asatidz Ma'had Aly".
  - **Corrections:** changing an answer after it was sent needs two new approvals, and is then sent again
    (the CMS holds it as a correction if it is already published).
  - **Privacy:** the asker's name is shown only when they are not anonymous; their email is never received.
- **D31 CMS client.**
  - **Settings:** `CMS_WEBHOOK_URL`, `CMS_WEBHOOK_SECRET` (shared with the CMS, 32+ characters) and
    `SIAKAD_API_KEY` in `.env`, read through `config()`.
  - **Signing:** every call is signed as in spec §2.
  - **Retries:** calls run as queued jobs and retry on `429` (after `Retry-After`) and `5xx` or connection
    errors with backoff of 1, 5, 15 and 60 minutes. A `4xx` is not retried; it is shown to the author or the
    approvers.
  - **Integration log:** every call in both directions (time, endpoint, status, id, short response) is
    kept, and Superadmin reads it on a new **Integrasi CMS** page with a "check configuration" button.
  - **Without settings** (local development) the integration is off, nothing is sent, and the pages say so.
- **D32 Queue worker.** CMS calls need a running worker. The scheduler starts
  `queue:work --stop-when-empty` every minute, so no extra service is needed. On a server with Supervisor,
  a permanent worker is better, and the operations notes will say so.

## Steps

### Step 1 — Discussion rooms (large) — ✅ done 2026-11-04

- Tables (named `discussions*`, because exam rooms already use `rooms`):
  - `discussions` (kind: bimbingan | lpppk; name, description, lead muhadhir, locked, closed);
  - `discussion_members` (role lead | member, muted_at/until, last_read_message_id);
  - `discussion_messages` (author, body, reply_to, hidden_by/at);
  - `discussion_files` (private disk, kind `discussion-file`);
  - `discussion_notes`.
  LPPPK-wide restriction (no access at all) comes with Step 3.
- `ManageDiscussions` action: post, reply, attach (10 MB limit, D18), notes, mark read, mute, restrict, delete,
  lock. Moderation is audited.
- A room page component, used by every kind: chat with polling, a files tab and a notes tab. Unread
  badges go in the sidebar, and notifications are grouped (D27).
- Tests: access by membership, mute/restrict/lock, file access, polling query count.

### Step 2 — Bimbingan penelitian dan penulisan (medium)

- **Admin and academic monitor:** create, rename and close groups, pick the lead muhadhir, and add or
  remove mahasantri (search, several at once).
- **Muhadhir:** "Bimbingan" lists the groups they lead. **Mahasantri:** "Bimbingan" lists their groups.
  Both open the room from there.
- Closed groups stay readable.

### Step 3 — LPPPK channels and moderation (medium)

- A **LPPPK** menu for every mahasantri and muhadhir, with the channels list and room.
- Admin and the academic monitor create, rename and lock channels.
- A **Moderation** page: search a person and mute (until a date), restrict or lift; the list of everyone
  muted or restricted.

### Step 4 — Articles in SIAKAD (large)

- `articles` table (author user, title, summary, body_md, keywords, status draft | in_review |
  changes_requested | published | withdrawn, published_at), `article_reviews` (round, reviewer, decision,
  note) and `article_versions` (snapshot on every publish).
- **Pages:** "Artikel saya" (write, preview, send for review, edit, withdraw) and the **review queue** for
  muhadhir. No public page in SIAKAD: the website shows the articles.
- Notifications to the author and the reviewers.

### Step 5 — CMS integration: articles (medium)

- `CmsClient` (signing, JSON body as sent, timeouts), `PushArticle` / `WithdrawArticle` / `CheckArticle`
  jobs with backoff, a daily status check, and the `cms_requests` log.
- The **Integrasi CMS** page (Superadmin): configuration check (URL, secret length, API key, queue worker
  seen recently) and the log.
- Tests with `Http::fake()`: the signature matches the spec example, `201`/`200`, `422` not retried,
  `429` and `5xx` retried, status read.

### Step 6 — Tanya Hukum (large)

- `POST /api/cms/legal-questions`:
  - **Checks:** the Bearer key, the signature (300 s window, constant-time compare), validation (`422`
    with errors), and `Idempotency-Key` (a repeat returns `200` with the same id).
  - **Ids** look like `SIAKAD-Q-000123`.
  - **Logging and abuse:** inbound requests are logged and rate limited.
- The **Tanya Hukum** inbox in LPPPK shows each question, with its category, asker (unless anonymous),
  the moderator note and the discussion room.
- **Answer drafting:** a muhadhir writes the answer and references; two other muhadhir approve it; it is
  sent to the `callback_url` with `cms_question_id`; the answer is shown as "terkirim / dikoreksi".
  Corrections need two new approvals.
- Tests: signature and Bearer failures (`401`), the stale clock, idempotency, the approval rules, the
  callback payload and retries.

## Open with the CMS team

- Add the article type `article` (D29), so simple articles are kept apart from academic publications.

## Size and order

- **Steps 1–3** come first: the discussion engine, then the two places that use it.
- **Step 4** (articles) can be built without the CMS.
- **Steps 5–6** need the CMS values in `.env`. They are built and tested against `Http::fake()` and the
  spec's examples; a real end-to-end check needs the CMS team's staging URL and secret (the CMS also has
  `php artisan siakad:simulate` for its side).
