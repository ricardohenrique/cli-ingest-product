# Upsert Feature — Implementation Plan

## 1. Motivation

Today `DoctrineFlattenedProductWriter` performs an unconditional `INSERT` per row. Re-ingesting the same JSONL feed produces duplicate variant rows (no unique key prevents it) or, once a unique key is added, blows up the run with a constraint violation. The desired behavior is **idempotent ingestion**: a re-run of the same feed should leave the table in a consistent, deduplicated state, with mutated fields (price, stock, flavor_notes, etc.) reflecting the latest input.

The proposed business key is **`(sku, variant_sku)`** — i.e. one row per product variant. This matches the natural grain of `FlattenedProductRow` produced by `ProductFlattener` (one row per variant in the feed).

## 2. Is `(sku, variant_sku)` a sound key?

**Yes, with one nuance.**

**Strengths:**
- It matches the row grain emitted by `ProductFlattener::flatten()` (one row per variant). `ProductRowValidator` already enforces both columns as required and non-empty, so we have a guarantee that the key is never partially null.
- Both columns are already `NOT NULL varchar` in the schema — PostgreSQL composite unique indexes work cleanly on these.
- `variant_sku` alone is **not** a safe key — there is no contract that it is globally unique across products. Composing it with the parent `sku` removes that risk.
- The README's "What I'd do with more time" section already anticipated this exact change (`INSERT ... ON CONFLICT ... DO UPDATE`), aligning with the documented direction.

**Nuance / risk:**
- If two different logical products in the feed ever shared the same `(sku, variant_sku)` pair (data quality issue upstream), the second one will silently overwrite the first. Document this as an assumption.

**Alternatives considered:**
- `variant_sku` alone — rejected: no contractual global uniqueness from the feed.
- Surrogate hash key — rejected: adds a column with no business meaning, breaks lookup by SKU.
- Soft delete + version column — rejected: over-engineering for the stated requirement.

**Recommendation:** proceed with the composite unique key `(sku, variant_sku)`.

## 3. Proposed Approach

Use PostgreSQL's native `INSERT ... ON CONFLICT (sku, variant_sku) DO UPDATE SET ...`. This is atomic per row, transaction-safe, and avoids the read-then-write race that an application-side "check first" would introduce.

**Key decisions:**
- Replace per-row `Connection::insert()` with a parameterised `INSERT ... ON CONFLICT` statement inside the existing chunked transaction.
- Use `EXCLUDED.<col>` references in the `DO UPDATE` clause — keeps the writer declarative and column-list driven.
- The port contract (`RowWriterPort::write(iterable $rows): void`) does **not** change. Upsert semantics are an adapter-internal concern.
- Stay with DBAL (no ORM); `INSERT ... ON CONFLICT` over `MERGE` for broader PG version compatibility.

## 4. Schema Changes

**File:** `migrations/Version20260528000001.php` (existing migration, modified in place)

Add the composite unique constraint directly to the existing `up()` method, after the table creation:

```sql
ALTER TABLE flattened_products
    ADD CONSTRAINT uq_flattened_products_sku_variant
    UNIQUE (sku, variant_sku);
```

And add the corresponding drop to `down()`:

```sql
ALTER TABLE flattened_products DROP CONSTRAINT uq_flattened_products_sku_variant;
```

**Notes:**
- Since the environment is always recreated from scratch, no dedup step is needed — the table is fresh when the migration runs.
- A composite `UNIQUE` constraint auto-creates a B-tree index, which is what `ON CONFLICT (sku, variant_sku)` needs.

## 5. Domain Changes

**None.** `FlattenedProductRow`, `ProductFlattener`, `ProductRowValidator`, and the port interfaces are unaffected. Upsert is an infrastructure concern.

## 6. Application Layer Changes

**None to the handler logic.** `IngestProductFeedHandler` does not need to know that the writer now upserts. The `RowWriterPort` contract is unchanged.

> Deferred: adding an `upsertedCount` / `updatedCount` distinction to `IngestProductFeedResult` — the adapter cannot cheaply distinguish insert vs. update without per-row `RETURNING xmax = 0` inspection, which would defeat batched performance. This is a clean follow-up if needed.

## 7. Infrastructure / Adapter Changes

**File:** `src/Infrastructure/Persistence/DoctrineFlattenedProductWriter.php`

Changes:
1. Add a `KEY_COLUMNS = ['sku', 'variant_sku']` constant alongside the existing `COLUMNS` constant.
2. Replace `insertChunk()`'s per-row `$this->connection->insert(...)` with a parameterised `INSERT ... ON CONFLICT (sku, variant_sku) DO UPDATE SET ...` per row inside the existing transaction.
3. Build the `DO UPDATE SET` clause programmatically from `COLUMNS` minus `KEY_COLUMNS` — keeps the column list as the single source of truth.
4. Preserve sparse-row behaviour: build the `INSERT` column list from each row's actual keys, not the full `COLUMNS` constant.
5. Keep existing try/commit/rollBack and `PersistenceException` wrapping intact.

Illustrative SQL shape:
```sql
INSERT INTO flattened_products (sku, name, ..., variant_index)
VALUES (:sku, :name, ..., :variant_index)
ON CONFLICT (sku, variant_sku) DO UPDATE SET
    name = EXCLUDED.name,
    origin_country = EXCLUDED.origin_country,
    ...
    variant_index = EXCLUDED.variant_index;
```

## 8. Config / Wiring

**No changes** to `config/services.yaml`. The writer keeps the same class, same constructor signature, same port binding.

## 9. Test Strategy

### 9.1 Integration tests — `DoctrineFlattenedProductWriterTest`

Add the following cases:

1. **`testWritingSameRowTwiceProducesSingleRow`** — write `('BEAN-0001', 'V1')`, then again with a changed `variant_stock`; assert `COUNT(*) = 1` and the persisted value matches the second write.
2. **`testWritingMixOfNewAndExistingRowsUpsertsCorrectly`** — seed two rows, write one updated + one new; assert counts and field values.
3. **`testDifferentSkusWithSameVariantSkuAreTreatedAsDistinct`** — write `('BEAN-0001', 'V1')` and `('BEAN-0002', 'V1')`; assert `COUNT(*) = 2`.
4. **`testUpsertWithinSingleChunkBatch`** — same chunk contains two rows with the same `(sku, variant_sku)`; assert the second wins.

Existing `testWriterThrowsPersistenceExceptionOnDbFailure` stays unchanged.

### 9.2 Application handler integration test

**No change.** `IngestProductFeedHandlerTest` uses `InMemoryRowWriter`; handler is upsert-agnostic.

### 9.3 Domain unit tests

**No change.** `ProductFlattener`, `ProductRowValidator`, and `FlattenedProductRow` are unaffected.

### 9.4 Functional (console) test

**No change.** Upsert behavior is verified at the writer integration layer.

## 10. Step-by-Step Implementation Tasks

### Step 1 — Schema migration
- **Layer:** Infrastructure (migration)
- **What:** Add the composite unique constraint to the existing `migrations/Version20260528000001.php` — append `ADD CONSTRAINT uq_flattened_products_sku_variant UNIQUE (sku, variant_sku)` to `up()` and the corresponding drop to `down()`.
- **File:** `migrations/Version20260528000001.php`
- **Acceptance:** Migration succeeds on a fresh DB (the only supported scenario).

### Step 2 — Writer adapter upsert
- **Layer:** Infrastructure
- **What:** Modify `DoctrineFlattenedProductWriter::insertChunk()` to issue `INSERT ... ON CONFLICT (sku, variant_sku) DO UPDATE SET ...` per row. Add `KEY_COLUMNS` constant. Build SET clause programmatically. Preserve sparse-row behaviour.
- **File:** `src/Infrastructure/Persistence/DoctrineFlattenedProductWriter.php`
- **Acceptance:** Existing tests pass; new upsert tests pass; transaction/rollback semantics unchanged.

### Step 3 — Test additions
- **Layer:** Tests
- **What:** Add the four new test cases to `DoctrineFlattenedProductWriterTest`.
- **Acceptance:** All tests green in Docker (`docker compose run --rm php bin/phpunit`).

### Step 4 — Documentation
- **Layer:** Docs
- **What:** Append an "Idempotency" section to `docs/import-process.md`; update README to move "Idempotent writes" from "future work" to "architectural decisions".

## 11. Risks and Trade-offs

| Risk | Mitigation |
|---|---|
| Two upstream products legitimately share `(sku, variant_sku)`. | Document as an assumption; latest-written row wins silently. |
| Per-row `executeStatement` is slightly slower than `Connection::insert`. | Negligible — both are per-row PG round-trips inside one transaction. Multi-row batching is a clean follow-up. |
| Sparse rows: absent column on update may clobber stored value. | Since it's not in the INSERT list it's not in `EXCLUDED` — existing value is preserved. This is the desired behaviour; document it. |

## 12. Files Touched

| File | Change |
|---|---|
| `migrations/Version20260528000001.php` | Modified — composite unique constraint added |
| `src/Infrastructure/Persistence/DoctrineFlattenedProductWriter.php` | Modified — upsert SQL |
| `tests/Integration/Infrastructure/Persistence/DoctrineFlattenedProductWriterTest.php` | Modified — new test cases |
| `docs/import-process.md` | Modified — idempotency section |
| `README.md` | Modified — move "Idempotent writes" from future to decisions |
