---
name: Section refactor
description: Refactor a single page section for spacing, hierarchy, and builder-native structure without full rebuild.
enable_agentic: true
enable_prompt: true
---

# Section refactor

## Steps
1. Identify target section via structure tools (`elementor-v3-get-page-structure` or block parse).
2. Snapshot the post.
3. Prefer batch mutate over full rebuild: `stonewright/elementor-v3-batch-mutate` or Gutenberg block update abilities.
4. Re-read structure; verify spacing tokens and heading levels.

## Rules
- Touch only the requested section.
