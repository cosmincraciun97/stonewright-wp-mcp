<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;

/**
 * @covers \Stonewright\WpMcp\Elementor\Provider\ProviderRouter
 */
final class ProviderRouterTest extends TestCase {

	public function test_architecture_is_resolved_before_any_provider_discovery(): void {
		$order = [];
		$router = new ProviderRouter(
			static function () use ( &$order ): array {
				$order[] = 'architecture';
				return self::architecture( 'v3' );
			},
			static function () use ( &$order ): array {
				$order[] = 'v3';
				return [];
			},
			static function () use ( &$order ): array {
				$order[] = 'atomic';
				return [ 'items' => [], 'issues' => [] ];
			},
			static function () use ( &$order ): array {
				$order[] = 'abilities';
				return [];
			}
		);

		$router->inspect( 42 );

		self::assertSame( [ 'architecture', 'v3', 'atomic', 'abilities' ], $order );
	}

	public function test_discovers_official_and_third_party_v3_ownership_from_live_schema_evidence(): void {
		$router = $this->router(
			'v3',
			[
				self::v3_widget( 'heading', 'elementor/elementor.php', '3.30.0', 'Elementor\\Widget_Heading', 'core-hash' ),
				self::v3_widget( 'form', 'elementor-pro/elementor-pro.php', '3.30.0', 'ElementorPro\\Modules\\Forms\\Widgets\\Form', 'pro-hash' ),
				self::v3_widget( 'card', 'acme-widgets/acme.php', '2.4.0', 'Acme\\Widgets\\Card', 'acme-hash' ),
			]
		);

		$result = $router->inspect( 7 );
		$providers = self::index_by( $result['providers'], 'id' );

		self::assertSame( 'supported', $result['selection']['status'] );
		self::assertSame( 'official', $providers['elementor-core']['ownership'] );
		self::assertSame( 'official', $providers['elementor-pro']['ownership'] );
		self::assertSame( 'third-party', $providers['plugin:acme-widgets']['ownership'] );
		self::assertSame( 'untrusted', $providers['plugin:acme-widgets']['trust'] );
		self::assertSame( 'discovered', $providers['plugin:acme-widgets']['certification'] );
		self::assertSame( [ 'v3' ], $providers['plugin:acme-widgets']['architectures'] );
		self::assertSame( 'acme-hash', $providers['plugin:acme-widgets']['capabilities'][0]['schema_fingerprint'] );
		self::assertSame( [], $providers['plugin:acme-widgets']['write_primitives'] );
		self::assertTrue( $providers['plugin:acme-widgets']['read_only'] );
	}

	public function test_discovers_atomic_extensions_and_preserves_runtime_schema_provenance(): void {
		$router = $this->router(
			'v4',
			[],
			[
				'items' => [
					[
						'atomic_type'       => 'e-acme-card',
						'kind'              => 'widget',
						'source_plugin'     => 'acme-atomic/acme.php',
						'source_version'    => '1.2.0',
						'runtime_class'     => 'Acme\\Atomic\\Card',
						'schema_fingerprint'=> 'atomic-hash',
						'provenance'        => [ 'schema' => 'live_elementor_runtime' ],
					],
				],
				'issues' => [],
			]
		);

		$result = $router->inspect( 8 );
		$provider = self::index_by( $result['providers'], 'id' )['plugin:acme-atomic'];

		self::assertSame( 'supported', $result['selection']['status'] );
		self::assertSame( [ 'v4' ], $provider['architectures'] );
		self::assertSame( 'atomic-hash', $provider['capabilities'][0]['schema_fingerprint'] );
		self::assertSame( 'live_elementor_runtime', $provider['capabilities'][0]['provenance']['schema'] );
	}

	public function test_incomplete_or_unknown_provider_evidence_is_unsupported_and_never_guessed(): void {
		$router = $this->router(
			'v4',
			[],
			[
				'items' => [
					[
						'atomic_type'    => 'e-mystery',
						'kind'           => 'widget',
						'source_plugin'  => '',
						'runtime_class'  => '',
					],
				],
				'issues' => [ [ 'code' => 'schema_unavailable', 'atomic_type' => 'e-broken' ] ],
			]
		);

		$result = $router->inspect( 9 );

		self::assertSame( 'unsupported', $result['selection']['status'] );
		self::assertSame( [], $result['providers'] );
		self::assertContains( 'incomplete_provider_evidence', array_column( $result['issues'], 'code' ) );
		self::assertContains( 'schema_unavailable', array_column( $result['issues'], 'code' ) );
	}

	public function test_mixed_architecture_remains_unsupported_even_when_providers_exist(): void {
		$router = $this->router( 'mixed', [ self::v3_widget( 'heading', 'elementor/elementor.php', '3.30.0', 'Elementor\\Widget_Heading', 'hash' ) ] );

		$result = $router->inspect( 10 );

		self::assertSame( 'unsupported', $result['selection']['status'] );
		self::assertSame( 'mixed_architecture', $result['selection']['reason'] );
		self::assertFalse( $result['writes_enabled'] );
	}

	public function test_manage_default_styles_is_native_preferred_only_from_upstream_runtime_metadata(): void {
		$ability = self::authentic_manage_default_styles_ability();
		$read_only = $ability;
		$read_only['name'] = 'elementor/get-page-structure';
		$read_only['meta']['annotations'] = [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ];
		$router = $this->router( 'v3', [], [ 'items' => [], 'issues' => [] ], [ $ability, $read_only ] );

		$result = $router->inspect();
		$preference = $result['native_preferred']['elementor/manage-default-styles'];

		self::assertTrue( $preference['available'] );
		self::assertSame( 'native-preferred', $preference['selection'] );
		self::assertSame( 'certified', $preference['certification'] );
		self::assertSame( 20, $preference['contract']['runtime_operation_limit'] );
		self::assertTrue( $preference['contract']['responsive_css'] );
		self::assertTrue( $preference['contract']['pseudo_states'] );
		self::assertSame( $ability['description'], $preference['description'] );
		self::assertFalse( $preference['routable_write'] );
		self::assertTrue( $preference['safety_closure_required'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $preference['schema_fingerprint'] );
		$provider = self::index_by( $result['providers'], 'id' )['elementor-core'];
		self::assertSame( [ 'global' ], $provider['architectures'] );
		self::assertFalse( $provider['read_only'] );
		self::assertSame( 'trusted', $provider['trust'] );
		self::assertSame( 'certified', $provider['certification'] );
		self::assertSame( [ 'elementor/manage-default-styles' ], array_column( $provider['write_primitives'], 'name' ) );
	}

	public function test_manage_default_styles_is_discovered_but_not_preferred_when_contract_is_incomplete(): void {
		$ability = self::authentic_manage_default_styles_ability();
		$ability['description'] = 'Bulk update and delete default styles.';
		$ability['input_schema']['properties']['operations']['items']['properties']['css']['description'] = 'Plain CSS.';

		$result = $this->router( 'v4', [], [ 'items' => [], 'issues' => [] ], [ $ability ] )->inspect();
		$preference = $result['native_preferred']['elementor/manage-default-styles'];

		self::assertTrue( $preference['available'] );
		self::assertSame( 'compatible', $preference['certification'] );
		self::assertSame( 'unsupported', $preference['selection'] );
		self::assertSame( 'upstream_contract_not_certified', $preference['reason'] );
	}

	public function test_actual_elementor_annotations_drive_read_write_semantics(): void {
		$read = self::authentic_manage_default_styles_ability();
		$read['name'] = 'elementor/get-default-styles';
		$read['meta']['annotations'] = [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ];
		$write = self::authentic_manage_default_styles_ability();

		$result = $this->router( 'v4', [], [ 'items' => [], 'issues' => [] ], [ $read, $write ] )->inspect();
		$provider = self::index_by( $result['providers'], 'id' )['elementor-core'];

		self::assertSame( [ 'elementor/manage-default-styles' ], array_column( $provider['write_primitives'], 'name' ) );
		self::assertFalse( $provider['read_only'] );
	}

	public function test_spoofed_elementor_source_metadata_cannot_become_certified(): void {
		$ability = self::authentic_manage_default_styles_ability();
		$ability['provenance']['ownership'] = 'explicit_registration_metadata';

		$result = $this->router( 'v4', [], [ 'items' => [], 'issues' => [] ], [ $ability ] )->inspect();
		$preference = $result['native_preferred']['elementor/manage-default-styles'];

		self::assertSame( 'compatible', $preference['certification'] );
		self::assertSame( 'unsupported', $preference['selection'] );
		self::assertSame( 'unverified', self::index_by( $result['providers'], 'id' )['elementor-core']['trust'] );
	}

	public function test_callback_inside_official_plugin_boundary_can_be_certified_without_admin_metadata_api(): void {
		$ability = self::authentic_manage_default_styles_ability();
		$ability['provenance']['ownership'] = 'registration_callback_and_plugin_boundary';

		$result = $this->router( 'v4', [], [ 'items' => [], 'issues' => [] ], [ $ability ] )->inspect();
		$preference = $result['native_preferred']['elementor/manage-default-styles'];

		self::assertSame( 'certified', $preference['certification'] );
		self::assertSame( 'native-preferred', $preference['selection'] );
		self::assertSame( 'trusted', self::index_by( $result['providers'], 'id' )['elementor-core']['trust'] );
	}

	public function test_manage_default_styles_is_not_synthesized_when_upstream_ability_is_absent(): void {
		$result = $this->router( 'v3' )->inspect();

		self::assertFalse( $result['native_preferred']['elementor/manage-default-styles']['available'] );
		self::assertSame( 'unsupported', $result['native_preferred']['elementor/manage-default-styles']['selection'] );
		self::assertArrayNotHasKey( 'input_schema', $result['native_preferred']['elementor/manage-default-styles'] );
	}

	/** @param list<array<string,mixed>> $v3 */
	private function router( string $architecture, array $v3 = [], array $atomic = [ 'items' => [], 'issues' => [] ], array $abilities = [] ): ProviderRouter {
		return new ProviderRouter(
			static fn(): array => self::architecture( $architecture ),
			static fn(): array => $v3,
			static fn(): array => $atomic,
			static fn(): array => $abilities
		);
	}

	/** @return array<string,mixed> */
	private static function architecture( string $architecture ): array {
		return [
			'document_architecture' => $architecture,
			'write_target'          => 'mixed' === $architecture ? 'v3-surgical' : $architecture,
			'write_blocked'         => in_array( $architecture, [ 'mixed', 'unknown' ], true ),
		];
	}

	/** @return array<string,mixed> */
	private static function v3_widget( string $type, string $plugin, string $version, string $class, string $hash ): array {
		return [
			'widget_type'        => $type,
			'source_plugin'      => $plugin,
			'source_version'     => $version,
			'runtime_class'      => $class,
			'schema_hash'        => $hash,
			'provenance'         => [ 'controls' => 'live_elementor_runtime' ],
		];
	}

	/**
	 * Contract fixture transcribed from Elementor commit 3afafe33.
	 * The 20-operation cap is enforced by MAX_BATCH_SIZE at runtime, not maxItems.
	 *
	 * @return array<string,mixed>
	 */
	private static function authentic_manage_default_styles_ability(): array {
		return [
			'name'          => 'elementor/manage-default-styles',
			'label'         => 'Manage Default Styles (Site-Wide)',
			'description'   => 'Bulk manage default styles with update and delete actions. CSS supports @media(--breakpoint) and &:hover, &:focus, and &:active states. Maximum 20 operations per request.',
			'input_schema'  => [
				'type' => 'object',
				'required' => [ 'operations' ],
				'properties' => [
					'operations' => [
						'type' => 'array',
						'description' => 'Bulk operations (1–20).',
						'items' => [
							'type' => 'object',
							'required' => [ 'action', 'tag' ],
							'properties' => [
								'action' => [ 'type' => 'string', 'enum' => [ 'update', 'delete' ] ],
								'css' => [ 'type' => 'string', 'description' => 'Supports &:hover/&:focus/&:active and @media(--breakpoint).' ],
							],
						],
					],
				],
			],
			'output_schema' => [ 'type' => 'object', 'required' => [ 'status', 'results' ] ],
			'meta'          => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'source_plugin' => 'elementor/elementor.php',
				'source_version' => '3afafe33',
				'contract' => [ 'runtime_operation_limit' => 20 ],
			],
			'runtime_class' => 'Elementor\\Modules\\Mcp\\Abilities\\Manage_Default_Styles_Ability',
			'provenance' => [
				'schema' => 'upstream_registered_ability',
				'ownership' => 'registration_callback_and_wordpress_plugin_metadata',
				'source_repository' => 'elementor/elementor',
				'source_commit' => '3afafe33b7499b4e8fcb4c684e55111721bb0c96',
				'source_path' => 'modules/mcp/abilities/manage-default-styles-ability.php',
			],
		];
	}

	/** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
	private static function index_by( array $rows, string $key ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$out[ (string) $row[ $key ] ] = $row;
		}
		return $out;
	}
}
