# SIAKAD ⇄ CMS Integration API

Version 1.1, 2026-11-04. Machine-readable version: [siakad-openapi.yaml](siakad-openapi.yaml). Changes since 1.0 are listed at the end.

This document is the contract the SIAKAD project implements to work with the CMS:

1. **Articles:**
   - SIAKAD pushes published academic articles to the CMS, which keeps them in an approval inbox.
   - After approval (Draft → Under Review → Approved for website publication) they appear in the Ma'had Aly website repository (`/publikasi`).
   - SIAKAD can read each article's review status and public URL.
2. **Tanya Hukum:**
   - A visitor asks on the website.
   - A CMS moderator forwards the question to SIAKAD.
   - Scholars answer inside SIAKAD.
   - SIAKAD sends the answer back to the CMS.
   - A CMS admin publishes the question and answer.

```
SIAKAD                                         CMS
  │  POST   /api/webhooks/siakad/articles  ──────▶  inbox (draft) ─▶ review ─▶ website
  │  GET    /api/webhooks/siakad/articles/{id} ──▶  review status + public URL
  │  DELETE /api/webhooks/siakad/articles/{id} ──▶  withdraw from website
  │
  │  ◀─── POST {SIAKAD}/legal-questions      moderator forwards a question
  │  POST   /api/webhooks/siakad/answers  ───────▶  answered ─▶ admin publishes
```

---

## 1. Environments and base URLs

| | Base URL |
|---|---|
| CMS (receives webhooks) | `https://{ADMIN_DOMAIN}/api/webhooks/siakad`, e.g. `https://admin.pondok.example/api/webhooks/siakad` (local: `http://admin.pondok.test/api/webhooks/siakad`) |
| SIAKAD (receives CMS calls) | value of `SIAKAD_BASE_URL` in the CMS, e.g. `https://siakad.example.ac.id/api/cms` |

All bodies are JSON (`Content-Type: application/json`, UTF-8). All timestamps are ISO 8601 with time zone.

## 2. Authentication and signing

Both systems share two secrets, configured in the CMS `.env`:

| Setting | Used for |
|---|---|
| `SIAKAD_WEBHOOK_SECRET` | HMAC signature of **every** request in **both** directions. At least **32 random characters** (e.g. `openssl rand -hex 32`); the CMS readiness check (`php artisan cms:check`) warns about shorter secrets. |
| `SIAKAD_API_KEY` | Bearer token the CMS sends to SIAKAD (`Authorization: Bearer …`). |

### Signature

```
signature = lowercase_hex( HMAC_SHA256( key = SIAKAD_WEBHOOK_SECRET,
                                        message = "{timestamp}.{raw request body}" ) )
```

Send it in two headers:

| Header | Value |
|---|---|
| `X-Siakad-Timestamp` | Unix time in seconds when the request is sent |
| `X-Siakad-Signature` | the hex signature above |

Rules:
- Sign the **exact bytes** you send. For requests without a body (`GET`, `DELETE`), the body is the empty string, so the message is `"{timestamp}."`.
- The receiver rejects requests whose timestamp is more than **300 seconds** away from its own clock (replay protection). Keep servers in sync with NTP.
- Compare signatures in constant time.
- The CMS answers an invalid or expired signature with `401 {"message": "Invalid signature."}` and logs the attempt.

PHP example (SIAKAD side). Read the secret through `config()`, not `env()`: `env()` returns `null` once the configuration is cached.

```php
// config/services.php: 'cms' => ['url' => env('CMS_WEBHOOK_URL'), 'secret' => env('CMS_WEBHOOK_SECRET')],

$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$ts = time();
$sig = hash_hmac('sha256', $ts.'.'.$body, config('services.cms.secret'));

Http::withHeaders(['X-Siakad-Timestamp' => $ts, 'X-Siakad-Signature' => $sig])
    ->withBody($body, 'application/json')
    ->post(config('services.cms.url').'/articles');
```

## 3. Endpoints hosted by the CMS (SIAKAD → CMS)

Rate limit: 120 requests per minute per IP. Exceeding it returns `429`. Retry after the `Retry-After` header.

### 3.1 `POST /articles`: create or update an article

Idempotent on `id`: sending the same `id` again updates the stored article. Status `201` means the article was new, `200` means it was updated.

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | string ≤ 191 | yes | SIAKAD's stable article ID |
| `type` | enum | no | `research` (default), `journal_article`, `bahtsul_masail`, `thesis`, `book` |
| `title` | string ≤ 255 | yes | |
| `authors` | string[] or string | yes | each name ≤ 255, e.g. `["Dr. H. Mahbub Ridwan, M.Ag."]` |
| `year` | integer | yes | publication year, 1900 to next year |
| `abstract` | string ≤ 20 000 | no | plain text |
| `body_html` | string ≤ 1 000 000 | no | full text, HTML; unsafe markup is removed by the CMS |
| `keywords` | string[] or string | no | each keyword ≤ 100 |
| `doi` | string ≤ 255 | no | without `https://doi.org/` |
| `file_url` | URL ≤ 2048 | no | public PDF link |
| `source_url` | URL ≤ 2048 | no | public page in SIAKAD or OJS |

Example:

```json
{
  "id": "ART-2026-0042",
  "type": "journal_article",
  "title": "Metodologi Qiyas Kausalitas dalam Jam'ul Jawami'",
  "authors": ["Dr. H. Mahbub Ridwan, M.Ag."],
  "year": 2026,
  "abstract": "Kajian ini …",
  "keywords": ["ushul fiqh", "qiyas"],
  "doi": "10.1234/ahkam.2026.12",
  "source_url": "https://siakad.example.ac.id/artikel/ART-2026-0042"
}
```

Response `201`:

```json
{ "status": "received", "cms_id": "01a1…", "review_status": "draft" }
```

Sending an article again, depending on its state:

| State in the CMS | Effect |
|---|---|
| `draft`, `under_review` | content updated, state unchanged |
| `approved` | goes back to `under_review`; the website keeps showing the approved version until an editor approves the change |
| `rejected` | treated as the author's revision: goes back to `under_review` and the reviewers are notified |
| `withdrawn` | goes back to `under_review` |

### 3.2 `GET /articles/{id}`: review status and public URL

Signed like every other call (empty body). Not written to the CMS integration log, so SIAKAD may poll it (for example once after each push and then daily). Stay within the rate limit.

Response `200`:

```json
{
  "id": "ART-2026-0042",
  "cms_id": "01a1…",
  "review_status": "approved",
  "review_note": null,
  "reviewed_at": "2026-11-04T10:15:00+07:00",
  "changes_pending_review": false,
  "public_url": "https://mahad.example/publikasi/metodologi-qiyas-kausalitas",
  "received_at": "2026-11-03T08:00:00+07:00"
}
```

| Field | Meaning |
|---|---|
| `review_status` | `draft`, `under_review`, `approved`, `rejected` or `withdrawn` |
| `review_note` | the reviewer's reason, only when `rejected` |
| `changes_pending_review` | `true` when an update to an approved article waits for re-approval (the old version is still online) |
| `public_url` | the article's page on the website, or `null` while it is not publicly visible (not approved yet, withdrawn, or hidden by an editor) |

Unknown id: `404 {"message": "Article not found."}`.

### 3.3 `DELETE /articles/{id}`: withdraw an article

Removes the article from the website (the publication is archived) and marks it *Withdrawn*. A later `POST` with the same `id` brings it back for review.

Response `200`: `{ "status": "withdrawn", "cms_id": "…" }`. Unknown id: `404 {"message": "Article not found."}`.

### 3.4 `POST /answers`: answer to a forwarded question

| Field | Type | Required | Notes |
|---|---|---|---|
| `question_id` | string | one of the two | `cms_question_id` received in §4.1 (preferred) |
| `siakad_question_id` | string | one of the two | the `id` SIAKAD returned in §4.1 |
| `answer_html` | string ≤ 200 000 | yes | the approved answer, HTML |
| `answered_by` | string ≤ 255 | yes | e.g. `"Dewan Asatidz Ma'had Aly"` |
| `references` | string ≤ 5 000 | no | kitab references, plain text |

Response `200`:

```json
{ "status": "received", "cms_id": "…", "question_status": "answered", "correction_pending": false }
```

Sending an answer again is how SIAKAD corrects it:
- **Before publication** (`question_status: "answered"`): the new answer replaces the previous one.
- **After publication** (`question_status: "published"`): the website keeps the published answer. The new answer is held as a **correction** (`correction_pending: true`) until a CMS moderator applies or dismisses it. The moderator may have edited the published wording, so a correction never goes live automatically.

`404 {"message": "Question not found."}` means the question is unknown. `409 {"message": …}` means the question was never forwarded (e.g. it was rejected).

### 3.5 Errors (CMS)

Error bodies are JSON with a `message`; validation errors add `errors`.

| Status | Meaning | Retry? |
|---|---|---|
| `200` / `201` | accepted | – |
| `401` | missing, invalid or expired signature | no: fix signing or clock |
| `404` | unknown article or question | no |
| `409` | wrong state (answer for a question that was not forwarded) | no |
| `422` | validation error, body `{"message": …, "errors": {field: [..]}}` | no: fix payload |
| `429` | rate limited | yes, after `Retry-After` |
| `5xx` | CMS problem | yes, with exponential backoff (1, 5, 15, 60 min) |

## 4. Endpoints hosted by SIAKAD (CMS → SIAKAD)

SIAKAD must verify `Authorization: Bearer {SIAKAD_API_KEY}` **and** the signature headers (§2).

### 4.1 `POST {SIAKAD_BASE_URL}/legal-questions`: receive a question

Sent when a CMS moderator forwards a question. Headers also include `Idempotency-Key: {cms_question_id}`. If SIAKAD receives the same key again (the CMS retries after timeouts), it **must return the existing question** instead of creating a duplicate.

Request:

```json
{
  "cms_question_id": "01a19…",
  "tracking_code": "TH-7KQ4M9AB",
  "category": "muamalah",
  "question": "Bagaimana hukum …",
  "asker": { "name": "Ahmad", "city": "Kendal", "anonymous": false },
  "submitted_at": "2026-10-31T09:12:00+07:00",
  "moderator_note": "Mohon dijawab dengan rujukan kitab Syafi'iyah.",
  "callback_url": "https://admin.pondok.example/api/webhooks/siakad/answers"
}
```

`category` is one of: `ibadah`, `muamalah`, `munakahat`, `mawaris`, `jinayah`, `akhlak`, `lainnya`. When `asker.anonymous` is `true`, `name` is `null`. The asker's email is never sent.

Required response: `201` (or `200` for a repeat), body:

```json
{ "id": "SIAKAD-Q-000123" }
```

Error responses SIAKAD should use, and how the CMS reacts:

| SIAKAD returns | Meaning | CMS reaction |
|---|---|---|
| `401` / `403` | bad API key or signature | no retry: the question goes back to the moderator immediately |
| `422` | invalid payload, body `{"message": …, "errors": {…}}` | no retry: back to the moderator immediately |
| other `4xx` (except `408`, `429`) | refused | no retry: back to the moderator immediately |
| `408`, `429`, `5xx`, timeout (15 s), connection error, or `2xx` without `id` | temporary | retried |

The CMS makes **up to 5 attempts** in total (retries after 1, 5, 15 and 60 minutes). If all fail, the question goes back to the moderator. Every attempt is visible in the CMS integration log.

When the scholars approve an answer, SIAKAD calls §3.4 (the `callback_url`).

## 5. Status lifecycles

**Articles (CMS):** `draft` → `under_review` → `approved` (published on website) or `rejected`; `withdrawn` via §3.3. Sending an `approved`, `rejected` or `withdrawn` article again returns it to `under_review` (§3.1). SIAKAD reads the current state with §3.2.

**Tanya Hukum (CMS):**
- The normal path is `submitted` → `forwarding` → `forwarded` → `answered` → `published`; the moderator can instead `reject` a question.
- A correction sent after publication waits for the moderator (§3.4).
- The asker follows the status with their tracking code on the website (`/tanya-hukum/status`).

## 6. Testing without SIAKAD

The CMS ships a simulator that sends correctly signed webhooks into the CMS:

```bash
php artisan siakad:simulate article             # new article (random id)
php artisan siakad:simulate article --id=ART-1  # create/update ART-1
php artisan siakad:simulate status --id=ART-1   # read review status and public URL
php artisan siakad:simulate withdraw --id=ART-1
php artisan siakad:simulate answer              # answer the oldest forwarded question
php artisan siakad:simulate answer --id=TH-XXXXXXXX
```

With `SIAKAD_DRIVER=fake` (default), forwarding a question pretends SIAKAD accepted it. Set `SIAKAD_DRIVER=http` and `SIAKAD_BASE_URL` to talk to a real SIAKAD.

## 7. Checklist for the SIAKAD team

- [ ] Store `CMS_WEBHOOK_SECRET` (same value as the CMS `SIAKAD_WEBHOOK_SECRET`, 32+ random characters) and the API key; read them through `config()`.
- [ ] Sign every call to the CMS (§2) and keep the clock in sync.
- [ ] Push articles on publish/update (§3.1); call §3.3 when an article is withdrawn.
- [ ] Optionally show the review status and website link from §3.2.
- [ ] Implement `POST /legal-questions` (§4.1) with Bearer and signature checks, idempotency, and the error codes above.
- [ ] Call §3.4 when an answer is approved; retry on `5xx`/`429` with backoff. Corrections after publication are reviewed by the CMS moderator.
- [ ] Log request IDs and CMS responses for support.

## Changes

**1.1 (2026-11-04)**
- New `GET /articles/{id}` (§3.2): review status, reviewer note and public URL.
- Re-sending a **rejected** article now returns it to review and notifies the reviewers (it used to stay rejected).
- An answer sent **after publication** is held as a correction for the moderator instead of replacing the published answer; the response has `correction_pending` (§3.4).
- The CMS no longer retries forwarding when SIAKAD answers `4xx` (except `408`/`429`); the question goes back to the moderator immediately (§4.1).
- Documented the field size limits, error bodies, secret length, the `config()` PHP example and that the CMS makes up to 5 attempts in total.
- Section numbers changed: withdraw is now §3.3, answers §3.4 and errors §3.5.

**1.0 (2026-10-31)**: first version.
