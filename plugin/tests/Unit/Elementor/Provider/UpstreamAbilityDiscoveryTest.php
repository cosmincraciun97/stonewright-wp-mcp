<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery;

/** @covers \Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery */
final class UpstreamAbilityDiscoveryTest extends TestCase {

	public function test_consumes_upstream_metadata_and_schema_without_reconstructing_it(): void {
		$input = [ 'type' => 'object', 'properties' => [ 'operations' => [ 'type' => 'array', 'maxItems' => 20 ] ] ];
		$ability = new class( $input ) {
			/** @param array<string,mixed> $input */
			public function __construct( private array $input ) {}
			public function get_name(): string { return 'elementor/manage-default-styles'; }
			public function get_label(): string { return 'Manage default styles'; }
			public function get_description(): string { return 'Upstream semantic description.'; }
			/** @return array<string,mixed> */
			public function get_input_schema(): array { return $this->input; }
			/** @return array<string,mixed> */
			public function get_output_schema(): array { return [ 'type' => 'object' ]; }
			/** @return array<string,mixed> */
			public function get_meta(): array { return [ 'source_plugin' => 'elementor/elementor.php', 'source_version' => '3.30.0' ]; }
		};

		$result = UpstreamAbilityDiscovery::from_abilities( [ $ability ] );

		self::assertCount( 1, $result );
		self::assertSame( $input, $result[0]['input_schema'] );
		self::assertSame( 'Upstream semantic description.', $result[0]['description'] );
		self::assertSame( 'unknown', $result[0]['source_plugin'] );
		self::assertSame( 'elementor/elementor.php', $result[0]['meta']['source_plugin'] );
		self::assertSame( 'upstream_registered_ability', $result[0]['provenance']['schema'] );
	}

	public function test_non_elementor_and_throwing_abilities_are_skipped_without_breaking_discovery(): void {
		$throwing = new class() {
			public function get_name(): string { throw new \RuntimeException( 'broken extension' ); }
		};
		$foreign = new class() {
			public function get_name(): string { return 'other/do-work'; }
		};

		self::assertSame( [], UpstreamAbilityDiscovery::from_abilities( [ $throwing, $foreign, 'invalid' ] ) );
	}

	public function test_callback_owner_is_used_instead_of_generic_ability_wrapper(): void {
		$callback_owner = new ElementorCallbackFixture();
		$ability = new GenericAbilityFixture( [ $callback_owner, 'execute_guarded' ] );

		$result = UpstreamAbilityDiscovery::from_abilities( [ $ability ] );

		self::assertCount( 1, $result );
		self::assertSame( ElementorCallbackFixture::class, $result[0]['runtime_class'] );
		self::assertSame( 'unknown', $result[0]['source_plugin'] );
		self::assertSame( 'registration_callback', $result[0]['provenance']['ownership'] );
		self::assertNotSame( GenericAbilityFixture::class, $result[0]['runtime_class'] );
	}

	public function test_runtime_contract_discovers_exact_upstream_constants(): void {
		$ability = new GenericAbilityFixture( [ new ManageDefaultStylesCallbackFixture(), 'execute_guarded' ] );

		$result = UpstreamAbilityDiscovery::from_abilities( [ $ability ] );

		self::assertSame( 20, $result[0]['runtime_contract']['runtime_operation_limit'] );
		self::assertSame( 'class', $result[0]['runtime_contract']['class_type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_third_party_callback_cannot_spoof_elementor_provider_identity_with_metadata(): void {
		$plugin_dir = WP_CONTENT_DIR . '/plugins';
		$folder     = $plugin_dir . '/spoof-provider';
		mkdir( $folder . '/src', 0700, true );
		file_put_contents( $folder . '/bootstrap.php', "<?php\n/*\nPlugin Name: Spoof Provider\nVersion: 1.2.3\n*/\n" );
		file_put_contents(
			$folder . '/src/Callback.php',
			"<?php\nnamespace StonewrightSpoofProviderFixture;\nfinal class Callback { public function execute_guarded(array \$input = []): array { return \$input; } }\n"
		);
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', $plugin_dir );
		}
		$GLOBALS['stonewright_test_options']['active_plugins'] = [ 'spoof-provider/bootstrap.php' ];
		require_once $folder . '/src/Callback.php';
		$core_spoof = new GenericAbilityFixture(
			[ new \StonewrightSpoofProviderFixture\Callback(), 'execute_guarded' ],
			[
				'source_plugin'  => 'elementor/elementor.php',
				'source_version' => '99.0.0',
				'provider_id'    => 'elementor-core',
			]
		);
		$pro_spoof = new GenericAbilityFixture(
			[ new \StonewrightSpoofProviderFixture\Callback(), 'execute_guarded' ],
			[
				'source_plugin' => 'elementor-pro/elementor-pro.php',
				'provider_id'   => 'elementor-pro',
			],
			'elementor/spoof-pro-provider'
		);

		$result = UpstreamAbilityDiscovery::from_abilities( [ $core_spoof, $pro_spoof ] );

		self::assertCount( 2, $result );
		self::assertSame( 'spoof-provider/bootstrap.php', $result[0]['source_plugin'] );
		self::assertSame( '1.2.3', $result[0]['source_version'] );
		self::assertSame( \StonewrightSpoofProviderFixture\Callback::class, $result[0]['runtime_class'] );
		self::assertSame( 'active_plugin_header', $result[0]['provenance']['ownership'] );
		self::assertSame( 'elementor/elementor.php', $result[0]['meta']['source_plugin'] );
		self::assertSame( 'spoof-provider/bootstrap.php', $result[1]['source_plugin'] );
		self::assertSame( 'elementor-pro/elementor-pro.php', $result[1]['meta']['source_plugin'] );

		$router = new ProviderRouter(
			static fn(): array => [ 'document_architecture' => 'v4', 'write_target' => 'v4', 'write_blocked' => false ],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => $result
		);
		$report = $router->inspect();
		$providers = array_column( $report['providers'], null, 'id' );

		self::assertArrayHasKey( 'plugin:spoof-provider', $providers );
		self::assertArrayNotHasKey( 'elementor-core', $providers );
		self::assertArrayNotHasKey( 'elementor-pro', $providers );
		self::assertSame( 'third-party', $providers['plugin:spoof-provider']['ownership'] );
		self::assertSame( 'untrusted', $providers['plugin:spoof-provider']['trust'] );
	}
}

final class ElementorCallbackFixture {
	public function execute_guarded( array $input = [] ): array { return $input; }
}

final class ManageDefaultStylesCallbackFixture {
	public const CLASS_TYPE = 'class';
	public const MAX_BATCH_SIZE = 20;
	public function execute_guarded( array $input = [] ): array { return $input; }
}

final class GenericAbilityFixture {
	/** @var callable */
	protected $execute_callback;
	/** @param array<string,mixed> $meta */
	public function __construct( callable $callback, private array $meta = [], private string $name = 'elementor/manage-default-styles' ) { $this->execute_callback = $callback; }
	public function get_name(): string { return $this->name; }
	public function get_label(): string { return 'Manage default styles'; }
	public function get_description(): string { return 'Bulk update/delete with @media(--breakpoint), &:hover, and 20 operations.'; }
	/** @return array<string,mixed> */
	public function get_input_schema(): array { return [ 'type' => 'object' ]; }
	/** @return array<string,mixed> */
	public function get_output_schema(): array { return [ 'type' => 'object' ]; }
	/** @return array<string,mixed> */
	public function get_meta(): array { return array_merge( [ 'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ] ], $this->meta ); }
}
