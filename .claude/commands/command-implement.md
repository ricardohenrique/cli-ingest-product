# Subagent: code-executer Implement Command

Execute an existing implementation plan.
The command must locate and execute a plan file from the `docs/` directory.
The plan is the source of truth. Do not invent additional scope unless required to complete the plan safely.

---

## Input

A plan file:

```text
docs/<plan-file>.md
```

Example:

```text
docs/05-06-2026-get-user-order-1.md
```

---

## Execution Process

### 1. Load and Analyze the Plan

* Read the complete plan file.
* Understand all requirements.
* Understand all implementation steps.
* Review risk assessment.
* Identify dependencies between steps.
* Validate that the plan is complete enough to execute.

If critical information is missing:

* Stop execution.
* Produce a clarification report.
* Do not implement assumptions.

---

### 2. Analyze Existing Codebase

Before making changes:

* Read affected modules.
* Understand architecture patterns.
* Identify reusable components.
* Check conventions already used by the project.
* Verify dependencies.
* Verify database schema if relevant.

Rules:

* Reuse existing patterns whenever possible.
* Avoid introducing unnecessary abstractions.
* Follow project architecture and coding standards.

---

### 3. Execute Plan Sequentially

Implement steps in the exact order defined by the plan.

Rules:

* Complete one step before starting another.
* Keep the application in a working state after every step.
* Avoid partially implemented functionality.
* Avoid introducing dead code.

---

### 4. Generate Tests

For every implemented change:

Create or update:

* Unit tests
* Integration tests
* Feature tests

When applicable.

Tests should verify:

* Happy path
* Edge cases
* Error handling
* Business rules

Rules:

* New functionality must be covered by tests.
* Existing tests must continue passing.
* Do not remove tests without justification.

---

Validation includes:

* Tests passing
* Static analysis passing
* Formatting passing

---

## Output

Generate:

### Implementation Summary

```md
# Implementation Summary

## Executed Plan
- docs/<plan-file>.md

## Completed Steps
- [x] Step 1
- [x] Step 2

## Files Modified
- src/...
- tests/...

## Tests Added
- ...

## Risks Identified
- ...

## Follow-up Recommendations
- ...
```

---

## Rules

* Never skip plan steps.
* Never change architecture without justification.
* Never leave the application in a broken state.
* Prefer incremental, deployable changes.
* Follow project conventions over personal preferences.
* Generate tests whenever functionality changes.
* If the implementation differs from the plan, document why.
* Stop and report blockers instead of guessing.

---

## Final Response

Return:

```text
Implementation completed successfully.
```

or

```text
Implementation blocked.
```

Followed by a detailed implementation summary.
