# PetroApp Transfer Events

Idempotent, concurrency-safe ingestion of station transfer events with a per-station reconciliation summary endpoint.

## Tech Stack

- **PHP 8.4** + **Laravel 12**
- **PostgreSQL 16** (idempotency + concurrency enforced at the DB layer)
- **Pest 3** for tests
- **Docker** + **docker-compose** (single `app` service + `db` service)
- **OpenAPI 3.1** spec at [`openapi.yaml`](./openapi.yaml)

## Requirements

- Docker 20+ and Docker Compose v2 (no local PHP/Composer required)

## How to Run

### Docker (recommended)

```bash
cp .env.example .env
make run                    # docker compose up --build
# app listens on http://localhost:8000
```

The entrypoint waits for Postgres, runs migrations, then starts the dev server.

### Local (only if you have PHP 8.4 + Postgres)

```bash
composer install
php artisan migrate
php artisan serve
```

## How to Run Tests

```bash
# Unit + feature tests (Postgres-backed, RefreshDatabase between tests)
make test

# Concurrency proof test (requires the stack running in another terminal)
make test-concurrency
```

`make test` runs everything **except** the concurrency test, which is gated by the
`CONCURRENCY_BASE_URL` env var. The concurrency test runs against a live HTTP
server because that's the only honest way to prove HTTP-level race safety.

## API Examples

### Ingest events

```bash
curl -s -X POST http://localhost:8000/transfers \
  -H 'Content-Type: application/json' \
  -d '{
    "events": [
      {"event_id":"evt-001","station_id":"S1","amount":100.50,"status":"approved","created_at":"2026-02-19T10:00:00Z"},
      {"event_id":"evt-002","station_id":"S1","amount":49.75,"status":"approved","created_at":"2026-02-19T10:05:00Z"}
    ]
  }'
# → {"inserted":2,"duplicates":0,"rejected":[]}
```

### Re-send the same batch (idempotency)

```bash
# Same payload again
# → {"inserted":0,"duplicates":2,"rejected":[]}
```

### Get a station summary

```bash
curl -s http://localhost:8000/stations/S1/summary
# → {"station_id":"S1","total_approved_amount":150.25,"events_count":2}
```

### Partial-accept validation example

```bash
curl -s -X POST http://localhost:8000/transfers \
  -H 'Content-Type: application/json' \
  -d '{
    "events": [
      {"event_id":"ok","station_id":"S1","amount":10,"status":"approved","created_at":"2026-02-19T10:00:00Z"},
      {"event_id":"bad","station_id":"S1","amount":-1,"status":"approved","created_at":"2026-02-19T10:00:00Z"}
    ]
  }'
# → {"inserted":1,"duplicates":0,"rejected":[{"index":1,"event_id":"bad","errors":["The amount field must be at least 0."]}]}
```

---

## Design Notes

### Idempotency strategy

`event_id` is the **primary key** of the `transfer_events` table. Every insert goes through:

```sql
INSERT INTO transfer_events (...) VALUES (...), (...)
ON CONFLICT (event_id) DO NOTHING
RETURNING event_id;
```

- `inserted` = `count(RETURNING)` — rows the DB actually created.
- `duplicates` = `batch_size - inserted`.

Idempotency becomes a **storage-level invariant**, not application code that can
drift. A duplicate `event_id` is structurally impossible to insert twice — across
processes, nodes, or restarts.

### Concurrency strategy

Same mechanism. `ON CONFLICT DO NOTHING` is **atomic at the row level** in
Postgres: two concurrent inserts of the same `event_id` are serialized by the row
lock acquired during conflict detection. Exactly one wins; the other gets zero
rows in `RETURNING`. No application locks, no advisory locks, no read-then-write
race window.

Proven by `tests/Feature/ConcurrencyTest.php`, which forks 25 processes posting
the same `event_id` simultaneously and asserts:

- exactly 1 row exists,
- `sum(inserted)` across responses == 1,
- `sum(duplicates)` across responses == 24.

### Tradeoffs

| Decision | Choice | Why | Tradeoff |
|---|---|---|---|
| Storage | Postgres | Native `ON CONFLICT ... RETURNING`, real PK constraint. | One more container vs SQLite — worth it. |
| Batch strategy | **Partial accept** | Upstream is external; one bad event shouldn't block 999 good ones. Response extended with `rejected[]`. | Slightly richer response than spec example. |
| `events_count` | **All statuses** (spec default) | Complements `total_approved_amount` (already approved-only). | Caller must note the two fields use different filters. |
| Concurrency | DB PK + `ON CONFLICT DO NOTHING RETURNING` | Single atomic statement; no app locks. | Postgres-specific syntax — isolated behind the repository interface. |
| Repository | Port + Eloquent impl + InMemory impl | Satisfies "swappable store"; speeds up unit tests. | Two impls to keep aligned. |
| Test framework | Pest 3 | Modern, concise; default in new Laravel installs. | Slightly less ubiquitous than raw PHPUnit (Pest *is* PHPUnit under the hood). |
| Docker layout | Single `app` + `db` | Minimal surface area for the reviewer. | Not production-grade (no opcache, no nginx) — out of scope. |

### Why not an in-memory lock?

It would only protect a single PHP process. Two requests served by different
workers (or two containers behind a load balancer) would race. The DB constraint
is the only correct answer for the stated requirement.

### Why not `insertOrIgnore()`?

Laravel's `insertOrIgnore()` compiles to `ON CONFLICT DO NOTHING` on Postgres,
but its return value isn't a reliable inserted-vs-ignored split across drivers.
A raw query with `RETURNING event_id` gives an exact count.

### Project layout

```
app/
  Domain/                                  # storage-agnostic value objects + port
    TransferEvent.php
    StationSummary.php
    IngestResult.php
    Contracts/TransferEventRepository.php
  Infrastructure/Persistence/
    EloquentTransferEventRepository.php    # Postgres impl
    InMemoryTransferEventRepository.php    # used by unit tests
  Http/Controllers/
    TransferController.php
    StationSummaryController.php
  Providers/DomainServiceProvider.php      # binds the port to Eloquent impl
database/migrations/
  2026_05_23_000000_create_transfer_events_table.php
tests/
  Feature/TransferIngestionTest.php        # 9 scenarios
  Feature/ConcurrencyTest.php              # forked-process race test
  Unit/InMemoryRepositoryTest.php
docker/entrypoint.sh
Dockerfile
docker-compose.yml
openapi.yaml
Makefile
```

### Operational notes

- Logs go to **stderr** (`LOG_CHANNEL=stderr`) so `docker compose logs -f app` shows everything.
- No secrets in the repo. The `APP_KEY` in `.env.example` is a clearly fake dev placeholder; `entrypoint.sh` regenerates one if missing.
- Migrations run automatically on container startup (idempotent via `--force`).
