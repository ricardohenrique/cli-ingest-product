# FeedFlattener — Optimization Plan

Derived from `docs/architecture-review.md`. Each item carries a tech-lead decision (Accept / Accept with modification / Reject), a concrete fix, acceptance criteria, and blast radius. Items are then grouped into ordered implementation steps.

---

## Decisions at a glance

| # | Review item | Decision |
|---|---|---|
| 1 | Move console command to `src/Infrastructure/Console/` | Accept |
| 2 | Delete `HealthController` and `AppStory` | Accept with modification |
| 3 | Handle empty-variants case in flattener/handler | Accept with modification |
| 4 | Rename `processedCount` to reflect row semantics | Accept with modification |
| 5 | Make `IngestProductFeedResult` immutable | Accept |
| 6 | Catch `ProductFeedException` instead of `FlatteningException` | Accept |
| 7 | Move `ProductRowValidator` logic into `FlattenedProductRow` constructor | **Reject** |
| 8 | True multi-row batch inserts in `DoctrineFlattenedProductWriter` | Accept with modification |
| 9 | Flatten / complete `Driven/` namespace | **Reject** |
| 10 | Inline `IngestProductFeedInput` | **Reject** |

---

## Accepted items (detailed)

### A1 — Move console command to `src/Infrastructure/Console/`

**Problem:** `src/UI/Console/IngestProductFeedSymfonyCommand.php` directly violates `.claude/rules/symfony.md`.

**Fix:**
- Move `src/UI/Console/IngestProductFeedSymfonyCommand.php` → `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php`; update namespace to `App\Infrastructure\Console`
- Delete empty `src/UI/` directory
- Move `tests/Functional/UI/Console/IngestProductFeedSymfonyCommandTest.php` → `tests/Functional/Infrastructure/Console/IngestProductFeedSymfonyCommandTest.php`; update namespace and `use` import
- Delete empty `tests/Functional/UI/` directory

**Acceptance criteria:**
- `bin/console list | grep feed:ingest` still shows the command
- `bin/phpunit` passes
- `find src/UI tests/Functional/UI -type d 2>/dev/null` returns nothing

**Blast radius:** Low (2 files moved, 2 directories deleted)

---

### A2 — Delete `HealthController` and `AppStory` (and prune dead config)

**Decision:** Accept with modification — delete outright, do **not** relocate the controller. Adding an HTTP surface to a CLI-only project preserves routing, security, and profiler wiring for no gain.

**Fix:**
- Delete `src/Controller/HealthController.php` and empty `src/Controller/` directory
- Delete `src/Story/AppStory.php` and empty `src/Story/` directory
- Audit `config/packages/` — for each candidate below, remove if `grep -r` finds no production-code reference:
  - `nelmio_api_doc.yaml` and `config/routes/nelmio_api_doc.yaml`
  - `web_profiler.yaml` and `config/routes/web_profiler.yaml`
  - `zenstruck_foundry.yaml`
  - `security.yaml` and `config/routes/security.yaml`
- Remove the `controllers:` resource from `config/routes.yaml` if no controllers remain
- Run `composer remove` for packages whose only consumer was a deleted file (e.g. `zenstruck/foundry`) if package audit confirms zero remaining usage

**Acceptance criteria:**
- `bin/console cache:clear` succeeds
- `bin/console debug:router` shows no HTTP routes
- `bin/phpunit` passes

**Blast radius:** Medium (2 source files + up to ~6 config files + possible `composer.json` edits)

---

### A3 — Handle products with zero variants

**Decision:** Accept with modification — throw `FlatteningException` from the flattener (not the handler). The domain is the right place to declare "a product with no variants is not flattenable"; the handler should stay orchestration-only.

**Problem:** Products with no variants silently disappear from the report — neither `processedCount` nor `skippedCount` is incremented.

**Fix:**
- In `src/Domain/ProductFeed/ProductFlattener.php`: replace `if (empty($variants)) { return []; }` with:
  ```php
  throw new FlatteningException(
      sprintf('Product "%s" has no variants', $data['sku'] ?? '<unknown>')
  );
  ```
- In `tests/Unit/Domain/ProductFeed/ProductFlattenerTest.php`: rename `testZeroVariantsProducesNoRows` → `testZeroVariantsThrowsFlatteningException`; assert `FlatteningException` is thrown
- Add `testProductWithNoVariantsIsCountedAsSkipped` in `tests/Integration/Application/IngestProductFeedHandlerTest.php`: feed one record with `'variants' => []`; assert `skippedCount === 1`, `errors` contains the line number, `processedCount === 0`

**Acceptance criteria:**
- Updated unit and new integration tests pass
- A feed of N records satisfies `processed + skipped == N`

**Blast radius:** Low (1 source file, 2 test files)

---

### A4 — Split counters into `recordsProcessed` / `recordsSkipped` / `rowsWritten`

**Decision:** Accept with modification — split rather than simple rename. Today `Processed` counts rows and `Skipped` counts records, making the summary table units inconsistent. The split fixes this at the source and gives operators both numbers.

**Problem:** `processedCount` increments per row, not per record. The name misleads consumers.

**Fix:**
- In `src/Application/IngestProductFeed/IngestProductFeedResult.php` (reshaped under A5): public readonly fields `int $recordsProcessed`, `int $recordsSkipped`, `int $rowsWritten`, `array $errors`
- In `src/Application/IngestProductFeed/IngestProductFeedHandler.php`: track local `$recordsProcessed`, `$rowsWritten` counters; increment `$recordsProcessed` once per successfully processed record (after all its rows have been validated), `$rowsWritten` per yielded row
- In `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php` (post-A1): update table header to `['Records processed', 'Rows written', 'Records skipped', 'Errors']` and read `$result->recordsProcessed`, `$result->rowsWritten`, `$result->recordsSkipped`
- Update all assertions in `tests/Integration/Application/IngestProductFeedHandlerTest.php` to use the new property names and semantics:
  - `testHappyPathThreeValidRecordsAllWritten`: `recordsProcessed === 3`, `rowsWritten === 6`, `recordsSkipped === 0`
  - `testMalformedRecordFromReaderIsSkipped`: `recordsProcessed === 2`, `rowsWritten === 2`, `recordsSkipped === 1`
  - `testRecordThatFailsValidationIsSkipped`: `recordsProcessed === 2`, `rowsWritten === 2`, `recordsSkipped === 1`
  - `testEmptyInputProducesNoWritesAndNoErrors`: all three counters `=== 0`

**Acceptance criteria:**
- `bin/phpunit` green
- Running `feed:ingest` on the sample fixture prints a four-column table where `rowsWritten` equals the actual DB row count on a fresh table

**Blast radius:** Medium (result VO, handler, command, integration test — 4–5 files)

---

### A5 — Make `IngestProductFeedResult` immutable

**Problem:** `IngestProductFeedResult` is mutable and acts as both a result object and an in-flight accumulator, violating the codebase-wide readonly value-object convention.

**Fix:**
- Reshape `src/Application/IngestProductFeed/IngestProductFeedResult.php` as `final readonly class` with public readonly constructor parameters: `int $recordsProcessed`, `int $recordsSkipped`, `int $rowsWritten`, `array $errors`. Remove all mutator methods
- In `src/Application/IngestProductFeed/IngestProductFeedHandler.php`: accumulate in local variables inside `handle()`, construct `new IngestProductFeedResult(...)` at the end and return it
- Command and test updates are subsumed in A4

**Acceptance criteria:**
- `IngestProductFeedResult` has no mutator methods
- All tests pass

**Blast radius:** Low–Medium (result VO + handler; test updates covered by A4)

---

### A6 — Catch `ProductFeedException` instead of `FlatteningException` in the handler

**Problem:** The handler loop only catches `FlatteningException`; a future record-level domain exception extending `ProductFeedException` would escape the loop and abort the entire feed.

**Fix:**
- In `src/Application/IngestProductFeed/IngestProductFeedHandler.php`: change `catch (FlatteningException $e)` → `catch (ProductFeedException $e)`; update `use` import accordingly
- Verify `PersistenceException` and `FeedSourceException` (both extend `ProductFeedException`) cannot be swallowed: confirm the writer call and reader open are outside this try block
- Add one integration test asserting that any `ProductFeedException` subtype thrown during flattening is counted as skipped (not a feed abort)

**Acceptance criteria:**
- Existing tests green
- New test confirms catch-by-base-class behaviour
- Writer-level `PersistenceException` still propagates (verified by existing writer failure test or code inspection)

**Blast radius:** Low (1 source file, 1 test)

---

### A8 — True multi-row batch inserts in `DoctrineFlattenedProductWriter`

**Decision:** Accept with modification — group rows in the chunk by column-signature first (rows can omit optional columns, so multi-row VALUES requires identical column lists per statement). Use positional `?` parameters to avoid named-parameter collisions across rows.

**Problem:** `insertChunk()` issues one `executeStatement` per row; the chunk transaction batches commit overhead but not SQL round-trips.

**Fix:**
- Refactor `src/Infrastructure/Persistence/DoctrineFlattenedProductWriter.php::insertChunk()`:
  - Group rows by serialized column-signature (sorted key list)
  - For each group build one multi-row INSERT: `INSERT INTO flattened_products (col1, col2, ...) VALUES (?,?,...), (?,?,...), ... ON CONFLICT (sku, variant_sku) DO UPDATE SET col1 = EXCLUDED.col1, ...`
  - Flatten all row values and types into a single positional array; pass to `executeStatement` once per group inside the existing transaction
- Replace `buildUpsertSql(array $columns): string` with `buildBatchUpsertSql(array $columns, int $rowCount): string`
- Add integration tests in `tests/Integration/Infrastructure/Persistence/DoctrineFlattenedProductWriterTest.php`:
  - Mixed-shape chunk: 4 same-shape rows + 2 rows missing an optional column → all 6 land in DB; 2 statement groups used
  - Conflict path: re-insert an existing row with a changed non-key column; assert upsert updates correctly

**Acceptance criteria:**
- All existing writer tests pass
- New tests prove batch grouping and correct upsert on conflict
- Memory stays flat (no materialisation beyond the existing chunk buffer)

**Blast radius:** Low (1 source file, 1 test file)

---

## Implementation steps (ordered by dependency)

| Step | Items | Depends on | Can parallelise with |
|---|---|---|---|
| 1 | A1, A2 — folder corrections, dead code deletion | — | Everything else |
| 2 | A3 — empty-variants throws | Step 1 (edit files in final locations) | — |
| 3 | A5 + A4 — immutable result + counter split | Step 2 (A3 changes counter semantics that A4 tests assert on) | — |
| 4 | A6 — broaden handler catch | Step 3 (test reads new readonly result shape) | Step 5 |
| 5 | A8 — batch inserts | None functionally; last for risk isolation | Step 4 |

Run `docker compose run --rm php bin/phpunit` after each step before proceeding.

---

## What we are NOT doing and why

### R7 — Push `ProductRowValidator` logic into `FlattenedProductRow` constructor

The validator enforces the writer's output contract (`REQUIRED_KEYS` is a writer-side concern, not an intrinsic property of "a flattened row"). Pushing this into the constructor would:
- Couple the domain VO to one writer's schema, foreclosing future writers with different required fields
- Make it impossible to construct a row for any consumer without satisfying this particular writer's requirements

Today's split is SRP-clean. No change.

### R9 — Flatten or complete the `Driven/` namespace

Pure cosmetics. The `Driven/Driving` split is established hexagonal terminology, costs nothing to keep, and is forward-looking if a driving port (use-case interface) is introduced later. Refactoring it now is churn with no functional or rule-compliance benefit.

### R10 — Inline `IngestProductFeedInput`

Near-term growth is likely (`chunkSize`, `dryRun`, `outputFormat`). The one-field DTO costs nothing to keep and would need re-extraction next sprint. Re-evaluate only if it remains single-field after two more feature iterations.
