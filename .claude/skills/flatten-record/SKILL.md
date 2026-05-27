---
description: Debug or explain the flattening strategy. Use when the user asks how a specific nested input maps to output rows, or wants to trace a flattening result.
---

## Trace a flattening

Given the user's input, do the following:

1. Read `src/Domain/Service/FlatteningService.php`
2. Manually trace the input through the logic step by step
3. Show the intermediate state after each recursive call
4. Show the final FlattenedRow key→value map
5. Highlight any array fields that produce multiple rows and explain why
