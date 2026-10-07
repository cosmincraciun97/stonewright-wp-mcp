<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Elementor\NativeExecute;
use Stonewright\WpMcp\Elementor\Provider\NativeElementorProvider;
use Stonewright\WpMcp\Elementor\Provider\NativeInputSchema;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
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

	public function test_permissions_follow_the_family_and_the_v4_gate(): void {
		$ability = $this->ability();
		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertFalse( $ability->permission_callback( [ 'ability' => 'elementor/manage-default-styles', 'input' => [] ] ) );
		self::assertFalse( $ability->permission_callback( [ 'ability' => 'elementor/build-composition', 'input' => [ 'post_id' => self::POST ] ] ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_theme_options' => true, 'manage_options' => true ];
		self::assertTrue( $ability->permission_callback( [ 'ability' => 'elementor/manage-default-styles', 'input' => [], 'dry_run' => false ] ) );

		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;
		self::assertSame( 'feature_disabled', $ability->permission_callback( [ 'ability' => 'elementor/manage-default-styles', 'input' => [] ] )->get_error_code() );
	}

	public function test_a_production_safe_write_is_blocked_before_any_token_or_native_call(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'production-safe', 'stonewright_elementor_v4_atomic' => true ];

		$gate = $this->ability()->permission_callback( [ 'ability' => 'elementor/manage-default-styles', 'dry_run' => false, 'input' => [] ] );

		self::assertSame( 'stonewright_v4_experimental_production_block', $gate->get_error_code() );
		self::assertSame( [], $this->runtime->calls );
	}

	private function ability(): NativeExecute {
		$decoded = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-native/elementor-4.3.4-abilities.json' ), true );
		$all     = array_values( (array) $decoded['abilities'] );
		$facts   = [
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
