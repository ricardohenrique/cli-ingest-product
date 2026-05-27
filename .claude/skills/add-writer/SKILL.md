---
description: Add a new RowWriter adapter. Use when the user asks to add a new output format or destination such as XML, SQLite, S3, or any new writer.
---

## Add a new writer adapter

Follow these steps exactly:

1. Create `src/Infrastructure/Writer/<Name>RowWriter.php`
    - Implement `App\Domain\Port\RowWriterPort`
    - Constructor-inject any dependencies (filesystem, connection, etc.)
    - Write all rows in a single transaction or batch where possible

2. Register in `config/services.yaml`:
```yaml
   App\Infrastructure\Writer\<Name>RowWriter:
       tags:
           - { name: app.row_writer, format: <format-slug> }
```

3. Add a unit test in `tests/Infrastructure/Writer/<Name>RowWriterTest.php`
    - Test happy path with 3+ rows
    - Test empty input (zero rows)

4. Update README.md "What I'd do with more time" → move this writer to "Done"

5. Confirm with the user which format slug maps to the `--output` flag value.
