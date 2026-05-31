# FeedFlattener

A CLI pipeline for ingesting JSONL product feeds, flattening nested structures
into tabular rows, and writing to a destination. PHP 8.4 + Symfony 8 + PostgreSQL.

## Architecture
- Hexagonal architecture: Domain → Application → Infrastructure
- Domain has zero framework dependencies
- Ports (interfaces) live in Domain; Adapters live in Infrastructure
- See src/ for the full structure

## Build & run
docker compose run --rm php bin/console feed:ingest /data/products.jsonl

## Tests
docker compose run --rm php bin/phpunit
docker compose run --rm php bin/phpunit --filter FlatteningServiceTest

## Conventions
- Value objects are immutable (readonly properties)
- Log and skip on record-level errors, throw on infrastructure failures
- New writers implement RowWriterPort and get a `app.row_writer` service tag
- New readers implement FeedReaderPort
- All classes are final unless explicitly designed for extension
