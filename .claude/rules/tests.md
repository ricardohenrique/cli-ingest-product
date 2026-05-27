---
description: Apply when writing or editing test files in tests/
---

## Test rules

- Unit tests for Domain classes use no mocks of domain objects — pass real instances
- Use in-memory stub writers (InMemoryRowWriter) for handler integration tests
- Never assert on log output — assert on behavior
