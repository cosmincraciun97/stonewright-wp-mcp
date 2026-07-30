---
name: Page migration
description: Migrate an existing page layout between builders or rebuild a page from current content without losing meaning.
enable_agentic: true
enable_prompt: true
---

# Page migration

## Steps
1. `stonewright-task-start` with migration intent.
2. Read source via `stonewright/content-get-page` and builder-specific structure tools.
3. Snapshot with backup abilities before any write.
4. Map sections to a design spec; validate with `stonewright/design-validate-spec`.
5. Build target with dry_run when available; compare structure.
6. Re-check media IDs — reuse library assets (`stonewright/media-list`) instead of re-uploading.

## Rules
- Never delete the source page until the user confirms the target.
