# Notification Service

Notification service for SMS, email and push, written in Laravel 13. Requests are saved and queued, and workers send them to the provider in the background.

PHP 8.4, Laravel 13, PostgreSQL, Redis, Docker Compose.

## Setup

```bash
docker compose up
```

The API runs on http://localhost:8000 and the Swagger UI on http://localhost:8000/docs (spec: `docs/openapi.yaml`).

By default the worker sends to a small fake provider in the app (`/api/mock-provider`). To use webhook.site, create a URL there, set it to respond with status `202` and a body like `{"messageId": "...", "status": "accepted", "timestamp": "..."}`, then put the URL in `PROVIDER_URL` in `.env.docker`. The fake provider is the default because free webhook.site URLs are deleted after 7 days and accept only 100 requests.

Tests:

```bash
docker compose run --rm app php artisan test
```

Without Docker (uses SQLite and the database queue):

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
```

Then run these in separate terminals:

```bash
php artisan serve
php artisan queue:work --queue=high,normal,low
php artisan schedule:work
```

## Architecture

```
client --> API --> PostgreSQL (notifications)
            |
            v
      Redis queues (high, normal, low) --> workers (3) --> provider
            ^
            |
scheduler (notifications:dispatch, every minute)
```

The API only saves the request and queues a job, so it stays fast during bursts. Workers do the sending in the background. Redis also holds the rate limit counters.

- `POST /api/notifications` validates the request, saves it with status `queued` and dispatches a `SendNotification` job. The response is `202`.
- The priority is used as the queue name. Workers run with `--queue=high,normal,low`, so high priority jobs are processed first.
- The job allows 100 sends per second per channel. Before sending it increments a counter in Redis (`INCR`, atomic) and checks it after, so even with several workers at most 100 pass in each one-second window. Jobs over the limit go back to the queue for a second.
- The worker posts `{"to", "channel", "content"}` to the provider.

Delivery and retries:

- 2xx: status `sent`. The provider's `messageId` and the latency are saved.
- 5xx, 429 or a connection error: status `retrying`. The job is released and tried again after 10s, 30s, 90s and 270s.
- Other 4xx: status `failed`, no retry.
- After 5 failed attempts the status is `failed` and the last error is saved.

To avoid sending the same notification twice, the job first moves it to `processing` with a conditional update (`WHERE status IN ('scheduled', 'queued', 'retrying')`). If two workers pick up the same notification at the same time, only one of them gets the row. If a worker dies while sending, the queue gives the job back after 90 seconds (`retry_after`). The job times out after 30 seconds, so a row that has been in `processing` for more than a minute is taken again. A job that finds its row in `processing` goes back to the queue and checks again a minute later, so a row doesn't stay there.

Delivery is at least once, not exactly once: if a worker dies after the provider accepted the message but before the status was saved, the message is sent again. The notification id is sent in the `Idempotency-Key` header so the provider can drop the second one.

The notification is saved before its job is put on the queue. If Redis is down at that moment the API still answers `202`, and the row keeps `dispatched_at` empty. The scheduler runs `notifications:dispatch` every minute and queues these rows (a simple outbox).

Redis runs with AOF on a volume, so queued and delayed jobs (retries, scheduled notifications) are kept when Redis restarts. Workers and the scheduler start after the API is healthy, i.e. after the migrations.

Other details:

- **Idempotency:** `idempotency_key` in the body or the `Idempotency-Key` header. If the key already exists, the existing notification is returned with `200`. The column has a unique index.
- **Validation:** `channel`, `recipient` and `content` are required. Per channel:
  - sms: phone number like `+905551234567`, max 160 characters
  - email: valid address, max 10000 characters
  - push: device token, max 256 characters
- **Batches:** if one item is invalid, the whole batch is rejected. Items whose idempotency key was already used are not created again; they are listed under `duplicates` with their own id and `batch_id`. If every key was used before, the response is `200` with the original `batch_id`.
- **Scheduled notifications:** `scheduled_at` dispatches the job with a delay. They can be cancelled before they are sent.
- **Metrics:** `GET /api/metrics` returns the queue size per priority, and per channel the counts by status, the success and failure rate, and the latency (avg, p95, max).
- **Health:** `GET /api/health` checks the database and the queue.
- **Logs:** JSON on stderr. Each request gets an `X-Correlation-ID` (generated if missing). It is returned in the response, stored on the notification, sent to the provider and included in the logs, including the worker logs.

## Endpoints

```bash
# create
curl -X POST localhost:8000/api/notifications -H "Content-Type: application/json" \
  -d '{"channel":"sms","recipient":"+905551234567","content":"Your code is 1234","priority":"high"}'

# batch, up to 1000
curl -X POST localhost:8000/api/batches -H "Content-Type: application/json" \
  -d '{"notifications":[{"channel":"sms","recipient":"+905551234567","content":"Hi"},{"channel":"email","recipient":"ada@example.com","content":"Hi"}]}'

# status
curl localhost:8000/api/notifications/{id}
curl localhost:8000/api/batches/{batchId}

# list
curl "localhost:8000/api/notifications?status=sent&channel=sms&from=2026-10-01&to=2026-10-31&per_page=50"

# cancel
curl -X POST localhost:8000/api/notifications/{id}/cancel

# scheduled
curl -X POST localhost:8000/api/notifications -H "Content-Type: application/json" \
  -d '{"channel":"push","recipient":"device-token-0123456789","content":"Sale starts now","scheduled_at":"2026-12-01T09:00:00Z"}'

curl localhost:8000/api/metrics
curl localhost:8000/api/health
```

## Possible improvements

- **Sliding window rate limit:** the current limit uses fixed one-second windows.
- **Jitter:** add some randomness to the retry delays.
- **Auth:** the API has no authentication.
