---
description: Apply when creating or editing classes in src/Domain/
---

## Domain rules

- No Symfony or Doctrine imports anywhere in src/Domain/
- No `new` instantiation of infrastructure classes inside Domain
- Value objects use readonly properties and have no setters
- Exceptions in Domain must extend a base DomainException
- Ports are PHP interfaces only — no abstract classes
- FeedRecord must always carry a $lineNumber for traceability
