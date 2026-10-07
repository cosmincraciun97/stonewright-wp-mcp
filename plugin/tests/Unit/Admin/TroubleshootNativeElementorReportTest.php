<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\TroubleshootPage;

/**
 * The Troubleshoot provider report lists every native Elementor ability, not only default styles.
 *
 * @covers \Stonewright\WpMcp\Admin\Pages\TroubleshootPage
 */
final class TroubleshootNativeElementorReportTest extends TestCase {

	public function test_every_native_ability_is_listed_with_its_result_selection_and_reason(): void {
		$html = self::render( self::report() );

		foreach ( [ 'elementor/manage-default-styles', 'elementor/manage-classes', 'elementor/manage-global-variable', 'elementor/get-page-structure', 'elementor/build-composition', 'elementor/manage-elements' ] as $name ) {
			self::assertStringContainsString( $name, $html, $name );
		}
		self::assertStringContainsString( 'native-preferred', $html );
		self::assertStringContainsString( 'native-write-refused', $html );
		self::assertStringContainsString( 'upstream_global_clear_cache', $html );
		self::assertStringContainsString( 'native-readback', $html );
		self::assertStringContainsString( 'staged_in_autosave', $html );
		self::assertStringContainsString( 'Native Elementor state: available', $html );
		self::assertStringContainsString( '5 certified', $html );
	}

	public function test_a_rejected_ability_shows_its_exact_issues_and_an_unregistered_one_says_so(): void {
		$report = self::report();
		$report['native_preferred']['elementor/manage-classes'] = [ 'available' => true, 'certification' => 'rejected', 'selection' => 'unsupported', 'reason' => 'upstream_contract_not_certified', 'issues' => [ 'input_schema_mismatch', 'runtime_identity_mismatch' ], 'native_write' => 'refused' ];
		$report['native_preferred']['elementor/build-composition'] = [ 'available' => false, 'certification' => 'unsupported', 'selection' => 'unsupported', 'reason' => 'upstream_ability_not_registered' ];

		$html = self::render( $report );

		self::assertStringContainsString( 'input_schema_mismatch', $html );
		self::assertStringContainsString( 'runtime_identity_mismatch', $html );
		self::assertStringContainsString( 'not registered', $html );
	}

	public function test_output_is_escaped_and_bounded(): void {
		$report = self::report();
		$report['native_preferred']['elementor/<script>alert(1)</script>'] = [ 'available' => true, 'certification' => '<b>x</b>', 'selection' => 'a', 'reason' => str_repeat( 'r', 500 ), 'issues' => [] ];
		for ( $index = 0; $index < 100; ++$index ) {
			$report['native_preferred'][ 'elementor/extra-' . $index ] = [ 'available' => true, 'certification' => 'certified', 'selection' => 'native-preferred', 'reason' => 'x', 'issues' => [] ];
		}

		$html = self::render( $report );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>x</b>', $html );
		self::assertLessThan( 20, substr_count( $html, 'elementor/extra-' ), 'the list is capped' );
		self::assertStringNotContainsString( str_repeat( 'r', 150 ), $html );
	}

	public function test_a_site_without_native_abilities_still_renders(): void {
		$html = self::render( [ 'providers' => [], 'issues' => [], 'native_preferred' => [], 'native_elementor' => [ 'state' => 'not_installed', 'certified' => [] ] ] );

		self::assertStringContainsString( 'Native Elementor state: not_installed', $html );
		self::assertStringContainsString( '0 certified', $html );
	}

	/** @param array<string,mixed> $report */
	private static function render( array $report ): string {
		ob_start();
		TroubleshootPage::render_elementor_provider_report( $report );
		return (string) ob_get_clean();
	}

	/** @return array<string,mixed> */
	private static function report(): array {
		return [
			'providers'        => [],
			'providers_count'  => 0,
			'issues'           => [],
			'native_elementor' => [ 'state' => 'available', 'certified' => [ 'elementor/build-composition', 'elementor/get-page-structure', 'elementor/manage-classes', 'elementor/manage-default-styles', 'elementor/manage-global-variable' ] ],
			'native_preferred' => [
				'elementor/build-composition'      => [ 'available' => true, 'certification' => 'certified', 'selection' => 'native-preferred', 'reason' => 'official_contract_certified', 'issues' => [], 'native_write' => 'allowed' ],
				'elementor/get-page-structure'     => [ 'available' => true, 'certification' => 'certified', 'selection' => 'native-readback', 'reason' => 'official_contract_certified', 'issues' => [], 'native_write' => 'read_only' ],
				'elementor/manage-classes'         => [ 'available' => true, 'certification' => 'certified', 'selection' => 'native-write-refused', 'reason' => 'official_contract_certified', 'issues' => [], 'native_write' => 'refused', 'native_write_reason' => 'upstream_global_clear_cache' ],
				'elementor/manage-default-styles'  => [ 'available' => true, 'certification' => 'certified', 'selection' => 'native-preferred', 'reason' => 'official_contract_certified', 'issues' => [], 'native_write' => 'allowed' ],
				'elementor/manage-elements'        => [ 'available' => true, 'certification' => 'unsupported', 'selection' => 'unsupported', 'reason' => 'upstream_global_clear_cache', 'reasons' => [ 'upstream_global_clear_cache', 'staged_in_autosave' ], 'issues' => [] ],
				'elementor/manage-global-variable' => [ 'available' => true, 'certification' => 'certified', 'selection' => 'native-write-refused', 'reason' => 'official_contract_certified', 'issues' => [], 'native_write' => 'refused', 'native_write_reason' => 'upstream_global_clear_cache' ],
			],
		];
	}
}
