# Product Feed Normalizer — implementation plan

## Project summary

A CLI pipeline that reads a nested JSONL product feed, flattens it into tabular rows, and writes the result to a PostgreSQL table. Built with PHP 8.2 + Symfony 7, hexagonal architecture, Docker-only runtime.

---

## Feed schema (`coffee_feed.jsonl`)

Each line is a JSON object with this structure:

```
{
  "sku":           string           — product identifier (required)
  "name":          string           — product name (required)
  "origin": {
    "country":     string,
    "region":      string,
    "farm":        string,
    "altitude_m":  int|null,        — nullable
    "process":     string,
    "coordinates": {                — optional field, absent on many records
      "lat": float,
      "lng": float
    }
  },
  "roast": {
    "level":       string,          — e.g. "light", "medium", "medium-dark", "dark", "french"
    "roasted_on":  string (ISO date),
    "roaster":     string
  },
  "flavor_notes":  string[],        — may be empty
  "tags":          string[],        — may be empty
  "tasting_score": {
    "acidity":     int,
    "body":        int,
    "sweetness":   int,
    "aroma":       int,
    "bitterness":  int
  },
  "in_stock":      bool,
  "variants": [                     — required, drives row expansion (1–N rows per product)
    {
      "sku_variant": string,        — unique variant identifier
      "size":        string,        — e.g. "100g", "250g", "500g", "1kg"
      "grind":       string,        — e.g. "espresso", "filter", "french_press", "aeropress", ...
      "price_eur":   float,
      "stock":       int            — may be 0
    }
  ],
  "description":   string           — optional, absent on some records
}
```

**Edge cases observed in the file:**
- `altitude_m` is `null` for some records (e.g. BEAN-0005).
- `origin.coordinates` is absent on most records; present on a minority.
- `description` is absent on some records.
- `flavor_notes` and `tags` can be empty arrays `[]`.

---

## Architecture

Hexagonal / Ports & Adapters with a light DDD flavor.

```
[ Primary side — driving ]

CLI Console Adapter (UI)
     ↓ drives
IngestProductFeedHandler (Application)
     ↓ calls through
FeedReaderPort / RowWriterPort (Domain — secondary/driven ports)
     ↓ implemented by
JsonlProductFeedReader / DoctrineFlattenedProductWriter (Infrastructure)

[ Secondary side — driven ]
```

**Port directions:**
- `FeedReaderPort` and `RowWriterPort` are **secondary (driven) ports** — the application calls out through them; infrastructure adapters implement them.
- `IngestProductFeedSymfonyCommand` is the **primary (driving) adapter** — it calls into the application via the handler.

```
src/
├── Domain/
│   ├── Port/
│   │   ├── Driving/                           # primary ports (use-case interfaces)
│   │   └── Driven/                            # secondary ports (outbound interfaces)
│   │       ├── FeedReaderPort.php             # interface: read(source): iterable<ReadResult>
│   │       └── RowWriterPort.php              # interface: write(iterable<FlattenedProductRow>): void
│   └── ProductFeed/
│       ├── ProductFeedItem.php                # value object: raw parsed record + line number
│       ├── FlattenedProductRow.php            # value object: key→value map after flattening
│       ├── ReadResult.php                     # value object: discriminated union (item | read error)
│       ├── ProductFlattener.php               # domain service: flattens nested objects/arrays
│       ├── ProductRowValidator.php            # domain service: validates flattened rows
│       └── Exception/
│           ├── ProductFeedException.php       # base: extends \DomainException
│           ├── FlatteningException.php        # domain flattening failure
│           ├── FeedSourceException.php        # infra→domain boundary: unreadable source
│           └── PersistenceException.php       # infra→domain boundary: write failure
│
├── Application/
│   └── IngestProductFeed/
│       ├── IngestProductFeedInput.php         # DTO: source path + output destination
│       ├── IngestProductFeedHandler.php       # orchestrator: read → flatten → validate → write
│       └── IngestProductFeedResult.php        # DTO: records processed, skipped, errors[]
│
├── Infrastructure/
│   ├── Input/
│   │   └── JsonlProductFeedReader.php         # implements FeedReaderPort, streams with yield
│   └── Persistence/
│       └── DoctrineFlattenedProductWriter.php # implements RowWriterPort, DBAL chunked insert
│
└── UI/
    └── Console/
        └── IngestProductFeedSymfonyCommand.php  # Symfony Console driving adapter
```

---

## Domain rules

- `ProductFeedItem` and `FlattenedProductRow` are immutable value objects (readonly properties, no setters).
- `ProductFeedItem` always carries a `$lineNumber` for traceability.
- `ProductFlattener` contains zero I/O or logging — pure transformation only.
- All concrete classes are `final`.
- Domain exceptions extend `ProductFeedException extends \DomainException`, never PHP's base `\Exception`.
- Infrastructure adapters translate their own exceptions into `FeedSourceException` or `PersistenceException` at the boundary — never letting raw infrastructure exceptions leak into the application layer.

**Flattening strategy (feed-specific):**

The flattener applies two distinct strategies depending on field type:

1. **Nested objects → dot-notation keys.** Applied recursively to `origin`, `roast`, `tasting_score`, and the optional `origin.coordinates`. If `origin.coordinates` is absent, the `origin.coordinates.lat` / `origin.coordinates.lng` keys are omitted from the row entirely (not null-filled).
2. **`variants` array → row expansion.** Each element in `variants` produces one `FlattenedProductRow`. Product-level fields are repeated on every variant row. The variant's position within the array is recorded as `variant_index` (0-based).
3. **Scalar string arrays → serialized text.** `flavor_notes` and `tags` are joined as comma-separated strings (empty array → empty string). They are **not** expanded into multiple rows.

A product with zero variants produces zero rows (not an error — logged at debug level).

## Validation rules (`ProductRowValidator`)

A `FlattenedProductRow` is considered invalid if any of the following are true:
- The row has zero keys after flattening.
- Any key is an empty string or contains only whitespace.
- Required keys are absent: `sku`, `name`, `variant_sku` (the three fields that uniquely identify a row).
- A required key is present but its value is `null` or empty string.

Violations throw `FlatteningException` with a message identifying the key and row source.

## Error handling rules

- **Record-level errors** (malformed JSON, flattening failure, validation failure): the reader yields a `ReadResult` carrying the error; the handler logs a warning with structured context (`line`, `source`, `error`, raw excerpt) and increments `skippedCount`. Never aborts the run.
- **Infrastructure errors** (file not found, DB unreachable): the adapter throws `FeedSourceException` or `PersistenceException` (both extend `ProductFeedException`); the handler lets these propagate; the console adapter catches them, prints an error message, and exits with code 1.
- `LoggerInterface` (PSR-3) is injected via constructor — never accessed statically.

## Schema strategy (`DoctrineFlattenedProductWriter`)

The output schema is **pre-defined, not dynamic**. A migration / init SQL file (`docker/db/init.sql`) creates a fixed `flattened_products` table. Unknown keys in a `FlattenedProductRow` that have no matching column are silently dropped and logged at debug level. This keeps the writer simple and the schema auditable. If the feed schema evolves, a new migration is added — no runtime DDL.

**`flattened_products` table columns** (derived from the feed schema above):

| Column | Type | Nullable |
|---|---|---|
| `id` | bigserial PK | no |
| `sku` | varchar(64) | no |
| `name` | varchar(255) | no |
| `origin_country` | varchar(128) | no |
| `origin_region` | varchar(128) | no |
| `origin_farm` | varchar(128) | no |
| `origin_altitude_m` | int | yes |
| `origin_process` | varchar(64) | no |
| `origin_coordinates_lat` | numeric(9,6) | yes |
| `origin_coordinates_lng` | numeric(9,6) | yes |
| `roast_level` | varchar(32) | no |
| `roast_roasted_on` | date | no |
| `roast_roaster` | varchar(128) | no |
| `flavor_notes` | text | no (empty string if none) |
| `tags` | text | no (empty string if none) |
| `tasting_score_acidity` | smallint | no |
| `tasting_score_body` | smallint | no |
| `tasting_score_sweetness` | smallint | no |
| `tasting_score_aroma` | smallint | no |
| `tasting_score_bitterness` | smallint | no |
| `in_stock` | boolean | no |
| `description` | text | yes |
| `variant_sku` | varchar(128) | no |
| `variant_size` | varchar(16) | no |
| `variant_grind` | varchar(32) | no |
| `variant_price_eur` | numeric(10,2) | no |
| `variant_stock` | int | no |
| `variant_index` | smallint | no |

---

## Implementation plan

Work through these steps in order. Each step must pass the code-reviewer before the next begins.

### Step 1 — Domain model

**Build:** `ProductFeedItem`, `FlattenedProductRow`, `ReadResult`, exception hierarchy (`ProductFeedException`, `FlatteningException`, `FeedSourceException`, `PersistenceException`)

**Acceptance criteria:**
- Both value objects are immutable (readonly, no setters) and final.
- `ProductFeedItem` carries `$lineNumber`.
- `ReadResult` is a discriminated union: either wraps a `ProductFeedItem` (success) or carries an error message + line number (failure).
- `ProductFeedException extends \DomainException` is the base; all other exceptions extend it.

**Tests:** unit — construct with valid data, assert all getters return expected values; assert exception hierarchy via `instanceof`.

---

### Step 2 — Domain ports

**Build:** `FeedReaderPort`, `RowWriterPort` (in `Domain/Port/Driven/`)

**Acceptance criteria:**
- Both are PHP interfaces living in `Domain/Port/Driven/`.
- `FeedReaderPort::read(string $source): iterable<ReadResult>` — yields both successes and errors; never throws on record-level failures.
- `RowWriterPort::write(iterable<FlattenedProductRow>): void`
- Zero Symfony or infrastructure imports.

**Tests:** none required for interfaces — covered by adapter tests.

---

### Step 3 — Domain services

**Build:** `ProductFlattener`, `ProductRowValidator`

**Acceptance criteria:**
- `ProductFlattener` takes a `ProductFeedItem`, returns `FlattenedProductRow[]`.
- Applies the feed-specific flattening strategy: dot-notation for nested objects, row expansion for `variants`, serialization for scalar arrays.
- `ProductRowValidator` takes a `FlattenedProductRow`, enforces the validation rules defined above, returns void or throws `FlatteningException`.
- Both classes contain zero I/O, zero logging, zero framework calls.

**Tests:** unit —
- Product with 1 variant → 1 `FlattenedProductRow`; `sku`, `name`, `variant_sku`, `variant_index=0` present.
- Product with 3 variants → 3 rows; each row carries the same `sku`/`name` with `variant_index` 0, 1, 2 and the respective `variant_sku`.
- Nested `origin` object → dot-notation keys (`origin_country`, `origin_region`, etc.) present on each row.
- Record with `origin.coordinates` → `origin_coordinates_lat` and `origin_coordinates_lng` present.
- Record without `origin.coordinates` → `origin_coordinates_lat` / `origin_coordinates_lng` keys absent (not null).
- Record with `altitude_m: null` → `origin_altitude_m` key present with `null` value.
- `flavor_notes: ["milk chocolate", "regret"]` → `flavor_notes` = `"milk chocolate,regret"`.
- `flavor_notes: []` → `flavor_notes` = `""`.
- Product with 0 variants → returns empty array (no rows).
- Row missing `sku` → `FlatteningException`.
- Row missing `name` → `FlatteningException`.
- Row missing `variant_sku` → `FlatteningException`.
- Row with zero keys → `FlatteningException`.

---

### Step 4 — Application layer

**Build:** `IngestProductFeedInput`, `IngestProductFeedResult`, `IngestProductFeedHandler`

**Acceptance criteria:**
- `IngestProductFeedInput` is a plain readonly DTO (source path only).
- `IngestProductFeedResult` holds: `processedCount`, `skippedCount`, `errors[]` (array of structured strings: `"line N: <reason>"`).
- `IngestProductFeedHandler` injects `FeedReaderPort`, `RowWriterPort`, `LoggerInterface`.
- Handler iterates `ReadResult` values: on success → flatten → validate → buffer for write; on error → log warning, increment `skippedCount`.
- Handler has no flattening logic inline.

**Tests:** integration using `InMemoryFeedReader` + `InMemoryRowWriter` stubs —
- Happy path: 3 valid records → all written, result shows `processed=3 skipped=0`.
- One malformed record (reader yields an error `ReadResult`) → skipped, others written, result shows `skipped=1`.
- One record that fails validation → skipped, others written, result shows `skipped=1`.
- Empty input → zero writes, no errors.

---

### Step 5 — JSONL reader adapter

**Build:** `JsonlProductFeedReader`

**Acceptance criteria:**
- Implements `FeedReaderPort`.
- Uses `fgets()` in a loop with `yield` — never loads full file into memory.
- File handle closed in `try/finally`.
- Skips blank lines silently.
- Malformed JSON line: yields a failure `ReadResult` with `line`, `source`, `error`, raw excerpt (truncated to 200 chars) — does not log directly (logging is the handler's responsibility).
- File not found or unreadable: throws `FeedSourceException`.

**Tests:** unit —
- Valid JSONL with 3 records → yields 3 success `ReadResult` objects.
- File with one malformed JSON line → yields 2 success + 1 failure `ReadResult`.
- Blank lines are skipped.
- Non-existent file → throws `FeedSourceException`.

---

### Step 6 — PostgreSQL writer adapter

**Build:** `DoctrineFlattenedProductWriter`, `docker/db/init.sql`

**Acceptance criteria:**
- Implements `RowWriterPort`.
- Uses Doctrine DBAL (no ORM).
- Rows are written in **chunks of 500** within a single transaction per chunk; configurable via constructor argument.
- Empty input (zero rows) handled gracefully — no error, no empty transaction.
- Column names are the pre-defined schema keys; unknown keys in a row are dropped and logged at debug level.
- DB failure mid-write: transaction for the failing chunk is rolled back; throws `PersistenceException`.
- `init.sql` creates the `flattened_products` table with the known columns.

**Tests:** integration (real DB in Docker) —
- 3 rows written → verify they exist in the table.
- 1 200 rows written → verify all present (exercises chunking boundary).
- Empty input → no error, table unchanged.
- DB failure mid-write → `PersistenceException` thrown.

---

### Step 7 — Console command

**Build:** `IngestProductFeedSymfonyCommand`

**Acceptance criteria:**
- Accepts file path as argument or `FEED_INPUT_PATH` env variable; validates that one is provided and non-empty before dispatching.
- Dispatches `IngestProductFeedInput` to `IngestProductFeedHandler`.
- On success: prints summary table via `SymfonyStyle` (processed, skipped, errors list).
- On `ProductFeedException` (infrastructure boundary): prints error message and exits with code 1.
- Registered as `feed:ingest` command.

**Tests:** functional — run command with a fixture JSONL file, assert exit code 0 and printed summary; run with missing file argument, assert exit code 1.

---

### Step 8 — Docker & Compose setup

**Build:** `Dockerfile`, `docker-compose.yml`, `docker/db/init.sql` (referenced in Step 6), `.env.example`

**Acceptance criteria:**
- `docker compose up` on a fresh machine with no host dependencies beyond Docker brings up the app and a Postgres container.
- `docker compose run --rm app bin/console feed:ingest /data/products.jsonl` runs the full pipeline end-to-end.
- The input file is mounted into the container via a volume defined in `docker-compose.yml`.
- DB connection parameters are read from environment variables defined in `.env.example`.
- A smoke test (manual or scripted) confirms exit 0 and rows visible in the DB.

**Tests:** smoke — run the full command against the provided fixture file inside Docker, assert exit 0 and rows in DB.

---

### Step 9 — README

**Build:** `README.md`

**Content required (per the case brief):**
- How to run (clone → `docker compose up` → run command).
- Architectural decisions: hexagonal layering rationale, pre-defined schema strategy, chunked writes, `ReadResult` discriminated union for error propagation, exception hierarchy.
- What you'd do with more time (e.g., event emission for observability, configurable output adapters, schema introspection, metrics).
- AI assistance disclosure.

---

## Definition of done

- All 9 steps complete and reviewed.
- Full pipeline runs via `docker compose run --rm app bin/console feed:ingest /data/products.jsonl`.
- README covers all required sections.