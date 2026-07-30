<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\CheckpointRecord;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Workflow\DesignCheckpoint;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Design\Workflow\DesignCheckpoint
 * @covers \Stonewright\WpMcp\Abilities\Design\CheckpointRecord
 */
final class DesignCheckpointTest extends TestCase {

	private const POST_ID = 8801;

	private const OTHER_POST_ID = 8802;

	private const DIRECTION_ID = 9001;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode'                         => 'development',
			DesignDirectionService::ACTIVE_OPTION      => self::DIRECTION_ID,
			'stonewright_design_checkpoint_secret'     => str_repeat( 'a', 64 ),
			'stonewright_confirmation_secret'          => str_repeat( 'b', 64 ),
		];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [
			'read'         => true,
			'manage_options' => true,
			'edit_pages'   => true,
			'edit_post'    => true,
			'edit_posts'   => true,
		];
		$GLOBALS['stonewright_test_filters']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients']   = [];

		$GLOBALS['stonewright_test_posts'] = [
			self::POST_ID       => (object) [
				'ID'          => self::POST_ID,
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Checkpoint target',
				'meta'        => [ '_elementor_data' => (string) wp_json_encode( self::tree( 'Approved heading' ) ) ],
			],
			self::OTHER_POST_ID => (object) [
				'ID'          => self::OTHER_POST_ID,
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Another page',
				'meta'        => [ '_elementor_data' => (string) wp_json_encode( self::tree( 'Approved heading' ) ) ],
			],
		];

		$this->seed_direction( 'Stone and precision.' );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_posts']          = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_filters']        = [];
		$GLOBALS['stonewright_test_wpdb_inserts']   = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;

		global $wpdb;
		if ( property_exists( $wpdb, 'direction_rows' ) ) {
			$wpdb->direction_rows = [];
		}
	}

	// ---------------------------------------------------------------------
	// Scope gating: which builds get stopped, and which are left alone.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider gated_scopes
	 */
	public function test_scopes_that_establish_a_new_direction_require_a_checkpoint( string $scope ): void {
		self::assertTrue( DesignCheckpoint::required( $scope ), "{$scope} must be gated." );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function gated_scopes(): array {
		return [
			'new_identity' => [ 'new_identity' ],
			'replacement'  => [ 'replacement' ],
			'rebrand'      => [ 'rebrand' ],
		];
	}

	/**
	 * @dataProvider open_scopes
	 */
	public function test_maintenance_scopes_are_never_interrupted( string $scope ): void {
		self::assertFalse( DesignCheckpoint::required( $scope ), "{$scope} must not gain a new blocker." );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function open_scopes(): array {
		return [
			'preserve'       => [ 'preserve' ],
			'repair'         => [ 'repair' ],
			'content_only'   => [ 'content_only' ],
			'responsive_fix' => [ 'responsive_fix' ],
		];
	}

	public function test_unrecognised_scope_fails_closed(): void {
		self::assertTrue( DesignCheckpoint::required( 'whatever_the_agent_felt_like' ) );
	}

	public function test_empty_scope_falls_back_to_preserve_so_routine_edits_keep_working(): void {
		self::assertFalse( DesignCheckpoint::required( '' ) );
		self::assertFalse( DesignCheckpoint::required( '   ' ) );
	}

	public function test_scope_vocabulary_is_disjoint_and_complete(): void {
		self::assertSame( [], array_intersect( DesignCheckpoint::GATED_SCOPES, DesignCheckpoint::OPEN_SCOPES ) );
		self::assertCount( 7, DesignCheckpoint::scopes() );
	}

	/**
	 * @dataProvider task_scopes
	 */
	public function test_task_text_maps_to_a_scope( string $task, string $expected ): void {
		self::assertSame( $expected, DesignCheckpoint::scope_for_task( $task ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function task_scopes(): array {
		return [
			'rebrand'        => [ 'Rebrand the whole site around the new brand identity', 'rebrand' ],
			'redesign'       => [ 'Redesign the homepage from scratch', 'replacement' ],
			'new identity'   => [ 'Give the landing page a new visual direction', 'new_identity' ],
			'responsive'     => [ 'Fix the mobile layout overflow on the pricing section', 'responsive_fix' ],
			'copy'           => [ 'Fix a typo in the hero subtitle', 'content_only' ],
			'repair'         => [ 'The testimonial slider is broken after the update', 'repair' ],
			'plain edit'     => [ 'Add one more feature card to the existing grid', 'preserve' ],
		];
	}

	// ---------------------------------------------------------------------
	// Token binding: what the approval covers, and when it stops applying.
	// ---------------------------------------------------------------------

	public function test_issue_binds_post_section_direction_render_actor_and_expiry(): void {
		$issued = DesignCheckpoint::issue( self::POST_ID, 'hero', 'dirhash', 'renderhash' );

		self::assertStringStartsWith( DesignCheckpoint::TOKEN_PREFIX, $issued['token'] );
		self::assertSame( self::POST_ID, $issued['post_id'] );
		self::assertSame( 'hero', $issued['section_id'] );
		self::assertSame( 'dirhash', $issued['direction_hash'] );
		self::assertSame( 'renderhash', $issued['render_hash'] );
		self::assertSame( 42, $issued['approved_by'] );
		self::assertSame( DesignCheckpoint::TTL, $issued['expires_in'] );
		self::assertNotSame( '', $issued['approved_at'] );
		self::assertGreaterThan( $issued['approved_at'], $issued['expires_at'] );
	}

	public function test_verify_accepts_the_state_that_was_approved(): void {
		self::assertTrue( DesignCheckpoint::verify( $this->live_token(), $this->live_state() ) );
	}

	public function test_checkpoint_cannot_be_reused_for_another_post(): void {
		$state            = $this->live_state();
		$state['post_id'] = self::OTHER_POST_ID;

		$error = DesignCheckpoint::verify( $this->live_token(), $state );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( DesignCheckpoint::ERROR_MISMATCH, $error->get_error_code() );
		self::assertSame( 'post_id', $error->get_error_data()['field'] );
	}

	public function test_checkpoint_fails_after_the_direction_changes(): void {
		$token = $this->live_token();

		$this->seed_direction( 'A completely different language.' );

		$error = DesignCheckpoint::verify( $token, $this->live_state() );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( DesignCheckpoint::ERROR_MISMATCH, $error->get_error_code() );
		self::assertSame( 'direction_hash', $error->get_error_data()['field'] );
	}

	public function test_checkpoint_fails_after_the_approved_section_changes(): void {
		$token = $this->live_token();

		$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->meta['_elementor_data'] =
			(string) wp_json_encode( self::tree( 'Someone edited this after approval' ) );

		$error = DesignCheckpoint::verify( $token, $this->live_state() );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( DesignCheckpoint::ERROR_MISMATCH, $error->get_error_code() );
		self::assertSame( 'render_hash', $error->get_error_data()['field'] );
	}

	public function test_checkpoint_does_not_cover_a_different_section(): void {
		$state               = $this->live_state();
		$state['section_id'] = 'some-other-section';

		$error = DesignCheckpoint::verify( $this->live_token(), $state );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'section_id', $error->get_error_data()['field'] );
	}

	public function test_checkpoint_belongs_to_the_user_who_approved_it(): void {
		$token = $this->live_token();

		$GLOBALS['stonewright_test_current_user_id'] = 77;

		$error = DesignCheckpoint::verify( $token, $this->live_state() );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'approved_by', $error->get_error_data()['field'] );
	}

	/**
	 * @dataProvider malformed_tokens
	 */
	public function test_malformed_tokens_are_refused( string $token ): void {
		$error = DesignCheckpoint::verify( $token, $this->live_state() );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( DesignCheckpoint::ERROR_INVALID, $error->get_error_code() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformed_tokens(): array {
		return [
			'empty'       => [ '' ],
			'no prefix'   => [ 'nope.nope' ],
			'no dot'      => [ DesignCheckpoint::TOKEN_PREFIX . 'abc' ],
			'garbage'     => [ DesignCheckpoint::TOKEN_PREFIX . '!!!.!!!' ],
		];
	}

	public function test_tampered_payload_is_refused(): void {
		$token = $this->live_token();
		[ $payload, $signature ] = explode( '.', substr( $token, strlen( DesignCheckpoint::TOKEN_PREFIX ) ), 2 );

		$decoded = json_decode(
			(string) base64_decode( strtr( $payload . str_repeat( '=', ( 4 - strlen( $payload ) % 4 ) % 4 ), '-_', '+/' ), true ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			true
		);
		self::assertIsArray( $decoded );
		$decoded['post_id'] = self::OTHER_POST_ID;

		$forged = DesignCheckpoint::TOKEN_PREFIX . rtrim(
			strtr( base64_encode( (string) wp_json_encode( $decoded ) ), '+/', '-_' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'='
		) . '.' . $signature;

		$error = DesignCheckpoint::verify( $forged, $this->live_state() );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( DesignCheckpoint::ERROR_INVALID, $error->get_error_code() );
	}

	public function test_checkpoint_expires(): void {
		$GLOBALS['stonewright_test_filters'][ DesignCheckpoint::TTL_FILTER ] = static fn(): int => -1;

		$error = DesignCheckpoint::verify( $this->live_token(), $this->live_state() );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( DesignCheckpoint::ERROR_EXPIRED, $error->get_error_code() );
	}

	public function test_confirmation_token_is_not_a_checkpoint(): void {
		$confirmation = ConfirmationToken::issue( 'stonewright/design-checkpoint-record', [] );

		$error = DesignCheckpoint::verify( $confirmation, $this->live_state() );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( DesignCheckpoint::ERROR_INVALID, $error->get_error_code() );
	}

	public function test_bound_section_id_reports_the_approved_section(): void {
		self::assertSame( 'hero', DesignCheckpoint::bound_section_id( $this->live_token() ) );
		self::assertSame( '', DesignCheckpoint::bound_section_id( 'not-a-token' ) );
	}

	public function test_section_render_hash_finds_nested_sections_and_reports_absence(): void {
		$tree = self::tree( 'Approved heading' );

		$section = DesignCheckpoint::section_render_hash( $tree, 'hero' );
		$nested  = DesignCheckpoint::section_render_hash( $tree, 'hero-heading' );

		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $section );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $nested );
		self::assertNotSame( $section, $nested );
		self::assertSame( '', DesignCheckpoint::section_render_hash( $tree, 'missing' ) );
		self::assertSame( '', DesignCheckpoint::section_render_hash( $tree, '' ) );
		self::assertNotSame(
			$section,
			DesignCheckpoint::section_render_hash( self::tree( 'Different copy' ), 'hero' )
		);
	}

	// ---------------------------------------------------------------------
	// The ability that records the approval.
	// ---------------------------------------------------------------------

	public function test_ability_surface(): void {
		$ability = new CheckpointRecord();

		self::assertSame( DesignCheckpoint::CONTINUATION_ABILITY, $ability->name() );
		self::assertSame( 'design', $ability->category() );
		self::assertSame( [ 'post_id', 'section_id', 'approved' ], $ability->input_schema()['required'] );
	}

	public function test_ability_refuses_without_an_explicit_approval(): void {
		foreach ( [ [], [ 'approved' => false ], [ 'approved' => 'yes' ], [ 'approved' => 1 ] ] as $variant ) {
			$result = ( new CheckpointRecord() )->execute(
				array_merge( [ 'post_id' => self::POST_ID, 'section_id' => 'hero' ], $variant )
			);

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_design_checkpoint_not_approved', $result->get_error_code() );
		}
	}

	public function test_ability_returns_a_token_that_verifies_against_live_state(): void {
		$result = $this->record();

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( self::DIRECTION_ID, $result['direction_id'] );
		self::assertSame( 42, $result['approved_by'] );
		self::assertTrue( DesignCheckpoint::verify( $result['checkpoint_token'], $this->live_state() ) );
	}

	public function test_ability_logs_the_approving_actor(): void {
		$this->record();

		$rows = array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn( array $row ): bool => DesignCheckpoint::CONTINUATION_ABILITY === ( $row['data']['ability_name'] ?? '' )
			)
		);

		self::assertCount( 1, $rows );
		self::assertSame( 'ok', $rows[0]['data']['result_status'] );
		self::assertSame( 42, $rows[0]['data']['user_id'] );
		self::assertSame( 'design_checkpoint_approval', $rows[0]['data']['operation_class'] );

		$args = json_decode( (string) $rows[0]['data']['sanitized_args'], true );
		self::assertIsArray( $args );
		self::assertSame( 42, $args['_meta']['approved_by'] );
		self::assertSame( 'hero', $args['_meta']['section_id'] );
	}

	public function test_ability_refuses_a_section_that_is_not_on_the_page(): void {
		$result = $this->record( [ 'section_id' => 'never-rendered' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_design_checkpoint_section_missing', $result->get_error_code() );
	}

	public function test_ability_refuses_when_no_direction_is_in_force(): void {
		unset( $GLOBALS['stonewright_test_options'][ DesignDirectionService::ACTIVE_OPTION ] );

		$result = $this->record();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_direction_not_found', $result->get_error_code() );
	}

	public function test_ability_refuses_an_unknown_quality_report(): void {
		$result = $this->record( [ 'report_id' => 'deadbeef' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_quality_report_not_found', $result->get_error_code() );
	}

	public function test_ability_permission_gate_requires_design_management_and_post_edit(): void {
		$ability = new CheckpointRecord();
		$args    = [ 'post_id' => self::POST_ID, 'section_id' => 'hero', 'approved' => true ];

		self::assertTrue( $ability->permission_callback( $args ) );

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;
		self::assertInstanceOf( \WP_Error::class, $ability->permission_callback( $args ) );

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		$GLOBALS['stonewright_test_user_caps']['edit_post']      = false;
		self::assertInstanceOf( \WP_Error::class, $ability->permission_callback( $args ) );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>|\WP_Error
	 */
	private function record( array $overrides = [] ) {
		return ( new CheckpointRecord() )->execute(
			array_merge(
				[
					'post_id'    => self::POST_ID,
					'section_id' => 'hero',
					'approved'   => true,
				],
				$overrides
			)
		);
	}

	private function live_token(): string {
		$state = $this->live_state();

		return (string) DesignCheckpoint::issue(
			self::POST_ID,
			'hero',
			(string) $state['direction_hash'],
			(string) $state['render_hash']
		)['token'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function live_state(): array {
		return [
			'post_id'        => self::POST_ID,
			'section_id'     => 'hero',
			'direction_hash' => DesignCheckpoint::active_direction_hash(),
			'render_hash'    => DesignCheckpoint::section_render_hash( self::stored_tree(), 'hero' ),
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function stored_tree(): array {
		$decoded = json_decode(
			(string) $GLOBALS['stonewright_test_posts'][ self::POST_ID ]->meta['_elementor_data'],
			true
		);

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function tree( string $heading ): array {
		return [
			[
				'id'       => 'hero',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[
						'id'         => 'hero-heading',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => $heading ],
						'elements'   => [],
					],
				],
			],
		];
	}

	private function seed_direction( string $summary ): void {
		global $wpdb;

		if ( ! property_exists( $wpdb, 'direction_rows' ) ) {
			self::markTestSkipped( 'The test wpdb double does not expose direction_rows.' );
		}

		$contract = [
			'schema_version' => '1.0.0',
			'name'           => 'Quarry',
			'summary'        => $summary,
		];

		$wpdb->direction_rows = [
			self::DIRECTION_ID => [
				'id'               => self::DIRECTION_ID,
				'slug'             => 'quarry',
				'status'           => 'ready',
				'contract_json'    => (string) wp_json_encode( $contract ),
				'contract_hash'    => DesignDirectionService::hash( $contract ),
				'source_type'      => 'manual',
				'source_refs_json' => (string) wp_json_encode( [] ),
				'revision'         => 1,
				'created_at'       => '2026-07-01 00:00:00',
				'updated_at'       => '2026-07-01 00:00:00',
			],
		];
	}
}
