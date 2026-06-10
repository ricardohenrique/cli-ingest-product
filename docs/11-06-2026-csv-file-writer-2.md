# CSV File Writer

**Date:** 11-06-2026
**Plan ID:** 2
**Status:** Approved
**Complexity:** Small

---

## 1. Requirements Analysis

### Functional Requirements
- [ ] Add a CSV file writer that writes flattened rows to a `.csv` file inside the `data/` folder
- [ ] CLI option `--writer=postgres|csv` on `feed:ingest` (default: `postgres`)
- [ ] CLI option `--output-path` to override the CSV output file path (default: `data/output.csv`)
- [ ] Unknown `--writer` value exits 1 with a helpful message listing supported options
- [ ] Default run unchanged: `feed:ingest /data/products.jsonl` continues to behave exactly as before

### Non-Functional Requirements
- [ ] Writer must stream row-by-row — no full-feed accumulation in memory
- [ ] Header row is written once from the first row's keys
- [ ] All new classes are `final`
- [ ] Domain layer remains Symfony-free

---

## 2. Architecture Review

### Existing Codebase Patterns
- `RowWriterPort` declares `write(iterable<FlattenedProductRow>): void` — the new writer implements this unchanged
- `DoctrineFlattenedProductWriter` chunks and upserts; the CSV writer is simpler: open, write header, stream rows, close
- `FeedSourceException` / `PersistenceException` are the established throw points for infrastructure failures
- `IngestProductFeedHandler` receives its writer via constructor — it is writer-agnostic
- Today `RowWriterPort` is aliased one-to-one in `services.yaml` to the Doctrine writer; we need runtime selection to support two writers

### Affected Areas
- `src/Infrastructure/Output/CsvFileRowWriter.php` — new
- `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php` — add `--writer` + `--output-path` options
- `config/services.yaml` — keep the Doctrine alias as default; explicitly define `CsvFileRowWriter` with its default output path parameter

### Reusable Components
- `RowWriterPort` — implemented as-is, no changes
- `PersistenceException` — thrown by the new writer on file open/write failures
- `InMemoryRowWriter` stub — used in tests as before

### Architecture Decision
Format selection lives in the **command only**. The command receives both writers via constructor injection, picks the correct one based on `--writer`, and constructs `IngestProductFeedHandler` with the chosen writer. No registry abstraction is needed for just two writers — the command holds both and a simple `match` resolves which to use.

---

## 3. Step Breakdown

### Step 1: `CsvFileRowWriter`
- **What:** New writer that streams flattened rows to a CSV file
- **Where:** `src/Infrastructure/Output/CsvFileRowWriter.php`
- **How:**
  - Implements `RowWriterPort`
  - Constructor: `string $outputPath`, `LoggerInterface $logger`
  - `write(iterable $rows)`:
    - Open file with `@fopen($outputPath, 'w')`; throw `PersistenceException` if it fails
    - On the first row, write the header using `fputcsv($handle, array_keys($row->getData()))`
    - For each row write `fputcsv($handle, array_values($row->getData()))`
    - Close handle in `finally`
  - Empty input writes nothing (no header — empty file)
  - Never accumulates rows in memory
- **Test:** Integration (real temp file):
  - 3 rows → valid CSV with correct header + 3 data rows
  - Empty input → empty file
  - Unwritable path → `PersistenceException`
  - Column order is consistent (matches `getData()` key order)
- **Complexity:** Small

### Step 2: Command options + wiring
- **What:** Add `--writer` and `--output-path` options; command selects the writer at runtime
- **Where:**
  - `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php`
  - `config/services.yaml`
- **How:**
  - Command constructor receives both `DoctrineFlattenedProductWriter $doctrineWriter` and `CsvFileRowWriter $csvWriter`
  - Add options in `configure()`:
    - `--writer` with default `'postgres'`
    - `--output-path` (optional, no default — falls back to the DI-configured path on `CsvFileRowWriter`)
  - In `execute()`:
    ```php
    $writer = match($input->getOption('writer')) {
        'postgres' => $this->doctrineWriter,
        'csv'      => $this->csvWriter,
        default    => null,
    };
    if ($writer === null) {
        $io->error('Unknown writer "...". Supported: postgres, csv');
        return Command::FAILURE;
    }
    ```
  - When `--output-path` is provided and writer is `csv`, re-instantiate `CsvFileRowWriter` with that path (or introduce `withOutputPath(string): self` — see Open Question Q1)
  - Handler is constructed inside `execute()` with the resolved writer (not pre-wired via DI)
  - In `services.yaml`:
    - Keep `RowWriterPort` alias pointing to `DoctrineFlattenedProductWriter` (used nowhere now that the command constructs the handler manually — can be removed if no other consumer)
    - Bind default output path parameter: `app.csv_writer.output_path: '%kernel.project_dir%/data/output.csv'`
    - Bind it to `CsvFileRowWriter.$outputPath`
- **Test:** Functional (CommandTester):
  - Default run (no `--writer`) uses Doctrine writer, exits 0
  - `--writer=csv` with a temp `--output-path` → exits 0, file contains correct CSV
  - `--writer=bogus` → exits 1 with supported values in message
- **Complexity:** Small

---

## 4. Risk Assessment

### Risks
- **Command now manually constructs the handler** (instead of receiving it via DI). This is a deliberate trade-off: it avoids a full registry/factory for only two writers, keeps the change small, but means the command gains one extra constructor dependency (`CsvFileRowWriter`).
- **`--output-path` override with the DI-constructed instance.** The `CsvFileRowWriter` is wired with a default path via DI. If the user passes `--output-path`, we need to either re-instantiate the writer or add a clone method. See Open Question Q1.
- **Header row key order.** `FlattenedProductRow::getData()` returns an array; key order depends on flattener insertion order. This is deterministic for the existing flattener but worth noting — different products with different optional fields will produce different column sets if rows are mixed. For the initial implementation, the header is taken from the first row only.

### Mitigations
- Tests cover the `--writer=bogus` path and the CSV output content
- `finally` block in the writer ensures the file handle is always closed even on exception

### Fallbacks
- If the command-owns-wiring approach feels too coupled, the plan can be extended to the registry+factory approach from the archived Plan 1 at any point — the `CsvFileRowWriter` implementation from Step 1 is reusable unchanged.

---

## 5. Execution Checklist

- [ ] Step 1: `CsvFileRowWriter` — new infrastructure writer with tests
- [ ] Step 2: Command options + wiring — `--writer`, `--output-path`, `services.yaml` param

---

## Decisions

**Q1 — resolved:** `--output-path` override re-instantiates `CsvFileRowWriter` inline inside `execute()`.
