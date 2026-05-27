---
description: Apply when creating services, commands, or wiring in config/
---

## Symfony wiring rules

- Writers are tagged with `app.row_writer` in services.yaml
- Readers are tagged with `app.feed_reader` in services.yaml
- Console commands live in src/Infrastructure/Console/
- Use constructor injection only — no service locator pattern
- Monolog channel for feed errors: `monolog.logger.feed`
