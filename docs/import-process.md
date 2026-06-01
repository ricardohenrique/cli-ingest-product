# Feed Ingestion — Step-by-Step Process

This document traces exactly what happens when you run:

```bash
docker compose exec app bin/console feed:ingest /data/coffee_feed.jsonl
```

---

## Overview

The pipeline has three layers. Each layer has one responsibility and only talks to the layer adjacent to it through an interface (port):

```
CLI Command (Infrastructure)  →  Application Handler  →  Domain (Flattener + Validator)
                                       ↑                          ↑
                                FeedReaderPort            RowWriterPort
                                       ↑                          ↑
                             JsonlProductFeedReader    DoctrineFlattenedProductWriter
```

---

## Step 1 — Symfony resolves the command

**File:** `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php`

Symfony boots and matches `feed:ingest` to `IngestProductFeedSymfonyCommand` (registered via the `#[AsCommand]` attribute). This class is the only Symfony-specific class in the entire codebase. Its sole responsibilities are:

- Read the file path from the CLI argument, or fall back to the `FEED_INPUT_PATH` environment variable.
- Build an `IngestProductFeedInput` value object and pass it to the handler.
- Format the result (a summary table and optional error list) back to the terminal.
- Return `Command::SUCCESS` (exit 0) or `Command::FAILURE` (exit 1).

If no path is found, it prints an error and exits immediately with failure.

---

## Step 2 — The handler orchestrates the pipeline

**File:** `src/Application/IngestProductFeed/IngestProductFeedHandler.php`

`IngestProductFeedHandler::handle()` is the application-layer orchestrator. It knows nothing about JSONL files or PostgreSQL — it only speaks to domain objects and ports. It receives:

- `FeedReaderPort` — the interface for reading records
- `RowWriterPort` — the interface for writing rows
- `ProductFlattener` — domain service that expands nested records into flat rows
- `ProductRowValidator` — domain service that validates each row
- `LoggerInterface` — for warning on skipped lines

The handler keeps four local accumulators (`recordsProcessed`, `recordsSkipped`, `rowsWritten`, `errors`) and passes them by reference into a private generator method, then calls `$this->writer->write(...)` with that generator — a lazy stream of `FlattenedProductRow` objects. The writer pulls rows from the generator as it needs them; reading, flattening, and writing are all pipelined and no full dataset is ever held in memory at once. Once the writer returns, the handler constructs a single `IngestProductFeedResult` (a `final readonly` value object) from the final counter values and returns it.

---

## Step 3 — The file is read line by line

**File:** `src/Infrastructure/Input/JsonlProductFeedReader.php`
**Port:** `src/Domain/Port/Driven/FeedReaderPort.php`

`JsonlProductFeedReader` implements `FeedReaderPort` and opens the file with `fopen`. It reads the file one line at a time with `fgets`, keeping memory usage flat regardless of file size.

For each non-empty line it:

1. Calls `json_decode()` on the raw line.
2. If JSON is invalid → yields `ReadResult::failure(lineNumber, errorMessage, rawExcerpt)`.
3. If JSON is valid → wraps the decoded array in a `ProductFeedItem` (a value object carrying the raw data + line number) and yields `ReadResult::success(item)`.

**File:** `src/Domain/ProductFeed/ReadResult.php`

`ReadResult` is a discriminated union — either a success wrapping a `ProductFeedItem`, or a failure wrapping an error message and a raw excerpt for logging. The handler checks `isSuccess()` on every result before proceeding.

If the file cannot be opened at all, `JsonlProductFeedReader` throws `FeedSourceException`, which propagates up and aborts the entire run with exit 1.

---

## Step 4 — Parse failures are logged and skipped

**File:** `src/Application/IngestProductFeed/IngestProductFeedHandler.php`

Back in the handler's generator, if a `ReadResult` is a failure:

- A `warning` is logged with the line number, source path, error message, and raw excerpt.
- `recordsSkipped` is incremented.
- The error is recorded so it can be shown in the terminal summary.
- The generator continues to the next line — one bad line never stops the run.

---

## Step 5 — Each product record is flattened into rows

**File:** `src/Domain/ProductFeed/ProductFlattener.php`

`ProductFlattener::flatten()` receives a `ProductFeedItem` (nested JSON object) and returns an array of `FlattenedProductRow` objects. Three rules drive the transformation:

### Rule 1 — Nested objects → underscore-prefixed flat keys

Any associative sub-object is recursively flattened. Examples:

| Input (nested) | Output (flat key) |
|---|---|
| `origin.country` | `origin_country` |
| `origin.coordinates.lat` | `origin_coordinates_lat` |
| `roast.level` | `roast_level` |
| `tasting_score.acidity` | `tasting_score_acidity` |

Absent optional objects (e.g. `origin.coordinates` is not always present) simply produce no keys — not nulls.

### Rule 2 — `variants` array → row expansion

The `variants` array is extracted first and removed from the product-level data. Each variant becomes one output row. Product-level fields are repeated on every row. The variant's position in the array is stored as `variant_index` (0-based).

A field normalization is applied here: the feed sends `sku_variant`, but the output schema expects `variant_sku`. The flattener renames it during the prefix pass.

**Zero-variant records:** if `variants` is missing or empty after extraction, `ProductFlattener` throws a `FlatteningException` (e.g. `"Product 'COF-001' has no variants"`). Such a record produces no rows and is not silently dropped — the handler catches the exception and counts it under `recordsSkipped` (see Step 6).

### Rule 3 — Scalar string arrays → comma-joined text

Fields like `flavor_notes` and `tags` are arrays of strings in the feed. They are joined with a comma into a single text value (`"chocolate,caramel,nuts"`). They are not expanded into multiple rows.

**File:** `src/Domain/ProductFeed/FlattenedProductRow.php`

Each `FlattenedProductRow` is an immutable value object holding the flat key-value map and the original line number (for traceability if the row later fails to write).

---

## Step 6 — Each row is validated

**File:** `src/Domain/ProductFeed/ProductRowValidator.php`

Before a row is yielded to the writer, `ProductRowValidator::validate()` checks:

- The row is not empty.
- No key is blank or whitespace-only.
- The three required fields are present and non-empty: `sku`, `name`, `variant_sku`.

If validation fails, a `FlatteningException` is thrown. The handler catches the base `ProductFeedException` (so this and the zero-variant case are handled uniformly), logs a warning, increments `recordsSkipped`, and continues — the same skip-and-continue policy as read failures.

---

## Step 7 — Valid rows are written to PostgreSQL in chunks

**File:** `src/Infrastructure/Persistence/DoctrineFlattenedProductWriter.php`
**Port:** `src/Domain/Port/Driven/RowWriterPort.php`

`DoctrineFlattenedProductWriter` implements `RowWriterPort` and receives the generator from the handler. It buffers rows into chunks of **500** (configurable). When a chunk is full (or the generator is exhausted), it calls `insertChunk()`.

Each chunk is wrapped in a single Doctrine DBAL transaction. Inside the transaction the writer does **not** issue one `INSERT` per row. Instead:

1. `$connection->beginTransaction()`
2. Rows in the chunk are grouped by their **column signature** — the sorted list of column names actually present on the row. Different rows may omit different optional columns, so they cannot share an `INSERT` statement; rows with the same shape can.
3. Within each group, rows are de-duplicated by the conflict key `(sku, variant_sku)` so the same key cannot appear twice in one batch (PostgreSQL forbids that under `ON CONFLICT`).
4. For each group, the writer builds one multi-row SQL statement of the form:

   ```sql
   INSERT INTO flattened_products (col1, col2, ...)
   VALUES (?, ?, ...), (?, ?, ...), ...
   ON CONFLICT (sku, variant_sku) DO UPDATE SET col1 = EXCLUDED.col1, ...
   ```

   and executes it via `$connection->executeStatement($sql, $flatValues, $flatTypes)` with positional `?` parameters.
5. `$connection->commit()` on success.
6. `$connection->rollBack()` + throw `PersistenceException` on any failure.

This means **one round-trip per shape-group per chunk** instead of one per row — typically one round-trip per chunk in practice, since most rows in a feed share the same shape.

Only the failing chunk is rolled back — previous committed chunks are kept. This trades strict all-or-nothing atomicity for resilience on large feeds.

**Column mapping:** The writer has a fixed `COLUMNS` constant listing the writable columns. Only keys present in the row are sent to the query (sparse rows are safe). Non-string columns (`boolean`, `integer`, `float`) have explicit DBAL types declared in `COLUMN_TYPES` and are attached to the matching positional indexes — without these, DBAL defaults untyped parameters to `PDO::PARAM_STR`, which would silently cast `false` to `""` and break boolean fields.

**Schema:** The `flattened_products` table is created by `migrations/Version20260528000001.php`, applied automatically when the container starts.

---

## Step 8 — The result is printed to the terminal

**File:** `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php`

Once the generator is exhausted and the writer returns, the handler constructs an `IngestProductFeedResult` (immutable, `final readonly`) carrying `recordsProcessed`, `rowsWritten`, `recordsSkipped`, and the list of `errors`, and returns it to the command. The command prints:

```
 ------------------- -------------- ----------------- --------
  Records processed   Rows written   Records skipped   Errors
 ------------------- -------------- ----------------- --------
  500                 1341           0                 0
 ------------------- -------------- ----------------- --------
```

- **Records processed** — feed items successfully read, flattened, validated and handed to the writer.
- **Rows written** — total `FlattenedProductRow` instances yielded to the writer (one record typically becomes N rows, one per variant).
- **Records skipped** — feed items rejected at any stage (bad JSON, flattening failure, zero variants, validation failure).
- **Errors** — count of recorded error messages (one per skipped record); each is listed below the table with its line number and reason.

The process exits 0 on success, 1 if any infrastructure exception was thrown.

---

## Error handling summary

| What failed | Behaviour | Exit code |
|---|---|---|
| File not found / unreadable | `FeedSourceException` thrown → run aborted | 1 |
| Invalid JSON on a line | Line skipped, warning logged, `recordsSkipped` incremented | 0 |
| Record has zero variants | `FlatteningException` thrown → record skipped, warning logged, `recordsSkipped` incremented | 0 |
| Missing required field after flattening | `FlatteningException` thrown → record skipped, warning logged, `recordsSkipped` incremented | 0 |
| DB write failure on a chunk | `PersistenceException` thrown → run aborted, chunk rolled back | 1 |

---

## File reference

| File | Layer | Purpose |
|---|---|---|
| `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php` | Infrastructure | CLI entry point; reads args, calls handler, prints result |
| `src/Application/IngestProductFeed/IngestProductFeedHandler.php` | Application | Orchestrates reader → flattener → validator → writer pipeline |
| `src/Application/IngestProductFeed/IngestProductFeedInput.php` | Application | Value object carrying the source path into the handler |
| `src/Application/IngestProductFeed/IngestProductFeedResult.php` | Application | Immutable `final readonly` result: `recordsProcessed`, `rowsWritten`, `recordsSkipped`, `errors` |
| `src/Domain/Port/Driven/FeedReaderPort.php` | Domain (port) | Interface: yields `ReadResult` items from a source path |
| `src/Domain/Port/Driven/RowWriterPort.php` | Domain (port) | Interface: consumes an iterable of `FlattenedProductRow` |
| `src/Domain/ProductFeed/ProductFeedItem.php` | Domain | Value object: raw JSON data + line number from one feed line |
| `src/Domain/ProductFeed/ReadResult.php` | Domain | Discriminated union: parse success (item) or failure (error + excerpt) |
| `src/Domain/ProductFeed/FlattenedProductRow.php` | Domain | Value object: flat key-value map + line number after flattening |
| `src/Domain/ProductFeed/ProductFlattener.php` | Domain | Flattens nested product JSON into one `FlattenedProductRow` per variant; rejects zero-variant records with `FlatteningException` |
| `src/Domain/ProductFeed/ProductRowValidator.php` | Domain | Validates required fields on each flattened row |
| `src/Domain/ProductFeed/Exception/ProductFeedException.php` | Domain | Abstract base for all domain exceptions; caught by the handler for uniform skip-and-continue handling |
| `src/Domain/ProductFeed/Exception/FeedSourceException.php` | Domain | Thrown when the source file cannot be opened |
| `src/Domain/ProductFeed/Exception/FlatteningException.php` | Domain | Thrown when a record cannot be flattened or validated (incl. zero variants) |
| `src/Domain/ProductFeed/Exception/PersistenceException.php` | Domain | Thrown when a database write fails |
| `src/Infrastructure/Input/JsonlProductFeedReader.php` | Infrastructure | Implements `FeedReaderPort`; reads JSONL file line by line |
| `src/Infrastructure/Persistence/DoctrineFlattenedProductWriter.php` | Infrastructure | Implements `RowWriterPort`; inserts rows in chunks via grouped multi-row Doctrine DBAL statements |
| `migrations/Version20260528000001.php` | Infrastructure | Doctrine migration that creates the `flattened_products` table |
| `config/services.yaml` | Config | Wires `FeedReaderPort` → `JsonlProductFeedReader` and `RowWriterPort` → `DoctrineFlattenedProductWriter` |

---

## Idempotency

Re-running `feed:ingest` on the same file is safe. The writer uses PostgreSQL `INSERT ... ON CONFLICT (sku, variant_sku) DO UPDATE SET ...`, so duplicate rows are updated in place rather than inserted again. The business key is the combination of `sku` + `variant_sku` — one row per product variant.

**Assumption:** `(sku, variant_sku)` pairs are globally unique across the upstream feed. If two distinct products share the same pair, the latest-ingested row silently wins.

**Sparse rows:** if a column is absent in the incoming record, it is omitted from the `INSERT` and therefore not present in `EXCLUDED` — the existing stored value is preserved rather than overwritten with null.
