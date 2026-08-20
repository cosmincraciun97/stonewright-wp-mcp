# Task 17 Fix Report

## Status

Fixed the Important review finding. Compact task-start now projects each anti-slop rule with `id`, `summary`, `severity`, and `guidance`, preserving the actionable `QualityRuleRegistry::floor()` contract while omitting unrelated row keys. String guidance is capped at 100 characters before lower-priority compact metadata is removed.

## RED

`./vendor/bin/phpunit tests/Unit/Context/ContextUserContextTest.php`

Failed in `test_compact_task_start_includes_anti_slop_floor_for_visual_profiles` because compact rows did not contain `summary`.

## GREEN

- `./vendor/bin/phpunit tests/Unit/Context/ContextUserContextTest.php` — 8 tests, 95 assertions.
- `./vendor/bin/phpunit tests/Unit/WorkflowEfficiencyAbilitiesTest.php` — 30 tests, 638 assertions.
- `./vendor/bin/phpunit tests/Unit/Abilities/System/RulesGetTest.php` — 33 tests, 328 assertions.
- IDE lint diagnostics — no errors.

## Compact payload

The exact `WorkflowEfficiencyAbilitiesTest` task-start fixture encodes to **3576 bytes**, below the strict 3600-byte cap.
