<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\ImportReview;
use Stonewright\WpMcp\SkillLibrary\MutationBoundary;
use Stonewright\WpMcp\SkillLibrary\PackInventory;
use Stonewright\WpMcp\SkillLibrary\PackRefresh;
use Stonewright\WpMcp\SkillLibrary\Repository;
use Stonewright\WpMcp\SkillLibrary\SkillCatalog;
use Stonewright\WpMcp\SkillLibrary\VisibilityRules;

/** @covers \Stonewright\WpMcp\SkillLibrary\SkillCatalog @covers \Stonewright\WpMcp\SkillLibrary\LifecycleDecisions */
final class SkillCatalogTest extends TestCase {

	private const IMPORT = "---\nname: Example\ndescription: Use when writing examples.\n---\n# Import\n";

	private TestRepository $repository;
	private TestBoundary $boundary;
	private SkillCatalog $catalog;

	protected function setUp(): void {
		$this->repository = new TestRepository();
		$this->boundary = new TestBoundary();
		$this->catalog = new SkillCatalog( $this->repository, $this->boundary, static fn( array $constraints ): bool => true );
	}

	private function example( array $changes = [] ): array {
		return $changes + [ 'slug' => 'example', 'title' => 'Example', 'description' => 'Use when writing examples.', 'content' => '# First', 'source' => 'user', 'enabled' => true, 'enable_agentic' => true, 'enable_prompt' => false, 'status' => 'active' ];
	}

	public function test_update_archives_the_exact_previous_record(): void {
		$id = $this->catalog->store( $this->example( [ 'private_extension' => [ 'synthetic' => true ] ] ) );
		$this->assertSame( 1, $id );
		$first = $this->catalog->identify( 1 );
		$this->assertSame( 1, $this->catalog->store( [ 'slug' => 'example', 'title' => 'Updated', 'content' => '# Second' ] ) );
		$this->assertSame( $first, $this->catalog->revisions( 'example' )[0] );
		$this->assertSame( [ 'synthetic' => true ], $this->catalog->lookup( 'example' )['private_extension'] );
		$this->assertSame( 2, $this->catalog->lookup( 'example' )['revision'] );
	}

	public function test_trash_excludes_every_agent_read_and_restore_is_disabled_draft(): void {
		$this->catalog->store( $this->example() );
		$this->assertTrue( $this->catalog->put_in_trash( 1 ) );
		$this->assertNull( $this->catalog->lookup( 'example' ) );
		$this->assertNull( $this->catalog->identify( 1 ) );
		$this->assertSame( [], $this->catalog->browse() );
		$this->assertSame( '', $this->catalog->instruction_index() );
		$this->assertFalse( $this->repository->rows[1]['enabled'] );
		$this->assertTrue( $this->catalog->recover( 1 ) );
		$restored = $this->catalog->lookup( 'example' );
		$this->assertSame( 'draft', $restored['status'] );
		$this->assertFalse( $restored['enabled'] );
		$this->assertFalse( $restored['enable_agentic'] );
		$this->assertFalse( $restored['enable_prompt'] );
	}

	public function test_builtin_records_are_protected_from_removal_and_content_edits(): void {
		$this->repository->rows[42] = $this->example( [ 'id' => 42, 'source' => 'builtin', 'revision' => 1 ] );
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->put_in_trash( 42 ) );
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->erase( 42 ) );
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->store( [ 'slug' => 'example', 'title' => 'Forged', 'content' => '# Forged' ] ) );
		$this->assertTrue( $this->catalog->set_exposure( 42, false ) );
		$this->assertSame( '# First', $this->repository->rows[42]['content'] );
	}

	public function test_gate_refusal_or_atomic_exchange_failure_preserves_record(): void {
		$this->catalog->store( $this->example() );
		$before = $this->repository->rows;
		$this->boundary->allowed = false;
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->put_in_trash( 1 ) );
		$this->assertSame( $before, $this->repository->rows );
		$this->boundary->allowed = true;
		$this->repository->reject_exchange = true;
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->store( [ 'slug' => 'example', 'title' => 'Updated', 'content' => '# Changed' ] ) );
		$this->assertSame( $before, $this->repository->rows );
		$this->assertSame( [], $this->catalog->revisions( 'example' ) );
	}

	public function test_permanent_deletion_requires_trash_and_delegates_token_gate(): void {
		$this->catalog->store( $this->example() );
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->erase( 1 ) );
		$this->catalog->put_in_trash( 1 );
		$this->boundary->allowed = false;
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->erase( 1, 'synthetic-confirmation' ) );
		$this->assertArrayHasKey( 1, $this->repository->rows );
		$this->boundary->allowed = true;
		$this->assertTrue( $this->catalog->erase( 1, 'synthetic-confirmation' ) );
		$this->assertSame( [], $this->repository->rows );
		$this->assertSame( [], $this->catalog->revisions( 'example' ) );
		$this->assertSame( 'destroy', $this->boundary->last_action );
		$this->assertSame( 'synthetic-confirmation', $this->boundary->last_token );
	}

	public function test_import_never_overwrites_and_rollback_restores_a_snapshot(): void {
		$this->catalog->store( $this->example() );
		$this->catalog->store( [ 'slug' => 'example', 'title' => 'Second', 'content' => '# Second' ] );
		$this->assertTrue( $this->catalog->restore_revision( 'example', 1 ) );
		$this->assertSame( '# First', $this->catalog->lookup( 'example' )['content'] );
		$this->assertSame( 3, $this->catalog->lookup( 'example' )['revision'] );
		$this->assertCount( 2, $this->catalog->revisions( 'example' ) );
		$review = \Stonewright\WpMcp\SkillLibrary\ImportReview::examine( 'example.md', "---\nname: Example\ndescription: Use when writing examples.\n---\n# Import\n" );
		$this->assertIsArray( $review );
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->import_review( $review ) );
		$this->assertSame( '# First', $this->catalog->lookup( 'example' )['content'] );
	}

	public function test_exposure_and_exports_use_logical_records_only(): void {
		$this->catalog->store( $this->example() );
		$this->assertCount( 1, $this->catalog->browse( false, 'agentic' ) );
		$this->assertSame( [], $this->catalog->browse( false, 'prompt' ) );
		$this->assertSame( [ 'example' ], array_column( $this->catalog->topic_matches( '' ), 'slug' ) );
		$markdown = $this->catalog->export_document( 1 );
		$this->assertIsString( $markdown );
		$this->assertStringContainsString( '# First', $markdown );
		$this->assertNotEmpty( $this->boundary->events );
		foreach ( $this->boundary->events as $event ) {
			$this->assertArrayNotHasKey( 'content', $event['summary'] );
			$this->assertArrayNotHasKey( 'confirmation_token', $event['summary'] );
		}
	}

	public function test_active_save_cannot_bypass_lint_and_missing_components_only_hide_it(): void {
		$result = $this->catalog->store( $this->example( [ 'description' => '' ] ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [], $this->repository->rows );
		$unavailable = new SkillCatalog( $this->repository, $this->boundary, static fn( array $constraints ): bool => false );
		$this->assertSame( 1, $unavailable->store( $this->example( [ 'version_constraints' => [ 'elementor' => 'required' ] ] ) ) );
		$this->assertTrue( $this->repository->rows[1]['enabled'] );
		$this->assertSame( [], $unavailable->browse( false, 'agentic' ) );
		$this->assertCount( 1, $unavailable->browse() );
	}

	/** @dataProvider every_source */
	public function test_missing_components_never_block_enabling( string $source ): void {
		$absent = static fn( array $constraints ): bool => false;
		$catalog = new SkillCatalog( $this->repository, $this->boundary, $absent );
		$this->repository->rows[6] = $this->example( [ 'id' => 6, 'revision' => 1, 'source' => $source, 'status' => 'draft', 'enabled' => false, 'version_constraints' => [ 'any_of' => 'acf|pods' ] ] );
		$this->assertTrue( $catalog->set_exposure( 6, true ) );
		$this->assertTrue( $this->repository->rows[6]['enabled'] );
		$this->assertSame( 'active', $this->repository->rows[6]['status'] );
		$this->assertSame( [], $catalog->browse( false, 'agentic' ) );
		$this->assertSame( [ 'acf|pods' ], VisibilityRules::missing( $this->repository->rows[6], $absent ) );
	}

	/** @dataProvider every_source */
	public function test_an_absent_provider_hides_a_skill_but_never_blocks_enabling_it( string $source ): void {
		$present = false;
		$compatible = static function ( array $constraints ) use ( &$present ): bool {
			foreach ( array_keys( $constraints ) as $component ) {
				if ( str_starts_with( (string) $component, 'provider:' ) ) {
					return $present;
				}
			}
			return true;
		};
		$catalog = new SkillCatalog( $this->repository, $this->boundary, $compatible );
		$this->repository->rows[7] = $this->example( [ 'id' => 7, 'revision' => 1, 'source' => $source, 'status' => 'draft', 'enabled' => false, 'version_constraints' => [ 'provider:elementor-native' => 'required' ] ] );

		$this->assertTrue( $catalog->set_exposure( 7, true ) );
		$this->assertTrue( $this->repository->rows[7]['enabled'] );
		$this->assertSame( 'active', $this->repository->rows[7]['status'] );
		$this->assertSame( [], $catalog->browse( false, 'agentic' ) );
		$this->assertCount( 1, $catalog->browse() );
		$this->assertSame( [ 'provider:elementor-native' ], VisibilityRules::missing( $this->repository->rows[7], $compatible ) );

		$present = true;
		$this->assertCount( 1, $catalog->browse( false, 'agentic' ) );
		$this->assertSame( [], VisibilityRules::missing( $this->repository->rows[7], $compatible ) );
	}

	public static function every_source(): array {
		return [ 'built-in' => [ 'builtin' ], 'playbook' => [ 'playbook' ], 'local' => [ 'user' ], 'imported' => [ 'uploaded' ], 'candidate' => [ 'candidate' ] ];
	}

	public function test_digit_leading_plugin_slugs_can_be_saved_and_enabled(): void {
		$constraints = [ '3d-viewer' => 'required', 'any_of' => '2fa-guard|redirection' ];
		$this->assertSame( 1, $this->catalog->store( $this->example( [ 'version_constraints' => $constraints ] ) ) );
		$this->assertTrue( $this->catalog->set_exposure( 1, false ) );
		$this->assertTrue( $this->catalog->set_exposure( 1, true ) );
		$this->assertSame( $constraints, $this->repository->rows[1]['version_constraints'] );
		$this->assertCount( 1, $this->catalog->browse( false, 'agentic' ) );
	}

	public function test_empty_import_receipt_cannot_reach_the_repository(): void {
		$review = \Stonewright\WpMcp\SkillLibrary\ImportReview::examine( 'example.md', "---\nname: Example\ndescription: Use when writing examples.\n---\n# Import\n" );
		$this->assertIsArray( $review );
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->import_review( $review ) );
		$this->assertSame( [], $this->repository->rows );
	}

	public function test_unknown_private_review_claims_are_not_copied_to_audits(): void {
		$this->catalog->store( $this->example( [ 'reviewed_content_hash' => [ 'private' => 'synthetic metadata' ], 'review_hash' => [ 'private' => 'synthetic metadata' ] ] ) );
		$this->assertArrayNotHasKey( 'reviewed_content_hash', $this->boundary->events[0]['summary'] );
		$this->assertArrayNotHasKey( 'review_hash', $this->boundary->events[0]['summary'] );
	}

	/** @dataProvider untrusted_authority_claims */
	public function test_local_save_cannot_manufacture_verification_or_provenance( array $claims ): void {
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->store( $this->example( $claims ) ) );
		$this->assertSame( [], $this->repository->rows );
	}

	public static function untrusted_authority_claims(): array {
		return [
			[ [ 'source' => 'candidate', 'verification_count' => 2 ] ],
			[ [ 'verification_count' => 99 ] ],
			[ [ 'trusted' => true ] ],
			[ [ 'trust' => [ 'site_verified' => true ] ] ],
			[ [ 'semantic_fingerprint' => str_repeat( 'a', 64 ) ] ],
			[ [ 'revision' => 99 ] ],
			'cleared conflicts' => [ [ 'conflicts' => [] ] ],
		];
	}

	/** @dataProvider component_availability */
	public function test_every_shipped_entry_can_be_disabled_and_enabled_again( bool $available ): void {
		$compatible = static fn( array $constraints ): bool => $available;
		$catalog = new SkillCatalog( $this->repository, $this->boundary, $compatible );
		$inventory = PackInventory::scan( dirname( STONEWRIGHT_DIR ) . '/skills' );
		$this->assertIsArray( $inventory );
		$this->assertSame( [], $inventory['diagnostics'] );
		$identities = [];
		foreach ( $inventory['entries'] as $entry ) {
			$identities[ $entry['pack_key'] ] = $entry['record']['slug'];
		}
		$plan = PackRefresh::plan( $inventory, [], $identities );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertSame( [], $plan['conflicts'] );
		$this->assertCount( count( $inventory['entries'] ), $plan['upserts'] );
		foreach ( $plan['upserts'] as $record ) {
			$id = $this->repository->insert_unique( $record + [ 'revision' => 1 ] );
			$this->assertIsInt( $id );
			foreach ( [ false, true ] as $enabled ) {
				$result = $catalog->set_exposure( $id, $enabled );
				$this->assertTrue( $result, $record['slug'] . ( is_wp_error( $result ) ? ': ' . wp_json_encode( $result->get_error_data() ) : '' ) );
				$this->assertSame( $enabled, $this->repository->rows[ $id ]['enabled'] );
			}
			$this->assertSame( 'active', $this->repository->rows[ $id ]['status'] );
			// Enabling records the choice; visibility still waits for every required component.
			$this->assertSame( $available || empty( $record['version_constraints'] ), VisibilityRules::eligible( $this->repository->rows[ $id ], 'all', $compatible ), $record['slug'] );
		}
	}

	public static function component_availability(): array {
		return [ 'components present' => [ true ], 'components absent' => [ false ] ];
	}

	/** @dataProvider site_sources */
	public function test_site_guidance_still_needs_authored_content_lint_to_enable( string $source ): void {
		$this->repository->rows[5] = $this->example( [ 'id' => 5, 'revision' => 1, 'source' => $source, 'status' => 'draft', 'enabled' => false, 'description' => 'Use when editing Elementor layouts.', 'content' => 'Call `stonewright/example-missing`.' ] );
		$before = $this->repository->rows;
		$result = $this->catalog->set_exposure( 5, true );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_skill_lint_failed', $result->get_error_code() );
		$this->assertSame( $before, $this->repository->rows );
	}

	public static function site_sources(): array {
		return [ 'local' => [ 'user' ], 'imported' => [ 'uploaded' ], 'candidate' => [ 'candidate' ] ];
	}

	/** @dataProvider ended_lifecycles */
	public function test_enabling_cannot_revive_a_stale_or_retired_entry( string $status ): void {
		$this->repository->rows[9] = $this->example( [ 'id' => 9, 'revision' => 1, 'source' => 'playbook', 'status' => $status, 'enabled' => false ] );
		$before = $this->repository->rows;
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->set_exposure( 9, true ) );
		$this->assertSame( $before, $this->repository->rows );
	}

	public static function ended_lifecycles(): array {
		return [ 'retired playbook' => [ 'retired' ], 'stale playbook' => [ 'stale' ] ];
	}

	public function test_enabling_promotes_a_draft_to_active(): void {
		$this->repository->rows[9] = $this->example( [ 'id' => 9, 'revision' => 1, 'status' => 'draft', 'enabled' => false ] );
		$this->assertTrue( $this->catalog->set_exposure( 9, true ) );
		$this->assertSame( 'active', $this->repository->rows[9]['status'] );
		$this->assertTrue( $this->repository->rows[9]['enabled'] );
	}

	/** @dataProvider rows_failing_current_validators */
	public function test_disable_trash_and_erase_do_not_rerun_write_validators( array $row ): void {
		$this->repository->rows[7] = $row + [ 'id' => 7, 'slug' => 'example', 'revision' => 1, 'enabled' => true, 'status' => 'active' ];
		$this->assertTrue( $this->catalog->set_exposure( 7, false ) );
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->set_exposure( 7, true ) );
		$this->assertFalse( $this->repository->rows[7]['enabled'] );
		$this->assertTrue( $this->catalog->put_in_trash( 7 ) );
		$this->assertTrue( $this->catalog->erase( 7, 'synthetic-confirmation' ) );
		$this->assertSame( [], $this->repository->rows );
	}

	public static function rows_failing_current_validators(): array {
		return [
			'credential in body' => [ [ 'title' => 'Example', 'description' => 'Use when writing examples.', 'content' => '-----BEGIN ' . 'PRIVATE KEY-----', 'source' => 'user' ] ],
			'incomplete stored row' => [ [ 'content' => '# Example' ] ],
		];
	}

	public function test_rollback_validates_only_a_result_that_would_be_active(): void {
		$this->repository->rows[7] = $this->example( [ 'id' => 7, 'revision' => 3 ] );
		$this->repository->archive['example'][1] = $this->example( [ 'id' => 7, 'revision' => 1, 'status' => 'draft', 'enabled' => false, 'content' => '-----BEGIN ' . 'PRIVATE KEY-----' ] );
		$this->repository->archive['example'][2] = $this->example( [ 'id' => 7, 'revision' => 2, 'description' => '' ] );
		$this->assertInstanceOf( \WP_Error::class, $this->catalog->restore_revision( 'example', 2 ) );
		$this->assertSame( 3, $this->repository->rows[7]['revision'] );
		$this->assertTrue( $this->catalog->restore_revision( 'example', 1 ) );
		$this->assertSame( 'draft', $this->repository->rows[7]['status'] );
		$this->assertSame( 4, $this->repository->rows[7]['revision'] );
	}

	public function test_reviewed_import_creates_one_disabled_draft_bound_to_its_review(): void {
		$review = ImportReview::examine( 'example.md', self::IMPORT );
		$this->assertIsArray( $review );
		$this->assertSame( 1, $this->catalog->import_review( $review, 'synthetic-receipt' ) );
		$row = $this->repository->rows[1];
		$this->assertSame( [ 'example', 'uploaded', 'draft', false ], [ $row['slug'], $row['source'], $row['status'], $row['enabled'] ] );
		$this->assertSame( "# Import\n", $row['content'] );
		$this->assertSame( 'import', $this->boundary->last_action );
		$this->assertSame( 'synthetic-receipt', $this->boundary->last_token );
		$this->assertSame( $review['review_hash'], $this->boundary->events[0]['summary']['review_hash'] );
	}

	public function test_reviewed_import_never_replaces_an_existing_skill(): void {
		$this->catalog->store( $this->example() );
		$before = $this->repository->rows;
		$review = ImportReview::examine( 'example.md', self::IMPORT );
		$this->assertIsArray( $review );
		$result = $this->catalog->import_review( $review, 'synthetic-receipt' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_skill_import_exists', $result->get_error_code() );
		$this->assertSame( $before, $this->repository->rows );
		$this->assertNotSame( 'import', $this->boundary->last_action );
	}

	/** @dataProvider reserved_identity_writes */
	public function test_new_skills_cannot_take_an_unseeded_builtin_identity( string $write ): void {
		$catalog = new SkillCatalog( $this->repository, $this->boundary, static fn( array $constraints ): bool => true, [], null, [ 'example' ] );
		$review = ImportReview::examine( 'example.md', self::IMPORT );
		$this->assertIsArray( $review );
		$result = 'import' === $write ? $catalog->import_review( $review, 'synthetic-receipt' ) : $catalog->store( $this->example() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [], $this->repository->rows );
	}

	public static function reserved_identity_writes(): array {
		return [ 'import' => [ 'import' ], 'local save' => [ 'save' ] ];
	}

	public function test_untrusted_edits_invalidate_prior_verification_without_erasing_history(): void {
		$this->repository->rows[42] = $this->example( [ 'id' => 42, 'source' => 'candidate', 'revision' => 1, 'verification_count' => 2, 'semantic_fingerprint' => str_repeat( 'a', 64 ) ] );
		$this->assertSame( 42, $this->catalog->store( [ 'slug' => 'example', 'title' => 'Changed', 'content' => '# New unverified body' ] ) );
		$record = $this->catalog->lookup( 'example' );
		$this->assertSame( 0, $record['verification_count'] );
		$this->assertSame( 'draft', $record['status'] );
		$this->assertFalse( $record['enabled'] );
		$this->assertSame( 2, $this->catalog->revisions( 'example' )[0]['verification_count'] );
	}
}

/** Synthetic logical repository; no WordPress table schema is represented here. */
final class TestRepository implements Repository {
	public array $rows = [];
	public array $archive = [];
	public bool $reject_exchange = false;

	public function all_records(): array { return array_values( $this->rows ); }
	public function find_slug( string $slug ): ?array {
		foreach ( $this->rows as $row ) { if ( $row['slug'] === $slug ) { return $row; } }
		return null;
	}
	public function find_id( int $id ): ?array { return $this->rows[ $id ] ?? null; }
	public function insert_unique( array $record ): int|\WP_Error {
		if ( null !== $this->find_slug( $record['slug'] ) ) { return new \WP_Error( 'duplicate_slug', 'Duplicate.' ); }
		$id = count( $this->rows ) + 1;
		$this->rows[ $id ] = $record + [ 'id' => $id ];
		return $id;
	}
	public function exchange_record( int $id, array $replacement, int $expected_revision ): bool|\WP_Error {
		if ( $this->reject_exchange || ( $this->rows[ $id ]['revision'] ?? 0 ) !== $expected_revision ) { return new \WP_Error( 'synthetic_exchange_refused', 'Refused.' ); }
		$this->archive[ $replacement['slug'] ][ $expected_revision ] = $this->rows[ $id ];
		$this->rows[ $id ] = $replacement;
		return true;
	}
	public function remove_record( int $id, int $expected_revision ): bool|\WP_Error {
		if ( ( $this->rows[ $id ]['revision'] ?? 0 ) !== $expected_revision ) { return new \WP_Error( 'synthetic_exchange_refused', 'Refused.' ); }
		unset( $this->archive[ $this->rows[ $id ]['slug'] ], $this->rows[ $id ] );
		return true;
	}
	public function snapshots( string $slug ): array { return array_values( $this->archive[ $slug ] ?? [] ); }
	public function read_snapshot( string $slug, int $revision ): ?array { return $this->archive[ $slug ][ $revision ] ?? null; }
}

/** Records the delegated security boundary; never a production capability implementation. */
final class TestBoundary implements MutationBoundary {
	public bool $allowed = true;
	public string $last_action = '';
	public string $last_token = '';
	public array $events = [];
	public function authorize( string $action, array $summary, string $token ): bool|\WP_Error {
		$this->last_action = $action;
		$this->last_token = $token;
		return $this->allowed ? true : new \WP_Error( 'synthetic_gate_refused', 'Refused.' );
	}
	public function record( string $action, array $summary, string $outcome ): void {
		$this->events[] = [ 'action' => $action, 'summary' => $summary, 'outcome' => $outcome ];
	}
}
