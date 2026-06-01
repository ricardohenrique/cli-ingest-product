# Product Feed Normalizer

A CLI pipeline that reads a nested JSONL product feed, flattens it into tabular rows, and writes the result to PostgreSQL. Built with PHP 8.4 + Symfony 8, hexagonal architecture.

---

## How to run

**Requirements:** Docker and Docker Compose only. No host dependencies beyond that.

```bash
# 1. Clone and enter the project
git clone <repo-url>
cd cli-ingest-product

# 2. Bring up the stack — migrations run automatically on first start
docker compose up -d

# 3. Run the ingestion (the data/ folder is mounted into the container)
docker compose exec app bin/console feed:ingest data/coffee_feed.jsonl
```

On success the command prints a summary table:

```
 ------------------- -------------- ----------------- --------
  Records processed   Rows written   Records skipped   Errors
 ------------------- -------------- ----------------- --------
  500                 1341           0                 0
 ------------------- -------------- ----------------- --------
```

Exit code 0 on success, 1 on infrastructure failure (unreadable file, DB unreachable).

### Running the tests

```bash
docker compose run --rm php bin/phpunit
docker compose run --rm php bin/phpunit --filter ProductFlattenerTest
```

### Adding a schema change

```bash
# Generate a new empty migration
docker compose exec app bin/console doctrine:migrations:generate

# Edit the generated file in migrations/, then apply it
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
```

---

## Architectural decisions

### Hexagonal architecture / Ports & Adapters

The codebase is split into three layers:

- **Domain** — pure business logic: value objects (`ProductFeedItem`, `FlattenedProductRow`, `ReadResult`), domain services (`ProductFlattener`, `ProductRowValidator`), port interfaces, and the exception hierarchy. Zero framework or infrastructure imports.
- **Application** — the use-case orchestrator (`IngestProductFeedHandler`). Injects ports and services via constructor; no I/O of its own.
- **Infrastructure** — concrete adapters and framework-facing classes: `JsonlProductFeedReader` (implements `FeedReaderPort`), `DoctrineFlattenedProductWriter` (implements `RowWriterPort`), and `IngestProductFeedSymfonyCommand` (the only Symfony-specific class, lives at `src/Infrastructure/Console/`). Translates infrastructure exceptions into domain boundary exceptions (`FeedSourceException`, `PersistenceException`).

The dependency rule flows inward: Infrastructure → Application → Domain.

### ReadResult discriminated union

The `FeedReaderPort` yields `ReadResult` values (either success or failure) rather than throwing on bad lines. This keeps error handling in one place — the handler — and lets the reader remain a pure I/O adapter with no logging responsibility. The handler logs, counts, and skips. Infrastructure errors (file not found) still throw, aborting the run.

### Immutable result, local accumulation

`IngestProductFeedResult` is `final readonly` — it is constructed exactly once, at the very end of the handler, from the final counter values. During the run the handler accumulates state in local variables passed by reference into the generator. This keeps the public result object a true value object (no setters, no mutation after construction).

### Counters: records vs. rows

The result exposes three independent counters so the operator can reconcile input lines against output rows:

- `recordsProcessed` — feed items (lines) successfully read, flattened, validated, and yielded to the writer.
- `rowsWritten` — flattened rows yielded to the writer (one record typically expands into N rows, one per variant).
- `recordsSkipped` — feed items rejected at any stage (invalid JSON, flattening failure, zero variants, validation failure).

### No silent drops

Every record either lands in `recordsProcessed` or `recordsSkipped`. A product with zero variants used to vanish silently; it now raises `FlatteningException` in `ProductFlattener`, is caught by the handler, logged at `warning`, and counted in `recordsSkipped`. The same applies to validation failures and parse failures — there is no path through the pipeline that loses a record without accounting for it.

### Pre-defined schema, not dynamic DDL

`DoctrineFlattenedProductWriter` maps rows to a fixed set of columns defined in `migrations/Version20260528000001.php`. Unknown keys are dropped silently in the column mapper. This avoids runtime DDL, keeps the schema auditable, and makes the writer simple. Schema evolution = a new migration.

### Idempotency

Re-running `feed:ingest` on the same file is safe. The writer uses PostgreSQL `INSERT ... ON CONFLICT (sku, variant_sku) DO UPDATE SET ...`, so duplicate rows are updated in place rather than causing a constraint violation. The composite business key `(sku, variant_sku)` matches the natural grain of the flattened output — one row per product variant. If a column is absent in a sparse row, the existing stored value is preserved rather than overwritten.

### Chunked, grouped batch writes

Rows are buffered into chunks of 500 (configurable). Each chunk is its own transaction, so a failure rolls back only the failing chunk. Within a chunk the writer groups rows by their **column signature** (the sorted list of columns actually present), then issues one multi-row `INSERT ... VALUES (...), (...), ... ON CONFLICT ... DO UPDATE` statement per group via `Connection::executeStatement` with positional parameters. This reduces the chunk from N round-trips to one round-trip per distinct row shape. Rows are also de-duplicated by conflict key within each group so the same `(sku, variant_sku)` cannot appear twice in a single batch.

### Flattening strategy

Three rules, applied by `ProductFlattener`:

1. **Nested objects** (`origin`, `roast`, `tasting_score`, optional `origin.coordinates`) → underscore-separated flat keys (`origin_country`, `roast_level`, ...). Absent optional objects produce no keys (not nulls).
2. **`variants` array** → row expansion. Each variant becomes one output row; product-level fields are repeated. Position is recorded as `variant_index`. A product with zero variants is rejected with `FlatteningException`.
3. **Scalar string arrays** (`flavor_notes`, `tags`) → comma-joined text. Not expanded.

### Exception hierarchy

```
ProductFeedException (abstract, extends \DomainException)
├── FlatteningException   — domain: bad record shape (incl. zero variants)
├── FeedSourceException   — infra→domain boundary: unreadable source
└── PersistenceException  — infra→domain boundary: write failure
```

Infrastructure adapters never let their own exceptions leak past the domain boundary. The handler catches the abstract `ProductFeedException` so any domain-level rejection is handled uniformly: log, count, continue.

---

## What I'd do with more time

- **Domain events** — emit `ProductFeedIngested` and `ProductRowSkipped` events for external observability (metrics, alerting).
- **Configurable output adapters** — a `SqliteFlattenedProductWriter` would be trivial to add; the port interface already isolates the choice.
- **Schema introspection** — derive columns dynamically from the first batch of rows rather than hard-coding them, so the writer generalises beyond this specific feed.
- **Parallel processing** — split the file into chunks and process each in a worker process via Symfony Messenger.
- **Structured run log** — persist `IngestProductFeedResult` to a `feed_runs` table for audit history.

---

## AI assistance disclosure

This project was built with Claude Code (Anthropic) as a hands-on collaborator. The AI was used to:

- Validate and refine the architectural plan (hexagonal layering, exception hierarchy, flattening strategy).
- Analyse the `coffee_feed.jsonl` structure and derive the concrete output schema.
- Generate the implementation of all layers under tech-lead direction.
- Write the test suite and catch a field-naming mismatch (`sku_variant` → `variant_sku` normalization) during the red-green cycle.

All design decisions, architectural choices, and acceptance criteria were directed by the developer. The AI wrote code; the developer drove what and why.
