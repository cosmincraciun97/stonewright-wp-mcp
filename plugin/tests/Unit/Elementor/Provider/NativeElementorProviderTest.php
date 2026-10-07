<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\NativeElementorProvider;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;

require_once __DIR__ . '/FakeNativeRuntime.php';

/**
 * @covers \Stonewright\WpMcp\Elementor\Provider\NativeElementorProvider
 */
final class NativeElementorProviderTest extends TestCase {

	private const POST = 9301;
	private const KIT  = 9300;

	private FakeNativeRuntime $runtime;

	protected function setUp(): void {
		AtomicSchemaRepository::invalidate();
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_elementor_v4_atomic' => true ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true, 'manage_options' => true, 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [
			self::KIT  => (object) [ 'ID' => self::KIT, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Kit', 'post_content' => '', 'post_excerpt' => '', 'meta' => [ '_elementor_page_settings' => [ 'a' => 1 ] ] ],
			self::POST => (object) [ 'ID' => self::POST, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Page', 'post_content' => '', 'post_excerpt' => '', 'meta' => [ '_elementor_data' => wp_json_encode( self::document() ), '_elementor_edit_mode' => 'builder' ] ],
		];
		$this->runtime            = new FakeNativeRuntime();
		$this->runtime->styles    = [ 'h1' => self::style( 'color: red' ), 'p' => self::style( 'margin: 0' ) ];
	}

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		AtomicSchemaRepository::invalidate();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	// ---------------------------------------------------------------- routing and gates

	public function test_an_ability_whose_native_write_is_refused_never_executes(): void {
		foreach ( [ 'elementor/manage-classes', 'elementor/manage-global-variable' ] as $name ) {
			$result = $this->provider()->run( $name, [ 'operations' => [ [ 'action' => 'delete', 'id' => 'g-1' ] ] ] );

			self::assertInstanceOf( \WP_Error::class, $result, $name );
			self::assertSame( 'stonewright_native_route_refused', $result->get_error_code(), $name );
			$data = $result->get_error_data();
			self::assertSame( 409, $data['status'] );
			self::assertSame( 'stonewright_v4_fallback', $data['route']['route'] );
			self::assertSame( 'upstream_global_clear_cache', $data['route']['reason'] );
			self::assertNotSame( [], $data['route']['fallback'] );
		}
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_a_dry_run_of_a_refused_route_is_a_plan_that_says_it_cannot_execute(): void {
		$result = $this->provider()->run( 'elementor/manage-classes', [ 'operations' => [ [ 'action' => 'delete', 'id' => 'g-1' ] ] ], [ 'dry_run' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'refused', $result['status'] );
		self::assertFalse( $result['executable'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'upstream_global_clear_cache', $result['refusal']['reason'] );
		self::assertSame( 'stonewright_native_route_refused', $result['refusal']['code'] );
		self::assertNotSame( [], $result['refusal']['fallback'] );
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_a_dry_run_of_an_uncertified_or_unavailable_ability_names_the_missing_feature(): void {
		$result = $this->provider( [], [ 'site_exposure_enabled' => false ] )->run( 'elementor/build-composition', self::composition_input(), [ 'dry_run' => true ] );

		self::assertSame( 'refused', $result['status'] );
		self::assertSame( 'elementor_mcp_site_exposure', $result['refusal']['missing_feature'] );
		self::assertFalse( $result['executable'] );
	}

	public function test_a_dry_run_that_fails_a_gate_is_still_an_error(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;

		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input(), [ 'dry_run' => true ] );

		self::assertSame( 'feature_disabled', $result->get_error_code() );
	}

	public function test_an_uncertified_ability_falls_back_with_the_exact_issues_and_never_executes(): void {
		$abilities = self::recorded();
		foreach ( $abilities as $index => $ability ) {
			if ( 'elementor/manage-default-styles' === $ability['name'] ) {
				$abilities[ $index ]['input_schema']['properties']['injected'] = [ 'type' => 'string' ];
			}
		}

		$result = $this->provider( $abilities )->run( 'elementor/manage-default-styles', self::style_input() );

		self::assertSame( 'stonewright_native_route_refused', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'upstream_contract_not_certified', $data['route']['reason'] );
		self::assertContains( 'input_schema_mismatch', $data['route']['issues'] );
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_an_unknown_ability_is_refused(): void {
		$result = $this->provider()->run( 'elementor/publish-document', [ 'post_id' => self::POST ] );

		self::assertSame( 'stonewright_native_route_refused', $result->get_error_code() );
		self::assertSame( 'not_available_for_certification', $result->get_error_data()['route']['reason'] );
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_the_experimental_flag_and_production_safe_gates_still_apply_to_native_writes(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;
		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );
		self::assertSame( 'feature_disabled', $result->get_error_code() );

		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'production-safe', 'stonewright_elementor_v4_atomic' => true ];
		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );
		self::assertSame( 'stonewright_v4_experimental_production_block', $result->get_error_code() );
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_an_inactive_atomic_editor_fails_with_the_exact_missing_feature(): void {
		$result = $this->provider( null, [ 'atomic_editor_active' => false ] )->run( 'elementor/manage-default-styles', self::style_input() );

		self::assertSame( 'stonewright_native_atomic_editor_inactive', $result->get_error_code() );
		self::assertSame( 'atomic_editor', $result->get_error_data()['missing_feature'] );
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_a_site_without_native_abilities_falls_back_and_names_the_missing_feature(): void {
		$result = $this->provider( [], [ 'site_exposure_enabled' => false ] )->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'stonewright_native_route_refused', $result->get_error_code() );
		self::assertSame( 'elementor_mcp_site_exposure', $result->get_error_data()['route']['missing_feature'] );
	}

	// ---------------------------------------------------------------- kit-level default styles

	public function test_default_styles_dry_run_plans_without_calling_elementor_or_writing(): void {
		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input(), [ 'dry_run' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'planned', $result['status'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'native', $result['route']['route'] );
		self::assertSame( [ [ 'kind' => 'default_style', 'ref' => 'h2', 'action' => 'update', 'index' => 0 ] ], $result['planned'] );
		self::assertSame( [], $this->runtime->calls );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_default_styles_write_runs_inside_the_closure_and_verifies_the_stored_state(): void {
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$runtime->styles['h2'] = self::style( 'font-size: 2rem' );
			return [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ] ] ];
		};
		$before = TreeHasher::hash( $this->runtime->styles );

		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'applied', $result['status'] );
		self::assertSame( [ 'elementor/manage-default-styles' ], $this->runtime->called_abilities() );
		self::assertSame( [ 'operations' => self::style_input()['operations'] ], $this->runtime->calls[0][1] );
		self::assertNotSame( '', $result['kit_snapshot_id'] );
		self::assertSame( self::KIT, $result['kit_id'] );
		self::assertSame( $before, $result['write_receipt']['before_hash'] );
		self::assertSame( TreeHasher::hash( $this->runtime->styles ), $result['write_receipt']['readback_hash'] );
		self::assertSame( 'verified', $result['write_receipt']['verification_status'] );
		self::assertSame( 'not_needed', $result['write_receipt']['rollback_status'] );
		self::assertSame( [ 'h2' ], $result['changed_refs'] );
		self::assertTrue( $result['readback']['verified'] );
		self::assertSame( 'default_styles_repository', $result['readback']['method'] );
		self::assertSame( 'not_applicable', $result['css']['status'] );
		self::assertFalse( PostWriteLock::owned_by( self::KIT, 'native' ), 'the lock is released' );
		self::assertFalse( get_option( 'stonewright_elementor_lock_' . self::KIT, false ) );
		self::assertNotContains( 'stonewright/elementor-css-regenerate', $this->runtime->called_abilities() );
	}

	public function test_default_styles_are_snapshotted_before_elementor_runs(): void {
		$seen = [];
		$this->runtime->executor = static function () use ( &$seen ): array {
			$seen = array_column( $GLOBALS['stonewright_test_post_meta_calls'], 'meta_key' );
			return [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ] ] ];
		};
		$this->runtime->executor = function ( string $name, array $input, FakeNativeRuntime $runtime ) use ( &$seen ): array {
			$seen = array_column( $GLOBALS['stonewright_test_post_meta_calls'], 'meta_key' );
			$runtime->styles['h2'] = self::style( 'font-size: 2rem' );
			return [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ] ] ];
		};

		$this->provider()->run( 'elementor/manage-default-styles', self::style_input() );

		self::assertContains( '_stonewright_backups', $seen, 'the kit snapshot exists before the native call' );
	}

	public function test_a_failed_kit_snapshot_aborts_before_elementor_runs(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => false ];

		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );

		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_a_busy_write_lock_aborts_before_elementor_runs(): void {
		PostWriteLock::acquire( self::KIT, 'someone-else' );

		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );

		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertSame( [], $this->runtime->calls );
		PostWriteLock::release( self::KIT, 'someone-else' );
	}

	public function test_default_styles_input_is_validated_before_anything_happens(): void {
		$cases = [
			'no operations'    => [ 'operations' => [] ],
			'too many'         => [ 'operations' => array_fill( 0, 21, [ 'action' => 'delete', 'tag' => 'p' ] ) ],
			'unknown action'   => [ 'operations' => [ [ 'action' => 'create', 'tag' => 'p', 'css' => 'color: red' ] ] ],
			'update without css' => [ 'operations' => [ [ 'action' => 'update', 'tag' => 'p' ] ] ],
			'no tag'           => [ 'operations' => [ [ 'action' => 'delete' ] ] ],
			'bad mode'         => [ 'operations' => [ [ 'action' => 'update', 'tag' => 'p', 'css' => 'color: red', 'mode' => 'merge' ] ] ],
			'not a list'       => [ 'operations' => 'delete everything' ],
		];
		foreach ( $cases as $label => $input ) {
			$result = $this->provider()->run( 'elementor/manage-default-styles', $input );

			self::assertInstanceOf( \WP_Error::class, $result, $label );
			self::assertSame( 'stonewright_native_invalid_input', $result->get_error_code(), $label );
			self::assertSame( 400, $result->get_error_data()['status'], $label );
		}
		self::assertSame( [], $this->runtime->calls );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_a_partial_failure_rolls_the_whole_batch_back(): void {
		$before = $this->runtime->styles;
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$runtime->styles['h2'] = self::style( 'font-size: 2rem' );
			return [
				'status'  => 'partial_error',
				'results' => [
					[ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ],
					[ 'index' => 1, 'action' => 'update', 'status' => 'error', 'code' => 'invalid_tag', 'message' => 'Invalid HTML tag' ],
				],
			];
		};

		$result = $this->provider()->run( 'elementor/manage-default-styles', [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ], [ 'action' => 'update', 'tag' => 'nope', 'css' => 'x: y' ] ] ] );

		self::assertSame( 'stonewright_native_operations_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( 'failed', $data['verification_status'] );
		self::assertSame( 'invalid_tag', $data['operations'][1]['code'] );
		self::assertSame( $before, $this->runtime->styles );
	}

	public function test_a_change_to_an_unplanned_tag_is_unexpected_and_rolled_back(): void {
		$before = $this->runtime->styles;
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$runtime->styles['h2'] = self::style( 'font-size: 2rem' );
			$runtime->styles['p']  = self::style( 'margin: 99px' );
			return [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ] ] ];
		};

		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );

		self::assertSame( 'stonewright_native_unexpected_change', $result->get_error_code() );
		self::assertSame( [ 'p' ], $result->get_error_data()['unexpected_refs'] );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
		self::assertSame( $before, $this->runtime->styles );
	}

	public function test_a_claimed_update_or_delete_that_is_not_stored_fails_the_readback(): void {
		$this->runtime->executor = static fn(): array => [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ] ] ];
		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );
		self::assertSame( 'stonewright_native_readback_mismatch', $result->get_error_code() );
		self::assertSame( 'failed', $result->get_error_data()['verification_status'] );

		$this->runtime->executor = static fn(): array => [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'delete', 'status' => 'ok', 'tag' => 'p' ] ] ];
		$result = $this->provider()->run( 'elementor/manage-default-styles', [ 'operations' => [ [ 'action' => 'delete', 'tag' => 'p' ] ] ] );
		self::assertSame( 'stonewright_native_readback_mismatch', $result->get_error_code() );
	}

	public function test_a_wp_error_from_elementor_that_changed_nothing_needs_no_rollback(): void {
		$this->runtime->executor = static fn(): \WP_Error => new \WP_Error( 'elementor_v4_required', 'Atomic Editor is off', [ 'status' => 403 ] );

		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );

		self::assertSame( 'elementor_v4_required', $result->get_error_code() );
		self::assertSame( 'not_needed', $result->get_error_data()['rollback_status'] );
		self::assertSame( 'failed', $result->get_error_data()['verification_status'] );
	}

	public function test_a_wp_error_that_left_partial_state_is_rolled_back(): void {
		$before = $this->runtime->styles;
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): \WP_Error {
			$runtime->styles['h2'] = self::style( 'half done' );
			return new \WP_Error( 'unexpected_server_error', 'boom', [ 'status' => 500 ] );
		};

		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );

		self::assertSame( 'unexpected_server_error', $result->get_error_code() );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
		self::assertSame( $before, $this->runtime->styles );
	}

	public function test_a_failed_rollback_is_reported_not_hidden(): void {
		$this->runtime->restore_styles_succeeds = false;
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): \WP_Error {
			$runtime->styles['h2'] = self::style( 'half done' );
			return new \WP_Error( 'unexpected_server_error', 'boom', [ 'status' => 500 ] );
		};

		$result = $this->provider()->run( 'elementor/manage-default-styles', self::style_input() );

		self::assertSame( 'failed', $result->get_error_data()['rollback_status'] );
	}

	// ---------------------------------------------------------------- composition: draft document

	public function test_a_composition_on_a_draft_is_applied_verified_by_nested_readback_and_css_is_regenerated_post_scoped(): void {
		$this->runtime->executor = self::composition_executor();
		$before_hash = TreeHasher::hash( self::document() );

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'applied', $result['status'] );
		self::assertFalse( $result['staged'] );
		self::assertFalse( $result['published'] );
		self::assertSame( [ 'n1' ], $result['root_element_ids'] );
		self::assertSame( 3, $result['readback']['checked'] );
		self::assertSame( 'document_tree', $result['readback']['method'] );
		self::assertTrue( $result['readback']['verified'] );
		self::assertSame( $before_hash, $result['write_receipt']['before_hash'] );
		self::assertNotSame( $before_hash, $result['write_receipt']['readback_hash'] );
		self::assertSame( 'verified', $result['write_receipt']['verification_status'] );
		self::assertNotSame( '', $result['write_receipt']['snapshot_id'] );
		self::assertSame( 'v4', $result['write_receipt']['architecture'] );
		self::assertSame( 'regenerated', $result['css']['status'] );
		self::assertSame( [ 'elementor/build-composition', 'stonewright/elementor-css-regenerate' ], $this->runtime->called_abilities() );
		self::assertSame( [ 'post_id' => self::POST ], $this->runtime->calls[1][1], 'post-scoped, nothing site-wide' );
		self::assertSame( [ 'post_id' => self::POST, 'xml_structure' => self::composition_input()['xml_structure'], 'parent_id' => 'root1' ], $this->runtime->calls[0][1] );
		self::assertNull( get_option( 'stonewright_elementor_lock_' . self::POST, null ) );
	}

	public function test_a_css_regeneration_failure_does_not_undo_the_write_but_is_reported(): void {
		$this->runtime->executor   = self::composition_executor();
		$this->runtime->css_result = new \WP_Error( 'stonewright_css_busy', 'busy', [ 'status' => 409 ] );

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'applied', $result['status'] );
		self::assertSame( 'failed', $result['css']['status'] );
		self::assertSame( 'stonewright_css_busy', $result['css']['error_code'] );
		self::assertNotSame( [], $result['warnings'] );
		self::assertStringContainsString( 'stonewright-elementor-css-regenerate', $result['next_step'] );
	}

	public function test_a_dropped_nested_child_is_an_error_and_the_document_is_restored(): void {
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$result = self::persist_composition( $runtime );
			// Elementor reports three nodes but the third child never reached storage.
			$tree = json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true );
			array_pop( $tree[0]['elements'][1]['elements'] );
			$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $tree );
			return $result;
		};

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'n3', $data['problems'][0]['id'] );
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( 'failed', $data['verification_status'] );
		self::assertSame( TreeHasher::hash( self::document() ), TreeHasher::hash( json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true ) ) );
		self::assertNotContains( 'stonewright/elementor-css-regenerate', $this->runtime->called_abilities() );
	}

	public function test_a_change_to_an_element_the_composition_did_not_touch_is_unexpected_and_rolled_back(): void {
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$result = self::persist_composition( $runtime );
			$tree   = json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true );
			$tree[0]['elements'][0]['settings']['title'] = [ '$$type' => 'string', 'value' => 'Silently changed' ];
			$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $tree );
			return $result;
		};

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'stonewright_native_unexpected_change', $result->get_error_code() );
		self::assertSame( [ 'keep1' ], $result->get_error_data()['unexpected_refs'] );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
		self::assertSame( TreeHasher::hash( self::document() ), TreeHasher::hash( json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true ) ) );
	}

	public function test_the_result_ids_must_match_the_resolved_structure(): void {
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$result = self::persist_composition( $runtime );
			$result['root_element_ids'] = [ 'someone-else' ];
			return $result;
		};

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'stonewright_native_result_inconsistent', $result->get_error_code() );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
	}

	public function test_replace_children_must_leave_exactly_the_new_roots_under_the_parent(): void {
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			// Elementor reports success but the old child is still there.
			return self::persist_composition( $runtime );
		};

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input( [ 'mode' => 'replace_children' ] ) );

		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
		self::assertSame( 'replace_children_mismatch', $result->get_error_data()['problems'][0]['code'] );
	}

	public function test_replace_children_that_removed_the_old_child_verifies(): void {
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$result = self::persist_composition( $runtime );
			$tree   = json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true );
			$tree[0]['elements'] = [ $tree[0]['elements'][1] ];
			$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $tree );
			$result['removed_element_ids'] = [ 'keep1' ];
			return $result;
		};

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input( [ 'mode' => 'replace_children' ] ) );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'applied', $result['status'] );
		self::assertSame( [ 'keep1' ], $result['removed_element_ids'] );
	}

	public function test_css_regeneration_runs_after_the_write_lock_is_released(): void {
		$this->runtime->executor = self::composition_executor();

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'regenerated', $result['css']['status'] );
		self::assertFalse( $this->runtime->lock_held_during_css, 'the CSS ability takes the same per-post lock, so it must not run while the closure holds it' );
	}

	public function test_a_composition_dry_run_asks_elementor_to_validate_only_and_writes_nothing(): void {
		$this->runtime->executor = static fn(): array => [ 'success' => true, 'post_id' => self::POST, 'root_element_ids' => [ 'n1' ], 'resolved_xml' => '<e-div-block id="n1"/>', 'edit_url' => 'x', 'version' => 'v' ];

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input(), [ 'dry_run' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'planned', $result['status'] );
		self::assertSame( true, $this->runtime->calls[0][1]['dry_run'] );
		self::assertSame( [], array_filter( $GLOBALS['stonewright_test_post_meta_calls'], static fn( array $call ): bool => '_stonewright_backups' === $call['meta_key'] ) );
		self::assertSame( [ [ 'kind' => 'element', 'ref' => 'root1', 'action' => 'insert_composition', 'index' => 0 ] ], $result['planned'] );
	}

	// ---------------------------------------------------------------- composition: published document

	public function test_an_edit_on_a_published_document_is_reported_as_staged_in_autosave_never_applied(): void {
		$this->runtime->statuses[ self::POST ] = 'publish';
		$live_before = $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'];
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$tree = self::document();
			$tree = self::with_composition( $tree );
			$runtime->autosaves[ self::POST ] = $tree;
			return self::composition_result();
		};

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'staged_in_autosave', $result['status'] );
		self::assertTrue( $result['staged'] );
		self::assertFalse( $result['published'] );
		self::assertTrue( $result['queued'] );
		self::assertSame( 'autosave_tree', $result['readback']['method'] );
		self::assertTrue( $result['readback']['verified'] );
		self::assertSame( 'not_applicable', $result['css']['status'] );
		self::assertSame( $live_before, $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], 'the live document is untouched' );
		self::assertSame( [ 'elementor/build-composition' ], $this->runtime->called_abilities(), 'nothing is published and no CSS is regenerated' );
		self::assertStringContainsString( 'explicit', $result['next_step'] );
		self::assertSame( TreeHasher::hash( self::document() ), $result['write_receipt']['before_hash'] );
		self::assertSame( TreeHasher::hash( self::with_composition( self::document() ) ), $result['write_receipt']['readback_hash'] );
	}

	public function test_a_staged_edit_builds_on_the_pending_autosave_not_the_live_document(): void {
		$this->runtime->statuses[ self::POST ] = 'private';
		$earlier = self::with_composition( self::document(), 'p1' );
		$this->runtime->autosaves[ self::POST ] = $earlier;
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$runtime->autosaves[ self::POST ] = self::with_composition( $runtime->autosaves[ self::POST ] );
			return self::composition_result();
		};

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'staged_in_autosave', $result['status'] );
		self::assertSame( TreeHasher::hash( $earlier ), $result['write_receipt']['before_hash'] );
	}

	public function test_a_staged_write_that_changed_the_live_document_fails_and_restores_it(): void {
		$this->runtime->statuses[ self::POST ] = 'publish';
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$runtime->autosaves[ self::POST ] = self::with_composition( self::document() );
			$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( self::with_composition( self::document() ) );
			return self::composition_result();
		};

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'stonewright_native_live_document_changed', $result->get_error_code() );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
		self::assertSame( TreeHasher::hash( self::document() ), TreeHasher::hash( json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true ) ) );
		self::assertNull( $this->runtime->autosaves[ self::POST ] ?? null, 'the autosave that did not exist before is removed' );
	}

	public function test_a_staged_write_whose_autosave_is_missing_fails(): void {
		$this->runtime->statuses[ self::POST ] = 'publish';
		$this->runtime->executor = static fn(): array => self::composition_result();

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'stonewright_native_autosave_missing', $result->get_error_code() );
	}

	public function test_a_staged_nested_drop_restores_the_previous_autosave(): void {
		$this->runtime->statuses[ self::POST ]  = 'publish';
		$earlier                                = self::with_composition( self::document(), 'p1' );
		$this->runtime->autosaves[ self::POST ] = $earlier;
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$tree = self::with_composition( $runtime->autosaves[ self::POST ] );
			array_pop( $tree[0]['elements'][2]['elements'] );
			$runtime->autosaves[ self::POST ] = $tree;
			return self::composition_result();
		};

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
		self::assertSame( $earlier, $this->runtime->autosaves[ self::POST ] );
	}

	public function test_an_ambiguous_autosave_on_an_unpublished_document_is_refused_before_writing(): void {
		$this->runtime->autosaves[ self::POST ] = self::document();

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'stonewright_native_ambiguous_autosave', $result->get_error_code() );
		self::assertSame( [], $this->runtime->calls );
	}

	// ---------------------------------------------------------------- composition: routing and exposure

	public function test_a_v3_document_routes_to_the_stonewright_v3_writers_and_is_never_converted(): void {
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( [ [ 'id' => 'v3a', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] );

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input( [ 'parent_id' => 'document' ] ) );

		self::assertSame( 'stonewright_native_route_refused', $result->get_error_code() );
		$route = $result->get_error_data()['document_route'];
		self::assertSame( 'stonewright_v3', $route['route'] );
		self::assertFalse( $route['conversion'] );
		self::assertContains( 'stonewright/elementor-v3-batch-mutate', $route['fallback'] );
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_a_mixed_document_routes_per_subtree(): void {
		$mixed = [
			[ 'id' => 'v3a', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'v3b', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ] ] ],
			[ 'id' => 'root1', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [] ],
		];
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $mixed );

		$v3_parent = $this->provider()->run( 'elementor/build-composition', self::composition_input( [ 'parent_id' => 'v3a' ] ) );
		self::assertSame( 'stonewright_v3', $v3_parent->get_error_data()['document_route']['route'] );

		$root = $this->provider()->run( 'elementor/build-composition', self::composition_input( [ 'parent_id' => 'document' ] ) );
		self::assertSame( 'refused', $root->get_error_data()['document_route']['route'] );
		self::assertSame( 'mixed_document_root_insert', $root->get_error_data()['document_route']['reason'] );

		$missing = $this->provider()->run( 'elementor/build-composition', self::composition_input( [ 'parent_id' => 'nope' ] ) );
		self::assertSame( 'parent_not_found', $missing->get_error_data()['document_route']['reason'] );
		self::assertSame( [], $this->runtime->calls );

		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$tree = json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true );
			$tree[1]['elements'][] = self::composition_subtree();
			$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $tree );
			return self::composition_result();
		};
		$atomic = $this->provider()->run( 'elementor/build-composition', self::composition_input() );
		self::assertIsArray( $atomic, $atomic instanceof \WP_Error ? $atomic->get_error_message() . wp_json_encode( $atomic->get_error_data() ) : '' );
		self::assertSame( 'applied', $atomic['status'] );
		self::assertSame( 'mixed', $atomic['write_receipt']['architecture'] );
	}

	public function test_an_atomic_type_the_live_site_lacks_fails_with_the_exact_missing_feature(): void {
		$this->runtime->atomic_types = [ 'e-div-block', 'e-heading' ];

		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input() );

		self::assertSame( 'stonewright_atomic_type_unavailable', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( [ 'e-paragraph' ], $data['missing_types'] );
		self::assertSame( 'atomic_type:e-paragraph', $data['missing_feature'] );
		self::assertSame( [ 'e-div-block', 'e-heading' ], $data['available_types'] );
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_a_composition_input_is_validated(): void {
		$cases = [
			'no post'     => [ 'post_id' => 0 ],
			'empty xml'   => [ 'xml_structure' => '' ],
			'bad mode'    => [ 'mode' => 'wipe' ],
			'huge xml'    => [ 'xml_structure' => '<e-div-block>' . str_repeat( 'x', 300000 ) . '</e-div-block>' ],
			'bad parent'  => [ 'parent_id' => [ 'array' ] ],
		];
		foreach ( $cases as $label => $override ) {
			$result = $this->provider()->run( 'elementor/build-composition', self::composition_input( $override ) );

			self::assertInstanceOf( \WP_Error::class, $result, $label );
			self::assertSame( 'stonewright_native_invalid_input', $result->get_error_code(), $label );
		}
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_a_missing_post_is_not_found(): void {
		$result = $this->provider()->run( 'elementor/build-composition', self::composition_input( [ 'post_id' => 424242 ] ) );

		self::assertSame( 'not_found', $result->get_error_code() );
		self::assertSame( [], $this->runtime->calls );
	}

	// ---------------------------------------------------------------- readback ability

	public function test_the_certified_structure_read_returns_the_result_and_flags_a_pending_autosave(): void {
		$this->runtime->executor = static fn(): array => [ 'elements' => [ [ 'id' => 'root1', 'elType' => 'e-div-block', 'version' => 4, 'title' => '', 'elements' => [] ] ] ];
		$this->runtime->autosaves[ self::POST ] = self::document();

		$result = $this->provider()->run( 'elementor/get-page-structure', [ 'post_id' => self::POST ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'read', $result['status'] );
		self::assertTrue( $result['read_only'] );
		self::assertSame( 'published_document', $result['reads'] );
		self::assertTrue( $result['autosave_pending'] );
		self::assertSame( 'root1', $result['structure']['elements'][0]['id'] );
		self::assertSame( [ [ 'elementor/get-page-structure', [ 'post_id' => self::POST ] ] ], $this->runtime->calls );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'a read snapshots and writes nothing' );
	}

	public function test_the_structure_read_validates_its_input_and_the_post(): void {
		self::assertSame( 'stonewright_native_invalid_input', $this->provider()->run( 'elementor/get-page-structure', [ 'post_id' => 0 ] )->get_error_code() );
		self::assertSame( 'stonewright_native_invalid_input', $this->provider()->run( 'elementor/get-page-structure', [ 'post_id' => self::POST, 'include_content' => true ] )->get_error_code(), 'include_content needs element_id' );
		self::assertSame( 'not_found', $this->provider()->run( 'elementor/get-page-structure', [ 'post_id' => 424242 ] )->get_error_code() );
		self::assertSame( [], $this->runtime->calls );
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * @param list<array<string,mixed>>|null $abilities
	 * @param array<string,mixed>            $facts     Overrides of the environment facts.
	 */
	private function provider( ?array $abilities = null, array $facts = [] ): NativeElementorProvider {
		$abilities = $abilities ?? self::recorded();
		$facts     = array_merge(
			[
				'elementor_version' => '4.3.4', 'module_present' => true, 'module_active' => true, 'abilities_api' => true,
				'mcp_adapter' => true, 'mcp_adapter_version' => '0.6.1', 'mcp_adapter_provider' => 'elementor-core',
				'mcp_composer' => true, 'mcp_composer_version' => '1.0.19', 'site_exposure_enabled' => true, 'atomic_editor_active' => true,
			],
			$facts
		);
		$router = new ProviderRouter(
			static fn(): array => [],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => $abilities,
			static fn(): array => UpstreamAbilityDiscovery::summarize_environment( $facts )
		);
		return new NativeElementorProvider( $router, $this->runtime );
	}

	/** @return list<array<string,mixed>> */
	private static function recorded(): array {
		$decoded = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-native/elementor-4.3.4-abilities.json' ), true );
		return array_values( (array) $decoded['abilities'] );
	}

	/** @return array<string,mixed> */
	private static function style( string $css ): array {
		return [ 'id' => 'h', 'label' => 'x', 'type' => 'class', 'variants' => [ [ 'meta' => [ 'breakpoint' => 'desktop', 'state' => null ], 'props' => [ 'css' => $css ] ] ] ];
	}

	/** @return array<string,mixed> */
	private static function style_input(): array {
		return [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ] ] ];
	}

	/** @param array<string,mixed> $override @return array<string,mixed> */
	private static function composition_input( array $override = [] ): array {
		return array_merge(
			[
				'post_id'       => self::POST,
				'xml_structure' => '<e-div-block configuration-id="box"><e-heading configuration-id="h"/><e-paragraph configuration-id="p"/></e-div-block>',
				'parent_id'     => 'root1',
			],
			$override
		);
	}

	/** @return list<array<string,mixed>> */
	private static function document(): array {
		return [
			[
				'id' => 'root1', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [
					[ 'id' => 'keep1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'title' => [ '$$type' => 'string', 'value' => 'Keep me' ] ], 'elements' => [] ],
				],
			],
		];
	}

	/** @return array<string,mixed> */
	private static function composition_subtree( string $suffix = '' ): array {
		return [
			'id' => 'n1' . $suffix, 'elType' => 'e-div-block', 'settings' => [], 'elements' => [
				[ 'id' => 'n2' . $suffix, 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [], 'elements' => [] ],
				[ 'id' => 'n3' . $suffix, 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => [], 'elements' => [] ],
			],
		];
	}

	/**
	 * @param list<array<string,mixed>> $tree
	 * @return list<array<string,mixed>>
	 */
	private static function with_composition( array $tree, string $suffix = '' ): array {
		$tree[0]['elements'][] = self::composition_subtree( $suffix );
		return $tree;
	}

	/** @return array<string,mixed> */
	private static function composition_result(): array {
		return [
			'success'          => true,
			'post_id'          => self::POST,
			'root_element_ids' => [ 'n1' ],
			'edit_url'         => 'https://example.test/wp-admin/post.php?post=' . self::POST . '&action=elementor',
			'version'          => '2026-10-07 00:00:00',
			'resolved_xml'     => '<e-div-block configuration-id="box" id="n1"><e-heading configuration-id="h" id="n2"/><e-paragraph configuration-id="p" id="n3"/></e-div-block>',
		];
	}

	/** Writes the composition into the stored document, as Elementor does for an unpublished page. */
	private static function persist_composition( FakeNativeRuntime $runtime ): array {
		unset( $runtime );
		$tree = json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true );
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( self::with_composition( $tree ) );
		return self::composition_result();
	}

	private static function composition_executor(): \Closure {
		return static fn( string $name, array $input, FakeNativeRuntime $runtime ): array => self::persist_composition( $runtime );
	}
}
