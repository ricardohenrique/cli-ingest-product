# CSV Reader + JSON Writer + Runtime Format Selection

**Date:** 11-06-2026
**Plan ID:** 1
**Status:** Draft
**Complexity:** Medium

---

## 1. Requirements Analysis

### Functional Requirements
- [ ] Add a CSV reader (comma-delimited, first row is the header)
- [ ] Add a JSON file writer (outputs to the `data/` folder)
- [ ] CLI option `--reader=jsonl|csv` (default: `jsonl`)
- [ ] CLI option `--writer=postgres|json` (default: `postgres`)
- [ ] CLI option `--output-path` for overriding the JSON writer output path
- [ ] Unknown format value exits 1 with a helpful error listing supported formats
- [ ] Default run unchanged: `feed:ingest /data/products.jsonl` continues to work as before

### Non-Functional Requirements
- [ ] JSON writer must stream row-by-row — no full-feed in-memory accumulation
- [ ] CSV reader must stream with `yield` — no full-file load
- [ ] New adapters are wired via DI tags; no service-locator pattern
- [ ] Domain layer remains Symfony/Doctrine-free
- [ ] All new classes are `final` unless explicitly designed for extension

---

## 2. Architecture Review

### Existing Codebase Patterns
- Two driven ports (`FeedReaderPort`, `RowWriterPort`) live in `Domain/Port/Driven/`
- Today each port is aliased one-to-one in `config/services.yaml` to a single concrete adapter
- `.claude/rules/symfony.md` anticipates multi-adapter wiring via `app.feed_reader` / `app.row_writer` tags with a `format` attribute — this feature is where that is activated
- `IngestProductFeedHandler` receives reader + writer via constructor injection; it is format-agnostic
- `JsonlProductFeedReader` streams with `yield` via `fgets`; throws `FeedSourceException` on bad source
- `DoctrineFlattenedProductWriter` chunks 500 rows, groups by column-signature, upserts with `ON CONFLICT`
- `ProductFlattener` requires a `variants` array and expands one product into N rows — this is the key constraint for CSV (see Section 6)

### Affected Areas
- `src/Infrastructure/Input/` — new `CsvProductFeedReader`
- `src/Infrastructure/Output/` — new `JsonFileRowWriter` (new folder)
- `src/Infrastructure/Registry/` — new `FeedReaderRegistry`, `RowWriterRegistry` (new folder)
- `src/Infrastructure/Factory/` — new `IngestProductFeedHandlerFactory` (new folder)
- `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php` — add options, depend on factory
- `src/Application/IngestProductFeed/IngestProductFeedHandler.php` — flattener type changes to port (Step 3)
- `src/Domain/Port/Driven/` — new `FlattenerPort` interface (Step 2, see Open Questions)
- `src/Domain/ProductFeed/` — new `PassthroughFlattener`, new `UnsupportedFormatException`
- `config/services.yaml` — remove port aliases, add tag wiring and JSON output path parameter

### Reusable Components
- `ReadResult::success()` / `ReadResult::failure()` — used as-is by the CSV reader
- `FeedSourceException`, `PersistenceException` — thrown by new adapters following the existing pattern
- `InMemoryFeedReader`, `InMemoryRowWriter` — test stubs, used as-is in handler integration tests
- `ProductRowValidator` — unchanged, validates every flattened row regardless of format

### Architecture Decision
Format selection lives **entirely in the Infrastructure/Console layer**. The handler remains format-agnostic. A new `IngestProductFeedHandlerFactory` receives both registries, both flatteners, the validator, and the logger via DI, and constructs the correct handler at runtime from the `--reader`/`--writer` options. The two hard port-aliases in `services.yaml` are removed; resolution moves to the factory.

---

## 3. Step Breakdown

### Step 1: `UnsupportedFormatException`
- **What:** New domain exception for unknown reader/writer format names
- **Where:** `src/Domain/ProductFeed/Exception/UnsupportedFormatException.php`
- **How:** `final class UnsupportedFormatException extends ProductFeedException`. Constructor accepts the bad format string and the list of supported formats; includes both in the message. Zero framework imports.
- **Test:** Unit — `instanceof ProductFeedException`; message contains the bad format and supported list
- **Complexity:** Small

### Step 2: `FlattenerPort` + `PassthroughFlattener`
- **What:** Promote flattening to a Domain port with two implementations: the existing variant-expanding one and a new passthrough for pre-flattened CSV rows
- **Where:**
  - `src/Domain/Port/Driven/FlattenerPort.php` (new interface)
  - `src/Domain/ProductFeed/ProductFlattener.php` (add `implements FlattenerPort`)
  - `src/Domain/ProductFeed/PassthroughFlattener.php` (new)
- **How:** `FlattenerPort` declares `flatten(ProductFeedItem $item): FlattenedProductRow[]`. `PassthroughFlattener` returns exactly one `FlattenedProductRow` from the item's data with its line number — no variant expansion, no variant key required. `ProductFlattener` behaviour and existing tests are unchanged; it simply gains `implements FlattenerPort`.
- **Test:** Unit — `PassthroughFlattener` with a flat data item returns 1 row preserving all keys; `ProductFlattener instanceof FlattenerPort` passes; existing `ProductFlattenerTest` stays green
- **Complexity:** Small

> **Why this approach (Option B):** A CSV row is already at variant grain — one row per variant. Sending it through `ProductFlattener::flatten()` would throw `FlatteningException` (no `variants` key). Option A (CSV reader re-nests columns into a nested structure to reuse the existing flattener) would couple the reader to the full domain schema inversely — brittle and wrong. A per-format flattening strategy keeps each class with a single responsibility.

### Step 3: Handler depends on `FlattenerPort`
- **What:** Change `IngestProductFeedHandler` to depend on the port, not the concrete class
- **Where:** `src/Application/IngestProductFeed/IngestProductFeedHandler.php`
- **How:** Replace `private readonly ProductFlattener $flattener` with `private readonly FlattenerPort $flattener`. No logic change.
- **Test:** Existing `IngestProductFeedHandlerTest` stays green (inject real `ProductFlattener`); add one case using `PassthroughFlattener` with flat records to verify end-to-end passthrough
- **Complexity:** Small

### Step 4: `CsvProductFeedReader`
- **What:** New reader that streams a comma-delimited CSV file as `ReadResult` items
- **Where:** `src/Infrastructure/Input/CsvProductFeedReader.php`
- **How:**
  - Tag: `#[AutoconfigureTag('app.feed_reader', ['format' => 'csv'])]`
  - Open with `@fopen`; throw `FeedSourceException` if it fails
  - Read header row with `fgetcsv` (delimiter `,`)
  - Stream each data row: `array_combine(header, cells)` → `ProductFeedItem`; `lineNumber` = file line (header = 1, first data row = 2)
  - On column-count mismatch or `fgetcsv` returning `[null]` (blank line): skip blank, yield `ReadResult::failure` for mismatch
  - Close handle in `finally`
  - CSV rows are expected to carry the **flattened key vocabulary** (`sku`, `origin_country`, `variant_sku`, `variant_index`, …) — each row is already at variant grain; string values for numeric columns are acceptable (downstream DB writer handles coercion via DBAL types)
- **Test:** Unit — 3-row CSV → 3 success results with correct keys and line numbers; row with wrong column count → 1 failure; header-only file → empty; blank line skipped; non-existent file → `FeedSourceException`
- **Complexity:** Small

### Step 5: `JsonFileRowWriter`
- **What:** New writer that streams flattened rows to a JSON array file
- **Where:** `src/Infrastructure/Output/JsonFileRowWriter.php`
- **How:**
  - Tag: `#[AutoconfigureTag('app.row_writer', ['format' => 'json'])]`
  - Constructor: `string $outputPath`, feed `LoggerInterface`
  - `write(iterable $rows)`: open file with `@fopen('w')` (throw `PersistenceException` on failure); write `[`; then for each row write `json_encode($row->getData(), JSON_THROW_ON_ERROR)` separated by `,`\n; then write `]`; close in `finally`
  - Empty input writes `[]`
  - Never accumulates rows in memory — streaming via foreach
  - Overwrites the file each run
- **Test:** Integration (real temp file) — 3 rows → valid JSON array of 3 objects with expected keys; empty input → `[]`; 1 200 rows → 1 200 elements (verify streaming, not array accumulation); unwritable path → `PersistenceException`
- **Complexity:** Small

### Step 6: `FeedReaderRegistry` + `RowWriterRegistry`
- **What:** Two registry classes that resolve a tagged adapter by its `format` string
- **Where:**
  - `src/Infrastructure/Registry/FeedReaderRegistry.php`
  - `src/Infrastructure/Registry/RowWriterRegistry.php`
- **How:**
  - Each registry receives a `#[AutowireIterator('app.feed_reader', indexAttribute: 'format')]` / `app.row_writer` tagged iterator (keyed by the `format` attribute) via constructor
  - `get(string $format)`: return the matching adapter or throw `UnsupportedFormatException` with the bad format + supported list
  - `supportedFormats(): list<string>`: returns the keys
  - No container access — purely constructor-injected iterable; satisfies "no service locator pattern"
- **Test:** Unit — build registry with stub adapters; `get('csv')` returns it; `get('xml')` throws `UnsupportedFormatException` listing supported formats in message
- **Complexity:** Small

### Step 7: `IngestProductFeedHandlerFactory`
- **What:** Factory that constructs the correct handler from runtime format strings
- **Where:** `src/Infrastructure/Factory/IngestProductFeedHandlerFactory.php`
- **How:**
  - Constructor: `FeedReaderRegistry`, `RowWriterRegistry`, `ProductFlattener` (variant-expanding), `PassthroughFlattener`, `ProductRowValidator`, feed `LoggerInterface`, `string $defaultJsonOutputPath`
  - `create(string $readerFormat, string $writerFormat, ?string $outputPathOverride): IngestProductFeedHandler`:
    - Resolves reader from registry
    - Pairs flattener: `jsonl` → `ProductFlattener`, `csv` → `PassthroughFlattener`
    - Resolves writer from registry; if `json` and `$outputPathOverride` is set, re-instantiates `JsonFileRowWriter` with the override path
    - Returns `new IngestProductFeedHandler(reader, writer, flattener, validator, logger)`
  - No service locator — all dependencies are constructor-injected
- **Test:** Unit — `create('jsonl', 'postgres', null)` → handler with correct adapters; `create('csv', 'json', '/tmp/out.json')` → handler with CSV reader + passthrough + json writer at given path; unknown reader format → `UnsupportedFormatException`; unknown writer format → `UnsupportedFormatException`
- **Complexity:** Medium

### Step 8: Command options + `services.yaml` wiring
- **What:** Add `--reader`, `--writer`, `--output-path` options to the command; wire everything in DI
- **Where:**
  - `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php`
  - `config/services.yaml`
- **How:**
  - Command now depends on `IngestProductFeedHandlerFactory` (not `IngestProductFeedHandler` directly)
  - Add options in `configure()`:
    - `--reader` (default `jsonl`)
    - `--writer` (default `postgres`)
    - `--output-path` (optional, default null)
  - In `execute()`: resolve options, call `$factory->create(reader, writer, outputPath)`, then run as today
  - Catch `UnsupportedFormatException` → `$io->error(...)` with supported format list, return `Command::FAILURE`
  - In `services.yaml`:
    - Remove the two port aliases (`FeedReaderPort`, `RowWriterPort`)
    - Add a parameter for the default JSON output path (e.g. `app.json_writer.output_path: '%kernel.project_dir%/data/flattened_products.json'`)
    - Bind it to `JsonFileRowWriter.$outputPath` and `IngestProductFeedHandlerFactory.$defaultJsonOutputPath`
    - Tags are declared via `#[AutoconfigureTag]` on each adapter class (keeps format co-located with the class)
- **Test:**
  - Functional (CommandTester) — default run with JSONL fixture exits 0 and prints summary (update existing test to use factory)
  - CSV fixture + `--writer=json --output-path=/tmp/test.json` exits 0 and produces a valid JSON file
  - `--reader=bogus` exits 1 with a message listing `jsonl`, `csv`
- **Complexity:** Medium

### Step 9: Documentation
- **What:** Update README and add a note in `docs/` about the new formats and design
- **Where:** `README.md`
- **How:** Document the new `--reader`, `--writer`, `--output-path` options with examples; explain the tagged-adapter registry pattern so a contributor knows how to add a third format; document CSV row-shape contract (flat key vocabulary, variant grain, string-typed numeric cells); note the `FlattenerPort` addition and that CSV uses `PassthroughFlattener`
- **Test:** A new contributor should be able to add a third reader/writer by implementing the port + adding an `#[AutoconfigureTag]`
- **Complexity:** Small

---

## 4. Risk Assessment

### Risks

| Risk | Severity | Mitigation |
|---|---|---|
| `FlattenerPort` addition changes a Domain port and the handler's dependency | Medium | Explicit step (Step 2–3) with targeted tests; `ProductFlattener` keeps its behaviour unchanged |
| Removing `FeedReaderPort`/`RowWriterPort` aliases from `services.yaml` could break the functional test | Low | Functional test is updated in Step 8; handler is now built by the factory |
| JSON writer loading all rows into memory before encoding | High | Stream row-by-row with incremental writes; integration test with 1 200 rows verifies no full-array build |
| Unknown `--reader`/`--writer` value passed by user | Low | Registry throws `UnsupportedFormatException`; command catches and exits 1 with supported list |
| CSV numeric columns arrive as strings (DBAL type mapping) | Medium | Documented in README; Postgres writer already handles coercion via `COLUMN_TYPES`; JSON writer emits strings as-is — acceptable |
| `--output-path` override path doesn't exist or is unwritable | Low | `JsonFileRowWriter` throws `PersistenceException`; command propagates as failure |

### Mitigations
- All steps end with a green `docker compose run --rm php bin/phpunit`
- Each step is reviewed by `code-reviewer` before the next begins
- `PassthroughFlattener` is explicitly tested to verify it never touches the `variants` key

### Fallbacks
- If the `FlattenerPort` approach is not approved, the CSV reader can synthesise a single-element `variants` array to reuse the existing `ProductFlattener` — this is Option A, documented as a fallback but not recommended (see Step 2 rationale)

---

## 5. Execution Checklist

- [ ] Step 1: `UnsupportedFormatException` — new domain exception
- [ ] Step 2: `FlattenerPort` + `PassthroughFlattener` — new domain port and passthrough implementation
- [ ] Step 3: Handler depends on `FlattenerPort` — swap concrete type to interface
- [ ] Step 4: `CsvProductFeedReader` — new infrastructure reader
- [ ] Step 5: `JsonFileRowWriter` — new infrastructure writer
- [ ] Step 6: `FeedReaderRegistry` + `RowWriterRegistry` — format-keyed adapter registries
- [ ] Step 7: `IngestProductFeedHandlerFactory` — runtime handler construction from format strings
- [ ] Step 8: Command options + `services.yaml` wiring — `--reader`, `--writer`, `--output-path`
- [ ] Step 9: Documentation — README, CSV contract, tagged-adapter extension guide

---

## Open Questions (resolve before starting Step 2)

**Q1 (critical):** Approve **Option B** (`FlattenerPort` + `PassthroughFlattener`) for the CSV flattening problem? Or prefer Option A (CSV reader re-nests columns to reuse `ProductFlattener` unchanged)? This changes a Domain port and the handler's dependency.

**Q2:** Confirm the CSV header vocabulary is the **flattened key set** (`sku`, `origin_country`, `variant_sku`, `variant_index`, …), i.e. each CSV row is already at variant grain.

**Q3:** For `--output-path` override, prefer the factory re-instantiates `JsonFileRowWriter` with the override path, or add an immutable `withOutputPath(string): self` clone method to the writer?

**Q4:** JSON writer output as a **single array-of-objects file** (default in this plan) vs **JSON Lines** (one object per line, symmetric with the JSONL reader)?
