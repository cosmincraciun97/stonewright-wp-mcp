<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Elementor\NativeExecute;
use Stonewright\WpMcp\Elementor\Provider\NativeElementorProvider;
use Stonewright\WpMcp\Elementor\Provider\NativeObserver;
use Stonewright\WpMcp\Elementor\Provider\NativeInputSchema;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once __DIR__ . '/FakeNativeRuntime.php';
require_once dirname( __DIR__, 2 ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\Elementor\NativeExecute
 * @covers \Stonewright\WpMcp\Elementor\Provider\NativeInputSchema
 */
final class NativeExecuteAbilityTest extends TestCase {
	use ChangeSetAssertions;

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
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		IncidentStore::reset_for_tests();
		$tree = [ [ 'id' => 'root1', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [ [ 'id' => 'keep1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [], 'elements' => [] ] ] ] ];
		$GLOBALS['stonewright_test_posts'] = [
			self::KIT  => (object) [ 'ID' => self::KIT, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Kit', 'post_content' => '', 'post_excerpt' => '', 'meta' => [] ],
			self::POST => (object) [ 'ID' => self::POST, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Page', 'post_content' => '', 'post_excerpt' => '', 'meta' => [ '_elementor_data' => wp_json_encode( $tree ) ] ],
		];
		$this->runtime         = new FakeNativeRuntime();
		$this->runtime->styles = [ 'p' => [ 'type' => 'class', 'variants' => [ [ 'props' => [ 'css' => 'margin: 0' ] ] ] ] ];
	}

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		AtomicSchemaRepository::invalidate();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		IncidentStore::reset_for_tests();
	}

	public function test_it_is_a_registered_experimental_write_ability_named_for_the_native_bridge(): void {
		$ability = $this->ability();

		self::assertSame( 'stonewright/elementor-native-execute', $ability->name() );
		self::assertSame( 'elementor', $ability->category() );
		self::assertTrue( $ability->meta()['experimental'] );
		$registry = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Core/AbilityRegistry.php' );
		self::assertStringContainsString( 'NativeExecute::class', $registry );
		self::assertStringContainsString( "'stonewright/elementor-native-execute'", (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Abilities/System/ToolProfile.php' ) );
	}

	public function test_the_input_schema_offers_only_routable_abilities_with_their_certified_input_shapes(): void {
		$schema = $this->ability()->input_schema();

		self::assertSame( [ 'elementor/build-composition', 'elementor/get-page-structure', 'elementor/manage-default-styles' ], $schema['properties']['ability']['enum'] );
		self::assertNotContains( 'elementor/manage-classes', $schema['properties']['ability']['enum'] );
		self::assertNotContains( 'elementor/manage-elements', $schema['properties']['ability']['enum'] );
		self::assertSame( [ 'ability', 'input' ], $schema['required'] );
		self::assertTrue( $schema['properties']['dry_run']['default'], 'writes plan first by default' );
		$branches = $schema['properties']['input']['anyOf'];
		self::assertCount( 3, $branches );
		$required = array_map( static fn( array $branch ): array => $branch['required'], $branches );
		self::assertContains( [ 'operations' ], $required );
		self::assertContains( [ 'post_id', 'xml_structure' ], $required );
		self::assertContains( [ 'post_id' ], $required );
		self::assertSame( [ 'append', 'replace_children' ], $branches[ array_search( [ 'post_id', 'xml_structure' ], $required, true ) ]['properties']['mode']['enum'] );
		self::assertArrayHasKey( 'repair_of', $schema['properties'] );
		self::assertArrayHasKey( 'supersedes', $schema['properties'] );
	}

	public function test_the_embedded_certified_schemas_stay_within_the_router_schema_limits(): void {
		$schema = $this->ability()->input_schema();
		foreach ( $schema['properties']['input']['anyOf'] as $branch ) {
			self::assertTrue( ProviderRouter::schema_within_limits( $branch ) );
		}
		self::assertLessThan( 32768, strlen( (string) wp_json_encode( $schema ) ) );
		self::assertFalse( ProviderRouter::schema_within_limits( [ 'type' => 'object', 'properties' => array_fill( 0, 300, [ 'type' => 'string' ] ) ] ) );
		self::assertSame( [ 'elementor/build-composition', 'elementor/get-page-structure', 'elementor/manage-default-styles' ], NativeInputSchema::routable_abilities() );
	}

	public function test_a_dry_run_is_the_default_and_calls_nothing_for_default_styles(): void {
		$result = $this->ability()->execute( [ 'ability' => 'elementor/manage-default-styles', 'input' => [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ] ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'planned', $result['status'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( [], $this->runtime->calls );
		self::assertSame( 'dry_run', $result['change_set']['verification']['evidence']['outcome'] );
		self::assertValidChangeSet( $result['change_set'] );
		self::assertSame( [], $result['change_set']['applied'] );
	}

	public function test_a_verified_default_styles_write_returns_a_change_set_with_planned_and_applied_changes(): void {
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$runtime->styles['h2'] = [ 'type' => 'class', 'variants' => [ [ 'props' => [ 'css' => 'font-size: 2rem' ] ] ] ];
			return [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ] ] ];
		};

		$result = $this->ability()->execute( [ 'ability' => 'elementor/manage-default-styles', 'dry_run' => false, 'input' => [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ] ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'applied', $result['status'] );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( [ [ 'kind' => 'default_style', 'ref' => 'h2', 'action' => 'update', 'index' => 0 ] ], $change_set['planned'] );
		self::assertSame( $change_set['planned'], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( $result['write_receipt']['before_hash'], $change_set['before_hash'] );
		self::assertSame( $result['write_receipt']['readback_hash'], $change_set['after_hash'] );
		self::assertFalse( $change_set['rollback_available'], 'a kit snapshot does not cover the default style posts' );
		self::assertNull( $change_set['rollback_recipe_ref'] );
		self::assertSame( 'mode_policy', $change_set['approval_reason'] );
		self::assertSame( $change_set['change_set_id'], end( $GLOBALS['stonewright_test_wpdb_inserts'] )['data']['change_set_id'] );
	}

	public function test_a_staged_composition_is_a_queued_change_set_with_nothing_applied(): void {
		$this->runtime->statuses[ self::POST ] = 'publish';
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$tree = json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true );
			$tree[0]['elements'][] = [ 'id' => 'n1', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [] ];
			$runtime->autosaves[ self::POST ] = $tree;
			return [ 'success' => true, 'post_id' => self::POST, 'root_element_ids' => [ 'n1' ], 'edit_url' => 'x', 'version' => 'v', 'resolved_xml' => '<e-div-block id="n1"/>' ];
		};

		$result = $this->ability()->execute( [ 'ability' => 'elementor/build-composition', 'dry_run' => false, 'input' => [ 'post_id' => self::POST, 'xml_structure' => '<e-div-block configuration-id="a"/>', 'parent_id' => 'root1' ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'staged_in_autosave', $result['status'] );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'queued', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( [], $change_set['applied'], 'a staged edit is never reported as applied' );
		self::assertCount( 1, $change_set['planned'] );
		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( $result['write_receipt']['readback_hash'], $change_set['verification']['evidence']['observed_hash'] );
	}

	public function test_a_failed_readback_returns_a_failed_change_set_with_the_rollback_state(): void {
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$tree = json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true );
			$tree[0]['elements'][] = [ 'id' => 'n1', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [] ];
			$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $tree );
			return [ 'success' => true, 'post_id' => self::POST, 'root_element_ids' => [ 'n1' ], 'edit_url' => 'x', 'version' => 'v', 'resolved_xml' => '<e-div-block id="n1"><e-heading id="n2"/></e-div-block>' ];
		};

		$result = $this->ability()->execute( [ 'ability' => 'elementor/build-composition', 'dry_run' => false, 'input' => [ 'post_id' => self::POST, 'xml_structure' => '<e-div-block configuration-id="a"><e-heading configuration-id="b"/></e-div-block>', 'parent_id' => 'root1' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
		$change_set = $result->get_error_data()['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertCount( 1, $change_set['missing'] );
		self::assertSame( 'succeeded', $change_set['verification']['evidence']['rollback_status'] );
	}

	public function test_a_refused_ability_is_blocked_and_audited_without_a_native_call(): void {
		$result = $this->ability()->execute( [ 'ability' => 'elementor/manage-classes', 'dry_run' => false, 'input' => [ 'operations' => [] ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_native_route_refused', $result->get_error_code() );
		self::assertSame( [], $this->runtime->calls );
		self::assertNotEmpty( $GLOBALS['stonewright_test_wpdb_inserts'] );
	}

	public function test_a_read_returns_the_structure_without_a_change_set_or_a_snapshot(): void {
		$this->runtime->executor = static fn(): array => [ 'elements' => [] ];

		$result = $this->ability()->execute( [ 'ability' => 'elementor/get-page-structure', 'input' => [ 'post_id' => self::POST ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'read', $result['status'] );
		self::assertArrayNotHasKey( 'change_set', $result );
		self::assertSame( [], array_filter( $GLOBALS['stonewright_test_post_meta_calls'], static fn( array $call ): bool => '_stonewright_backups' === $call['meta_key'] ) );
	}

	public function test_permissions_follow_the_family(): void {
		$ability = $this->ability();
		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertFalse( $ability->permission_callback( [ 'ability' => 'elementor/manage-default-styles', 'input' => [] ] ) );
		self::assertFalse( $ability->permission_callback( [ 'ability' => 'elementor/build-composition', 'input' => [ 'post_id' => self::POST ] ] ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_theme_options' => true, 'manage_options' => true ];
		self::assertTrue( $ability->permission_callback( [ 'ability' => 'elementor/manage-default-styles', 'input' => [], 'dry_run' => false ] ) );
	}

	public function test_it_does_not_depend_on_the_v4_experimental_flag_or_module_probe(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		V4FeatureGate::set_atomic_module_present_for_tests( false );
		$ability = $this->ability();

		self::assertTrue( $ability->permission_callback( [ 'ability' => 'elementor/manage-default-styles', 'input' => [] ] ) );
		self::assertTrue( $ability->permission_callback( [ 'ability' => 'elementor/manage-default-styles', 'input' => [], 'dry_run' => false ] ) );
		$plan = $ability->execute( [ 'ability' => 'elementor/manage-default-styles', 'input' => [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ] ] ] ] );
		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertSame( 'planned', $plan['status'] );
	}

	public function test_it_is_available_only_when_native_elementor_is_available_with_a_certified_ability(): void {
		foreach ( [
			'not installed'  => [ [ 'elementor_version' => '', 'module_present' => false, 'module_active' => false, 'abilities_api' => false, 'mcp_adapter' => false, 'mcp_composer' => false, 'site_exposure_enabled' => false, 'atomic_editor_active' => false ], 'not_installed' ],
			'module missing' => [ [ 'module_present' => false, 'module_active' => false ], 'module_unavailable' ],
			'exposure off'   => [ [ 'site_exposure_enabled' => false ], 'exposure_disabled' ],
		] as $label => [ $facts, $state ] ) {
			$gate = $this->ability( [], $facts )->permission_callback( [ 'ability' => 'elementor/manage-default-styles', 'input' => [] ] );

			self::assertInstanceOf( \WP_Error::class, $gate, $label );
			self::assertSame( 'stonewright_native_unavailable', $gate->get_error_code(), $label );
			self::assertSame( $state, $gate->get_error_data()['state'], $label );
			self::assertSame( 409, $gate->get_error_data()['status'], $label );
			self::assertNotSame( [], $gate->get_error_data()['fallback'], $label );
		}
	}

	public function test_an_elementor_version_outside_the_certified_range_is_refused_with_the_reason_and_the_fallback(): void {
		$abilities = array_values( (array) json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-native/elementor-4.3.4-abilities.json' ), true )['abilities'] );
		foreach ( $abilities as $index => $row ) {
			$abilities[ $index ]['source_version'] = '4.4.0';
		}

		$gate = $this->ability( $abilities, [ 'elementor_version' => '4.4.0' ] )->permission_callback( [ 'ability' => 'elementor/build-composition', 'input' => [ 'post_id' => self::POST ] ] );

		self::assertInstanceOf( \WP_Error::class, $gate );
		self::assertSame( 'stonewright_native_unavailable', $gate->get_error_code() );
		self::assertSame( 'available_uncertified', $gate->get_error_data()['state'] );
		self::assertContains( 'elementor_version_out_of_range', $gate->get_error_data()['issues'] );
		self::assertStringContainsString( 'elementor_version_out_of_range', $gate->get_error_message() );
		self::assertStringContainsString( '4.3.0 - 4.3.*', $gate->get_error_message() );
	}

	public function test_a_production_safe_write_needs_a_confirmation_token_and_no_native_call_runs_without_one(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'production-safe' ];
		$ability = $this->ability();
		$args    = [ 'ability' => 'elementor/manage-default-styles', 'dry_run' => false, 'input' => [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ] ] ] ];

		self::assertTrue( $ability->permission_callback( $args ), 'the permission check is about who, not about the mode' );
		$result = $ability->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $this->runtime->calls );
	}

	public function test_a_production_safe_write_with_a_token_runs_inside_the_closure(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'production-safe' ];
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ): array {
			$runtime->styles['h2'] = [ 'type' => 'class', 'variants' => [ [ 'props' => [ 'css' => 'font-size: 2rem' ] ] ] ];
			return [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ] ] ];
		};
		$args  = [ 'ability' => 'elementor/manage-default-styles', 'dry_run' => false, 'input' => [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ] ] ] ];
		$token = ConfirmationToken::issue( 'stonewright/elementor-native-execute', $args );

		$result = $this->ability()->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'applied', $result['status'] );
		self::assertSame( 'confirmation_token', $result['change_set']['approval_reason'] );
	}

	public function test_a_dry_run_and_a_read_need_no_token_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'production-safe' ];
		$ability = $this->ability();

		$plan = $ability->execute( [ 'ability' => 'elementor/manage-default-styles', 'input' => [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ] ] ] ] );
		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertSame( 'planned', $plan['status'] );
		self::assertSame( [], $this->runtime->calls );

		$this->runtime->executor = static fn(): array => [ 'success' => true, 'post_id' => self::POST, 'resolved_xml' => '<e-div-block id="n1"/>' ];
		$composition = $ability->execute( [ 'ability' => 'elementor/build-composition', 'dry_run' => true, 'input' => [ 'post_id' => self::POST, 'xml_structure' => '<e-div-block configuration-id="a"/>', 'parent_id' => 'root1' ] ] );
		self::assertIsArray( $composition, $composition instanceof \WP_Error ? $composition->get_error_message() : '' );
		self::assertSame( 'planned', $composition['status'] );

		$this->runtime->executor = static fn(): array => [ 'elements' => [] ];
		$read = $ability->execute( [ 'ability' => 'elementor/get-page-structure', 'input' => [ 'post_id' => self::POST ] ] );
		self::assertIsArray( $read, $read instanceof \WP_Error ? $read->get_error_message() : '' );
		self::assertSame( 'read', $read['status'] );
	}

	public function test_the_in_process_call_is_marked_as_ours_so_the_observer_stays_out(): void {
		$seen = [];
		$this->runtime->executor = static function ( string $name, array $input, FakeNativeRuntime $runtime ) use ( &$seen ): array {
			$seen[] = NativeObserver::inside_native_execute();
			$runtime->styles['h2'] = [ 'type' => 'class', 'variants' => [ [ 'props' => [ 'css' => 'font-size: 2rem' ] ] ] ];
			return [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ] ] ];
		};

		self::assertFalse( NativeObserver::inside_native_execute() );
		$result = $this->ability()->execute( [ 'ability' => 'elementor/manage-default-styles', 'dry_run' => false, 'input' => [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ] ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( [ true ], $seen );
		self::assertFalse( NativeObserver::inside_native_execute(), 'the mark is cleared when the call returns' );
	}

	public function test_a_native_call_made_through_the_tool_is_not_observed_a_second_time(): void {
		remove_all_actions( 'wp_before_execute_ability' );
		remove_all_actions( 'wp_after_execute_ability' );
		NativeObserver::register();
		$input = [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2', 'css' => 'font-size: 2rem' ] ] ];
		$this->runtime->executor = static function ( string $name, array $native_input, FakeNativeRuntime $runtime ) use ( $input ): array {
			do_action( 'wp_before_execute_ability', $name, $native_input, null );
			$runtime->styles['h2'] = [ 'type' => 'class', 'variants' => [ [ 'props' => [ 'css' => 'font-size: 2rem' ] ] ] ];
			do_action( 'wp_after_execute_ability', $name, $native_input, [ 'status' => 'ok' ], null );
			return [ 'status' => 'ok', 'results' => [ [ 'index' => 0, 'action' => 'update', 'status' => 'ok', 'tag' => 'h2' ] ] ];
		};
		$named = static fn( array $row ): bool => 'elementor/manage-default-styles' === ( $row['data']['ability_name'] ?? '' );

		$result = $this->ability()->execute( [ 'ability' => 'elementor/manage-default-styles', 'dry_run' => false, 'input' => $input ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'applied', $result['status'] );
		self::assertSame( [], array_filter( $GLOBALS['stonewright_test_wpdb_inserts'], $named ), 'the tool records the call; the observer stays out' );

		// The same call made outside the tool is observed.
		do_action( 'wp_before_execute_ability', 'elementor/manage-default-styles', $input, null );
		do_action( 'wp_after_execute_ability', 'elementor/manage-default-styles', $input, [ 'status' => 'ok' ], null );
		self::assertCount( 1, array_filter( $GLOBALS['stonewright_test_wpdb_inserts'], $named ) );

		remove_all_actions( 'wp_before_execute_ability' );
		remove_all_actions( 'wp_after_execute_ability' );
		NativeObserver::reset_for_tests();
	}

	/**
	 * @param list<array<string,mixed>>|null $abilities Registered abilities; the recorded 4.3.4 set when null.
	 * @param array<string,mixed>            $facts     Environment facts that replace the healthy ones.
	 */
	private function ability( ?array $abilities = null, array $facts = [] ): NativeExecute {
		$decoded = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-native/elementor-4.3.4-abilities.json' ), true );
		$all     = $abilities ?? array_values( (array) $decoded['abilities'] );
		$facts   = $facts + [
			'elementor_version' => '4.3.4', 'module_present' => true, 'module_active' => true, 'abilities_api' => true,
			'mcp_adapter' => true, 'mcp_adapter_version' => '0.6.1', 'mcp_adapter_provider' => 'elementor-core',
			'mcp_composer' => true, 'mcp_composer_version' => '1.0.19', 'site_exposure_enabled' => true, 'atomic_editor_active' => true,
		];
		$router  = new ProviderRouter(
			static fn(): array => [],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => $all,
			static fn(): array => UpstreamAbilityDiscovery::summarize_environment( $facts )
		);
		return new NativeExecute( new NativeElementorProvider( $router, $this->runtime ) );
	}
}
