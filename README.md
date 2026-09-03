# Notification Service

A multichannel notification microservice built with Laravel. Clients submit a notification through a small authenticated REST API; the service persists it, then delivers it through an **ordered list of channels** (SMS, email, push) with **automatic fallback** — if the first channel fails, the next one is tried, and so on until one succeeds. Work is processed asynchronously on **priority-based queues** (`high` / `default` / `low`), and notifications can be **scheduled** for future delivery, with a per-minute scheduler promoting them to the queue when their time arrives. Every delivery attempt — success or failure — is written to a `notification_logs` audit trail.

---

## Architecture overview

### Request-to-delivery flow

```
                        POST /api/notifications
                                 │
                                 ▼
                   StoreNotificationRequest (validation)
                                 │
                                 ▼
                     Notification record created
                     status = pending, attempts = 0
                                 │
                 ┌───────────────┴────────────────┐
                 │                                │
        scheduled_at is null              scheduled_at is in
        or already in the past               the future
                 │                                │
                 ▼                                ▼
     SendNotificationJob::dispatch      record just sits as
     onto the priority queue            "pending" until due
                 │                                │
                 │                                ▼
                 │                  notifications:dispatch-scheduled
                 │                  (runs every minute, promotes due
                 │                   records by dispatching the job)
                 │                                │
                 └───────────────┬────────────────┘
                                 ▼
                    SendNotificationJob@handle
                    - status = processing, attempts++
                    - loop channels in array order:
                        ChannelFactory::make($name)
                        └─ $channel->send($notification)
                             success → NotificationLog(success)
                                       status = sent, STOP
                             failure → NotificationLog(failed, error)
                                       continue to next channel
                    - all channels failed → status = failed
                                 │
                                 ▼
                     notification_logs rows
                  (one per attempt, per channel)
```

### Strategy pattern for channels

Each delivery mechanism is a **strategy** behind a single interface:

- **`App\Notifications\Channels\NotificationChannelInterface`** — one method, `send(Notification $notification): bool`. Implementations return `true` on success and throw a descriptive exception on failure.
- **`EmailChannel`** — sends via Laravel's `Mail` facade (`Mail::raw`, title → subject, body → content). Wraps failures in a `RuntimeException`.
- **`SmsChannel`** — stub that logs `SMS to {recipient}: {body}` at info level. Swap in Twilio / Kavenegar later.
- **`PushChannel`** — stub that logs `PUSH to {recipient}: {title} - {body}` at info level. Swap in FCM / APNs later.
- **`ChannelFactory::make(string $channelName): NotificationChannelInterface`** — maps `"email" | "sms" | "push"` to the concrete strategy; throws `InvalidArgumentException` for anything else.

The job never references a concrete channel class. It walks the notification's `channels` array, asks the factory for a strategy per name, and calls `send()`. Adding a new channel = one new class + one `match` arm in the factory; nothing else changes.

### Key components

| Component | Path | Responsibility |
|---|---|---|
| `Notification` model | `app/Models/Notification.php` | Notification record; `logs()` hasMany; casts `channels` → array, `scheduled_at` → datetime |
| `NotificationLog` model | `app/Models/NotificationLog.php` | Per-attempt audit row; `notification()` belongsTo |
| `StoreNotificationRequest` | `app/Http/Requests/StoreNotificationRequest.php` | Validation for the create endpoint |
| `NotificationController` | `app/Http/Controllers/Api/NotificationController.php` | `store`, `index`, `show`, `status` |
| `SendNotificationJob` | `app/Jobs/SendNotificationJob.php` | Queued delivery with fallback; `$tries = 3`, `backoff() = [10, 30, 60]` |
| Channel strategies | `app/Notifications/Channels/` | Interface, three channels, factory |
| `DispatchScheduledNotifications` | `app/Console/Commands/DispatchScheduledNotifications.php` | `notifications:dispatch-scheduled`; promotes due records |
| `CreateTestUser` | `app/Console/Commands/CreateTestUser.php` | `app:create-test-user`; local API user |
| Schedule | `routes/console.php` | Runs the dispatch command every minute |

---

## Tech stack

- **PHP** 8.4
- **Laravel** 13.x (`laravel/framework` `^13.17`; slim skeleton — routing/scheduling in `bootstrap/app.php` + `routes/`, no `app/Console/Kernel.php`)
- **Laravel Sanctum** 4.x — API token authentication (personal access tokens)
- **Queue driver:** `database` (`jobs` / `failed_jobs` tables) — no Redis required
- **Database:** SQLite (`database/database.sqlite`)
- **Mail:** `log` driver by default (emails written to `storage/logs/laravel.log`)

---

## Setup

```sh
# 1. Clone
git clone <your-repo-url> notification-service
cd notification-service

# 2. Install PHP dependencies
composer install

# 3. Environment file
cp .env.example .env
php artisan key:generate

# 4. Create the SQLite database file
touch database/database.sqlite

# 5. Run migrations (notifications, notification_logs, users, jobs, cache, sanctum tokens)
php artisan migrate

# 6. Create a test user for hitting the API
php artisan app:create-test-user
#   → Test user ready: test@example.com (password: password)
#   override with: --email= --password= --name=
```

`.env.example` already ships with the values a fresh clone needs:

```
DB_CONNECTION=sqlite
QUEUE_CONNECTION=database
MAIL_MAILER=log
```

---

## Running it

Delivery is asynchronous and scheduling is time-driven, so local development needs **three terminals**:

```sh
# Terminal 1 — HTTP API
php artisan serve
#   → http://127.0.0.1:8000

# Terminal 2 — queue worker (processes SendNotificationJob across all priority queues)
php artisan queue:work --queue=high,default,low

# Terminal 3 — scheduler (local stand-in for system cron; fires notifications:dispatch-scheduled every minute)
php artisan schedule:work
```

Notes:

- The `--queue=high,default,low` order matters: the worker drains `high` before `default` before `low`.
- In production, Terminal 3 is replaced by a single system cron entry: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`.
- To trigger a scheduled sweep immediately without waiting for the minute boundary: `php artisan notifications:dispatch-scheduled`.

---

## API reference

Base URL: `http://127.0.0.1:8000/api`

All endpoints except `POST /login` require a Sanctum bearer token:

```
Authorization: Bearer <token>
Accept: application/json
```

Send `Accept: application/json` on every request — without it, an unauthenticated call returns a `500` (framework tries to redirect to a non-existent `login` route) instead of a clean `401`.

---

### `POST /api/login`

Exchange credentials for a plain-text Sanctum token. **No auth required.**

**Request body** (form or JSON):

| Field | Rules |
|---|---|
| `email` | required, email |
| `password` | required, string |

**Response** `200 OK` — the raw token string as `text/plain`:

```
3|kQ9c0J8s7dR2f1a5B4n6M8x0Z2v4C6b8N0m2K4j6
```

**Response** `422` on bad credentials:

```json
{ "message": "The provided credentials are incorrect.", "errors": { "email": ["The provided credentials are incorrect."] } }
```

---

### `POST /api/notifications`

Create a notification. If it is unscheduled or its `scheduled_at` is already in the past, `SendNotificationJob` is dispatched immediately; a future `scheduled_at` leaves the record `pending` for the scheduler. **Auth required.**

**Request body:**

| Field | Rules | Notes |
|---|---|---|
| `recipient` | required, string | email address, phone number, or device token |
| `title` | nullable, string | used as the email subject |
| `body` | required, string | message content |
| `channels` | required, array, min 1 | ordered fallback list |
| `channels.*` | string, `in:sms,email,push` | |
| `priority` | nullable, `in:high,medium,low` | defaults to `medium` |
| `scheduled_at` | nullable, date, `after_or_equal:now` | null = send now |

**Example request:**

```json
{
  "recipient": "user@example.com",
  "title": "Welcome aboard",
  "body": "Thanks for signing up!",
  "channels": ["sms", "email"],
  "priority": "high"
}
```

**Response** `201 Created`:

```json
{
  "id": 1,
  "recipient": "user@example.com",
  "title": "Welcome aboard",
  "body": "Thanks for signing up!",
  "channels": ["sms", "email"],
  "priority": "high",
  "scheduled_at": null,
  "status": "pending",
  "attempts": 0,
  "created_at": "2026-09-02T18:00:00.000000Z",
  "updated_at": "2026-09-02T18:00:00.000000Z"
}
```

`status` is `pending` in the response body because the job runs on the queue a moment later. Poll the status endpoint to watch it move to `sent` / `failed`.

**Response** `422` — validation errors in the standard Laravel shape.

---

### `GET /api/notifications`

Paginated list, newest first, 15 per page. **Auth required.**

**Query parameters** (both optional):

| Param | Effect |
|---|---|
| `status` | exact match on `status` (`pending`, `processing`, `sent`, `failed`) |
| `channel` | `whereJsonContains('channels', <value>)` — notifications whose channel list includes this value |

**Example:** `GET /api/notifications?status=sent&channel=email`

**Response** `200 OK` — Laravel paginator envelope:

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 2,
      "recipient": "user@example.com",
      "title": "Welcome aboard",
      "body": "Thanks for signing up!",
      "channels": ["sms", "email"],
      "priority": "high",
      "scheduled_at": null,
      "status": "sent",
      "attempts": 1,
      "created_at": "2026-09-02T18:00:00.000000Z",
      "updated_at": "2026-09-02T18:00:05.000000Z"
    }
  ],
  "first_page_url": "http://127.0.0.1:8000/api/notifications?page=1",
  "from": 1,
  "last_page": 1,
  "per_page": 15,
  "to": 1,
  "total": 1
}
```

---

### `GET /api/notifications/{notification}`

Single notification with its full delivery log. **Auth required.** Unknown id → `404`.

**Response** `200 OK`:

```json
{
  "id": 2,
  "recipient": "user@example.com",
  "title": "Welcome aboard",
  "body": "Thanks for signing up!",
  "channels": ["sms", "email"],
  "priority": "high",
  "scheduled_at": null,
  "status": "sent",
  "attempts": 1,
  "created_at": "2026-09-02T18:00:00.000000Z",
  "updated_at": "2026-09-02T18:00:05.000000Z",
  "logs": [
    {
      "id": 1,
      "notification_id": 2,
      "channel": "sms",
      "status": "success",
      "response": null,
      "created_at": "2026-09-02T18:00:05.000000Z",
      "updated_at": "2026-09-02T18:00:05.000000Z"
    }
  ]
}
```

---

### `GET /api/notifications/{notification}/status`

Lightweight payload for polling — no body text, no full log rows. **Auth required.** Unknown id → `404`.

**Response** `200 OK`:

```json
{
  "id": 2,
  "status": "sent",
  "attempts": 1,
  "channels": ["sms", "email"],
  "logs_count": 1
}
```

---

## Example curl walkthrough

```sh
BASE=http://127.0.0.1:8000/api

# 1. Log in — capture the token
TOKEN=$(curl -s -X POST $BASE/login \
  -H 'Accept: application/json' \
  -d 'email=test@example.com&password=password')
echo "$TOKEN"

# 2. Create an immediate notification (dispatched to the 'high' queue right away)
curl -s -X POST $BASE/notifications \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -d 'recipient=user@example.com' \
  -d 'title=Welcome' \
  -d 'body=Thanks for signing up!' \
  -d 'channels[]=sms' -d 'channels[]=email' \
  -d 'priority=high'
# → 201, {"id":1, "status":"pending", ...}

# 3. Create a notification scheduled 2 minutes out (stays 'pending' until the scheduler promotes it)
curl -s -X POST $BASE/notifications \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -d 'recipient=user@example.com' \
  -d 'body=This one is scheduled' \
  -d 'channels[]=push' \
  -d "scheduled_at=$(date -u -v+2M '+%Y-%m-%dT%H:%M:%SZ')"
# → 201, {"id":2, "status":"pending", "scheduled_at":"...", ...}

# 4. Poll status (run the queue worker in another terminal to see it flip to 'sent')
curl -s $BASE/notifications/1/status \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
# → {"id":1,"status":"sent","attempts":1,"channels":["sms","email"],"logs_count":1}

# 5. View the full delivery log
curl -s $BASE/notifications/1 \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
# → full notification object with a "logs" array

# 6. Filtered list
curl -s "$BASE/notifications?status=sent&channel=email" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

For step 3 on Linux, use `-d "scheduled_at=$(date -u -d '+2 minutes' '+%Y-%m-%dT%H:%M:%SZ')"`.

---

## Design decisions

**Priority queues (`high` / `default` / `low`).**
A notification's `priority` maps to a named queue in `SendNotificationJob`'s constructor (`high → high`, `medium → default`, `low → low`). Running the worker as `--queue=high,default,low` makes it drain higher-priority work first, so a burst of low-priority digest emails can't delay an urgent OTP. Priority lives on the job/queue rather than in application code, so no custom prioritisation logic is needed.

**Fallback order via the `channels` array.**
`channels` is an *ordered* JSON list, e.g. `["sms", "email", "push"]`. The job tries each in sequence and **stops at the first success**; failures are logged and it moves on. This models "reach the user however you can, but prefer SMS" without a rules engine — the caller expresses intent purely by ordering the array. Each attempt (success or failure, with the provider response / error message) becomes a `notification_logs` row, so the delivery path is fully auditable. If every channel fails, the notification is marked `failed`.

**Database queue driver.**
The `database` driver keeps the project to a single dependency (SQLite) — no Redis, no Beanstalkd, nothing extra to install or run for a demo or a grader. Queued jobs are visible as rows in the `jobs` table and failures land in `failed_jobs`, which makes the async flow easy to inspect and explain. The trade-off (polling latency, lower throughput than Redis) is irrelevant at this scale, and switching later is a one-line `.env` change.

**Sanctum personal access tokens.**
Stateless bearer tokens fit a microservice with no first-party browser UI. `POST /login` returns the raw token; clients send it as `Authorization: Bearer …`. No session/CSRF machinery.

**Retry strategy.**
`SendNotificationJob` sets `$tries = 3` with `backoff() = [10, 30, 60]` seconds, so transient provider errors get three escalating attempts before the job is parked in `failed_jobs`. `failed()` marks the notification `failed` so its state never silently diverges from the queue's.

---

## Possible future improvements

- **Real provider integrations** — replace the `SmsChannel` / `PushChannel` log stubs with Twilio or Kavenegar for SMS and FCM / APNs for push; move credentials to config.
- **Notification templates** — named, versioned templates with variable interpolation (`Hi {name}, your order {id} shipped`) instead of raw `title` / `body` per request.
- **Retry backoff tuning** — per-channel and per-priority backoff, jitter to avoid thundering-herd retries, and a configurable dead-letter policy.
- **Webhook delivery callbacks** — accept provider status webhooks (delivered / bounced / clicked) and reconcile them back onto `notification_logs` so `status` reflects true end-to-end delivery, not just "handed to provider".
- **Rate limiting / throttling** per recipient and per channel.
- **Idempotency keys** on `POST /notifications` to make client retries safe.
- **Metrics & observability** — per-channel success rates, queue depth, time-to-deliver histograms.

---

## Tests

Run the suite with:

```sh
php artisan test
```

The suite runs against an in-memory SQLite database (configured in `phpunit.xml`) and uses the `RefreshDatabase` trait, so it needs no setup and leaves no state behind. Coverage:

| File | What it covers |
|---|---|
| `tests/Feature/NotificationApiTest.php` | auth (401), create → 201 + persisted as `pending`, validation (422), future `scheduled_at` does **not** queue a job, immediate create **does** queue a job, `show` returns logs, unknown id → 404, `index` filtering by `?status=` and `?channel=`, `/status` payload shape |
| `tests/Feature/SendNotificationJobTest.php` | first channel succeeds → one `success` log + `sent`; first channel fails → falls back, two logs (`failed` then `success`), `sent`; all channels fail → `failed` + `attempts` incremented; unknown channel name → `failed` log, job does not crash |
| `tests/Feature/ScheduledDispatchCommandTest.php` | past-due `pending` notification is dispatched; future-scheduled is not; already-`sent` notification is not re-dispatched |

Model factories live in `database/factories/NotificationFactory.php` (states: `scheduledInThePast`, `scheduledInTheFuture`, `sent`, `channels([...])`).

Current status: **18 passing, 68 assertions.**
