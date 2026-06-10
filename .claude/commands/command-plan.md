# Subagent: tech-lead-architect Plan Command

Create a structured implementation plan for the requested feature or change.
The plan must be saved inside the `docs/` folder as a Markdown file.

## File Naming

Create the file using the following format:

```
docs/DD-MM-YYYY-plan-title-sequential-id.md
```
Example:
```
docs/05-06-2026-get-user-order-1.md
```
### Rules:

- Use the current date in DD-MM-YYYY format.
- Convert the feature title to kebab-case.
- Add a sequential numeric ID at the end.
- If a file with the same name exists, increment the ID.


## Steps

### 1. Requirements Analysis
- List functional requirements (what the system must do).
- List non-functional requirements (performance, security, scalability).

### 2. Architecture Review
- Read the existing codebase structure to understand current patterns.
- Identify which modules, services, or components are affected.
- Determine if the change fits the existing architecture or requires structural changes.
- Check for existing utilities, patterns, or abstractions to reuse.

### 3. Step Breakdown
- Break the work into ordered, independently testable steps.
- Each step should be completable in one session and produce a working state.
- Format each step as:
    - **What**: The concrete deliverable.
    - **Where**: Which files or modules to touch.
    - **How**: Technical approach and key decisions.
    - **Test**: How to verify this step works.

Rules:
- Plans should target 1-10 steps. Fewer means the scope might be too narrow, more means break it into phases.
- Each step must leave the system in a deployable state. No half-implemented features.
- Include data migration steps if schema changes are involved.
- Estimate relative complexity (small/medium/large) for each step, not time.


### 4. Risk Assessment
- Identify what could go wrong at each step.
- Call out areas with high uncertainty that may need spikes.
- Suggest fallback approaches for risky steps.

### 5. Output the Plan
Present as a numbered checklist that can be executed sequentially.

# Implementation Plan: <Feature Title>

**Date:** DD-MM-YYYY  
**Plan ID:** <sequential-id>  
**Status:** Draft  
**Complexity:** Small | Medium | Large

## 1. Requirements Analysis

### Functional Requirements
- [ ] ...

### Non-Functional Requirements
- [ ] ...

## 2. Architecture Review

### Existing Codebase Patterns
- ...

### Affected Areas
- ...

### Reusable Components
- ...

### Architecture Decision
- ...

## 3. Step Breakdown

### Step 1: <Step Name>
- **What:** ...
- **Where:** ...
- **How:** ...
- **Test:** ...
- **Complexity:** Small | Medium | Large

## 4. Risk Assessment

### Risks
- ...

### Mitigations
- ...

### Fallbacks
- ...

## 5. Execution Checklist

- [ ] Step 1: ...
- [ ] Step 2: ...


### Final Instruction

After creating the plan file, respond with:

- Plan created: docs/<filename>.md

Do not implement the feature during this command.
