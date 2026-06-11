# CSV Field Rename Map

**Date:** 11-06-2026
**Plan ID:** 3
**Status:** Draft
**Complexity:** Small

---

## 1. Requirements Analysis

### Functional Requirements
- [ ] `CsvFileRowWriter` accepts an optional field rename map `array<string, string>` (e.g. `['sku' => 'product-id']`)
- [ ] When the map is set, renamed keys appear in the CSV header row instead of the original flattened key names
- [ ] Data values are unchanged — only header labels are affected
- [ ] Keys absent from the map keep their original name
- [ ] The rename map is passable from the CLI via `--field-map=sku:product-id,name:product-title` (colon-separated pairs, comma-separated list)
- [ ] `--field-map` is only valid with `--writer=csv`; using it with `--writer=postgres` exits 1 with a clear message
- [ ] Default behaviour (no `--field-map`) is unchanged

### Non-Functional Requirements
- [ ] No memory overhead — the map is applied per-row at write time, not buffered
- [ ] Map parsing is done once in the command before the writer is constructed
- [ ] Invalid map format (e.g. malformed pair) exits 1 with a helpful error

---

## 2. Architecture Review

### Existing Codebase Patterns
- `CsvFileRowWriter` writes the header from `array_keys($row->getData())` and the values from `array_values($row->getData())` — both are the natural injection points for renaming
- Constructor injection is the project standard; the rename map is a natural third constructor argument with a `[]` default
- The command re-instantiates `CsvFileRowWriter` inline when `--output-path` is provided — the same pattern applies for `--field-map`
- The `$csvWriter` DI-configured instance (default path, no map) is used when neither override is set; any option supplied at runtime triggers an inline `new CsvFileRowWriter(...)` in the command

### Affected Areas
- `src/Infrastructure/Output/CsvFileRowWriter.php` — add `$fieldMap` constructor arg, apply in `write()`
- `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php` — add `--field-map` option, parse, pass to writer
- `tests/Integration/Infrastructure/Output/CsvFileRowWriterTest.php` — new test cases
- `tests/Functional/Infrastructure/Console/IngestProductFeedSymfonyCommandTest.php` — new test cases

### Reusable Components
- Existing inline re-instantiation pattern from `--output-path` (same approach for `--field-map`)
- `PersistenceException` — no new exceptions needed; map parsing errors are CLI validation, not writer errors

### Architecture Decision
The rename map is an **optional constructor argument** (`array $fieldMap = []`) on `CsvFileRowWriter`. This keeps the writer self-contained and testable in isolation. The command is responsible for parsing the raw CLI string into the map array and passing it to the writer. No new classes are needed.

---

## 3. Step Breakdown

### Step 1: Add `$fieldMap` to `CsvFileRowWriter`
- **What:** Accept an optional rename map and apply it when writing the header row
- **Where:** `src/Infrastructure/Output/CsvFileRowWriter.php`
- **How:**
  - Add `private readonly array $fieldMap = []` as a third constructor argument
  - In `write()`, replace the header write line with:
    ```php
    $keys = array_keys($data);
    $headers = array_map(fn(string $k) => $this->fieldMap[$k] ?? $k, $keys);
    fputcsv($handle, $headers, escape: '\\');
    ```
  - Data row (`array_values($data)`) is unchanged — values are written by position, so they align with the (possibly renamed) header automatically
  - The DI-wired instance in `services.yaml` does not need to change — `$fieldMap` defaults to `[]`
- **Test:** In `CsvFileRowWriterTest`:
  - Map `['sku' => 'product-id', 'name' => 'product-title']` → header row has `product-id`, `product-title`; data values unchanged
  - Partial map (only some keys renamed) → unmapped keys keep original names
  - Empty map `[]` → behaviour identical to no map (existing tests still pass)
- **Complexity:** Small

### Step 2: Add `--field-map` CLI option to the command
- **What:** Parse a `key:value,...` string from the CLI and pass the resulting map to the CSV writer
- **Where:** `src/Infrastructure/Console/IngestProductFeedSymfonyCommand.php`
- **How:**
  - Add option in `configure()`:
    ```php
    $this->addOption(
        'field-map',
        null,
        InputOption::VALUE_REQUIRED,
        'Rename CSV header columns: original:renamed,... (only with --writer=csv)',
    );
    ```
  - In `execute()`, parse the raw value into an array:
    ```php
    $fieldMap = [];
    $rawMap = $input->getOption('field-map');
    if ($rawMap !== null) {
        if ($writerName !== 'csv') {
            $io->error('--field-map is only supported with --writer=csv');
            return Command::FAILURE;
        }
        foreach (explode(',', $rawMap) as $pair) {
            $parts = explode(':', $pair, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                $io->error(sprintf('Invalid --field-map pair "%s". Expected format: original:renamed', $pair));
                return Command::FAILURE;
            }
            $fieldMap[trim($parts[0])] = trim($parts[1]);
        }
    }
    ```
  - When building the CSV writer (both the inline re-instantiation path and the DI-instance path), pass `$fieldMap`:
    ```php
    'csv' => new CsvFileRowWriter(
        $outputPath ?? $this->csvWriter->getOutputPath(), // see note below
        $this->logger,
        $fieldMap,
    ),
    ```
  - **Note on `$this->csvWriter`:** currently when neither `--output-path` nor `--field-map` is set, the pre-wired `$this->csvWriter` instance is returned directly. With `$fieldMap` in play, the logic becomes: always construct a new `CsvFileRowWriter` for the `csv` path, using the DI default path as fallback when `--output-path` is absent. To avoid exposing `getOutputPath()`, inject the default path string as a separate constructor argument on the command (`string $defaultCsvOutputPath`) bound to `%app.csv_writer.output_path%`, removing the need to call back into the DI-wired instance for its path. The `CsvFileRowWriter $csvWriter` constructor argument can then be removed from the command.
- **Test:** In `IngestProductFeedSymfonyCommandTest`:
  - `--writer=csv --field-map=sku:product-id` → CSV file header contains `product-id` not `sku`
  - `--field-map=sku:product-id` without `--writer=csv` → exits 1 with a clear error message
  - `--field-map=badformat` (no colon) → exits 1 with a format error message
  - Default run with no `--field-map` → unchanged behaviour
- **Complexity:** Small

---

## 4. Risk Assessment

### Risks
- **Command holds `$this->csvWriter` only to extract its default path.** This is awkward — the command shouldn't reach into a service to retrieve its config. The plan resolves this by injecting `string $defaultCsvOutputPath` directly into the command (same parameter already in `services.yaml`), which removes the `CsvFileRowWriter $csvWriter` dependency from the command constructor entirely.
- **Renamed key collision.** If two original keys map to the same target name (e.g. `['sku' => 'id', 'variant_sku' => 'id']`), the header will have duplicate columns. Decision: treat as user error, no validation needed in the writer; can be documented.
- **Map parse edge cases.** A value containing a colon (e.g. `origin:country:region`) — `explode(':', $pair, 2)` (limit 2) handles this correctly, preserving the colon in the renamed value.

### Mitigations
- Inject `$defaultCsvOutputPath` string into the command to cleanly separate config from behaviour
- `explode(':', $pair, 2)` prevents colon-in-value issues
- Empty map defaults to `[]` so no existing behaviour changes

### Fallbacks
- If injecting `$defaultCsvOutputPath` into the command is undesirable, keep `CsvFileRowWriter $csvWriter` but add a `getOutputPath(): string` method to the writer. Less clean but functional.

---

## 5. Execution Checklist

- [ ] Step 1: Add `$fieldMap` to `CsvFileRowWriter` — constructor arg + header rename logic + tests
- [ ] Step 2: Add `--field-map` CLI option — parse, validate, pass to writer + refactor command to inject default path string directly + tests
