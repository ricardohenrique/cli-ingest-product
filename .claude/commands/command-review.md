---
agent: code-reviewer
---

# Subagent: code-reviewer Review Command

Review the implementation of a plan against project architecture rules, SOLID principles, and coding conventions.

The review must be based on recently changed files relative to the executed plan.

---

## Input

A plan file or a description of what was implemented:

```text
docs/<plan-file>.md
```

Example:

```text
docs/05-06-2026-get-user-order-1.md
```

---

## Execution Process

### 1. Identify Scope

* Read the plan file to understand what was implemented.
* Identify all files created or modified during the implementation.
* Use `git diff` or `git status` to confirm which files changed.

### 2. Systematic Review

For each changed file, evaluate:

* SOLID principles compliance
* Clean architecture — correct layer placement
* PHP 8.4+ standards and strict typing
* Naming conventions and readability
* Test coverage — happy path, edge cases, failure scenarios
* Security — no mass assignment, no raw queries, no exposed secrets
* No silent exception swallowing
* No business logic in controllers or models

### 3. Generate Report

Produce a structured report using the format below.

---

## Output Format

```md
## Code Review Summary

### Critical Issues
- [File:Line] Description | Rule violated | Suggested fix

### High Priority
- [File:Line] Description | Rule violated | Suggested fix

### Medium Priority
- [File:Line] Description | Rule violated | Suggested fix

### Low Priority / Suggestions
- [File:Line] Description | Improvement opportunity

### Positive Observations
- Well-implemented patterns or practices
```

---

## Rules

* Always reference exact file paths and line numbers.
* Be specific and actionable — never give vague advice.
* Flag critical stop-the-line issues immediately:
  * Catching `\Throwable` or `\Exception` silently
  * `throw new \Exception(...)` instead of a named domain exception
  * Business logic in a controller or model
  * Raw `DB::` query without justification
* Be balanced — acknowledge good practices alongside issues.

---

## Final Response

Return:

```text
Review completed.
```

or

```text
Review blocked: [reason]
```

Followed by the full review report.
