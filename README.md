# Notification Service

Notification service for SMS, email and push, written in Laravel 13. Requests are saved and queued, and workers send them to the provider in the background.

PHP 8.4, Laravel 13, PostgreSQL, Redis, Docker Compose.

## Setup

```bash
docker compose up
```

The API runs on http://localhost:8000 and the Swagger UI on http://localhost:8000/docs (spec: `docs/openapi.yaml`).

By default the worker sends to a small fake provider in the app (`/api/mock-provider`). To use webhook.site, create a URL there, set it to respond with status `202` and a body like `{"messageId": "...", "status": "accepted", "timestamp": "..."}`, then put the URL in `PROVIDER_URL` in `.env.docker`.

Tests:

```bash
docker compose run --rm app php artisan test
```

Without Docker (uses SQLite and the database queue):

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
php artisan queue:work --queue=high,normal,low
```

## How it works

- `POST /api/notifications` validates the request, saves it with status `queued` and dispatches a `SendNotification` job. The response is `202`.
- The priority is used as the queue name. Workers run with `--queue=high,normal,low`, so high priority jobs are processed first.
- The job uses Laravel's `RateLimited` middleware with a limit of 100 per second per channel. The limiter uses the cache (Redis), so it works across workers.
- The worker posts `{"to", "channel", "content"}` to the provider.

Delivery and retries:

- 2xx: status `sent`. The provider's `messageId` and the latency are saved.
- 5xx, 429 or a connection error: status `retrying`. The job is released and tried again after 10s, 30s, 90s and 270s.
- Other 4xx: status `failed`, no retry.
- After 5 failed attempts the status is `failed` and the last error is saved.

To avoid sending the same notification twice, the job first moves it to `processing` with a conditional update (`WHERE status IN ('scheduled', 'queued', 'retrying')`). If the same job runs twice, only one of them gets the row. If a worker dies while sending, the notification is picked up again after 2 minutes.

Other details:

- **Idempotency:** `idempotency_key` in the body or the `Idempotency-Key` header. If the key already exists, the existing notification is returned with `200`. The column has a unique index.
- **Validation:** `channel`, `recipient` and `content` are required. Per channel:
  - sms: phone number like `+905551234567`, max 160 characters
  - email: valid address, max 10000 characters
  - push: device token, max 256 characters
- **Batches:** if one item is invalid, the whole batch is rejected.
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

- **Outbox:** if Redis is down right after a notification is saved, its job is lost. An outbox table would fix this.
- **Sliding window rate limit:** the current limit uses fixed one-second windows.
- **Jitter:** add some randomness to the retry delays.
- **Auth:** the API has no authentication.
