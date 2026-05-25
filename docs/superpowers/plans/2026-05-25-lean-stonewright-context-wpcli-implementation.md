# Lean Stonewright Context and WP-CLI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove Stonewright-owned Figma/QA, force persistent skills/memory context before write work, add Elementor widget implementation guidance, and add full WP-CLI support.

**Architecture:** Keep Stonewright's builder abilities but move agent preparation into a mandatory context bootstrap token. The PHP plugin gates write/destructive MCP calls centrally, while the companion exposes a structured WP-CLI runner using argv arrays instead of shell strings.

**Tech Stack:** PHP 8.1 WordPress plugin, WordPress Abilities API, PHPUnit, TypeScript Node companion, Vitest, WP-CLI.

---

### Task 1: Update Spec and Agent Instructions

**Files:**
- Modify: `D:\Work\stonewright-wp-mcp\docs\superpowers\specs\2026-05-25-lean-stonewright-context-wpcli-design.md`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Core\AgentInstructions.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Core\AgentInstructionsTest.php`

- [ ] **Step 1: Write failing AgentInstructions tests**

Assert that default instructions no longer mention Figma/QA and do mention:

```php
$this->assertStringContainsString( 'stonewright/context-bootstrap', $instructions );
$this->assertStringContainsString( 'stonewright/elementor-widget-implementation-guide', $instructions );
$this->assertStringContainsString( 'Content, Style, and Advanced', $instructions );
$this->assertStringContainsString( 'official Elementor documentation', $instructions );
$this->assertStringContainsString( 'stonewright/learning-record', $instructions );
$this->assertStringNotContainsString( 'Figma', $instructions );
$this->assertStringNotContainsString( 'qa-', $instructions );
```

- [ ] **Step 2: Run the focused test**

Run:

```bash
cd plugin
vendor/bin/phpunit --filter AgentInstructionsTest
```

Expected: fail because instructions still include old Figma/QA gates and do not include the new Elementor guide.

- [ ] **Step 3: Update AgentInstructions**

Replace Figma/QA language with mandatory context, memory/skills, widget selection, widget documentation, online research, learning, and WP-CLI guidance.

- [ ] **Step 4: Re-run the focused test**

Expected: pass.

### Task 2: Add Context Bootstrap and Write Gate

**Files:**
- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Context\ContextToken.php`
- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Context\ContextBuilder.php`
- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\System\ContextBootstrap.php`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Core\AbilityRegistry.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Context\ContextBootstrapTest.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Context\ContextGateTest.php`

- [ ] **Step 1: Write failing context bootstrap tests**

Test that `ContextBootstrap` returns a `context_token`, full matched skill content, matching memory entries, and required Elementor followups.

- [ ] **Step 2: Write failing central gate tests**

Test that the registry execute wrapper returns `WP_Error('stonewright_context_required')` for write abilities without `stonewright_context_token`, and allows read abilities.

- [ ] **Step 3: Run focused tests**

Run:

```bash
cd plugin
vendor/bin/phpunit --filter 'ContextBootstrapTest|ContextGateTest'
```

Expected: fail because classes and gate are missing.

- [ ] **Step 4: Implement context classes and ability**

Implement transient-backed tokens, compact skill/memory matching, and full playbook loading for matched skills.

- [ ] **Step 5: Wire AbilityRegistry gate**

Before `execute()`, validate context token for write/destructive ability categories/names. Strip `stonewright_context_token` from args before passing to ability implementations.

- [ ] **Step 6: Re-run focused tests**

Expected: pass.

### Task 3: Add Learning Record

**Files:**
- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\System\LearningRecord.php`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Core\AbilityRegistry.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\System\LearningRecordTest.php`

- [ ] **Step 1: Write failing learning tests**

Test that a correction creates a typed memory entry and optionally creates/updates a user skill.

- [ ] **Step 2: Run focused test**

Expected: fail because ability is missing.

- [ ] **Step 3: Implement ability**

Use `Memory::put_typed()` and `Skills::save()` with audit logging and `manage_options` permission.

- [ ] **Step 4: Re-run focused test**

Expected: pass.

### Task 4: Add Elementor Widget Implementation Guide

**Files:**
- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Elementor\WidgetRegistry\WidgetImplementationGuide.php`
- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\Knowledge\WidgetImplementationGuide.php`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Core\AbilityRegistry.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Elementor\WidgetRegistry\WidgetImplementationGuideTest.php`

- [ ] **Step 1: Write failing guide tests**

Test that the guide recommends native widgets, includes Content/Style/Advanced control checklists, excludes HTML by default, includes docs, and marks online research when docs are stale/missing.

- [ ] **Step 2: Run focused test**

Expected: fail because guide classes are missing.

- [ ] **Step 3: Implement pure guide class and ability**

Combine `WidgetRecommender`, `WidgetCatalog`, and `ElementorKnowledgeBase::describe_widget()`.

- [ ] **Step 4: Re-run focused test**

Expected: pass.

### Task 5: Remove Figma and QA Surfaces

**Files:**
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Core\AbilityRegistry.php`
- Modify/Delete: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\Design\*Figma*.php`
- Delete: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\QA`
- Delete: `D:\Work\stonewright-wp-mcp\plugin\includes\QA`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Support\CompanionClient.php`
- Modify/Delete QA/Figma tests and fixtures.
- Modify/Delete Figma/QA skill packs and docs.

- [ ] **Step 1: Write failing absence tests**

Assert registered ability names contain no `figma` and no `stonewright/qa`.

- [ ] **Step 2: Run focused absence tests**

Expected: fail before removal.

- [ ] **Step 3: Remove registry entries and PHP-only Figma/QA classes**

Keep non-Figma DesignSpec validation/rendering intact.

- [ ] **Step 4: Remove/adjust tests, fixtures, docs, skills**

Remove tests that cover deleted functionality and update docs that enumerate abilities.

- [ ] **Step 5: Re-run focused absence tests**

Expected: pass.

### Task 6: Add WP-CLI Companion Runner

**Files:**
- Create: `D:\Work\stonewright-wp-mcp\companion\src\wp-cli-runner.ts`
- Modify: `D:\Work\stonewright-wp-mcp\companion\src\http-api.ts`
- Modify: `D:\Work\stonewright-wp-mcp\companion\src\mcp-server.ts`
- Modify: `D:\Work\stonewright-wp-mcp\companion\src\index.ts`
- Test: `D:\Work\stonewright-wp-mcp\companion\tests\wp-cli-runner.test.ts`
- Test: `D:\Work\stonewright-wp-mcp\companion\tests\http-endpoints.test.ts`
- Test: `D:\Work\stonewright-wp-mcp\companion\tests\mcp-server.test.ts`

- [ ] **Step 1: Write failing runner tests**

Test argv-only execution, denylist for `eval`, `eval-file`, `shell`, JSON parsing, timeout, redaction, and no shell.

- [ ] **Step 2: Run focused Node tests**

Run:

```bash
cd companion
npm test -- --run tests/wp-cli-runner.test.ts
```

Expected: fail because runner is missing.

- [ ] **Step 3: Implement runner and expose HTTP/MCP tools**

Add `/wp-cli/status`, `/wp-cli/run`, and `/wp-cli/discover`.

- [ ] **Step 4: Re-run focused Node tests**

Expected: pass.

### Task 7: Add WP-CLI PHP Abilities

**Files:**
- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\WpCli\Status.php`
- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\WpCli\Run.php`
- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\WpCli\Discover.php`
- Create helper abilities for ACF, CPT UI, Elementor, Gutenberg inspection/write surfaces as scoped wrappers.
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Core\AbilityRegistry.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\WpCli\WpCliAbilitiesTest.php`

- [ ] **Step 1: Write failing PHP tests**

Test permissions, denylist, confirmation-token behavior, post backup for `affected_posts`, companion payload shape, parsed JSON forwarding, and plugin helper schemas.

- [ ] **Step 2: Run focused tests**

Expected: fail because abilities are missing.

- [ ] **Step 3: Implement abilities**

Use `CompanionClient::post()`, `Permissions`, `Backup`, and `ConfirmationToken` consistently.

- [ ] **Step 4: Re-run focused tests**

Expected: pass.

### Task 8: Final Docs and Verification

**Files:**
- Modify: `D:\Work\stonewright-wp-mcp\AGENTS.md`
- Modify: `D:\Work\stonewright-wp-mcp\README.md`
- Modify docs under `D:\Work\stonewright-wp-mcp\docs`
- Modify skill packs under `D:\Work\stonewright-wp-mcp\skills`
- Modify: `D:\Work\stonewright-wp-mcp\companion\package.json`

- [ ] **Step 1: Update docs**

Remove Figma/QA instructions, document persistent context bootstrap, Elementor widget guide, learning record, and WP-CLI.

- [ ] **Step 2: Remove unused companion dependencies**

Remove Playwright, sharp, axe/vendor, and prompt/Figma-only dependencies where no longer used.

- [ ] **Step 3: Run verification**

Run:

```bash
cd plugin
composer test
composer phpstan
composer phpcs

cd ../companion
npm test
npm run build
```

Expected: all commands exit 0.

- [ ] **Step 4: Inspect git diff**

Confirm `companion/.env` remains unmodified/untracked and no unrelated files are touched.
