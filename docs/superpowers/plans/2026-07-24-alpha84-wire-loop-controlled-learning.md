# Alpha.84 Wire-Loop and Controlled Learning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Stonewright `1.0.0-alpha.84` with a transactional native Elementor Pro Loop Carousel/Loop Grid wiring ability and schema-repair learning that becomes active only after two distinct verified tasks or explicit approval.

**Architecture:** Extract the pure V3 mutation compiler from the existing batch ability, then compose it with live widget schemas, staged Theme Builder templates, one page write, locking, idempotency, readback, and rollback in a dedicated loop transaction service. Correlate typed schema failures with later verified readbacks through a bounded incident store and the existing `CandidateRepository`; promoted candidates continue to reach task-start through the existing active-skill matcher.

**Tech Stack:** PHP 8.1+, WordPress Abilities API, Elementor/Elementor Pro runtime schemas, PHPUnit, PHPStan, PHPCS, Composer, Node.js/TypeScript companion, Vitest, GitHub Actions.

## Global Constraints

- Product name is **Stonewright**; PHP namespace is `Stonewright\WpMcp`; ability names use `stonewright/`.
- Plugin license remains `AGPL-3.0-or-later`; companion license remains `MIT`.
- Validate every `template_spec` with `DesignSpec\Validator::validate()` before rendering.
- Call `Backup::snapshot_post()` before every Elementor page or template mutation.
- Every write uses a real `Permissions` callback and the existing context-token gate.
- Production-safe destructive behavior uses `ConfirmationToken`; the original verified token covers transaction-owned rollback cleanup.
- Never write `_elementor_data` directly, double-encode it, strip unknown settings, convert widget types, or rewrite a full page for this feature.
- Existing templates are read-only in this flow; only transaction-created templates may be deleted during rollback.
- Direct-mode parity and the Figma tokens pack remain outside alpha.84.
- Public docs and release text must not name third-party competitor products or disclose internal development tooling.

---

## File Map

### New production files

- `plugin/includes/Elementor/Write/V3MutationCompiler.php` — pure in-memory V3 operation compiler extracted from `BatchMutate`.
- `plugin/includes/Elementor/Write/PostWriteLock.php` — bounded per-post lease with owner-safe release.
- `plugin/includes/Elementor/Loop/LoopIntentCompiler.php` — maps compact loop intent to controls present in the live widget schema.
- `plugin/includes/Elementor/Loop/LoopQueryProbe.php` — validates post type/filter intent and performs a bounded result probe.
- `plugin/includes/Elementor/Loop/LoopReadbackVerifier.php` — verifies parent, widget, template, query, responsive settings, and hash.
- `plugin/includes/Elementor/Loop/LoopTransaction.php` — coordinates template staging, one page write, finalization, rollback, lock, and idempotency.
- `plugin/includes/Abilities/ElementorV3/WireLoop.php` — public `stonewright/elementor-wire-loop` contract and security boundary.
- `plugin/includes/Knowledge/Lifecycle/SchemaRepairIncidentStore.php` — bounded seven-day rejected-shape correlation store.
- `plugin/includes/Knowledge/Lifecycle/SchemaRepairLearning.php` — creates, verifies, and promotes candidates from verified repairs.

### Modified production files

- `plugin/includes/Abilities/ElementorV3/BatchMutate.php` — delegate compilation, use the write lock, and emit learning observations.
- `plugin/includes/ThemeBuilder/TemplateStore.php` — staged draft creation, owner marker, publish, and owner-checked cleanup.
- `plugin/includes/Context/ContextToken.php` — expose the verified task hash without exposing token contents.
- `plugin/includes/Elementor/Schema/RuntimeFingerprint.php` — check learned-skill version constraints against the live runtime.
- `plugin/includes/Knowledge/Lifecycle/CandidateRepository.php` — add bounded pruning and a lookup suitable for repair verification.
- `plugin/includes/Core/AbilityRegistry.php` — register and expose wire-loop on essential Elementor surfaces.
- `plugin/includes/Abilities/System/ToolProfile.php` — recommend wire-loop for native loop tasks.
- `plugin/includes/Context/ContextBuilder.php` — keep approved learned recipes compact and relevant in task-start.
- `plugin/includes/Support/PublicApiContractSnapshot.php` — include new public service methods where contract policy requires it.

### New tests

- `plugin/tests/Unit/Elementor/Write/V3MutationCompilerTest.php`
- `plugin/tests/Unit/Elementor/Write/PostWriteLockTest.php`
- `plugin/tests/Unit/ThemeBuilder/TemplateStoreStagingTest.php`
- `plugin/tests/Unit/Elementor/Loop/LoopIntentCompilerTest.php`
- `plugin/tests/Unit/Elementor/Loop/LoopQueryProbeTest.php`
- `plugin/tests/Unit/Elementor/Loop/LoopReadbackVerifierTest.php`
- `plugin/tests/Unit/Elementor/Loop/LoopTransactionTest.php`
- `plugin/tests/Unit/ElementorV3/WireLoopTest.php`
- `plugin/tests/Unit/Knowledge/SchemaRepairLearningTest.php`

### Modified tests

- `plugin/tests/Unit/ElementorV3/BatchMutateTest.php`
- `plugin/tests/Unit/Knowledge/KnowledgeLifecycleTest.php`
- `plugin/tests/Unit/Context/ContextBootstrapTest.php`
- `plugin/tests/Unit/Core/AbilityRegistryEssentialModeTest.php`
- `plugin/tests/Unit/WorkflowEfficiencyAbilitiesTest.php`
- `plugin/tests/Unit/AbilityKernelAuditTest.php`
- `plugin/tests/Integration/ContractTest.php`

---

### Task 1: Shared V3 mutation compiler

**Files:**
- Create: `plugin/includes/Elementor/Write/V3MutationCompiler.php`
- Create: `plugin/tests/Unit/Elementor/Write/V3MutationCompilerTest.php`
- Modify: `plugin/includes/Abilities/ElementorV3/BatchMutate.php`
- Modify: `plugin/tests/Unit/ElementorV3/BatchMutateTest.php`

**Interfaces:**
- Consumes: an Elementor tree and normalized batch operations.
- Produces: `V3MutationCompiler::compile(array $tree, array $operations, bool $require_evidence, bool $stop_on_error): array|\WP_Error`.
- Result keys: `tree`, `items`, `refs`, `applied`, `failed`, and `targeted_ids`.

- [ ] **Step 1: Write compiler characterization tests**

```php
public function test_compiles_widget_under_real_parent_without_writing(): void {
	$tree = [ self::container( 'parent-a' ) ];
	$result = ( new V3MutationCompiler() )->compile(
		$tree,
		[
			[
				'action'      => 'add_widget',
				'op_id'       => 'loop',
				'parent_id'   => 'parent-a',
				'widget_type' => 'heading',
				'settings'    => [ 'title' => 'Safe title' ],
			],
		],
		false,
		true
	);

	self::assertIsArray( $result );
	self::assertSame( 1, $result['applied'] );
	self::assertArrayHasKey( 'loop', $result['refs'] );
	self::assertSame( 1, count( $result['tree'][0]['elements'] ) );
	self::assertSame( [], ElementorData::read( 901 ) );
}

public function test_missing_parent_fails_without_root_fallback(): void {
	$result = ( new V3MutationCompiler() )->compile(
		[ self::container( 'parent-a' ) ],
		[
			[
				'action'      => 'add_widget',
				'parent_id'   => 'missing',
				'widget_type' => 'heading',
				'settings'    => [ 'title' => 'Safe title' ],
			],
		],
		false,
		true
	);

	self::assertInstanceOf( \WP_Error::class, $result );
	self::assertSame( 'stonewright_batch_operation_failed', $result->get_error_code() );
	self::assertSame( 'stonewright_parent_not_found', $result->get_error_data()['cause_code'] );
}
```

- [ ] **Step 2: Run the new tests and confirm the class is missing**

Run:

```bash
cd plugin
composer test -- --filter V3MutationCompilerTest
```

Expected: FAIL because `V3MutationCompiler` does not exist.

- [ ] **Step 3: Extract the pure compiler**

Create the class with this public boundary:

```php
final class V3MutationCompiler {
	/**
	 * @param array<int,array<string,mixed>> $tree
	 * @param array<int,array<string,mixed>> $operations
	 * @return array{
	 *   tree:array<int,array<string,mixed>>,
	 *   items:list<array<string,mixed>>,
	 *   refs:array<string,string>,
	 *   applied:int,
	 *   failed:int,
	 *   targeted_ids:list<string>
	 * }|\WP_Error
	 */
	public function compile(
		array $tree,
		array $operations,
		bool $require_evidence = false,
		bool $stop_on_error = true
	): array|\WP_Error {
		$operations = self::normalize_operations( array_values( $operations ) );
		$architecture = (string) ( AtomicTreeInspector::inspect( $tree )['architecture'] ?? 'empty' );

		if ( 'mixed' === $architecture && self::contains_unparented_add( $operations ) ) {
			return self::error(
				'mixed_root_add_blocked',
				'Mixed Elementor documents require every added V3 node to name an existing V3-only parent.',
				[ 'status' => 409, 'architecture' => $architecture ]
			);
		}

		$blocking = self::blocked_targets( $tree, self::operation_target_ids( $operations ) );
		if ( [] !== $blocking ) {
			return self::error(
				'v3_architecture_mismatch',
				'A targeted Elementor element is or contains V4 Atomic nodes.',
				[ 'status' => 409, 'blocked_targets' => $blocking ]
			);
		}

		return $this->compile_operations( $tree, $operations, $require_evidence, $stop_on_error );
	}
}
```

Move the normalization, target collection, add/update/move/remove, parent
resolution, responsive-scope, schema-request, and repair-hint methods from
`BatchMutate` into this class without changing their validation behavior.
Keep the generated element ID in the returned `refs` so callers can establish
readback invariants before persistence.

- [ ] **Step 4: Replace private compilation inside BatchMutate**

```php
$compiled = ( new V3MutationCompiler() )->compile(
	$tree,
	$operations,
	$require_evidence,
	$stop
);
if ( $compiled instanceof \WP_Error ) {
	return $compiled;
}

$tree         = $compiled['tree'];
$items        = $compiled['items'];
$refs         = $compiled['refs'];
$applied      = $compiled['applied'];
$failed       = $compiled['failed'];
$targeted_ids = $compiled['targeted_ids'];
```

Delete only the now-duplicated private compiler methods from `BatchMutate`.
Keep its idempotency, expected-hash, snapshot, write, readback, metrics, and
response behavior unchanged.

- [ ] **Step 5: Run focused and regression tests**

Run:

```bash
cd plugin
composer test -- --filter 'V3MutationCompilerTest|BatchMutateTest'
```

Expected: PASS, including existing op-ref, mixed-document, responsive-scope,
schema-error, and readback tests.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/Elementor/Write/V3MutationCompiler.php \
  plugin/includes/Abilities/ElementorV3/BatchMutate.php \
  plugin/tests/Unit/Elementor/Write/V3MutationCompilerTest.php \
  plugin/tests/Unit/ElementorV3/BatchMutateTest.php
git commit -m "refactor: extract Elementor V3 mutation compiler"
```

### Task 2: Per-post write lease

**Files:**
- Create: `plugin/includes/Elementor/Write/PostWriteLock.php`
- Create: `plugin/tests/Unit/Elementor/Write/PostWriteLockTest.php`
- Modify: `plugin/includes/Abilities/ElementorV3/BatchMutate.php`

**Interfaces:**
- Produces: `PostWriteLock::acquire(int $post_id, string $owner, int $ttl = 30): array|\WP_Error`.
- Produces: `PostWriteLock::release(int $post_id, string $owner): bool`.
- Lease keys: `post_id`, `owner`, `expires_at`.

- [ ] **Step 1: Write lock tests**

```php
public function test_second_owner_is_busy_until_first_releases(): void {
	$first = PostWriteLock::acquire( 9049, 'txn-one', 30 );
	$second = PostWriteLock::acquire( 9049, 'txn-two', 30 );

	self::assertIsArray( $first );
	self::assertInstanceOf( \WP_Error::class, $second );
	self::assertSame( 'stonewright_elementor_write_busy', $second->get_error_code() );
	self::assertGreaterThan( time(), $second->get_error_data()['lock_expires_at'] );
	self::assertTrue( PostWriteLock::release( 9049, 'txn-one' ) );
	self::assertIsArray( PostWriteLock::acquire( 9049, 'txn-two', 30 ) );
}

public function test_wrong_owner_cannot_release_lock(): void {
	PostWriteLock::acquire( 9049, 'txn-one', 30 );
	self::assertFalse( PostWriteLock::release( 9049, 'txn-two' ) );
	self::assertInstanceOf( \WP_Error::class, PostWriteLock::acquire( 9049, 'txn-two', 30 ) );
}
```

- [ ] **Step 2: Run and confirm failure**

```bash
cd plugin
composer test -- --filter PostWriteLockTest
```

Expected: FAIL because the lock class does not exist.

- [ ] **Step 3: Implement an atomic option-backed lease**

```php
final class PostWriteLock {
	private const PREFIX = 'stonewright_elementor_lock_';

	public static function acquire( int $post_id, string $owner, int $ttl = 30 ): array|\WP_Error {
		$key = self::key( $post_id );
		$now = time();
		$lease = [
			'post_id'    => $post_id,
			'owner'      => sanitize_key( $owner ),
			'expires_at' => $now + max( 5, min( 120, $ttl ) ),
		];
		if ( add_option( $key, $lease, '', false ) ) {
			return $lease;
		}

		$current = get_option( $key, [] );
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= $now ) {
			delete_option( $key );
			if ( add_option( $key, $lease, '', false ) ) {
				return $lease;
			}
			$current = get_option( $key, [] );
		}

		return new \WP_Error(
			'stonewright_elementor_write_busy',
			__( 'Another Elementor transaction is writing this post.', 'stonewright' ),
			[
				'status'          => 409,
				'retryable'       => true,
				'lock_expires_at' => (int) ( $current['expires_at'] ?? $now + 5 ),
			]
		);
	}

	public static function release( int $post_id, string $owner ): bool {
		$key = self::key( $post_id );
		$current = get_option( $key, [] );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['owner'] ?? '' ), sanitize_key( $owner ) ) ) {
			return false;
		}
		return delete_option( $key );
	}

	private static function key( int $post_id ): string {
		return self::PREFIX . $post_id;
	}
}
```

- [ ] **Step 4: Guard BatchMutate writes and always release**

Acquire only after dry-run validation and before snapshot/write. Wrap the
mutation section in `try/finally`:

```php
$owner = 'batch-' . substr( $request_hash, 0, 24 );
$lease = PostWriteLock::acquire( $post_id, $owner );
if ( $lease instanceof \WP_Error ) {
	return $lease;
}
try {
	$snapshot_id = Backup::snapshot_post( $post_id );
	// Existing single-write and readback block remains here.
} finally {
	PostWriteLock::release( $post_id, $owner );
}
```

- [ ] **Step 5: Run tests**

```bash
cd plugin
composer test -- --filter 'PostWriteLockTest|BatchMutateTest'
```

Expected: PASS and no lock remains after success or failure.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/Elementor/Write/PostWriteLock.php \
  plugin/includes/Abilities/ElementorV3/BatchMutate.php \
  plugin/tests/Unit/Elementor/Write/PostWriteLockTest.php \
  plugin/tests/Unit/ElementorV3/BatchMutateTest.php
git commit -m "fix: serialize Elementor writes per post"
```

### Task 3: Transaction-owned staged loop templates

**Files:**
- Modify: `plugin/includes/ThemeBuilder/TemplateStore.php`
- Create: `plugin/tests/Unit/ThemeBuilder/TemplateStoreStagingTest.php`

**Interfaces:**
- Produces: `TemplateStore::create_staged(string $title, string $type, string $owner): int|\WP_Error`.
- Produces: `TemplateStore::publish_staged(int $template_id, string $owner): bool|\WP_Error`.
- Produces: `TemplateStore::finalize_staged(int $template_id, string $owner): bool|\WP_Error`.
- Produces: `TemplateStore::delete_staged(int $template_id, string $owner): bool|\WP_Error`.
- Adds private meta `_stonewright_transaction_owner`.

- [ ] **Step 1: Write ownership and lifecycle tests**

```php
public function test_staged_loop_item_is_draft_until_owner_publishes(): void {
	$id = TemplateStore::create_staged( 'Card', 'loop-item', 'txn-abc' );
	self::assertIsInt( $id );
	self::assertSame( 'draft', get_post_status( $id ) );
	self::assertSame( 'txn-abc', get_post_meta( $id, '_stonewright_transaction_owner', true ) );
	self::assertTrue( TemplateStore::publish_staged( $id, 'txn-abc' ) );
	self::assertSame( 'publish', get_post_status( $id ) );
	self::assertSame( 'txn-abc', get_post_meta( $id, '_stonewright_transaction_owner', true ) );
	self::assertTrue( TemplateStore::finalize_staged( $id, 'txn-abc' ) );
	self::assertSame( '', get_post_meta( $id, '_stonewright_transaction_owner', true ) );
}

public function test_wrong_owner_cannot_publish_or_delete(): void {
	$id = TemplateStore::create_staged( 'Card', 'loop-item', 'txn-abc' );
	self::assertInstanceOf( \WP_Error::class, TemplateStore::publish_staged( $id, 'txn-other' ) );
	self::assertInstanceOf( \WP_Error::class, TemplateStore::delete_staged( $id, 'txn-other' ) );
	self::assertNotNull( get_post( $id ) );
}
```

- [ ] **Step 2: Run and confirm missing methods**

```bash
cd plugin
composer test -- --filter TemplateStoreStagingTest
```

Expected: FAIL because staged methods do not exist.

- [ ] **Step 3: Add staged creation and owner checks**

Refactor `create()` through a private status-aware creator, keeping its current
published behavior:

```php
public static function create( string $title, string $type ): int|\WP_Error {
	return self::insert( $title, $type, 'publish', '' );
}

public static function create_staged( string $title, string $type, string $owner ): int|\WP_Error {
	if ( 'loop-item' !== $type || '' === sanitize_key( $owner ) ) {
		return new \WP_Error(
			'stonewright_staged_template_invalid',
			__( 'Staged templates require loop-item type and a transaction owner.', 'stonewright' ),
			[ 'status' => 400 ]
		);
	}
	return self::insert( $title, $type, 'draft', sanitize_key( $owner ) );
}

public static function publish_staged( int $template_id, string $owner ): bool|\WP_Error {
	$owned = self::assert_owner( $template_id, $owner );
	if ( $owned instanceof \WP_Error ) {
		return $owned;
	}
	$result = wp_update_post( [ 'ID' => $template_id, 'post_status' => 'publish' ], true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return true;
}

public static function finalize_staged( int $template_id, string $owner ): bool|\WP_Error {
	$owned = self::assert_owner( $template_id, $owner );
	if ( $owned instanceof \WP_Error ) {
		return $owned;
	}
	return delete_post_meta( $template_id, '_stonewright_transaction_owner' );
}

public static function delete_staged( int $template_id, string $owner ): bool|\WP_Error {
	$owned = self::assert_owner( $template_id, $owner );
	if ( $owned instanceof \WP_Error ) {
		return $owned;
	}
	return null !== wp_delete_post( $template_id, true );
}
```

`insert()` must set `_elementor_template_type`, `_elementor_edit_mode`, and one
JSON-encoded empty `_elementor_data` value exactly as `create()` does today.

- [ ] **Step 4: Run Theme Builder tests**

```bash
cd plugin
composer test -- --filter 'TemplateStoreStagingTest|ThemeBuilder'
```

Expected: PASS; existing template creation remains published.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/ThemeBuilder/TemplateStore.php \
  plugin/tests/Unit/ThemeBuilder/TemplateStoreStagingTest.php
git commit -m "feat: stage transaction-owned loop templates"
```

### Task 4: Live loop intent compilation and query probe

**Files:**
- Create: `plugin/includes/Elementor/Loop/LoopIntentCompiler.php`
- Create: `plugin/includes/Elementor/Loop/LoopQueryProbe.php`
- Create: `plugin/tests/Unit/Elementor/Loop/LoopIntentCompilerTest.php`
- Create: `plugin/tests/Unit/Elementor/Loop/LoopQueryProbeTest.php`
- Modify: `plugin/includes/Elementor/Schema/WidgetSchemaRepository.php`
- Modify: `plugin/tests/bootstrap.php`

**Interfaces:**
- Produces: `LoopIntentCompiler::compile(string $display, int $template_id, string $post_type, array $intent): array|\WP_Error`.
- Produces settings plus `widget_type`, `schema_hash`, `runtime_fingerprint`, `resolved_controls`, and `warnings`.
- Produces: `LoopQueryProbe::probe(string $post_type, array $query, bool $require_results): array|\WP_Error`.

- [ ] **Step 1: Write schema-mapping tests**

```php
public function test_carousel_uses_only_controls_exposed_by_live_schema(): void {
	WidgetSchemaRepository::set_test_schema(
		'loop-carousel',
		[
			'schema_hash' => str_repeat( 'a', 64 ),
			'runtime_fingerprint' => str_repeat( 'b', 64 ),
			'controls' => [
				'template_id'    => [ 'type' => 'select' ],
				'posts_per_page' => [ 'type' => 'number' ],
				'slides_to_show' => [ 'type' => 'number', 'responsive' => true ],
				'slides_to_show_tablet' => [ 'type' => 'number' ],
				'slides_to_show_mobile' => [ 'type' => 'number' ],
				'arrows' => [ 'type' => 'switcher', 'return_value' => 'yes' ],
			],
		]
	);

	$result = LoopIntentCompiler::compile(
		'carousel',
		77,
		'project',
		[
			'query' => [ 'posts_per_page' => 6 ],
			'responsive' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ],
			'arrows' => true,
		]
	);

	self::assertSame( 'loop-carousel', $result['widget_type'] );
	self::assertSame( 77, $result['settings']['template_id'] );
	self::assertSame( 3, $result['settings']['slides_to_show'] );
	self::assertSame( 'yes', $result['settings']['arrows'] );
	self::assertArrayNotHasKey( 'pagination', $result['settings'] );
}

public function test_missing_template_control_is_structured_failure(): void {
	WidgetSchemaRepository::set_test_schema(
		'loop-grid',
		[
			'schema_hash' => str_repeat( 'a', 64 ),
			'runtime_fingerprint' => str_repeat( 'b', 64 ),
			'controls' => [ 'columns' => [ 'type' => 'number' ] ],
		]
	);
	$result = LoopIntentCompiler::compile( 'grid', 77, 'project', [] );
	self::assertInstanceOf( \WP_Error::class, $result );
	self::assertSame( 'stonewright_loop_schema_incompatible', $result->get_error_code() );
	self::assertSame( 'template', $result->get_error_data()['missing_semantic_control'] );
}
```

Define `STONEWRIGHT_TESTS` as `true` in `plugin/tests/bootstrap.php`. Add
`WidgetSchemaRepository::set_test_schema(string $widget_type, ?array $schema): void`
and guard it with `defined('STONEWRIGHT_TESTS') && STONEWRIGHT_TESTS`;
production calls return without changing state. Reset the override in
`tearDown()`.

- [ ] **Step 2: Write query tests**

```php
public function test_empty_query_warns_by_default_and_blocks_when_required(): void {
	$GLOBALS['stonewright_test_search_posts'] = [];
	$warning = LoopQueryProbe::probe( 'project', [ 'posts_per_page' => 6 ], false );
	self::assertSame( 0, $warning['found'] );
	self::assertContains( 'query_returned_no_results', $warning['warnings'] );

	$blocked = LoopQueryProbe::probe( 'project', [ 'posts_per_page' => 6 ], true );
	self::assertInstanceOf( \WP_Error::class, $blocked );
	self::assertSame( 'stonewright_loop_query_empty', $blocked->get_error_code() );
}
```

- [ ] **Step 3: Run and confirm failures**

```bash
cd plugin
composer test -- --filter 'LoopIntentCompilerTest|LoopQueryProbeTest'
```

Expected: FAIL because both classes are missing.

- [ ] **Step 4: Implement semantic control resolution**

Use a finite alias map per semantic field, then require the selected key to
exist in `WidgetSchemaRepository::get($widget_type)['controls']`:

```php
private const CONTROL_ALIASES = [
	'template'      => [ 'template_id', 'loop_template_id', 'template' ],
	'post_type'     => [ 'post_type', 'query_post_type' ],
	'posts_per_page'=> [ 'posts_per_page', 'query_posts_per_page' ],
	'columns'       => [ 'columns', 'columns_tablet', 'columns_mobile' ],
	'slides_to_show'=> [ 'slides_to_show', 'slides_to_show_tablet', 'slides_to_show_mobile' ],
	'slides_to_scroll' => [ 'slides_to_scroll' ],
	'arrows'        => [ 'arrows', 'navigation' ],
	'pagination'    => [ 'pagination', 'pagination_type' ],
	'order'         => [ 'order' ],
	'orderby'       => [ 'orderby' ],
	'offset'        => [ 'offset' ],
];

private static function resolve_required(
	array $controls,
	string $semantic
): string|\WP_Error {
	foreach ( self::CONTROL_ALIASES[ $semantic ] ?? [] as $candidate ) {
		if ( array_key_exists( $candidate, $controls ) ) {
			return $candidate;
		}
	}
	return new \WP_Error(
		'stonewright_loop_schema_incompatible',
		sprintf( 'Live loop widget schema has no compatible %s control.', $semantic ),
		[
			'status' => 409,
			'missing_semantic_control' => $semantic,
			'accepted_aliases' => self::CONTROL_ALIASES[ $semantic ] ?? [],
		]
	);
}
```

Pass the resolved settings through `SettingsValidator::validate()` before
returning them. Do not emit any optional setting whose semantic control cannot
be resolved.

- [ ] **Step 5: Implement bounded query probing**

```php
public static function probe( string $post_type, array $query, bool $require_results ): array|\WP_Error {
	if ( ! post_type_exists( $post_type ) ) {
		return new \WP_Error(
			'stonewright_loop_post_type_invalid',
			__( 'The requested loop post type is not registered.', 'stonewright' ),
			[ 'status' => 400, 'post_type' => $post_type ]
		);
	}
	$args = self::sanitize_query( $post_type, $query );
	$args['posts_per_page'] = min( 20, max( 1, (int) ( $args['posts_per_page'] ?? 6 ) ) );
	$args['fields'] = 'ids';
	$args['no_found_rows'] = false;
	$result = new \WP_Query( $args );
	$found = (int) $result->found_posts;
	if ( 0 === $found && $require_results ) {
		return new \WP_Error(
			'stonewright_loop_query_empty',
			__( 'The validated loop query returned no content.', 'stonewright' ),
			[
				'status' => 409,
				'query_hash' => hash( 'sha256', (string) wp_json_encode( $args ) ),
			]
		);
	}
	return [
		'found' => $found,
		'sampled_ids' => array_slice( array_map( 'intval', (array) $result->posts ), 0, 20 ),
		'warnings' => 0 === $found ? [ 'query_returned_no_results' ] : [],
	];
}
```

Allow only `posts_per_page`, `post__in`, `post__not_in`, `tax_query`,
`meta_query`, `orderby`, `order`, and `offset`; sanitize nested tax/meta rows
and reject unknown keys instead of forwarding arbitrary `WP_Query` arguments.

- [ ] **Step 6: Run focused tests**

```bash
cd plugin
composer test -- --filter 'LoopIntentCompilerTest|LoopQueryProbeTest'
```

Expected: PASS for carousel, grid, responsive translation, unsupported
controls, invalid post type, and both empty-query modes.

- [ ] **Step 7: Commit**

```bash
git add plugin/includes/Elementor/Loop/LoopIntentCompiler.php \
  plugin/includes/Elementor/Loop/LoopQueryProbe.php \
  plugin/includes/Elementor/Schema/WidgetSchemaRepository.php \
  plugin/tests/bootstrap.php \
  plugin/tests/Unit/Elementor/Loop/LoopIntentCompilerTest.php \
  plugin/tests/Unit/Elementor/Loop/LoopQueryProbeTest.php
git commit -m "feat: compile native Elementor loop intent"
```

### Task 5: Loop readback verification

**Files:**
- Create: `plugin/includes/Elementor/Loop/LoopReadbackVerifier.php`
- Create: `plugin/tests/Unit/Elementor/Loop/LoopReadbackVerifierTest.php`

**Interfaces:**
- Produces: `LoopReadbackVerifier::verify(array $tree, array $expected): array|\WP_Error`.
- Expected keys: `tree_hash`, `parent_id`, `widget_id`, `widget_type`, `template_id`, `settings`.

- [ ] **Step 1: Write invariant-specific tests**

```php
public function test_verifies_exact_loop_linkage_and_settings(): void {
	$tree = [ self::containerWithLoop( 'parent-a', 'widget-a', 'loop-grid', 77 ) ];
	$result = LoopReadbackVerifier::verify(
		$tree,
		[
			'tree_hash'   => TreeHasher::hash( $tree ),
			'parent_id'   => 'parent-a',
			'widget_id'   => 'widget-a',
			'widget_type' => 'loop-grid',
			'template_id' => 77,
			'template_control' => 'template_id',
			'settings'    => [ 'columns' => 3 ],
		]
	);
	self::assertTrue( $result['verified'] );
	self::assertSame( [ 'hash', 'parent', 'widget_type', 'template', 'settings' ], $result['checks'] );
}

public function test_wrong_parent_names_failed_invariant(): void {
	$tree = [ self::containerWithLoop( 'other', 'widget-a', 'loop-grid', 77 ) ];
	$result = LoopReadbackVerifier::verify(
		$tree,
		[
			'tree_hash' => TreeHasher::hash( $tree ),
			'parent_id' => 'parent-a',
			'widget_id' => 'widget-a',
			'widget_type' => 'loop-grid',
			'template_id' => 77,
			'template_control' => 'template_id',
			'settings' => [],
		]
	);
	self::assertInstanceOf( \WP_Error::class, $result );
	self::assertSame( 'parent', $result->get_error_data()['failed_invariant'] );
}
```

- [ ] **Step 2: Run and confirm failure**

```bash
cd plugin
composer test -- --filter LoopReadbackVerifierTest
```

Expected: FAIL because the verifier does not exist.

- [ ] **Step 3: Implement exact bounded verification**

```php
public static function verify( array $tree, array $expected ): array|\WP_Error {
	$actual_hash = TreeHasher::hash( $tree );
	if ( ! hash_equals( (string) $expected['tree_hash'], $actual_hash ) ) {
		return self::mismatch( 'hash', [ 'actual_hash' => $actual_hash ] );
	}
	$path = ElementorData::find_path( $tree, (string) $expected['widget_id'] );
	if ( null === $path ) {
		return self::mismatch( 'widget_missing' );
	}
	$widget = self::resolve( $tree, $path );
	if ( ! self::path_is_child_of( $tree, $path, (string) $expected['parent_id'] ) ) {
		return self::mismatch( 'parent' );
	}
	if ( (string) ( $widget['widgetType'] ?? '' ) !== (string) $expected['widget_type'] ) {
		return self::mismatch( 'widget_type' );
	}
	$settings = is_array( $widget['settings'] ?? null ) ? $widget['settings'] : [];
	$template_key = (string) $expected['template_control'];
	if ( (int) ( $settings[ $template_key ] ?? 0 ) !== (int) $expected['template_id'] ) {
		return self::mismatch( 'template' );
	}
	foreach ( (array) $expected['settings'] as $key => $value ) {
		if ( ! array_key_exists( $key, $settings ) || $settings[ $key ] !== $value ) {
			return self::mismatch( 'settings', [ 'control' => (string) $key ] );
		}
	}
	return [
		'verified' => true,
		'checks' => [ 'hash', 'parent', 'widget_type', 'template', 'settings' ],
		'readback_hash' => $actual_hash,
	];
}
```

- [ ] **Step 4: Run tests and commit**

```bash
cd plugin
composer test -- --filter LoopReadbackVerifierTest
cd ..
git add plugin/includes/Elementor/Loop/LoopReadbackVerifier.php \
  plugin/tests/Unit/Elementor/Loop/LoopReadbackVerifierTest.php
git commit -m "feat: verify Elementor loop readback"
```

Expected: PASS, including one test for each failed invariant.

### Task 6: Transactional loop orchestration

**Files:**
- Create: `plugin/includes/Elementor/Loop/LoopTransaction.php`
- Create: `plugin/tests/Unit/Elementor/Loop/LoopTransactionTest.php`
- Modify: `plugin/includes/ThemeBuilder/TemplateStore.php`

**Interfaces:**
- Consumes: the already-authorized and normalized WireLoop arguments.
- Produces: `LoopTransaction::run(array $args): array|\WP_Error`.
- Supports a test-only phase-failure hook reset after every test.

- [ ] **Step 1: Write dry-run, existing-template, and new-template tests**

```php
public function test_dry_run_plans_without_page_or_template_writes(): void {
	$before = ElementorData::read( 9049 );
	$result = LoopTransaction::run( self::args( [ 'dry_run' => true, 'template_id' => 77 ] ) );
	self::assertSame( 'planned', $result['status'] );
	self::assertSame( '', $result['snapshot_id'] );
	self::assertSame( $before, ElementorData::read( 9049 ) );
	self::assertCount( 0, self::createdLoopTemplates() );
}

public function test_new_template_is_published_only_after_page_readback(): void {
	$result = LoopTransaction::run(
		self::args(
			[
				'template_spec' => self::validTemplateSpec(),
				'template_title' => 'Project card',
			]
		)
	);
	self::assertSame( 'applied', $result['status'] );
	self::assertTrue( $result['created_template'] );
	self::assertSame( 'publish', get_post_status( $result['template_id'] ) );
	self::assertTrue( $result['readback']['verified'] );
	self::assertSame( '', get_post_meta( $result['template_id'], '_stonewright_transaction_owner', true ) );
}
```

- [ ] **Step 2: Write rollback matrix tests**

Use a data provider with these injected phases:

```php
public static function rollbackPhases(): array {
	return [
		'template_create'   => [ 'template_create', false ],
		'template_write'    => [ 'template_write', true ],
		'template_readback' => [ 'template_readback', true ],
		'page_write'        => [ 'page_write', true ],
		'page_readback'     => [ 'page_readback', true ],
		'template_publish'  => [ 'template_publish', true ],
		'final_readback'    => [ 'final_readback', true ],
		'template_finalize' => [ 'template_finalize', true ],
	];
}

/**
 * @dataProvider rollbackPhases
 */
public function test_failure_restores_page_and_removes_only_owned_template(
	string $phase,
	bool $template_was_created
): void {
	$before = ElementorData::read( 9049 );
	LoopTransaction::fail_at_for_test( $phase );
	$result = LoopTransaction::run(
		self::args( [ 'template_spec' => self::validTemplateSpec() ] )
	);
	self::assertInstanceOf( \WP_Error::class, $result );
	self::assertSame( $before, ElementorData::read( 9049 ) );
	self::assertSame( 'completed', $result->get_error_data()['rollback_status'] );
	if ( $template_was_created ) {
		self::assertCount( 0, self::transactionOwnedTemplates() );
	}
}
```

Also prove an existing template still exists after every page failure.

- [ ] **Step 3: Run and confirm failure**

```bash
cd plugin
composer test -- --filter LoopTransactionTest
```

Expected: FAIL because `LoopTransaction` is missing.

- [ ] **Step 4: Implement the transaction phases**

Use this control structure:

```php
public static function run( array $args ): array|\WP_Error {
	$post_id = (int) $args['post_id'];
	$owner = 'loop-' . substr( hash( 'sha256', $post_id . '|' . (string) $args['idempotency_key'] ), 0, 24 );
	$request_hash = TreeHasher::hash( self::request_payload( $args ) );
	$replay = IdempotencyStore::lookup( $post_id, (string) $args['idempotency_key'], $request_hash );
	if ( $replay instanceof \WP_Error || is_array( $replay ) ) {
		return $replay;
	}

	$plan = self::plan( $args );
	if ( $plan instanceof \WP_Error || ! empty( $args['dry_run'] ) ) {
		return $plan;
	}

	$lease = PostWriteLock::acquire( $post_id, $owner );
	if ( $lease instanceof \WP_Error ) {
		return $lease;
	}

	$page_snapshot = '';
	$template_id = (int) ( $plan['template_id'] ?? 0 );
	$created_template = false;
	try {
		if ( ! empty( $plan['template_tree'] ) ) {
			$template_id = self::stage_template( $plan, $owner );
			if ( $template_id instanceof \WP_Error ) {
				return $template_id;
			}
			$created_template = true;
		}

		$compiled = self::compile_page( $plan, (int) $template_id );
		if ( $compiled instanceof \WP_Error ) {
			return self::rollback_error( $compiled, $post_id, $page_snapshot, (int) $template_id, $owner, $created_template );
		}

		$page_snapshot = Backup::snapshot_post( $post_id );
		if ( '' === $page_snapshot || ! ElementorData::write( $post_id, $compiled['tree'], [ 'touched_ids' => [ $plan['parent_id'] ] ] ) ) {
			return self::rollback_error( self::phase_error( 'page_write' ), $post_id, $page_snapshot, (int) $template_id, $owner, $created_template );
		}

		$readback = LoopReadbackVerifier::verify( ElementorData::read( $post_id ), $compiled['expected_readback'] );
		if ( $readback instanceof \WP_Error ) {
			return self::rollback_error( $readback, $post_id, $page_snapshot, (int) $template_id, $owner, $created_template );
		}

		if ( $created_template ) {
			$published = TemplateStore::publish_staged( (int) $template_id, $owner );
			if ( $published instanceof \WP_Error || ! $published ) {
				return self::rollback_error( self::phase_error( 'template_publish' ), $post_id, $page_snapshot, (int) $template_id, $owner, true );
			}
		}

		$final = LoopReadbackVerifier::verify( ElementorData::read( $post_id ), $compiled['expected_readback'] );
		if ( $final instanceof \WP_Error ) {
			return self::rollback_error( $final, $post_id, $page_snapshot, (int) $template_id, $owner, $created_template );
		}
		if ( $created_template ) {
			$finalized = TemplateStore::finalize_staged( (int) $template_id, $owner );
			if ( $finalized instanceof \WP_Error || ! $finalized ) {
				return self::rollback_error( self::phase_error( 'template_finalize' ), $post_id, $page_snapshot, (int) $template_id, $owner, true );
			}
		}

		$response = self::success_response( $plan, $compiled, $readback, (int) $template_id, $created_template, $page_snapshot );
		IdempotencyStore::remember( $post_id, (string) $args['idempotency_key'], $request_hash, $response );
		return $response;
	} finally {
		PostWriteLock::release( $post_id, $owner );
		self::$fail_at = null;
	}
}
```

`plan()` must validate the existing page, explicit V3-only parent, expected
hash, existing loop-item template or validated/rendered `template_spec`, live
schema mapping, and query probe before any persistent write.

For `template_spec` dry-runs, use template ID `1` only as a private
schema-validation placeholder, report `template_id: 0` plus
`template_id_source: transaction_created` to the caller, and compile again
with the actual staged template ID during apply. Never persist or expose the
placeholder as a real template reference.

`stage_template()` must:

1. call `TemplateStore::create_staged()`;
2. call `Backup::snapshot_post()` before writing rendered Elementor data;
3. use `ElementorData::write()`;
4. verify template tree hash;
5. return the ID only after successful readback.

If template write or template readback fails, `stage_template()` must call
`TemplateStore::delete_staged()` with the same owner before returning its
phase error.

`rollback_error()` must restore the page when a snapshot exists and delete only
a template for which `TemplateStore::delete_staged()` confirms the same owner.
Publication retains the private owner marker until final page/template
verification. `TemplateStore::finalize_staged()` clears it only after all
readbacks pass, so post-publication rollback remains owner-checkable.

Successful responses must include:

```php
[
	'execution_status' => 'applied',
	'verification_status' => 'verified',
	'rollback_status' => 'not_required',
	'effect_verified' => true,
]
```

Every transaction error must include `transaction_phase`, `mutation_state`,
`rollback_status`, `retryable`, and one bounded `repair` instruction. Add tests
that these fields exist for every injected failure phase.

- [ ] **Step 5: Prove one page write and scoped invalidation**

Add counters to the existing Elementor test stubs and assert:

```php
self::assertSame( 1, $GLOBALS['stonewright_test_elementor_write_count'][9049] ?? 0 );
self::assertSame( [ 9049, $result['template_id'] ], array_values( array_unique( $GLOBALS['stonewright_test_css_invalidations'] ?? [] ) ) );
self::assertSame( 0, $GLOBALS['stonewright_test_global_css_clear_count'] ?? 0 );
```

- [ ] **Step 6: Run transaction tests**

```bash
cd plugin
composer test -- --filter 'LoopTransactionTest|PostCacheInvalidatorTest|TemplateStoreStagingTest'
```

Expected: PASS for both template paths, both widget types, dry-run, lock,
idempotency, every rollback phase, exact readback failures, and cache scope.

- [ ] **Step 7: Commit**

```bash
git add plugin/includes/Elementor/Loop/LoopTransaction.php \
  plugin/includes/ThemeBuilder/TemplateStore.php \
  plugin/tests/Unit/Elementor/Loop/LoopTransactionTest.php \
  plugin/tests/bootstrap.php
git commit -m "feat: add transactional Elementor loop orchestration"
```

### Task 7: Public wire-loop ability and tool routing

**Files:**
- Create: `plugin/includes/Abilities/ElementorV3/WireLoop.php`
- Create: `plugin/tests/Unit/ElementorV3/WireLoopTest.php`
- Modify: `plugin/includes/Core/AbilityRegistry.php`
- Modify: `plugin/includes/Abilities/System/ToolProfile.php`
- Modify: `plugin/includes/Security/Permissions.php`
- Modify: `plugin/tests/Unit/Core/AbilityRegistryEssentialModeTest.php`
- Modify: `plugin/tests/Unit/WorkflowEfficiencyAbilitiesTest.php`
- Modify: `plugin/tests/Unit/AbilityKernelAuditTest.php`
- Modify: `plugin/tests/Integration/ContractTest.php`

**Interfaces:**
- Produces WordPress ability `stonewright/elementor-wire-loop`.
- Produces MCP tool `stonewright-elementor-wire-loop`.
- Delegates execution only to `LoopTransaction::run()`.

- [ ] **Step 1: Write ability contract and permission tests**

```php
public function test_contract_requires_exactly_one_template_source_at_execution(): void {
	$ability = new WireLoop();
	$missing = $ability->execute( self::baseArgs() );
	self::assertInstanceOf( \WP_Error::class, $missing );
	self::assertSame( 'stonewright_loop_template_source_invalid', $missing->get_error_code() );

	$both = $ability->execute(
		self::baseArgs() + [
			'template_id' => 77,
			'template_spec' => self::validTemplateSpec(),
		]
	);
	self::assertInstanceOf( \WP_Error::class, $both );
	self::assertSame( 'stonewright_loop_template_source_invalid', $both->get_error_code() );
}

public function test_permission_checks_page_and_template_capabilities(): void {
	$ability = new WireLoop();
	$result = $ability->permission_callback(
		[
			'post_id' => 9049,
			'template_id' => 77,
		]
	);
	self::assertTrue( $result );
	self::assertSame( [ 9049, 77 ], $GLOBALS['stonewright_test_edit_post_checks'] );
}

public function test_template_spec_requires_confirmation_in_production_safe_mode(): void {
	update_option( 'stonewright_mode', 'production-safe' );
	$result = ( new WireLoop() )->execute(
		self::baseArgs() + [ 'template_spec' => self::validTemplateSpec() ]
	);
	self::assertInstanceOf( \WP_Error::class, $result );
	self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
}
```

- [ ] **Step 2: Run and confirm failure**

```bash
cd plugin
composer test -- --filter WireLoopTest
```

Expected: FAIL because the ability is missing.

- [ ] **Step 3: Implement the ability boundary**

The input schema must use `additionalProperties: false`, require
`post_id`, `parent_id`, `display`, `post_type`, `idempotency_key`, and
`dry_run`, and define the compact query/responsive/navigation fields from the
approved spec.

```php
final class WireLoop extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-wire-loop';
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$page = Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( $page instanceof \WP_Error || ! $page ) {
			return $page;
		}
		if ( ! empty( $args['template_id'] ) ) {
			return Permissions::edit_post( (int) $args['template_id'] );
		}
		return Permissions::can_create_post_type( 'elementor_library' )
			&& Permissions::can_publish_post_type( 'elementor_library' );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$has_id = (int) ( $args['template_id'] ?? 0 ) > 0;
				$has_spec = is_array( $args['template_spec'] ?? null );
				if ( $has_id === $has_spec ) {
					return $this->error(
						'loop_template_source_invalid',
						__( 'Provide exactly one of template_id or template_spec.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}
				if ( empty( $args['dry_run'] ) && $has_spec ) {
					$verify_args = array_filter(
						$args,
						static fn( string $key ): bool => 'confirmation_token' !== $key,
						ARRAY_FILTER_USE_KEY
					);
					$token_error = $this->confirmation_token_error( $args, $verify_args );
					if ( null !== $token_error ) {
						return $token_error;
					}
				}
				return LoopTransaction::run( $args );
			}
		);
	}
}
```

The confirmation check is bound to the full request before a transaction may
create and later delete a staged template. Existing-template additive wiring
does not request a destructive confirmation token.

Add this helper to `Permissions` so capability policy stays centralized:

```php
public static function can_publish_post_type( string $post_type ): bool {
	$capability = self::publish_cap_for_status( $post_type, 'publish' );
	return null !== $capability && current_user_can( $capability );
}
```

- [ ] **Step 4: Register and route the ability**

Add `WireLoop::class` to `AbilityRegistry::list()`, add
`stonewright/elementor-wire-loop` to `essential_ability_names()`, and include
it in the Elementor Pro/loop tool profiles. Add this exact tool description:

```php
'stonewright/elementor-wire-loop' =>
	'Plan or transactionally add a native Elementor Pro Loop Carousel or Loop Grid using an existing loop-item template or a validated template spec.',
```

- [ ] **Step 5: Run contract and routing tests**

```bash
cd plugin
composer test -- --filter 'WireLoopTest|AbilityRegistryEssentialModeTest|WorkflowEfficiencyAbilitiesTest|AbilityKernelAuditTest|ContractTest'
```

Expected: PASS; the MCP name is `stonewright-elementor-wire-loop`, the ability
is visible in essential mode, and write audit/context gates remain active.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/WireLoop.php \
  plugin/includes/Core/AbilityRegistry.php \
  plugin/includes/Abilities/System/ToolProfile.php \
  plugin/includes/Security/Permissions.php \
  plugin/tests/Unit/ElementorV3/WireLoopTest.php \
  plugin/tests/Unit/Core/AbilityRegistryEssentialModeTest.php \
  plugin/tests/Unit/WorkflowEfficiencyAbilitiesTest.php \
  plugin/tests/Unit/AbilityKernelAuditTest.php \
  plugin/tests/Integration/ContractTest.php
git commit -m "feat: expose safe Elementor wire-loop ability"
```

### Task 8: Controlled schema-repair learning

**Files:**
- Create: `plugin/includes/Knowledge/Lifecycle/SchemaRepairIncidentStore.php`
- Create: `plugin/includes/Knowledge/Lifecycle/SchemaRepairLearning.php`
- Create: `plugin/tests/Unit/Knowledge/SchemaRepairLearningTest.php`
- Modify: `plugin/includes/Context/ContextToken.php`
- Modify: `plugin/includes/Knowledge/Lifecycle/CandidateRepository.php`
- Modify: `plugin/tests/Unit/Knowledge/KnowledgeLifecycleTest.php`

**Interfaces:**
- Produces: `ContextToken::task_hash(string $token, string $ability_name): string|\WP_Error`.
- Produces: `SchemaRepairLearning::observe_failure(string $widget, array $attempted_settings, \WP_Error $error, string $task_hash): array`.
- Produces: `SchemaRepairLearning::observe_verified(string $widget, array $verified_settings, array $schema, string $task_hash): list<array<string,mixed>>`.
- Incident retention: seven days; maximum 200 total and 20 per runtime fingerprint.

- [ ] **Step 1: Write learning-gate tests**

```php
public function test_failure_alone_never_creates_candidate(): void {
	$error = new \WP_Error(
		'stonewright_elementor_settings_invalid',
		'Invalid control.',
		[
			'violations' => [
				[
					'path' => 'slides_to_show',
					'expected' => 'number',
					'received' => 'object',
				],
			],
			'schema_hash' => str_repeat( 'a', 64 ),
		]
	);
	SchemaRepairLearning::observe_failure(
		'loop-carousel',
		[ 'slides_to_show' => [ 'size' => 3 ] ],
		$error,
		str_repeat( '1', 64 )
	);
	self::assertSame( [], CandidateRepository::list( [ 'topic' => 'Elementor schema repair: loop-carousel/slides_to_show' ] ) );
}

public function test_two_distinct_verified_tasks_promote_one_deduplicated_candidate(): void {
	self::recordFailure( 'loop-carousel', 'slides_to_show' );
	$schema = self::schema();
	$first = SchemaRepairLearning::observe_verified(
		'loop-carousel',
		[ 'slides_to_show' => 3 ],
		$schema,
		str_repeat( '1', 64 )
	);
	$repeat = SchemaRepairLearning::observe_verified(
		'loop-carousel',
		[ 'slides_to_show' => 3 ],
		$schema,
		str_repeat( '1', 64 )
	);
	$second = SchemaRepairLearning::observe_verified(
		'loop-carousel',
		[ 'slides_to_show' => 4 ],
		$schema,
		str_repeat( '2', 64 )
	);

	self::assertSame( 1, $first[0]['candidate']['verification_count'] );
	self::assertSame( 1, $repeat[0]['candidate']['verification_count'] );
	self::assertSame( 'approved', $second[0]['candidate']['status'] );
	self::assertCount( 1, CandidateRepository::list( [ 'topic' => 'Elementor schema repair: loop-carousel/slides_to_show' ] ) );
}
```

Also cover explicit approval, incompatible schema fingerprint, conflicting
recipes, no user values in stored fact/recipe, retention pruning, and the
twenty-per-fingerprint limit.

- [ ] **Step 2: Run and confirm failure**

```bash
cd plugin
composer test -- --filter 'SchemaRepairLearningTest|KnowledgeLifecycleTest'
```

Expected: FAIL because the learning services and task hash accessor are absent.

- [ ] **Step 3: Expose only the verified task hash**

Refactor token loading into a private verified-claims method, keep
`ContextToken::verify()` behavior unchanged, and add:

```php
public static function task_hash( string $token, string $ability_name ): string|\WP_Error {
	$claims = self::verified_claims( $token, $ability_name );
	if ( $claims instanceof \WP_Error ) {
		return $claims;
	}
	$task_hash = (string) ( $claims['task_hash'] ?? '' );
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $task_hash ) ) {
		return self::error();
	}
	return $task_hash;
}
```

Never return raw task text or the token transient.

- [ ] **Step 4: Implement the bounded incident store**

Store only widget, control, expected type, received type, schema hash, runtime
fingerprint, task hash, and timestamps:

```php
final class SchemaRepairIncidentStore {
	private const OPTION = 'stonewright_schema_repair_incidents';
	private const TTL = 7 * DAY_IN_SECONDS;
	private const MAX_TOTAL = 200;
	private const MAX_PER_RUNTIME = 20;

	public static function record( array $incident ): void {
		$rows = self::prune( (array) get_option( self::OPTION, [] ) );
		$key = hash(
			'sha256',
			implode(
				'|',
				[
					(string) $incident['widget'],
					(string) $incident['control'],
					(string) $incident['schema_hash'],
					(string) $incident['received_type'],
				]
			)
		);
		$rows[ $key ] = array_merge(
			$incident,
			[ 'recorded_at' => time(), 'expires_at' => time() + self::TTL ]
		);
		update_option( self::OPTION, self::limit( $rows ), false );
	}

	public static function matching(
		string $widget,
		string $control,
		string $schema_hash
	): array {
		return array_values(
			array_filter(
				self::prune( (array) get_option( self::OPTION, [] ) ),
				static fn( array $row ): bool =>
					(string) $row['widget'] === $widget
					&& (string) $row['control'] === $control
					&& hash_equals( (string) $row['schema_hash'], $schema_hash )
			)
		);
	}
}
```

- [ ] **Step 5: Create and verify candidates only after readback**

```php
public static function observe_verified(
	string $widget,
	array $verified_settings,
	array $schema,
	string $task_hash
): array {
	$results = [];
	foreach ( $verified_settings as $control => $value ) {
		$incidents = SchemaRepairIncidentStore::matching(
			$widget,
			(string) $control,
			(string) ( $schema['schema_hash'] ?? '' )
		);
		if ( [] === $incidents ) {
			continue;
		}
		$input = self::candidate_input(
			$widget,
			(string) $control,
			self::value_type( $value ),
			$schema
		);
		$candidate = CandidateRepository::create( $input );
		if ( $candidate instanceof \WP_Error ) {
			continue;
		}
		$verified = CandidateRepository::verify(
			(int) $candidate['id'],
			$task_hash,
			(string) $schema['runtime_fingerprint'],
			true
		);
		if ( $verified instanceof \WP_Error ) {
			continue;
		}
		if ( (int) $verified['verification_count'] >= 2 ) {
			$promoted = CandidateRepository::promote( (int) $verified['id'], false, '' );
			$verified = $promoted instanceof \WP_Error
				? $verified
				: (array) $promoted['candidate'];
		}
		$results[] = [ 'candidate' => $verified, 'control' => (string) $control ];
	}
	return $results;
}
```

The candidate recipe must say only that a named control expects a named type
under the exact schema fingerprint. It must not serialize `$value`, titles,
URLs, query filters, post content, or media identifiers.

- [ ] **Step 6: Add repository pruning**

Add `CandidateRepository::prune(int $max_total = 500, int $max_per_topic = 25): int`
using prepared SQL. Delete only expired `candidate`, `verified`, `stale`, and
`rejected` rows; never delete `approved` rows automatically. Call it after
candidate creation.

- [ ] **Step 7: Run learning tests**

```bash
cd plugin
composer test -- --filter 'SchemaRepairLearningTest|KnowledgeLifecycleTest|ContextToken'
```

Expected: PASS; one task cannot increase the verification count twice, a
second distinct task promotes, and no failure-only record is agentic.

- [ ] **Step 8: Commit**

```bash
git add plugin/includes/Knowledge/Lifecycle/SchemaRepairIncidentStore.php \
  plugin/includes/Knowledge/Lifecycle/SchemaRepairLearning.php \
  plugin/includes/Context/ContextToken.php \
  plugin/includes/Knowledge/Lifecycle/CandidateRepository.php \
  plugin/tests/Unit/Knowledge/SchemaRepairLearningTest.php \
  plugin/tests/Unit/Knowledge/KnowledgeLifecycleTest.php
git commit -m "feat: learn only from verified Elementor repairs"
```

### Task 9: Connect learning to typed writes and task-start

**Files:**
- Modify: `plugin/includes/Abilities/ElementorV3/BatchMutate.php`
- Modify: `plugin/includes/Elementor/Loop/LoopTransaction.php`
- Modify: `plugin/includes/Context/ContextBuilder.php`
- Modify: `plugin/includes/Elementor/Schema/RuntimeFingerprint.php`
- Modify: `plugin/tests/Unit/ElementorV3/BatchMutateTest.php`
- Modify: `plugin/tests/Unit/Elementor/Loop/LoopTransactionTest.php`
- Modify: `plugin/tests/Unit/Elementor/Schema/WidgetSchemaRepositoryTest.php`
- Modify: `plugin/tests/Unit/Context/ContextBootstrapTest.php`

**Interfaces:**
- Consumes `stonewright_context_token` already added by the registry to write abilities.
- Emits bounded `learning` result rows containing candidate ID/status only.

- [ ] **Step 1: Write integration tests**

```php
public function test_schema_failure_then_verified_readback_records_candidate(): void {
	$token = ContextToken::issue( 'repair loop carousel settings' )['token'];
	$failed = $this->batch(
		[
			'stonewright_context_token' => $token,
			'settings' => [ 'slides_to_show' => [ 'size' => 3 ] ],
		]
	);
	self::assertInstanceOf( \WP_Error::class, $failed );

	$passed = $this->batch(
		[
			'stonewright_context_token' => $token,
			'settings' => [ 'slides_to_show' => 3 ],
		]
	);
	self::assertIsArray( $passed );
	self::assertSame( 'candidate', $passed['learning'][0]['status'] );
	self::assertSame( 1, $passed['learning'][0]['verification_count'] );
}

public function test_pending_candidate_is_absent_from_task_start(): void {
	$this->createCandidateWithOneVerification();
	$context = ContextBuilder::build( 'wire an Elementor carousel', 'elementor', 'write' );
	self::assertNotContains(
		'draft-elementor-schema-repair-loop-carousel-slides-to-show',
		array_column( $context['matched_skills'], 'slug' )
	);
}

public function test_runtime_constraints_require_every_component_clause(): void {
	self::assertTrue(
		RuntimeFingerprint::matches_constraints(
			[
				'elementor_core' => '>=3.20 <4.0',
				'elementor_pro' => '>=3.20',
			]
		)
	);
	self::assertFalse(
		RuntimeFingerprint::matches_constraints(
			[ 'elementor_core' => '>=4.0' ]
		)
	);
}
```

Add a companion test proving an approved, matching candidate is present and an
approved but incompatible/non-matching candidate is absent.

- [ ] **Step 2: Run and confirm failures**

```bash
cd plugin
composer test -- --filter 'BatchMutateTest|LoopTransactionTest|ContextBootstrapTest'
```

Expected: FAIL because no write path emits learning observations.

- [ ] **Step 3: Observe failures at the compiler boundary**

When compilation returns a settings error, derive the task hash from the
already-verified context token and call:

```php
$task_hash = ContextToken::task_hash(
	(string) ( $args['stonewright_context_token'] ?? '' ),
	$this->name()
);
if ( ! $task_hash instanceof \WP_Error ) {
	SchemaRepairLearning::observe_failure(
		(string) ( $operation['widget_type'] ?? '' ),
		(array) ( $operation['settings'] ?? [] ),
		$compiled,
		$task_hash
	);
}
```

Place shared extraction in `SchemaRepairLearning::observe_compilation_error()`
so BatchMutate and LoopTransaction do not duplicate violation parsing.

- [ ] **Step 4: Observe success only after readback**

After hash and structural readback pass:

```php
$learning = [];
if ( ! $task_hash instanceof \WP_Error ) {
	foreach ( $verified_widget_writes as $write ) {
		$schema = WidgetSchemaRepository::get( (string) $write['widget_type'] );
		if ( is_array( $schema ) ) {
			$learning = array_merge(
				$learning,
				SchemaRepairLearning::observe_verified(
					(string) $write['widget_type'],
					(array) $write['settings'],
					$schema,
					$task_hash
				)
			);
		}
	}
}
$response['learning'] = array_map(
	static fn( array $row ): array => [
		'id' => (int) $row['candidate']['id'],
		'status' => (string) $row['candidate']['status'],
		'verification_count' => (int) $row['candidate']['verification_count'],
	],
	$learning
);
```

Do not call `observe_verified()` for dry runs, failed writes, restored writes,
or mismatched readbacks.

- [ ] **Step 5: Keep task-start injection bounded**

Continue using `Skills::list_agentic()` so only promoted active skills qualify.
Add `RuntimeFingerprint::matches_constraints(array $constraints): bool` using
the current `describe()['components']` values and whitespace-separated
comparison clauses (`>=`, `>`, `=`, `<`, `<=`). In
`ContextBuilder::matched_skills()`, require runtime compatibility for
candidate-source skills and return no more than two learned schema repairs
inside the existing five-skill cap:

```php
if ( 'candidate' === (string) ( $skill['source'] ?? '' ) ) {
	$constraints = (array) ( $skill['version_constraints'] ?? [] );
	if ( ! RuntimeFingerprint::matches_constraints( $constraints ) ) {
		continue;
	}
}
```

Keep the public task-start payload at the existing slug/title/description
shape, and put the compact control/type recipe in the existing matched
playbook content. Do not add a database migration for duplicated candidate
metadata.

- [ ] **Step 6: Run focused integration tests**

```bash
cd plugin
composer test -- --filter 'BatchMutateTest|LoopTransactionTest|SchemaRepairLearningTest|ContextBootstrapTest'
```

Expected: PASS with learning absent on failures/dry-runs and present only after
verified writes.

- [ ] **Step 7: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/BatchMutate.php \
  plugin/includes/Elementor/Loop/LoopTransaction.php \
  plugin/includes/Knowledge/Lifecycle/SchemaRepairLearning.php \
  plugin/includes/Elementor/Schema/RuntimeFingerprint.php \
  plugin/includes/Context/ContextBuilder.php \
  plugin/tests/Unit/ElementorV3/BatchMutateTest.php \
  plugin/tests/Unit/Elementor/Loop/LoopTransactionTest.php \
  plugin/tests/Unit/Elementor/Schema/WidgetSchemaRepositoryTest.php \
  plugin/tests/Unit/Context/ContextBootstrapTest.php
git commit -m "feat: connect verified repair learning to Elementor writes"
```

### Task 10: Version, public docs, generated contracts, and release notes

**Files:**
- Modify: `plugin/stonewright.php`
- Modify: `plugin/README.md`
- Modify: `plugin/CHANGELOG.md`
- Modify: `companion/package.json`
- Modify: `companion/package-lock.json`
- Modify: `companion/src/version.ts`
- Modify: `companion/README.md`
- Modify: `companion/CHANGELOG.md`
- Create: `companion/tests/version.test.ts`
- Create: `plugin/tests/Unit/ReleaseVersionTest.php`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/architecture.md`
- Modify: `docs/elementor-v3-editor-adapter.md`
- Modify: `docs/install-prompts.md`
- Modify: `docs/installation.md`
- Modify: `docs/roadmap.md`
- Create: `docs/releases/1.0.0-alpha.84.md`
- Modify: `skills/stonewright/SKILL.md`
- Modify: `skills/elementor-v3-builder/SKILL.md`
- Regenerate: `docs/ability-truth-matrix.md`
- Regenerate: `docs/contracts/public-api-v1.json`
- Regenerate: `docs/contracts/direct-tools-v1.json`

**Interfaces:**
- Public version: `1.0.0-alpha.84`.
- Public MCP tool: `stonewright-elementor-wire-loop`.

- [ ] **Step 1: Add failing version and contract assertions**

Create `plugin/tests/Unit/ReleaseVersionTest.php`:

```php
public function test_plugin_metadata_is_alpha_84(): void {
	$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/stonewright.php' );
	self::assertStringContainsString( 'Version: 1.0.0-alpha.84', $source );
	self::assertStringContainsString(
		"define( 'STONEWRIGHT_VERSION', '1.0.0-alpha.84' );",
		$source
	);
}
```

Create `companion/tests/version.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { APP_VERSION } from '../src/version.js';

describe('release version', () => {
	it('reports alpha.84', () => {
		expect(APP_VERSION).toBe('1.0.0-alpha.84');
	});
});
```

- [ ] **Step 2: Run and confirm version failure**

```bash
cd plugin
composer test -- --filter ReleaseVersionTest
cd ../companion
npm test -- tests/version.test.ts
```

Expected: plugin version assertion and companion version assertion fail on
alpha.83.

- [ ] **Step 3: Bump metadata and write release documentation**

Set alpha.84 in the exact metadata files listed above. Document:

- one-call Loop Carousel and Loop Grid planning/wiring;
- existing-template and staged-template paths;
- dry-run, V3-parent restriction, lock, idempotency, readback, and rollback;
- empty-query warning versus `require_results`;
- failure-only incidents never becoming instructions;
- two distinct verified tasks or explicit approval before activation;
- Direct parity and Figma token mapping remaining future releases.

Evergreen download URLs must keep the `VERSION` placeholder.

- [ ] **Step 4: Regenerate contracts**

```bash
cd plugin
composer docs:matrix
composer contracts:generate
cd ../companion
npm run contracts:generate
npm run build:contracts
```

- [ ] **Step 5: Validate docs**

```bash
node scripts/check-docs-freshness.mjs
git diff --check
```

Expected: both exit 0, with no stale ability counts or generated artifacts.

- [ ] **Step 6: Commit**

```bash
git add plugin/stonewright.php plugin/README.md plugin/CHANGELOG.md \
  plugin/tests/Unit/ReleaseVersionTest.php \
  companion/package.json companion/package-lock.json companion/src/version.ts \
  companion/README.md companion/CHANGELOG.md companion/tests/version.test.ts \
  README.md CHANGELOG.md \
  docs/architecture.md docs/elementor-v3-editor-adapter.md \
  docs/install-prompts.md docs/installation.md docs/roadmap.md \
  docs/releases/1.0.0-alpha.84.md docs/ability-truth-matrix.md \
  docs/contracts/public-api-v1.json docs/contracts/direct-tools-v1.json \
  skills/stonewright/SKILL.md \
  skills/elementor-v3-builder/SKILL.md
git commit -m "docs: prepare Stonewright alpha.84"
```

### Task 11: Full local validation and security review

**Files:**
- Modify only files required by concrete validation failures.

**Interfaces:**
- Produces a clean, fully validated alpha.84 source tree.

- [ ] **Step 1: Run complete plugin validation**

```bash
cd plugin
composer install
composer test
composer phpstan
composer phpcs
composer security:audit
composer dependencies:audit
```

Expected: every command exits 0.

- [ ] **Step 2: Run complete companion validation**

```bash
cd companion
npm install
npm run typecheck
npm test
npm run build
```

Expected: every command exits 0.

- [ ] **Step 3: Run visual, contract, docs, and repository checks**

```bash
cd ../visual
npm install
npm run typecheck
npm test
npm run build
cd ..
node scripts/check-docs-freshness.mjs
git diff --check
git status --short
```

Expected: visual tests pass, docs are fresh, whitespace is clean, and status
contains only intentional changes.

- [ ] **Step 4: Review WordPress security contracts**

Run the `wp-code-review`, `wp-security-review`, and `wp-plugin-standards`
skills against the alpha.84 diff. Resolve every finding that affects:

- permissions;
- context or confirmation tokens;
- snapshots and rollback;
- direct database queries;
- sanitization/escaping;
- transaction ownership;
- candidate privacy;
- raw Elementor write guards.

- [ ] **Step 5: Re-run affected suites after every fix**

Use the narrow test first, then repeat Steps 1–3 after the last fix.

- [ ] **Step 6: Commit validation fixes**

```bash
git add -u
git commit -m "fix: harden alpha.84 release candidate"
```

If validation required no code changes, do not create an empty commit.

### Task 12: Build artifacts and verify their contents

**Files:**
- Create: `dist/stonewright-1.0.0-alpha.84.zip`
- Create: `dist/stonewright-companion-1.0.0-alpha.84.tgz`
- Create: `dist/SHA256SUMS.txt`

**Interfaces:**
- Produces clean, installable plugin and companion archives.

- [ ] **Step 1: Read and follow packaging skills**

Use `wp-plugin-packaging` and the repository release scripts. Do not package
the worktree root manually.

- [ ] **Step 2: Build both artifacts**

Run the same packaging shape as `.github/workflows/release.yml`:

```bash
cd plugin
composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
cd ../companion
npm ci
npm run typecheck
npm test
npm run tokens:measure
npm run build
cd ..
mkdir -p dist/stonewright
rsync -a plugin/ dist/stonewright/ \
  --exclude tests \
  --exclude bin \
  --exclude composer.json \
  --exclude composer.lock \
  --exclude .phpunit.cache \
  --exclude .phpunit.result.cache \
  --exclude phpstan.neon \
  --exclude phpcs.xml \
  --exclude phpunit.xml \
  --exclude phpunit.xml.dist
(cd dist && zip -qr stonewright-1.0.0-alpha.84.zip stonewright)
(cd companion && npm pack --pack-destination ../dist)
```

Expected files:

```text
dist/stonewright-1.0.0-alpha.84.zip
dist/stonewright-companion-1.0.0-alpha.84.tgz
```

- [ ] **Step 3: Inspect archive contents**

```bash
unzip -l dist/stonewright-1.0.0-alpha.84.zip
tar -tzf dist/stonewright-companion-1.0.0-alpha.84.tgz
```

Expected:

- plugin ZIP has one `stonewright/` root;
- runtime vendor/autoload files are present;
- companion `dist/`, `package.json`, and runtime files are present;
- no `.git`, tests, worktrees, local configs, credentials, caches, or source
  maps are present unless the existing package contract explicitly includes
  them.

- [ ] **Step 4: Generate and verify checksums**

```bash
shasum -a 256 \
  dist/stonewright-1.0.0-alpha.84.zip \
  dist/stonewright-companion-1.0.0-alpha.84.tgz \
  > dist/SHA256SUMS.txt
shasum -a 256 -c dist/SHA256SUMS.txt
```

Expected: both files report `OK`.

### Task 13: Refresh local plugin and companion, then verify live

**Files:**
- No source edits unless live verification exposes a reproducible defect.

**Interfaces:**
- Produces a local WordPress and MCP runtime both reporting alpha.84.

- [ ] **Step 1: Install the plugin through the configured WordPress MCP**

Use `claudeus-wp-mcp` to upload/update
`dist/stonewright-1.0.0-alpha.84.zip`, activate Stonewright if required, and
read back the installed plugin version. Do not use raw REST or shell `wp`.

- [ ] **Step 2: Refresh the local companion**

Install `dist/stonewright-companion-1.0.0-alpha.84.tgz` into the existing local
Stonewright companion configuration while preserving environment-only
credentials. Restart/reload the MCP client after installation.

- [ ] **Step 3: Verify canonical startup**

Call `stonewright-task-start` first with an Elementor loop task. Stop live
claims if that tool is absent. Confirm:

- `startup_ready: true`;
- plugin and companion report `1.0.0-alpha.84`;
- `stonewright-elementor-wire-loop` is visible in the activated Elementor
  profile;
- V4 toggle status remains unchanged from the saved dashboard option.

- [ ] **Step 4: Run controlled live proof**

On an explicitly safe local test page/template:

1. read the target page structure;
2. select an existing V3 parent;
3. call wire-loop with `dry_run: true`;
4. verify the resolved live controls and bounded diff;
5. apply with a unique idempotency key;
6. read back page and template through typed Elementor tools;
7. reopen Elementor editor and compare load behavior;
8. replay the same idempotency key and confirm no duplicate widget;
9. run a controlled rollback-path test only on a transaction-created test
   template;
10. confirm unrelated page CSS was not invalidated.

Use `elementor-mcp-*` for the design tree and `claudeus-wp-mcp` for WordPress
site/plugin health. Never substitute raw REST, direct post meta, or shell
scripts.

- [ ] **Step 5: Repair any live defect with TDD**

For each defect, add a failing fixture/test that reproduces the exact runtime
schema or transaction phase, implement the smallest fix, rerun Tasks 11–13,
and rebuild both artifacts/checksums.

### Task 14: Push, pull request, green CI, merge, and final artifacts

**Files:**
- Modify only files required by CI failures.

**Interfaces:**
- Produces a merged, green alpha.84 change and artifacts matching merged source.

- [ ] **Step 1: Verify branch state**

```bash
git status --short
git log --oneline --decorate -12
git diff origin/main...HEAD --check
```

Expected: clean worktree and only alpha.84 commits on
`codex/alpha84-wire-loop-learning`.

- [ ] **Step 2: Push the topic branch**

```bash
git push -u origin codex/alpha84-wire-loop-learning
```

Expected: push succeeds without force.

- [ ] **Step 3: Open a ready pull request**

The PR body must list:

- changed ability: `stonewright/elementor-wire-loop`;
- BatchMutate compiler/lock/learning changes;
- backup, permission, context-token, confirmation-token, validation, audit,
  idempotency, readback, and rollback impact;
- public docs changed;
- local plugin, companion, archive, checksum, and live verification evidence;
- Direct parity and Figma tokens explicitly excluded.

```bash
gh pr create \
  --base main \
  --head codex/alpha84-wire-loop-learning \
  --title "feat: add transactional Elementor loop wiring" \
  --body-file /tmp/stonewright-alpha84-pr.md
```

- [ ] **Step 4: Wait for every required check**

```bash
gh pr checks --watch --fail-fast=false
```

Expected: every required check is green. A pending, skipped-required, cancelled,
or red check blocks merge.

- [ ] **Step 5: Fix failures at their root**

For each failure:

1. read the exact job log;
2. reproduce locally;
3. add or correct the narrow test;
4. implement the fix;
5. run the narrow suite;
6. run Task 11;
7. commit and push;
8. wait for the full PR check set again.

- [ ] **Step 6: Merge only when all checks are green**

```bash
gh pr merge --merge --delete-branch
```

Expected: merge succeeds and reports a merge commit on `main`.

- [ ] **Step 7: Rebuild from merged source**

Fetch `origin/main`, create or use a clean release worktree at the exact merge
commit, rerun Task 11, rebuild Task 12 artifacts, and verify checksums again.
This prevents a ZIP/TGZ built from the pre-merge parent from being presented as
the final release artifact.

- [ ] **Step 8: Final report**

Report:

- PR URL and merge commit;
- all required CI checks and their final green state;
- plugin ZIP and companion TGZ absolute paths;
- both SHA-256 values;
- local plugin and companion versions;
- `stonewright-task-start` readiness;
- live carousel/grid proof and editor-load observation;
- any intentionally deferred Direct/Figma work.
