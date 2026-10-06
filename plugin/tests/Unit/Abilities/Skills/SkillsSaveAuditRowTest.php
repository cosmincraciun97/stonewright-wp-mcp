<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Knowledge\KnowledgeCandidateRecord;
use Stonewright\WpMcp\Abilities\Skills\SkillsSave;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;
use Stonewright\WpMcp\SkillLibrary\Site\WordPressBoundary;
use Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site\SkillTablesDouble;

/**
 * One call of the skills-save ability writes one audit row, named after that ability.
 *
 * The ability kernel records every call, whatever the outcome. The skill library's
 * write boundary must not add a second row with the same name for the same call, yet
 * the row an administrator reads still has to say which skill changed, to which
 * revision, and with which content hash. The admin and REST channels have no kernel
 * row, so the boundary's own row stays their only record.
 *
 * @covers \Stonewright\WpMcp\Abilities\Skills\SkillsSave
 * @covers \Stonewright\WpMcp\Abilities\AbilityKernel
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\WordPressBoundary
 */
final class SkillsSaveAuditRowTest extends TestCase {

	private const SAVE = 'stonewright/skills-save';

	private const BODY = '# Distinctive playbook body';

	private mixed $original_wpdb;

	private SkillTablesDouble $tables;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->tables                                = new SkillTablesDouble();
		$GLOBALS['wpdb']                             = $this->tables;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                             = $this->original_wpdb;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	// -------------------------------------------------------------------------
	// The ability channel
	// -------------------------------------------------------------------------

	public function test_one_ability_save_writes_exactly_one_row_named_after_the_ability(): void {
		$result = ( new SkillsSave() )->execute( $this->save_args() );

		self::assertSame( [ 'id' => 1, 'slug' => 'site-note', 'updated' => false ], $result );
		$rows = $this->audit_rows();
		self::assertSame( [ self::SAVE ], array_column( $rows, 'ability_name' ) );
		self::assertSame( 'ok', $rows[0]['result_status'] );
	}

	public function test_an_update_through_the_ability_also_writes_one_row(): void {
		( new SkillsSave() )->execute( $this->save_args() );
		$this->forget_rows();

		$result = ( new SkillsSave() )->execute( array_replace( $this->save_args(), [ 'content' => '# Second body' ] ) );

		self::assertSame( [ 'id' => 1, 'slug' => 'site-note', 'updated' => true ], $result );
		self::assertSame( [ self::SAVE ], array_column( $this->audit_rows(), 'ability_name' ) );
	}

	public function test_the_ability_row_carries_the_boundary_details_but_no_body(): void {
		( new SkillsSave() )->execute( $this->save_args() );

		$meta = $this->recorded_meta( $this->audit_rows()[0] );
		self::assertSame( 'save', $meta['skill_action'] ?? null );
		self::assertSame( 'site-note', $meta['skill_slug'] ?? null );
		self::assertSame( 'site-note', $meta['resource_ref'] ?? null );
		self::assertSame( 1, $meta['skill_revision'] ?? null );
		self::assertSame( hash( 'sha256', (string) $this->tables->skills[1]['content'] ), $meta['skill_content_hash'] ?? null );
		self::assertSame( 'skill_library', $meta['operation_class'] ?? null );
		self::assertSame( 'skill', $meta['resource_type'] ?? null );
		self::assertSame( WordPressBoundary::ABILITY, $meta['channel'] ?? null );
		self::assertSame( 'write', $meta['operation_kind'] ?? null, 'The kernel keeps declaring the call a write.' );
		self::assertStringNotContainsString( 'Distinctive playbook body', (string) wp_json_encode( $meta ) );
	}

	public function test_the_row_follows_the_stored_slug_and_the_new_revision_on_an_update(): void {
		( new SkillsSave() )->execute( $this->save_args() );
		$this->forget_rows();

		( new SkillsSave() )->execute( array_replace( $this->save_args(), [ 'slug' => 'Site Note', 'content' => '# Second body' ] ) );

		$meta = $this->recorded_meta( $this->audit_rows()[0] );
		self::assertSame( 'site-note', $meta['skill_slug'] ?? null, 'The row names the stored slug, not the raw input.' );
		self::assertSame( 1, $meta['skill_id'] ?? null );
		self::assertSame( 2, $meta['skill_revision'] ?? null );
		self::assertSame( hash( 'sha256', '# Second body' ), $meta['skill_content_hash'] ?? null );
	}

	public function test_a_refused_storage_write_still_writes_exactly_one_row(): void {
		$this->tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Note', 'description' => 'Use when noting.', 'content' => '# Before' ] );
		$this->tables->fail_skill_update = true;

		$result = ( new SkillsSave() )->execute( $this->save_args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		$rows = $this->audit_rows();
		self::assertSame( [ self::SAVE ], array_column( $rows, 'ability_name' ) );
		self::assertSame( 'error', $rows[0]['result_status'] );
		self::assertSame( 'save', $this->recorded_meta( $rows[0] )['skill_action'] ?? null );
	}

	public function test_a_stale_revision_writes_exactly_one_row(): void {
		( new SkillsSave() )->execute( $this->save_args() );
		$this->forget_rows();

		$result = ( new SkillsSave() )->execute( array_replace( $this->save_args(), [ 'revision' => 5 ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_write_conflict', $result->get_error_code() );
		$rows = $this->audit_rows();
		self::assertSame( [ self::SAVE ], array_column( $rows, 'ability_name' ) );
		self::assertSame( 'error', $rows[0]['result_status'] );
	}

	public function test_one_rest_ability_run_writes_exactly_one_row(): void {
		AuditLog::begin_request();
		$result = ( new SkillsSave() )->execute( $this->save_args() );
		RestRoutes::audit_post_dispatch(
			rest_ensure_response( [ 'name' => self::SAVE, 'result' => $result ] ),
			null,
			new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => self::SAVE ] )
		);

		self::assertSame( [ self::SAVE ], array_column( $this->audit_rows(), 'ability_name' ) );
	}

	public function test_a_production_safe_save_adds_only_the_token_event_to_its_one_row(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'production-safe' ];
		$args                                = $this->save_args();
		$args['confirmation_token']          = ConfirmationToken::issue( self::SAVE, $args );

		$result = ( new SkillsSave() )->execute( $args );

		self::assertIsArray( $result );
		self::assertSame( [ 'security.confirmation_token', self::SAVE ], array_column( $this->audit_rows(), 'ability_name' ) );
	}

	public function test_the_ability_channel_outside_an_audited_call_still_records_its_own_event(): void {
		$id = SkillLibraryService::open()->save_skill( $this->save_args() );

		self::assertSame( 1, $id );
		$rows = $this->audit_rows();
		self::assertSame( [ self::SAVE ], array_column( $rows, 'ability_name' ) );
		$payload = $this->recorded_args( $rows[0] );
		self::assertSame( WordPressBoundary::ABILITY, $payload['_meta']['channel'] ?? null );
		self::assertSame( 'site-note', $payload['slug'] ?? null );
	}

	// -------------------------------------------------------------------------
	// The admin and REST channels
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider person_channel_provider
	 */
	public function test_an_admin_or_rest_save_still_writes_its_boundary_row( string $channel ): void {
		$library = SkillLibraryService::open( $channel );

		self::assertSame( 1, $library->save_skill( $this->save_args() ) );
		self::assertSame( [ self::SAVE ], array_column( $this->audit_rows(), 'ability_name' ), 'A creation writes its one row.' );
		$this->forget_rows();

		self::assertSame( 1, $library->save_skill( array_replace( $this->save_args(), [ 'content' => '# Second body' ] ) ) );

		$rows = $this->audit_rows();
		self::assertSame( [ self::SAVE ], array_column( $rows, 'ability_name' ) );
		self::assertSame( 'ok', $rows[0]['result_status'] );
		$payload = $this->recorded_args( $rows[0] );
		self::assertSame( 1, $payload['skill_id'] ?? null );
		self::assertSame( 'site-note', $payload['slug'] ?? null );
		self::assertSame( 2, $payload['revision'] ?? null );
		self::assertSame( hash( 'sha256', '# Second body' ), $payload['content_hash'] ?? null );
		self::assertSame( 'skill_library', $payload['_meta']['operation_class'] ?? null );
		self::assertSame( 'skill', $payload['_meta']['resource_type'] ?? null );
		self::assertSame( 'site-note', $payload['_meta']['resource_ref'] ?? null );
		self::assertSame( $channel, $payload['_meta']['channel'] ?? null );
		self::assertStringNotContainsString( 'Second body', (string) $rows[0]['sanitized_args'] );
	}

	/** @return array<string, array{string}> */
	public static function person_channel_provider(): array {
		return [
			'admin screen' => [ WordPressBoundary::ADMIN ],
			'REST route'   => [ WordPressBoundary::REST ],
		];
	}

	public function test_a_refused_admin_save_keeps_its_blocked_boundary_row(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$id = SkillLibraryService::open( WordPressBoundary::ADMIN )->save_skill( $this->save_args() );

		self::assertInstanceOf( \WP_Error::class, $id );
		$rows = $this->audit_rows();
		self::assertSame( [ self::SAVE ], array_column( $rows, 'ability_name' ) );
		self::assertSame( 'blocked', $rows[0]['result_status'] );
	}

	// -------------------------------------------------------------------------
	// Other callers of the ability channel
	// -------------------------------------------------------------------------

	public function test_an_ability_with_another_name_keeps_the_skill_event_next_to_its_own_row(): void {
		$other = new class() extends \Stonewright\WpMcp\Abilities\AbilityKernel {
			public function name(): string {
				return 'stonewright/test-skill-writer';
			}

			public function label(): string {
				return 'Skill writer';
			}

			public function description(): string {
				return 'Saves a skill on behalf of another ability.';
			}

			public function category(): string {
				return 'test';
			}

			public function execute( array $args ): array|\WP_Error {
				return $this->audit_write(
					$args,
					static function (): array|\WP_Error {
						$saved = SkillLibraryService::open()->save_skill(
							[
								'slug'        => 'writer-note',
								'title'       => 'Writer note',
								'description' => 'Use when writing.',
								'content'     => '# Written',
							]
						);
						return $saved instanceof \WP_Error ? $saved : [ 'ok' => true ];
					}
				);
			}
		};

		$result = $other->execute( [] );

		self::assertSame( [ 'ok' => true ], $result );
		$names = array_column( $this->audit_rows(), 'ability_name' );
		sort( $names );
		self::assertSame( [ self::SAVE, 'stonewright/test-skill-writer' ], $names, 'The call row carries its own name; the skill save stays a separate resource event.' );
	}

	public function test_a_rollback_through_the_knowledge_ability_keeps_the_rollback_event_next_to_its_own_row(): void {
		$library = SkillLibraryService::open( WordPressBoundary::ADMIN );
		$library->save_skill( $this->save_args() );
		$library->save_skill( array_replace( $this->save_args(), [ 'content' => '# Second body' ] ) );
		$this->forget_rows();

		$result = ( new KnowledgeCandidateRecord() )->execute( [ 'action' => 'skill_rollback', 'skill_slug' => 'site-note', 'revision' => 1 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		$names = array_column( $this->audit_rows(), 'ability_name' );
		sort( $names );
		self::assertSame( [ 'stonewright/knowledge-candidate-record', 'stonewright/skills-rollback' ], $names );
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function save_args(): array {
		return [
			'slug'        => 'site-note',
			'title'       => 'Note',
			'description' => 'Use when noting.',
			'content'     => self::BODY,
		];
	}

	/** Forget the rows written so far, so a test sees only the rows of the call under test. */
	private function forget_rows(): void {
		$this->tables->inserts = [];
		AuditLog::reset_request_state();
	}

	/**
	 * Audit-log rows the skill tables double captured, in write order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function audit_rows(): array {
		$rows = [];
		foreach ( $this->tables->inserts as $insert ) {
			if ( str_contains( $insert['table'], 'stonewright_audit_log' ) ) {
				$rows[] = $insert['data'];
			}
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function recorded_args( array $row ): array {
		$decoded = json_decode( (string) ( $row['sanitized_args'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function recorded_meta( array $row ): array {
		$meta = $this->recorded_args( $row )['_meta'] ?? [];
		return is_array( $meta ) ? $meta : [];
	}
}
