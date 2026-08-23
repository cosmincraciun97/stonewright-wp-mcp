<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
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
		self::assertSame( 'elementor/elementor.php', $result[0]['source_plugin'] );
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
}
