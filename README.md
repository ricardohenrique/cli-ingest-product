# Product Feed Normalizer

A CLI pipeline that reads a nested JSONL product feed, flattens it into tabular rows, and writes the result to PostgreSQL. Built with PHP 8.4 + Symfony 8, hexagonal architecture.

---

## How to run

**Requirements:** Docker and Docker Compose only. No host dependencies beyond that.

```bash
# 1. Clone and enter the project
git clone <repo-url>
cd cli-ingest-product

# 2. Build the app image
docker compose build app

# 3. Bring up the database (first run applies init.sql automatically)
docker compose up -d postgres

# 4. Run the ingestion (the docs/ folder is mounted at /data inside the container)
docker compose run --rm app bin/console feed:ingest /data/coffee_feed.jsonl

# 5. Or write to CSV instead of PostgreSQL
docker compose run --rm app bin/console feed:ingest /data/coffee_feed.jsonl --output=csv

# 6. Or use the env variable instead of the argument
docker compose run --rm -e FEED_INPUT_PATH=/data/coffee_feed.jsonl app bin/console feed:ingest
```

On success the command prints a summary table:

```
 ----------- --------- --------
  Processed   Skipped   Errors
 ----------- --------- --------
  1 247        0         0
 ----------- --------- --------
```

Exit code 0 on success, 1 on infrastructure failure (unreadable file, DB unreachable).

### Running the tests

```bash
# Unit + functional tests (no DB required)
docker compose run --rm app vendor/bin/phpunit --exclude-group integration

# Full suite including DB integration tests
docker compose run --rm app vendor/bin/phpunit
```

---

## Architectural decisions

### Hexagonal architecture / Ports & Adapters

The codebase is split into four layers:

- **Domain** — pure business logic: value objects (`ProductFeedItem`, `FlattenedProductRow`, `ReadResult`), domain services (`ProductFlattener`, `ProductRowValidator`), port interfaces, and the exception hierarchy. Zero framework or infrastructure imports.
- **Application** — the use-case orchestrator (`IngestProductFeedHandler`). Injects ports and services via constructor; no I/O of its own.
- **Infrastructure** — concrete adapters: `JsonlProductFeedReader` (implements `FeedReaderPort`) and `DoctrineFlattenedProductWriter` (implements `RowWriterPort`). Translates infrastructure exceptions into domain boundary exceptions (`FeedSourceException`, `PersistenceException`).
- **UI** — `IngestProductFeedSymfonyCommand`, the only Symfony-specific class. Drives the application layer; handles presentation only.

The dependency rule flows inward: UI → Application → Domain ← Infrastructure.

### ReadResult discriminated union

The `FeedReaderPort` yields `ReadResult` values (either success or failure) rather than throwing on bad lines. This keeps error handling in one place — the handler — and lets the reader remain a pure I/O adapter with no logging responsibility. The handler logs, counts, and skips. Infrastructure errors (file not found) still throw, aborting the run.

### Pre-defined schema, not dynamic DDL

`DoctrineFlattenedProductWriter` maps rows to a fixed set of 28 columns defined in `docker/db/init.sql`. Unknown keys are dropped with a debug log. This avoids runtime DDL, keeps the schema auditable, and makes the writer simple. Schema evolution = a new migration.

### Chunked writes

Rows are inserted in configurable chunks of 500 (default). Each chunk is its own transaction, so a failure rolls back only the failing chunk. This trades strict all-or-nothing atomicity for resilience on large feeds.

### Flattening strategy

Three rules, applied by `ProductFlattener`:

1. **Nested objects** (`origin`, `roast`, `tasting_score`, optional `origin.coordinates`) → underscore-separated flat keys (`origin_country`, `roast_level`, ...). Absent optional objects produce no keys (not nulls).
2. **`variants` array** → row expansion. Each variant becomes one output row; product-level fields are repeated. Position is recorded as `variant_index`.
3. **Scalar string arrays** (`flavor_notes`, `tags`) → comma-joined text. Not expanded.

### Exception hierarchy

```
ProductFeedException (abstract, extends \DomainException)
├── FlatteningException   — domain: bad record shape
├── FeedSourceException   — infra→domain boundary: unreadable source
└── PersistenceException  — infra→domain boundary: write failure
```

Infrastructure adapters never let their own exceptions leak past the domain boundary.

---

## What I'd do with more time

- **Domain events** — emit `ProductFeedIngested` and `ProductRowSkipped` events for external observability (metrics, alerting).
- **Configurable output adapters** — a `SqliteFlattenedProductWriter` would be trivial to add; the port interface already isolates the choice.
- **Idempotent writes** — `INSERT ... ON CONFLICT (variant_sku) DO UPDATE` to make re-runs safe without manual truncation.
- **Schema introspection** — derive columns dynamically from the first batch of rows rather than hard-coding them, so the writer generalises beyond this specific feed.
- **Parallel processing** — split the file into chunks and process each in a worker process via Symfony Messenger.
- **Structured run log** — persist `IngestProductFeedResult` to a `feed_runs` table for audit history.

---

## AI assistance disclosure

This project was built with Claude Code (Anthropic, claude-sonnet-4-6) as a hands-on collaborator. The AI was used to:

- Validate and refine the architectural plan (hexagonal layering, exception hierarchy, flattening strategy).
- Analyse the `coffee_feed.jsonl` structure and derive the concrete output schema.
- Generate the implementation of all layers under tech-lead direction.
- Write the test suite and catch a field-naming mismatch (`sku_variant` → `variant_sku` normalization) during the red-green cycle.

All design decisions, architectural choices, and acceptance criteria were directed by the developer. The AI wrote code; the developer drove what and why.
