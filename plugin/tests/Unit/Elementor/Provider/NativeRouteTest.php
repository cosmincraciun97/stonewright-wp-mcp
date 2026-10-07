<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\NativeRoute;

/**
 * @covers \Stonewright\WpMcp\Elementor\Provider\NativeRoute
 */
final class NativeRouteTest extends TestCase {

	public function test_a_certified_allowed_write_routes_native(): void {
		foreach ( [ 'elementor/manage-default-styles' => 'kit_defaults', 'elementor/build-composition' => 'tree_composition' ] as $name => $family ) {
			$route = NativeRoute::for_ability( $name, self::block( [ $name => 'certified' ] ) );

			self::assertSame( 'native', $route['route'], $name );
			self::assertSame( $family, $route['family'], $name );
			self::assertSame( 'certified_native_write', $route['reason'], $name );
			self::assertFalse( $route['read_only'], $name );
		}
	}

	public function test_the_certified_readback_ability_routes_native_as_read_only(): void {
		$route = NativeRoute::for_ability( 'elementor/get-page-structure', self::block( [ 'elementor/get-page-structure' => 'certified' ] ) );

		self::assertSame( 'native', $route['route'] );
		self::assertTrue( $route['read_only'] );
		self::assertSame( 'structure_read', $route['family'] );
	}

	public function test_classes_and_variables_are_certified_but_their_native_write_is_refused_with_the_exact_reason(): void {
		$cases = [
			'elementor/manage-classes'         => [ 'stonewright/elementor-v4-create-class', 'stonewright/elementor-v4-update-class' ],
			'elementor/manage-global-variable' => [ 'stonewright/elementor-v4-create-variable', 'stonewright/elementor-v4-update-variable' ],
		];
		foreach ( $cases as $name => $fallback ) {
			$route = NativeRoute::for_ability( $name, self::block( [ $name => 'certified' ] ) );

			self::assertSame( 'stonewright_v4_fallback', $route['route'], $name );
			self::assertSame( 'upstream_global_clear_cache', $route['reason'], $name );
			self::assertSame( $fallback, $route['fallback'], $name );
		}
	}

	public function test_manage_elements_and_an_unknown_ability_have_no_native_route(): void {
		$elements = NativeRoute::for_ability( 'elementor/manage-elements', self::block( [ 'elementor/manage-elements' => 'unsupported' ], [ 'elementor/manage-elements' => 'upstream_global_clear_cache' ] ) );
		self::assertSame( 'stonewright_v4_fallback', $elements['route'] );
		self::assertSame( 'upstream_global_clear_cache', $elements['reason'] );

		$unknown = NativeRoute::for_ability( 'elementor/publish-document', self::block( [] ) );
		self::assertSame( 'stonewright_v4_fallback', $unknown['route'] );
		self::assertSame( 'not_available_for_certification', $unknown['reason'] );
	}

	public function test_an_uncertified_or_unregistered_ability_falls_back_and_names_the_missing_feature(): void {
		$rejected = NativeRoute::for_ability(
			'elementor/build-composition',
			self::block( [ 'elementor/build-composition' => 'rejected' ], [ 'elementor/build-composition' => 'upstream_contract_not_certified' ], [ 'input_schema_mismatch' ] )
		);
		self::assertSame( 'stonewright_v4_fallback', $rejected['route'] );
		self::assertSame( 'upstream_contract_not_certified', $rejected['reason'] );
		self::assertSame( [ 'input_schema_mismatch' ], $rejected['issues'] );
		self::assertSame( [ 'stonewright/elementor-v4-render-from-spec', 'stonewright/elementor-v4-update-node', 'stonewright/design-spec-to-elementor-v4' ], $rejected['fallback'] );

		$states = [
			'not_installed'           => 'elementor',
			'module_unavailable'      => 'elementor_mcp_module',
			'requirements_missing'    => 'elementor_mcp_requirements',
			'exposure_disabled'       => 'elementor_mcp_site_exposure',
			'no_abilities_registered' => 'elementor_mcp_abilities',
		];
		foreach ( $states as $state => $feature ) {
			$block          = self::block( [], [], [], $state );
			$block['certification']['elementor/build-composition'] = [ 'state' => 'unsupported', 'reason' => 'upstream_ability_not_registered', 'issues' => [] ];
			$route          = NativeRoute::for_ability( 'elementor/build-composition', $block );

			self::assertSame( 'stonewright_v4_fallback', $route['route'], $state );
			self::assertSame( 'upstream_ability_not_registered', $route['reason'], $state );
			self::assertSame( $feature, $route['missing_feature'], $state );
		}
	}

	public function test_default_styles_has_no_stonewright_fallback_writer(): void {
		$route = NativeRoute::for_ability( 'elementor/manage-default-styles', self::block( [ 'elementor/manage-default-styles' => 'rejected' ], [ 'elementor/manage-default-styles' => 'upstream_contract_not_certified' ] ) );

		self::assertSame( 'stonewright_v4_fallback', $route['route'] );
		self::assertSame( [], $route['fallback'] );
	}

	/** @return array<string,array{0:string,1:string,2:string,3:string}> */
	public static function document_cases(): array {
		return [
			'v4 document root'          => [ 'v4', 'document', 'native', 'v4_document' ],
			'v4 document atomic parent' => [ 'v4', 'atomic', 'native', 'v4_document' ],
			'empty document'            => [ 'empty', 'document', 'native', 'empty_document' ],
			'v3 document'               => [ 'v3', 'document', 'stonewright_v3', 'v3_document' ],
			'v3 document v3 parent'     => [ 'v3', 'v3', 'stonewright_v3', 'v3_document' ],
			'mixed atomic subtree'      => [ 'mixed', 'atomic', 'native', 'mixed_document_atomic_subtree' ],
			'mixed v3 subtree'          => [ 'mixed', 'v3', 'stonewright_v3', 'mixed_document_v3_subtree' ],
			'mixed root insert'         => [ 'mixed', 'document', 'refused', 'mixed_document_root_insert' ],
			'missing parent'            => [ 'v4', 'missing', 'refused', 'parent_not_found' ],
			'unknown architecture'      => [ 'unknown', 'document', 'refused', 'document_architecture_unknown' ],
		];
	}

	/** @dataProvider document_cases */
	public function test_documents_route_per_subtree_and_are_never_converted( string $architecture, string $parent, string $route, string $reason ): void {
		$decision = NativeRoute::for_document( $architecture, $parent );

		self::assertSame( $route, $decision['route'] );
		self::assertSame( $reason, $decision['reason'] );
		self::assertFalse( $decision['conversion'], 'a route never converts V3 to V4 or the reverse' );
	}

	public function test_v3_and_mixed_v3_subtrees_name_the_stonewright_v3_writers(): void {
		$decision = NativeRoute::for_document( 'v3', 'document' );

		self::assertSame( [ 'stonewright/elementor-v3-batch-mutate', 'stonewright/elementor-v3-build-page-from-spec' ], $decision['fallback'] );
	}

	/**
	 * @param array<string,string>       $states  Ability name to certification state.
	 * @param array<string,string>       $reasons Ability name to reason.
	 * @param list<string>               $issues
	 * @return array<string,mixed>
	 */
	private static function block( array $states, array $reasons = [], array $issues = [], string $state = 'available' ): array {
		$certification = [];
		foreach ( $states as $name => $result ) {
			$certification[ $name ] = [
				'state'  => $result,
				'reason' => $reasons[ $name ] ?? ( 'certified' === $result ? 'official_contract_certified' : 'unknown' ),
				'issues' => $issues,
			];
		}
		return [ 'state' => $state, 'certification' => $certification ];
	}
}
