# FeedFlattener — Live Coding Interview Prep

A CLI pipeline (PHP 8.4 / Symfony 8 / Doctrine DBAL / PostgreSQL, hexagonal) that
reads a JSONL product feed, flattens nested products into tabular rows (one row per
variant), validates them, and upserts them into `flattened_products`.

Read this the night before. Skim section 1, 4, and 5 right before the call.

---

## 0. The 30-second mental model

```
CLI command  →  Handler (Application)  →  Generator streams rows  →  Writer
   (path)         reader.read() ─ yields ReadResult                 (chunked
                  flattener.flatten() ─ FlattenedProductRow[]        upsert)
                  validator.validate()
```

- **Ports** (`FeedReaderPort`, `RowWriterPort`) live in `Domain/Port/Driven/`.
- **Adapters** (`JsonlProductFeedReader`, `DoctrineFlattenedProductWriter`) live in `Infrastructure/`.
- The **Domain** has zero framework imports. The **Handler** depends only on interfaces + domain services.
- Record-level errors are **logged and skipped**; infrastructure errors **throw**.

Files to have open:
- `src/Application/IngestProductFeed/IngestProductFeedHandler.php`
- `src/Domain/ProductFeed/ProductFlattener.php`
- `src/Infrastructure/Persistence/DoctrineFlattenedProductWriter.php`
- `src/Infrastructure/Input/JsonlProductFeedReader.php`
- `config/services.yaml`

---

## 1. Walkthrough script (0–10 min)

Say these out loud. Pause after each block to invite questions.

**What it does**
- "It's a CLI ingest pipeline. You point it at a JSONL feed of products, each product has nested metadata and a list of variants."
- "It flattens each product into one tabular row per variant — nested objects become `_`-delimited columns, scalar arrays get comma-joined — and upserts the rows into Postgres."
- "Run it with `docker compose run --rm php bin/console feed:ingest /data/products.jsonl`."

**Why hexagonal**
- "The core transformation logic — flattening and validation — is the valuable, testable part. I wanted it framework-free so it doesn't drift when Symfony or Doctrine change."
- "Two driven ports: `FeedReaderPort` (where data comes from) and `RowWriterPort` (where it goes). The domain depends on those interfaces; infrastructure implements them."
- "That means I can add a CSV reader or an S3 writer without touching domain or application code."

**Why a streaming Generator (not an array)**
- "The handler hands the writer a `Generator`, not a materialized array. The reader yields one `ReadResult` at a time, the handler flattens/validates and yields rows, and the writer pulls them in chunks."
- "Net effect: a 2GB feed never lives in memory. Peak memory is roughly one chunk, not the whole file."

**Why chunked upsert**
- "The writer buffers 500 rows, then does one multi-row `INSERT … VALUES (…),(…),…`. One round trip per 500 rows instead of one per row — that's the difference between minutes and hours on a large feed, and it's wrapped in a transaction so a chunk is all-or-nothing."

**Why `ON CONFLICT … DO UPDATE`**
- "The natural key is `(sku, variant_sku)`. Re-running the same feed should be idempotent and updates should land in place. Upsert gives me that in one statement without a read-modify-write race."

**Trade-offs I made (be honest, it scores points)**
- "The output schema is hard-coded in the writer's `COLUMNS` constant. That's deliberate: a fixed, typed schema with explicit DBAL types so booleans and floats don't get stringified. The cost is that adding a product field touches the writer. With more time I'd make it schema-agnostic."
- "Only JSONL in, only Postgres out. The ports are there precisely so adding formats is cheap, I just didn't build them — YAGNI for the assignment scope."
- "Errors are partitioned: bad data is logged and skipped so one bad line doesn't kill a million-line import; infrastructure failures throw and abort."

**What I'd improve with more time**
- "Schema-agnostic writer driven by a column map or migration metadata."
- "A `--dry-run` flag and progress reporting for operability."
- "stdin support so it composes in a Unix pipeline."
- "The flattener throws mid-generator on a no-variants product — I'd want to make sure a failure can't leave a chunk half-written; today the transaction protects each chunk but the ordering deserves a hard look."

---

## 2. Predicted coding tasks (ready-to-code approaches)

> General rule for every task: **state the layer first**, **reuse the port**, **write the test**.

### 2a. Add a new reader (CSV / JSON array / stdin)

- **Layer:** Infrastructure. Implement `FeedReaderPort::read(string $source): iterable`.
- **Contract to honor:** stream with `yield`, never load the whole file; yield `ReadResult::success(new ProductFeedItem($data, $lineNumber))` per record and `ReadResult::failure($line, $msg, $excerpt)` on a bad record; throw `FeedSourceException` only if the *source* can't be opened.
- **CSV sketch:**
  ```php
  final class CsvProductFeedReader implements FeedReaderPort
  {
      public function read(string $source): iterable
      {
          $handle = @fopen($source, 'r');
          if ($handle === false) {
              throw new FeedSourceException(sprintf('Cannot open feed source: %s', $source));
          }
          try {
              $header = fgetcsv($handle);
              $line = 1;
              while (($cells = fgetcsv($handle)) !== false) {
                  ++$line;
                  if ($cells === [null]) { continue; }
                  $data = array_combine($header, $cells);
                  yield ReadResult::success(new ProductFeedItem($data, $line));
              }
          } finally {
              fclose($handle);
          }
      }
  }
  ```
- **Wiring (the real gotcha):** `config/services.yaml` currently aliases `FeedReaderPort` to one concrete class. To swap, change that alias. To support multiple at runtime, introduce a `FeedReaderRegistry` keyed by file extension/`--format` and tag readers with `app.feed_reader` (this is what `.claude/rules/symfony.md` anticipates). Say this out loud — it shows you understand current wiring vs. the intended extension point.
- **Test:** unit test with a temp file (happy path 2–3 rows, blank line skipped, unopenable source throws).

### 2b. Add a new writer (CSV file / JSON Lines / S3)

- **Layer:** Infrastructure. Implement `RowWriterPort::write(iterable $rows): void`.
- **Contract to honor:** consume the iterable lazily (don't `iterator_to_array` it — that defeats streaming); throw `PersistenceException` on write failure.
- **JSONL sketch:**
  ```php
  final class JsonlRowWriter implements RowWriterPort
  {
      public function __construct(private readonly string $outputPath) {}

      public function write(iterable $rows): void
      {
          $handle = @fopen($this->outputPath, 'w');
          if ($handle === false) {
              throw new PersistenceException("Cannot open output: {$this->outputPath}");
          }
          try {
              foreach ($rows as $row) {
                  fwrite($handle, json_encode($row->getData(), JSON_THROW_ON_ERROR) . "\n");
              }
          } finally {
              fclose($handle);
          }
      }
  }
  ```
- **Wiring:** same story as readers — re-alias `RowWriterPort`, or add a registry + `app.row_writer` tag and a `--writer` option.
- **Test:** integration test with `InMemoryRowWriter` is already the pattern for the handler; for the new writer, write to a temp file and assert contents.

### 2c. Add a new product field to the schema

- **Where it flows:** Reader produces raw data → `ProductFlattener` flattens by key automatically (no change needed for a normal nested/scalar field) → `ProductRowValidator` only matters if the field is *required* → `DoctrineFlattenedProductWriter::COLUMNS` decides if it's persisted → DB migration adds the column.
- **Touch points for a field like `origin_certification`:**
  1. Add a migration adding the column.
  2. Add `'origin_certification'` to `COLUMNS` in the writer. Add to `COLUMN_TYPES` **only if non-string**.
  3. If required, add to `ProductRowValidator::REQUIRED_KEYS`.
  4. Tests: flattener already handles it; add a validator test if required; update writer integration test.
- **Say:** "Two code touches + a migration. That duplication between `COLUMNS` and the schema is exactly the smell that motivates the schema-agnostic refactor in 2g."

### 2d. Add a `--dry-run` flag

- **Layer:** Infrastructure (command) + a no-op writer.
- **Cleanest approach:** when `--dry-run` is set, swap the writer for a counting no-op writer rather than branching inside the DB writer.
  ```php
  // in configure()
  $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Process but do not persist');
  ```
- **Two ways to wire it:**
  1. Inject a `NullRowWriter` / `CountingRowWriter` and select it in the command when `--dry-run` is passed. Keeps the DB writer pure. **Preferred.**
  2. Add a `dryRun` field to `IngestProductFeedInput` and have the handler pick the writer — but the handler takes the writer by constructor, so option 1 is less invasive.
- **Test:** functional command test asserting the DB writer is never invoked (use `InMemoryRowWriter`, assert `countWrittenRows()` shows what *would* be written but DB untouched).

### 2e. Add progress reporting (a dot every N rows)

- **Layer:** Application/Infrastructure boundary. Don't print from the domain.
- **Cleanest approach:** pass an optional callback. The handler already counts `$rowsWritten`:
  ```php
  // in generateRows, after ++$rowsWritten;
  if ($onProgress !== null && $rowsWritten % 1000 === 0) {
      $onProgress($rowsWritten);
  }
  ```
- **Say:** "I'll keep the actual output (dots / progress bar) in the command via the callback — the domain stays I/O-free."
- **Test:** assert the callback fires the expected number of times (no asserting on stdout — see test rules).

### 2f. Read from stdin

- **Layer:** Infrastructure (reader) + command.
- **Approach:** treat `-` as a sentinel path meaning `php://stdin`. `JsonlProductFeedReader` already uses `fopen` + `fgets`, so:
  ```php
  $resolved = $source === '-' ? 'php://stdin' : $source;
  $handle = @fopen($resolved, 'r');
  ```
- **Command:** make the `path` argument default to `-`, or document that `-` means stdin. Watch the existing `FEED_INPUT_PATH` fallback ordering.
- **Test:** harder to unit-test stdin directly; test the `-`→`php://stdin` mapping, or pass `php://memory` with seeded content.

### 2g. Refactor the writer to be schema-agnostic (dynamic columns)

- **Layer:** Infrastructure (no domain change — this is the win).
- **Approach:** derive the column list from the row data itself instead of the `COLUMNS` constant. The writer *already* groups by column-signature, so the machinery is half there.
  - Replace `mapToColumns()` iterating `self::COLUMNS` with iterating `$row->getData()` keys.
  - Keep an allow-list / type map fetched from `information_schema.columns` (cache it once per run) so you only insert real columns and keep correct DBAL types.
  - `KEY_COLUMNS` and the upsert builder stay as-is.
- **Trade-off to voice:** "I lose the compile-time guarantee that a column exists; I gain not editing code per field. I'd guard it by reading the live table schema once and intersecting, so a typo'd key is skipped rather than blowing up the INSERT."
- **Risk flag:** boolean/float typing must still be honored, or `false` becomes `""`. That's literally why `COLUMN_TYPES` exists (see the comment in the file). Pull types from `information_schema`.

### 2h. Handle duplicate `(sku, variant_sku)` across records

- **Already handled within a chunk:** `groupByColumnSignature()` de-dupes by a conflict-key (`sku \x00 variant_sku`) keeping the **last** occurrence, so one INSERT never updates the same key twice (Postgres errors on that).
- **Across chunks:** the upsert's `ON CONFLICT DO UPDATE` makes a later chunk overwrite an earlier one — last-write-wins, idempotent.
- **The gap:** dedup is per-chunk, so you can't detect/report duplicates across chunks. If asked to *report* them, add a seen-set in the handler keyed by `(sku, variant_sku)` and emit a skip/warning — but note memory cost on huge feeds.
- **Say:** "Define 'handle' — silently last-write-wins, or surface a warning? That changes the design and the memory profile."

### 2i. Add a `--chunk-size` CLI option

- **Layer:** Infrastructure (command). The writer **already** takes `chunkSize` in its constructor (`DEFAULT_CHUNK_SIZE = 500`), it's just not exposed.
- **Cleanest:** bind it as a Symfony parameter the writer reads, so the env/DI wires it without changing the port.
  ```yaml
  # services.yaml
  App\Infrastructure\Persistence\DoctrineFlattenedProductWriter:
      arguments:
          $chunkSize: '%env(int:FEED_CHUNK_SIZE)%'
  ```
- **Say:** "Runtime per-invocation override via the port would mean changing the `write()` signature — I'd avoid that for one knob. Env/parameter is the lower-risk path."
- **Test:** integration test with chunk size 2 over 3 rows asserts two batches.

### 2j. Add a validation rule (e.g., `variant_price_eur > 0`)

- **Layer:** Domain (`ProductRowValidator`). This is a business rule.
  ```php
  $price = $row->get('variant_price_eur');
  if ($price !== null && (!is_numeric($price) || (float) $price <= 0)) {
      throw new FlatteningException('variant_price_eur must be greater than 0');
  }
  ```
- **Note:** `FlatteningException` (a `ProductFeedException`) is caught by the handler → log + skip. No handler change needed.
- **Open question to ask:** "Is price required or optional? Should a non-positive price skip the whole record or just that variant?"
- **Test:** unit test in `ProductRowValidatorTest` — valid price passes, zero/negative/non-numeric throws.

---

## 3. Conceptual / explanation questions

**Why hexagonal here?**
The transformation logic (flatten + validate) is the asset and the thing most worth testing. Isolating it behind ports keeps it framework-free and lets sources (reader) and sinks (writer) be swapped without touching it. For a pipeline whose whole point is "many sources, many destinations," ports/adapters is the natural fit.

**Why a Generator instead of returning an array?**
Constant memory. The handler yields rows lazily and the writer pulls them in chunks, so peak memory is ~one chunk regardless of feed size. Returning `FlattenedProductRow[]` would materialize the entire feed in RAM — a non-starter for large feeds. It also gives natural backpressure: nothing is produced faster than the writer consumes.

**Why chunk the writes?**
Round-trip amortization. One multi-row INSERT per 500 rows instead of 500 statements. Each chunk is one transaction, so it's atomic, and the buffer caps memory. 500 balances statement size / parameter count against round-trip savings.

**Why `ON CONFLICT … DO UPDATE` instead of DELETE + INSERT?**
Idempotent upsert in a single statement on the `(sku, variant_sku)` key — no read-modify-write, no window where the row is missing. DELETE+INSERT doubles the writes, churns indexes, and a crash between delete and insert loses data. Upsert is also concurrency-safe at the row level.

**Why is `ProductFeedException` abstract?**
It's a category, not a concrete error. You never throw `ProductFeedException` directly — you throw `FlatteningException`, `FeedSourceException`, `PersistenceException`. But the handler catches the base type to mean "any domain-level feed problem." Abstract base = shared catch point + forced specificity at throw sites.

**Why do domain exceptions extend `\DomainException`?**
They model business-rule / invariant violations, which is exactly what SPL's `\DomainException` (a `LogicException`) signals. It communicates intent, plays well with PHP's hierarchy, and keeps the domain from inventing a parallel exception tree.

**Why does the writer group rows by column-signature?**
Optional fields mean different rows have different columns present (`mapToColumns` skips absent columns). A batched INSERT needs every row in the batch to have an identical column list. Grouping by the sorted set of present columns lets each group emit one well-formed multi-row INSERT. The grouping step also de-duplicates by conflict-key so a single batch can't update the same `(sku, variant_sku)` twice (Postgres forbids that).

**Why does the validator live in Domain, not Application?**
"A row must have a non-empty sku/name/variant_sku" is a business rule about what a valid product row *is* — domain knowledge, independent of Symfony or the DB. The Application layer orchestrates; it shouldn't own the rules themselves. Keeping it in the domain makes it unit-testable with no framework and reusable across any handler.

**Why `final` on everything?**
Composition over inheritance, and it prevents accidental subclassing that would couple consumers to internals. If extension is needed, it's via a port (interface), not subclassing a concrete class. `final` is the default; you opt *out* deliberately.

**Why are ports interfaces, not abstract classes?**
A port is a pure contract — no shared state or behavior to inherit. Interfaces allow an adapter to implement multiple ports and avoid the single-inheritance constraint. Abstract classes leak implementation and invite the domain to depend on infrastructure-ish base behavior.

**What happens if the DB is unavailable mid-write?**
`insertChunk()` wraps each chunk in `beginTransaction`/`commit`, and on any `\Throwable` it `rollBack()`s, logs an error, and throws `PersistenceException`. The failing chunk is rolled back atomically, but **chunks already committed stay committed** — the import is partial. The command reports failure. There's no resume/checkpoint, so a re-run re-processes from the top (safe because upsert is idempotent).

**How would you scale to 10M records?**
The streaming + chunked design already gives constant memory, so it scales in memory terms today. Next levers: (1) tune chunk size and use `COPY`/`pg_copy` for the bulk path; (2) parallelize by partitioning the feed across workers (Symfony Messenger) writing disjoint key ranges; (3) checkpoint progress (last line number) for resumability; (4) drop/rebuild indexes around a full reload; (5) staging table then a single `INSERT … SELECT` swap.

**How would you add a second source format without breaking existing code?**
Implement `FeedReaderPort` for the new format — no edits to the existing reader, domain, or handler. Then either re-alias in `services.yaml` or introduce a `FeedReaderRegistry` that resolves the right reader by extension/`--format`, with readers tagged `app.feed_reader`. Existing tests keep passing because nothing existing changed — that's the OCP payoff.

---

## 4. Edge cases to know cold

| Scenario | What the code does today |
|---|---|
| **Empty file** | Reader opens fine, `fgets` returns false immediately, no rows. Handler returns `0/0/0`, command prints zero summary, exit success. |
| **File not found** | `@fopen` returns false → `FeedSourceException` thrown → command catches `ProductFeedException` → prints error, `Command::FAILURE`. |
| **Malformed JSON line** | `json_decode` fails → reader yields `ReadResult::failure(line, msg, excerpt)`. Handler logs warning, increments `recordsSkipped`, continues. |
| **Product with 0 variants** | `ProductFlattener` throws `FlatteningException('Product "X" has no variants')`. Handler catches → logs → skips the whole product. |
| **Product missing `sku`** | Flattens fine (sku just absent), then `ProductRowValidator` throws `FlatteningException('Missing required key "sku"')` → skipped. |
| **Duplicate `(sku, variant_sku)` in same file** | Within a chunk: `groupByColumnSignature` de-dupes keeping the last → one upsert. Across chunks: `ON CONFLICT DO UPDATE` overwrites. Last-write-wins, idempotent. |
| **`origin_altitude_m: null`** | Flattens to `origin_altitude_m => null`. Typed `INTEGER` in `COLUMN_TYPES` so DBAL binds it as NULL (not `""`). |
| **Optional `coordinates` absent** | Key never appears in the flattened data; `mapToColumns` skips columns the row doesn't `has()`. That row joins a different column-signature group — handled. |
| **`flavor_notes: []`** | Empty array. `isAssociative([])` returns false → `implode(',', [])` = `''`. So `flavor_notes => ''` (empty string, column present). |
| **`variant_index` ordering** | Flattener uses `foreach ($variants as $index => $variant)` and stamps `variant_index = $index`. Order is array order, deterministic. |

Extra ones worth a sentence:
- **Blank/whitespace-only lines:** skipped by the reader (`trim() === ''`), not counted as errors.
- **Empty or whitespace key after flatten:** validator throws `FlatteningException('... empty or whitespace-only key')` → skipped.
- **`getItem()` on a failed `ReadResult`:** returns `null` typed as `ProductFeedItem` (no guard) — safe because the handler only calls it after `isSuccess()`. Flag it as a latent footgun if asked.

---

## 5. Judgment calls — "they say X, you say Y"

**"Just load all rows into memory first."**
> "For small feeds that's fine and simpler. But the design target is large feeds — a multi-GB file would OOM. The generator keeps peak memory at one chunk. I'd only materialize if we needed a global operation like sorting or whole-file dedup, and even then I'd push that into the DB."

**"Let's DELETE then re-INSERT instead of upsert."**
> "That doubles writes, churns indexes, and creates a window where rows are missing — bad if anything reads concurrently. A crash mid-way loses data. Upsert is atomic per row and idempotent. DELETE+INSERT only makes sense if we want *removed* products to disappear — and for that I'd do a staged table swap, not per-row delete."

**"Just add the new field to the writer's `COLUMNS` constant."**
> "Totally fine for one field — that's the current pattern and it's two lines plus a migration. I'll do that now. Worth noting it duplicates schema knowledge between code and DB; if we expect frequent field changes, the better move is the schema-agnostic writer. Want the quick fix now and a ticket for the refactor, or do it properly today?"

**"Let's skip the test for this small feature."**
> *Agree when:* it's pure wiring/config (e.g., exposing an already-tested constructor arg) or a one-line CLI option with no logic. *Push back when:* there's a branch or a business rule. "This one has a conditional, so a quick test pays for itself — I'll keep it to one happy + one edge case so it's a couple of minutes."

**"Make `ProductFlattener` configurable via a YAML mapping."**
> "YAGNI for now — we have one feed shape and a config DSL is a lot of surface area to maintain and test. If we get a second incompatible shape, the right seam is a second `FeedReaderPort` adapter or a strategy, not a generic mapping engine. I'd hold off until there's a concrete second case."

---

## 6. Clarifying questions to ask in the pairing phase

Ask 2–3 *before* you start typing on any task.

1. "Should this be idempotent / safe to re-run, or is it a one-shot?"
2. "Do we care about backward compatibility on the port interfaces, or am I free to change a signature?"
3. "For this edge case — skip the bad record and continue, or fail the whole run?"
4. "Is this field required or optional? And if it's invalid, do we drop the variant or the whole product?"
5. "Are we optimizing for simplicity now or extensibility for likely future formats?"
6. "What's the expected feed size — does memory/throughput matter here, or is correctness the only concern?"
7. "Should errors surface to the user (CLI output / non-zero exit) or just go to the log?"
8. "For the new writer/reader, do we need runtime selection (a `--format` flag) or is swapping the binding enough?"
9. "Do you want a test for this, and if so unit or integration — given the test rules say no mocking domain objects and no asserting on logs?"
10. "Should I keep changes inside Infrastructure, or is touching the port acceptable for this?"

---

## 7. Things to say while coding (communication habits)

1. "I'm going to start by confirming the existing port, then the adapter."
2. "Before I touch the writer, let me check whether there's an existing abstraction I can reuse — the writer already takes `chunkSize`, so this is just exposing it."
3. "I'll keep this in the Domain because it's a business rule, not infrastructure."
4. "Let me write the failing test first so I know what 'done' looks like."
5. "This throws a `FlatteningException`, which the handler already catches and skips — so I don't need to change the handler."
6. "I'm deliberately not changing the port signature here to avoid a breaking change; I'll thread it through DI instead."
7. "I'll log-and-skip here since it's record-level, not throw — that matches the existing error policy."
8. "I'm going to avoid `iterator_to_array` so we keep the streaming guarantee."
9. "Quick trade-off: the simple version is X, the extensible version is Y — for this scope I'll do X and note Y."
10. "Let me run the suite in Docker before I call this done: `docker compose run --rm php bin/phpunit`."

---

## 8. Commands cheat-sheet

```bash
# Run
docker compose run --rm php bin/console feed:ingest /data/products.jsonl

# Tests (all)
docker compose run --rm php bin/phpunit

# Tests (filtered)
docker compose run --rm php bin/phpunit --filter ProductFlattenerTest
docker compose run --rm php bin/phpunit --filter IngestProductFeedHandlerTest
```

---

Final reminders: **state the layer first**, reuse the port, keep the domain framework-free, stream don't materialize, log-and-skip record errors / throw on infrastructure errors, write at least one test for anything with a branch.
