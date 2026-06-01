# FeedFlattener — Architecture Review

## Summary

The codebase has a clean, well-respected hexagonal layout. Domain is genuinely framework-free, ports live where they should, adapters are properly isolated, and the application handler stays a thin orchestrator. The pipeline (read → flatten → validate → write) is correctly sequenced and idiomatically uses a generator to keep memory flat. The main weaknesses are not architectural drift but *residual scaffolding from the Symfony skeleton* (`HealthController`, `Story/AppStory`) and a couple of small classes whose existence is borderline. One Symfony convention rule is also being violated: console commands should live in `src/Infrastructure/Console/`, not `src/UI/Console/`. None of these are blockers; the architecture is in a healthy state.

---

## Classes with no clear reason to exist

### 1. `src/Controller/HealthController.php`

**What it does:** A trivial HTTP endpoint returning `{"status":"ok"}`.

**Why it is questionable:** The project's stated purpose (per `.claude/CLAUDE.md`) is a CLI pipeline. There is no HTTP-facing functionality in scope. A health controller for a CLI ingestion job is dead weight that drags in routing and an entire HTTP surface area for zero gain.

**Suggestion:** Delete it. If "is the container healthy?" must be answerable, expose it as a `feed:health` console command, or drop it entirely. If kept, move it under `src/Infrastructure/Http/Controller/` to align with the hexagonal layout — the current `src/Controller/` top-level bypasses the layer hierarchy entirely.

---

### 2. `src/Story/AppStory.php`

**What it does:** An empty Zenstruck Foundry `Story` with a single commented-out line.

**Why it is redundant:** No fixtures are defined, no test uses it, and the project has no domain entities suitable for factories (everything is a plain value object). It is pure scaffolding.

**Suggestion:** Delete the file and the `src/Story/` directory. Re-add only when fixtures actually exist.

---

### 3. `src/Application/IngestProductFeed/IngestProductFeedInput.php`

**What it does:** Wraps a single `string $sourcePath`.

**Why it is borderline:** It is a one-field DTO whose only consumer is `IngestProductFeedHandler::handle()`. The abstraction adds a layer without adding meaning today.

**Suggestion:** Keep it only if you anticipate growing the input (e.g., `chunkSize`, `dryRun`, `outputFormat`). Otherwise inline it as `handle(string $sourcePath)`. Lean toward keeping it since growth is likely; flag it as YAGNI-risk if no new fields appear soon.

---

### 4. `src/Application/IngestProductFeed/IngestProductFeedResult.php`

**What it does:** Mutable counter + error log returned from the handler.

**Why it is questionable:** It is a mutable result object, which conflicts with the codebase's preference for immutability. It also doubles as an internal accumulator passed around inside `generateRows()`, mixing "result" and "scratchpad" responsibilities.

**Suggestion:** Either (a) make it a readonly value object built once at the end of `handle()` from internal accumulator variables, or (b) rename the accumulation role clearly and split it from the public-facing read API. Today's design works but is inconsistent with the rest of the domain's value-object discipline.

---

### 5. `src/Domain/ProductFeed/ProductRowValidator.php`

**What it does:** Validates that a `FlattenedProductRow` has required keys and no empty keys.

**Why it is borderline:** It contains 8 lines of real logic and is a separate class because the handler calls it after `flatten()`. The flattener itself could yield only valid rows; alternatively validation could live as a `FlattenedProductRow::assertValid()` method so invalid rows cannot exist.

**Suggestion:** Acceptable as-is (SRP-clean). Consider whether `FlattenedProductRow` should enforce its own invariants in the constructor — that would eliminate the validator and make invalid rows unrepresentable. If the validator stays, no change needed.

---

## Wrong layer placements

### 1. `src/UI/Console/IngestProductFeedSymfonyCommand.php`

- **Current layer:** `src/UI/Console/`
- **Correct layer:** `src/Infrastructure/Console/`
- **Reason:** `.claude/rules/symfony.md` explicitly states *"Console commands live in src/Infrastructure/Console/"*. The `src/UI/` folder has no other inhabitants and creates a fourth top-level layer the architecture rules do not define.

---

### 2. `src/Controller/HealthController.php`

- **Current layer:** `src/Controller/` (top-level, outside the hex layout)
- **Correct layer:** `src/Infrastructure/Http/Controller/` if retained
- **Reason:** Controllers are HTTP adapters and belong in Infrastructure. The current placement creates a fifth top-level folder that bypasses the architecture entirely.

---

### 3. `src/Story/AppStory.php`

- **Current layer:** `src/Story/` (top-level production source)
- **Correct layer:** Does not belong in `src/` at all — Foundry stories/factories should sit under `tests/` or `src/Infrastructure/Fixture/`
- **Reason:** Production source code should not contain test fixtures.

---

### 4. `src/Domain/Port/Driven/` (style note)

- The `Driven/` sub-namespace is fine, but no `Driving/` counterpart exists. Either flatten to `src/Domain/Port/` (since only one port direction is in use) or keep the structure as forward-looking. Not a violation — a style observation.

---

## Workflow issues

The pipeline `JsonlProductFeedReader → IngestProductFeedHandler::generateRows() → DoctrineFlattenedProductWriter::write()` is correctly sequenced and uses a generator end-to-end, keeping memory bounded for arbitrarily large feeds. A few observations:

### 1. Products with zero variants vanish silently from the report

`ProductFlattener::flatten()` returns `[]` for products with no variants. `IngestProductFeedHandler` iterates zero times and treats the record as fully processed — neither `incrementProcessed` nor `incrementSkipped` is called. The feed item disappears from the result counters entirely.

**Suggestion:** Either throw `FlatteningException('product has no variants')` so the handler logs and counts it as skipped, or explicitly call `$result->incrementSkipped()` when `flatten()` returns empty.

---

### 2. `processedCount` semantics are ambiguous

`processedCount` increments per-row, not per-record. The name suggests "records processed" but the actual value is "rows written". Looking at the integration test (`testHappyPathThreeValidRecordsAllWritten`), the intent is rows.

**Suggestion:** Rename to `writtenRowCount` (or split into `recordsProcessed` + `rowsWritten`) to remove the ambiguity.

---

### 3. Chunk boundary is transactional only — not a true batch insert

Each row is a separate `executeStatement` call inside the chunk transaction. The chunking only batches the *transaction boundary*, not the SQL round-trips. This is a performance issue: for a 500-row chunk, it is still 500 separate database round-trips.

**Suggestion:** Multi-row `INSERT INTO ... VALUES (...), (...), ...` with chunked parameter binding would be substantially faster. Not urgent, but notable.

---

### 4. Handler `try` block catches only `FlatteningException`

The flatten + validate block catches only `FlatteningException`. If a future validator throws something other than `FlatteningException`, the row escapes the loop and aborts the whole feed.

**Suggestion:** Catch `ProductFeedException` (the shared base) for record-level errors, while letting infrastructure exceptions (`PersistenceException`, `FeedSourceException`) bubble out unchanged.

---

## Correct placements (notable)

- `src/Domain/Port/Driven/FeedReaderPort.php` and `RowWriterPort.php` — correct location, pure interfaces, generic `iterable` return types that allow streaming.
- `src/Domain/ProductFeed/Exception/ProductFeedException.php` — abstract base extending `\DomainException`, with three named children cleanly partitioned by failure source. Matches `.claude/rules/domain.md` exactly.
- `src/Domain/ProductFeed/ReadResult.php` — private constructor + named static factories (`success`, `failure`) is the correct pattern for a discriminated union. Correctly in Domain since the reader port returns it.
- `src/Infrastructure/Input/JsonlProductFeedReader.php` — `fopen`/`fgets` with `try/finally`, yields per-line `ReadResult`s, captures excerpts on JSON failure, throws `FeedSourceException` for unopenable sources. Memory-safe and stream-correct.
- `src/Infrastructure/Persistence/DoctrineFlattenedProductWriter.php` — explicit DBAL column types for non-strings, upsert SQL built from key columns, chunked transactions. Correctly placed and well-encapsulated.
- `src/Application/IngestProductFeed/IngestProductFeedHandler.php` — pure orchestration with no business logic inline. Uses generators end-to-end. Exemplary application-layer code.
- `config/services.yaml` — port-to-adapter bindings are explicit and correct.
- `tests/Stub/InMemoryFeedReader.php` and `InMemoryRowWriter.php` — proper stubs implementing the ports, used by handler integration tests without mocking domain objects. Aligns with `.claude/rules/tests.md`.

---

## Recommendations (prioritized)

1. **Move the console command to `src/Infrastructure/Console/`** — `src/UI/Console/IngestProductFeedSymfonyCommand.php`. Direct violation of `.claude/rules/symfony.md`; quickest fix, highest rule-compliance impact.

2. **Delete `src/Controller/HealthController.php` and `src/Story/AppStory.php`** — unused Symfony-skeleton residue that pulls in HTTP and fixture infrastructure for a CLI-only project.

3. **Handle the empty-variants case in `ProductFlattener` or the handler** — `src/Domain/ProductFeed/ProductFlattener.php`. Products with no variants currently disappear from the report with no trace.

4. **Rename `processedCount` to `writtenRowCount`** — `src/Application/IngestProductFeed/IngestProductFeedResult.php`. The current name misleads consumers; test assertions confirm rows-not-records semantics.

5. **Make `IngestProductFeedResult` immutable** — `src/Application/IngestProductFeed/IngestProductFeedResult.php`. Aligns with the immutability convention used everywhere else and removes its mutable scratchpad role.

6. **Catch `ProductFeedException` instead of `FlatteningException` in the handler loop** — `src/Application/IngestProductFeed/IngestProductFeedHandler.php`. Future-proofs the skip-and-continue logic against new domain exception subtypes.

7. **Consider moving `ProductRowValidator` logic into `FlattenedProductRow`'s constructor** — `src/Domain/ProductFeed/FlattenedProductRow.php` and `ProductRowValidator.php`. Make invalid rows unrepresentable rather than detectable after the fact. Optional; today's design is acceptable.

8. **Adopt true multi-row batch inserts in `DoctrineFlattenedProductWriter`** — `src/Infrastructure/Persistence/DoctrineFlattenedProductWriter.php`. Performance-only, but the current per-row `executeStatement` defeats most of the benefit of chunking.

9. **Either flatten or complete the `Driven/` namespace structure** — `src/Domain/Port/Driven/`. Cosmetic; only matters if you commit to a `Driving/` counterpart later.

10. **Inline `IngestProductFeedInput` if no second field appears soon** — `src/Application/IngestProductFeed/IngestProductFeedInput.php`. Low priority; revisit only if it stays a one-field DTO.
