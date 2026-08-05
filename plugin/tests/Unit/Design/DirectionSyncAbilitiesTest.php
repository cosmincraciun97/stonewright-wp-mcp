<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\DirectionSyncApply;
use Stonewright\WpMcp\Abilities\Design\DirectionSyncPlan;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Design\Direction\DesignDirectionRepository;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Design\Direction\ElementorKitSyncPlanner;
use Stonewright\WpMcp\Design\Direction\ElementorKitWriter;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Security-envelope tests for the two direction sync abilities.
 *
 * Sync is the first place a stored direction reaches live Elementor state, so
 * the plan half must stay a read and the apply half must behave like every other
 * destructive Stonewright write: context token, design write permission,
 * confirmation token in production-safe mode, snapshot before mutation, only the
 * planned paths touched, unknown kit settings preserved, and the effect read back
 * before the receipt claims success.
 *
 * The dry-run `base_hash` is the concurrency guard: apply re-reads the kit and
 * refuses when the live state no longer matches the plan the caller approved.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\DirectionSyncPlan
 * @covers \Stonewright\WpMcp\Abilities\Design\DirectionSyncApply
 * @covers \Stonewright\WpMcp\Design\Direction\ElementorKitWriter
 */
final class DirectionSyncAbilitiesTest extends TestCase {

	private const KIT_ID = 44;

	private DirectionSyncRepository $repository;

	private DesignDirectionService $service;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'elementor_active_kit' => self::KIT_ID ];
		$GLOBALS['stonewright_test_user_caps']       = [
			'manage_options' => true,
			'edit_pages'     => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$GLOBALS['stonewright_test_posts']           = [
			self::KIT_ID => (object) [
				'ID'           => self::KIT_ID,
				'post_type'    => 'elementor_library',
				'post_title'   => 'Default Kit',
				'post_status'  => 'publish',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [ '_elementor_page_settings' => $this->kit_settings() ],
			],
		];

		$this->repository = new DirectionSyncRepository();
		$this->service    = new DesignDirectionService( $this->repository );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	// -------------------------------------------------------------------------
	// Registration and gates.
	// -------------------------------------------------------------------------

	public function test_abilities_use_the_documented_slugs_and_category(): void {
		self::assertSame( 'stonewright/design-direction-sync-plan', ( new DirectionSyncPlan( $this->service ) )->name() );
		self::assertSame( 'stonewright/design-direction-sync-apply', ( new DirectionSyncApply( $this->service ) )->name() );
		self::assertSame( 'design', ( new DirectionSyncPlan( $this->service ) )->category() );
		self::assertSame( 'design', ( new DirectionSyncApply( $this->service ) )->category() );
	}

	public function test_registry_publishes_both_sync_abilities(): void {
		$names = array_column( AbilityRegistry::all_abilities(), 'name' );

		self::assertContains( 'stonewright/design-direction-sync-plan', $names );
		self::assertContains( 'stonewright/design-direction-sync-apply', $names );
	}

	/**
	 * @dataProvider sync_ability_names
	 */
	public function test_registry_requires_the_task_context_token( string $name ): void {
		$schema = [];
		foreach ( AbilityRegistry::all_abilities() as $ability ) {
			if ( $name === $ability['name'] ) {
				$schema = $ability['input_schema'];
			}
		}

		self::assertArrayHasKey( 'stonewright_context_token', $schema['properties'] ?? [] );
		self::assertContains( 'stonewright_context_token', $schema['required'] ?? [] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function sync_ability_names(): array {
		return [
			'plan'  => [ 'stonewright/design-direction-sync-plan' ],
			'apply' => [ 'stonewright/design-direction-sync-apply' ],
		];
	}

	public function test_apply_gates_on_the_design_write_permission(): void {
		$ability = new DirectionSyncApply( $this->service );

		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		self::assertFalse( $ability->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			'edit_pages'     => true,
		];
		self::assertTrue( $ability->permission_callback( [] ) );
	}

	// -------------------------------------------------------------------------
	// Dry run.
	// -------------------------------------------------------------------------

	public function test_plan_returns_the_operations_without_touching_the_kit(): void {
		$id = $this->store_direction();

		$result = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( self::KIT_ID, $result['kit_id'] );
		self::assertSame( 64, strlen( (string) $result['base_hash'] ) );
		self::assertSame( [ 'tokens.colors.accent' ], array_column( $result['operations'], 'path' ) );
		self::assertSame( [], $this->meta_writes() );
	}

	public function test_plan_resolves_a_direction_by_slug(): void {
		$this->store_direction();

		$result = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'slug' => 'quarry' ] );

		self::assertIsArray( $result );
		self::assertSame( 'quarry', $result['slug'] );
	}

	public function test_plan_reports_a_missing_direction(): void {
		$result = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => 999 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( DesignDirectionService::NOT_FOUND_CODE, $result->get_error_code() );
	}

	public function test_plan_reports_a_site_without_an_active_kit(): void {
		unset( $GLOBALS['stonewright_test_options']['elementor_active_kit'] );
		$id = $this->store_direction();

		$result = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorKitSyncPlanner::ERROR_CODE, $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Apply.
	// -------------------------------------------------------------------------

	public function test_apply_writes_only_the_planned_paths_and_verifies_the_result(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 1, $result['applied'] );
		self::assertSame( [ 'tokens.colors.accent' ], array_column( $result['operations'], 'path' ) );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertSame( (string) $plan['base_hash'], $result['before_sha256'] );
		self::assertNotSame( $result['before_sha256'], $result['after_sha256'] );

		$settings = $this->live_settings();
		self::assertSame( '#7c2d12', $settings['custom_colors'][0]['color'] );
	}

	public function test_apply_preserves_kit_settings_it_was_not_asked_to_change(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		$settings = $this->live_settings();
		self::assertSame( 'h1.entry-title', $settings['page_title_selector'] );
		self::assertSame( '#1F2933', $settings['system_colors'][0]['color'] );
		self::assertSame( 'Fraunces', $settings['system_typography'][0]['typography_font_family'] );
		self::assertSame( 'Accent', $settings['custom_colors'][0]['title'] );
	}

	public function test_apply_only_writes_the_kit_settings_meta_key(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertSame(
			[ '_stonewright_backups', ElementorKitWriter::META_KEY ],
			array_column( $this->meta_writes(), 'meta_key' )
		);
	}

	public function test_apply_snapshots_the_kit_before_it_writes(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertIsArray( $result );
		self::assertNotSame( '', $result['snapshot_id'] );

		$keys = array_column( $this->meta_writes(), 'meta_key' );
		self::assertSame( '_stonewright_backups', $keys[0] );
	}

	public function test_apply_creates_a_custom_entry_for_a_token_the_kit_lacks(): void {
		$contract                                = $this->contract();
		$contract['tokens']['colors']['surface'] = '#f8fafc';
		$id                                      = $this->store_direction( $contract );
		$plan                                    = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 2, $result['applied'] );

		$settings = $this->live_settings();
		self::assertSame(
			[ 'accent', 'surface' ],
			array_column( $settings['custom_colors'], '_id' )
		);
		self::assertSame( '#f8fafc', $settings['custom_colors'][1]['color'] );
	}

	public function test_apply_updates_typography_in_place(): void {
		$contract = $this->contract();
		$contract['tokens']['typography']['heading']['font-size'] = '56px';
		$id   = $this->store_direction( $contract );
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		$settings = $this->live_settings();
		self::assertSame(
			[
				'size' => 56.0,
				'unit' => 'px',
			],
			$settings['system_typography'][0]['typography_font_size']
		);
		self::assertSame( 'Fraunces', $settings['system_typography'][0]['typography_font_family'] );
	}

	public function test_apply_on_a_synced_kit_changes_nothing(): void {
		$contract                               = $this->contract();
		$contract['tokens']['colors']['accent'] = '#C2410C';
		$id                                     = $this->store_direction( $contract );
		$plan                                   = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 0, $result['applied'] );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( [], $this->meta_writes() );
	}

	public function test_apply_records_an_audit_event(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertContains(
			'stonewright/design-direction-sync-apply',
			array_column( $this->audit_rows(), 'ability_name' )
		);
	}

	// -------------------------------------------------------------------------
	// Rejections.
	// -------------------------------------------------------------------------

	public function test_apply_refuses_a_stale_base_hash(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$settings                          = $this->kit_settings();
		$settings['custom_colors'][0]['color'] = '#111111';
		$this->write_live_settings( $settings );
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorKitSyncPlanner::STALE_CODE, $result->get_error_code() );
		self::assertSame( [], $this->meta_writes() );
	}

	public function test_apply_requires_a_base_hash(): void {
		$id = $this->store_direction();

		$result = ( new DirectionSyncApply( $this->service ) )->execute( [ 'id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorKitSyncPlanner::ERROR_CODE, $result->get_error_code() );
		self::assertSame( [], $this->meta_writes() );
	}

	public function test_apply_refuses_a_direction_that_is_not_sync_ready(): void {
		$contract                            = $this->contract();
		$contract['readiness']['sync_ready'] = false;
		$id                                  = $this->store_direction( $contract );
		$plan                                = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( DesignDirectionService::NOT_READY_CODE, $result->get_error_code() );
		self::assertSame( [], $this->meta_writes() );
	}

	public function test_apply_refuses_a_plan_with_a_blocked_value(): void {
		$contract                               = $this->contract();
		$contract['tokens']['colors']['accent'] = 'var(--brand)';
		$id                                     = $this->store_direction( $contract );
		$plan                                   = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorKitSyncPlanner::BLOCKED_CODE, $result->get_error_code() );
		self::assertSame( [], $this->meta_writes() );
	}

	public function test_apply_reports_a_missing_direction(): void {
		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => 999,
				'base_hash' => str_repeat( 'a', 64 ),
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( DesignDirectionService::NOT_FOUND_CODE, $result->get_error_code() );
		self::assertSame( [], $this->meta_writes() );
	}

	public function test_apply_refuses_when_the_kit_post_cannot_be_snapshotted(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		unset( $GLOBALS['stonewright_test_posts'][ self::KIT_ID ] );

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorKitWriter::BACKUP_CODE, $result->get_error_code() );
		self::assertSame( [], $this->meta_writes() );
	}

	public function test_apply_reports_a_write_that_did_not_land(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ ElementorKitWriter::META_KEY => false ];

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorKitWriter::ERROR_CODE, $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Production-safe mode.
	// -------------------------------------------------------------------------

	public function test_apply_is_blocked_without_a_token_in_production_safe_mode(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'        => $id,
				'base_hash' => (string) $plan['base_hash'],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $this->meta_writes() );
	}

	public function test_apply_accepts_a_token_bound_to_this_direction_and_base_hash(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$args  = [
			'id'        => $id,
			'base_hash' => (string) $plan['base_hash'],
		];
		$token = ConfirmationToken::issue( 'stonewright/design-direction-sync-apply', $args );

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			$args + [ 'confirmation_token' => $token ]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, $result['applied'] );
	}

	public function test_apply_rejects_a_token_issued_for_another_base_hash(): void {
		$id   = $this->store_direction();
		$plan = ( new DirectionSyncPlan( $this->service ) )->execute( [ 'id' => $id ] );
		self::assertIsArray( $plan );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$token = ConfirmationToken::issue(
			'stonewright/design-direction-sync-apply',
			[
				'id'        => $id,
				'base_hash' => str_repeat( 'b', 64 ),
			]
		);

		$result = ( new DirectionSyncApply( $this->service ) )->execute(
			[
				'id'                 => $id,
				'base_hash'          => (string) $plan['base_hash'],
				'confirmation_token' => $token,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
		self::assertSame( [], $this->meta_writes() );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Stores a sync-ready direction and returns its id.
	 *
	 * @param array<string,mixed>|null $contract Contract to store; defaults to the fixture.
	 */
	private function store_direction( ?array $contract = null ): int {
		$saved = $this->service->save(
			[
				'slug'        => 'quarry',
				'status'      => 'draft',
				'source_type' => 'manual',
				'contract'    => $contract ?? $this->contract(),
			],
			7
		);

		self::assertIsArray( $saved );

		return (int) $saved['id'];
	}

	/**
	 * A sync-ready contract that differs from the kit in exactly one color.
	 *
	 * @return array<string,mixed>
	 */
	private function contract(): array {
		$contract = DirectionContract::defaults();

		$contract['identity']['name']        = 'Quarry';
		$contract['tokens']['colors']        = [
			'primary' => '#1F2933',
			'accent'  => '#7c2d12',
		];
		$contract['tokens']['typography']    = [
			'heading' => [
				'font-family' => 'Fraunces',
				'font-size'   => '48px',
			],
		];
		$contract['readiness']['sync_ready'] = true;

		return $contract;
	}

	/**
	 * Elementor kit settings as they live in post meta.
	 *
	 * @return array<string,mixed>
	 */
	private function kit_settings(): array {
		return [
			'page_title_selector' => 'h1.entry-title',
			'system_colors'       => [
				[
					'_id'   => 'primary',
					'title' => 'Primary',
					'color' => '#1F2933',
				],
			],
			'custom_colors'       => [
				[
					'_id'   => 'accent',
					'title' => 'Accent',
					'color' => '#C2410C',
				],
			],
			'system_typography'   => [
				[
					'_id'                      => 'heading',
					'title'                    => 'Heading',
					'typography_font_family'   => 'Fraunces',
					'typography_font_size'     => [
						'size' => 48,
						'unit' => 'px',
					],
				],
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function live_settings(): array {
		$settings = get_post_meta( self::KIT_ID, ElementorKitWriter::META_KEY, true );

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * @param array<string,mixed> $settings Kit settings to store directly.
	 */
	private function write_live_settings( array $settings ): void {
		$post       = $GLOBALS['stonewright_test_posts'][ self::KIT_ID ];
		$meta       = (array) $post->meta;
		$meta[ ElementorKitWriter::META_KEY ] = $settings;
		$post->meta = $meta;

		$GLOBALS['stonewright_test_posts'][ self::KIT_ID ] = $post;
	}

	/**
	 * Post-meta writes captured by the test stubs.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function meta_writes(): array {
		return array_values(
			array_filter(
				(array) ( $GLOBALS['stonewright_test_post_meta_calls'] ?? [] ),
				static fn( array $call ): bool => 'update' === ( $call['action'] ?? '' )
			)
		);
	}

	/**
	 * Audit-log rows captured by the test wpdb double.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function audit_rows(): array {
		$rows = [];

		foreach ( (array) ( $GLOBALS['stonewright_test_wpdb_inserts'] ?? [] ) as $insert ) {
			$data = is_array( $insert['data'] ?? null ) ? $insert['data'] : $insert;
			if ( isset( $data['ability_name'] ) ) {
				$rows[] = $data;
			}
		}

		return $rows;
	}
}

/**
 * In-memory repository double for the sync ability tests.
 *
 * Each direction test file carries its own double so the files stay
 * independent: none of them depends on another file having been loaded first.
 */
final class DirectionSyncRepository extends DesignDirectionRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];

	/** @var list<array<string,mixed>> */
	public array $version_rows = [];

	private int $next_id = 1;

	private int $next_version_id = 1;

	public function __construct() {
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return list<array<string,mixed>>
	 */
	public function list( array $filters = [] ): array {
		return array_values( $this->records );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		foreach ( $this->records as $record ) {
			if ( $record['slug'] === $slug ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $record
	 * @return int|\WP_Error
	 */
	public function save( array $record ) {
		$id = isset( $record['id'] ) ? (int) $record['id'] : $this->next_id++;

		$record['id']           = $id;
		$record['created_at'] ??= '2026-07-24 09:00:00';
		$record['updated_at']   = '2026-07-24 09:00:00';
		$this->records[ $id ]   = $record;

		return $id;
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return int|\WP_Error
	 */
	public function add_version( array $snapshot ) {
		$snapshot['id']         = $this->next_version_id++;
		$snapshot['created_at'] = '2026-07-24 09:00:00';
		$this->version_rows[]   = $snapshot;

		return (int) $snapshot['id'];
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function versions( int $id ): array {
		return array_values(
			array_filter(
				$this->version_rows,
				static fn( array $row ): bool => (int) $row['direction_id'] === $id
			)
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function version( int $id, int $revision ): ?array {
		foreach ( $this->version_rows as $row ) {
			if ( (int) $row['direction_id'] === $id && (int) $row['revision'] === $revision ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function archive( int $id ) {
		if ( ! isset( $this->records[ $id ] ) ) {
			return new \WP_Error( 'stonewright_direction_write_failed', 'Missing record.' );
		}

		$this->records[ $id ]['status'] = 'archived';

		return true;
	}

	public function begin_transaction(): void {
	}

	public function commit_transaction(): void {
	}

	public function rollback_transaction(): void {
	}
}
