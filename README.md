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
make run
```

> `make run` runs `docker compose up --build` — app listens on **http://localhost:8000**

The entrypoint waits for Postgres, runs migrations, then starts the dev server.  
On first boot, `docker/postgres-init.sql` creates the `petroapp_test` database automatically.

### Local (only if you have PHP 8.4 + Postgres)

```bash
composer install
php artisan migrate
php artisan serve
```

## How to Run Tests

```bash
# Unit + feature tests — uses docker-compose.test.yml to isolate against
# petroapp_test so tests never touch the production DB.
make test

# Concurrency proof test (requires `make run` in another terminal)
make test-concurrency
```

`make test` composes `docker-compose.yml` + `docker-compose.test.yml`, which overrides
`DB_DATABASE=petroapp_test` and `RUN_MIGRATIONS=false` so `RefreshDatabase` owns
the test DB lifecycle entirely. The concurrency test is skipped until `CONCURRENCY_BASE_URL`
is set — it must run against a live HTTP server to prove real HTTP-level race safety.

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

### Unknown station (404)

```bash
curl -s http://localhost:8000/stations/GHOST/summary
# → 404 {"message":"Station 'GHOST' not found."}
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
| `events_count` | **All statuses** (spec default) | Complements `total_approved_amount` (already approved-only). Counting both as approved would make them redundant. | Caller must note the two fields use different filters. |
| Unknown station | **404** | A station exists in this system only once it has received an event. No separate stations table — zero events = unknown resource. | A station with only non-approved events is known (200) even though its total is 0. |
| Concurrency | DB PK + `ON CONFLICT DO NOTHING RETURNING` | Single atomic statement; no app locks. | Postgres-specific syntax — isolated behind the repository interface. |
| Validation | `IngestTransfersRequest` (shape) + `TransferIngestionService` (per-event) | Shape is fail-fast (400); per-event must loop to support partial-accept. Two concerns, two places. | FormRequest alone can't express partial-accept — splitting is intentional. |
| `amount` type | `string` in PHP DTO | Preserves `NUMERIC(14,2)` precision — floats can't represent all decimals exactly. `bcadd()` used for in-memory accumulation. | Slightly less ergonomic than `float` for arithmetic; correctness wins. |
| Repository | Port + Eloquent impl + InMemory impl | Satisfies "swappable store"; speeds up unit tests. | Two impls to keep aligned. |
| Test isolation | `docker-compose.test.yml` override | Declarative — DB swap is visible in a file, not buried in Makefile flags. | Reviewer must know compose override files. |
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
    TransferEvent.php                      # amount: string (NUMERIC precision)
    StationSummary.php
    IngestResult.php
    Contracts/TransferEventRepository.php  # port (swappable storage interface)
  Infrastructure/Persistence/
    EloquentTransferEventRepository.php    # Postgres impl (ON CONFLICT RETURNING)
    InMemoryTransferEventRepository.php    # test impl (bcadd for precision)
  Http/
    Controllers/
      TransferController.php               # thin: receive → delegate → respond
      StationSummaryController.php         # 404 for unknown stations
    Requests/
      IngestTransfersRequest.php           # shape validation → 400
  Services/
    TransferIngestionService.php           # per-event validation + DTO construction
    IngestResponse.php                     # typed service return value
  Providers/DomainServiceProvider.php      # binds port to Eloquent impl
database/migrations/
  2026_05_23_000000_create_transfer_events_table.php
tests/
  Feature/TransferIngestionTest.php        # 10 scenarios
  Feature/ConcurrencyTest.php              # 25 forked-process race test
  Unit/InMemoryRepositoryTest.php          # proves abstraction is swappable
docker/
  entrypoint.sh                            # wait → migrate → serve
  postgres-init.sql                        # creates petroapp_test on first boot
Dockerfile
docker-compose.yml                         # production stack
docker-compose.test.yml                    # test overrides (DB + migrations)
openapi.yaml
Makefile
```

### Operational notes

- Logs go to **stderr** (`LOG_CHANNEL=stderr`) so `docker compose logs -f app` shows everything.
- No secrets in the repo. The `APP_KEY` in `.env.example` is a clearly fake dev placeholder.
- Migrations run automatically on container startup (idempotent via `--force`).
- `petroapp_test` is created by `docker/postgres-init.sql` on first volume init — no manual setup needed.
