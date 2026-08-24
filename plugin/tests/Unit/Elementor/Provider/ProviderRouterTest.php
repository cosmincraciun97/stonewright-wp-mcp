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

	public function test_architecture_payload_is_allowlisted_and_bounded_in_the_shared_response(): void {
		$repair_tools = array_fill( 0, 1000, str_repeat( 'tool-', 300 ) );
		$router = new ProviderRouter(
			static fn(): array => [
				'document_architecture' => 'v3',
				'write_target'          => 'v3',
				'write_blocked'         => false,
				'reason'                => str_repeat( 'reason-', 1000 ),
				'repair_tools'          => $repair_tools,
				'arbitrary_payload'     => array_fill( 0, 1000, str_repeat( 'payload', 1000 ) ),
			],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => []
		);

		$result = $router->inspect();

		self::assertArrayNotHasKey( 'arbitrary_payload', $result['architecture'] );
		self::assertLessThanOrEqual( 1000, strlen( $result['architecture']['reason'] ) );
		self::assertCount( 20, $result['architecture']['repair_tools'] );
		self::assertSame( 1000, $result['architecture']['repair_tools_count'] );
		self::assertTrue( $result['architecture']['repair_tools_truncated'] );
		self::assertLessThan( 200000, strlen( (string) wp_json_encode( $result ) ) );
	}

	public function test_each_throwing_provider_is_isolated_and_surviving_providers_continue(): void {
		$providers = [ 'v3', 'atomic', 'abilities' ];
		foreach ( $providers as $throwing ) {
			$callbacks = [
				'v3'       => static fn(): array => [ self::v3_widget( 'heading', 'elementor/elementor.php', '3.30.0', 'Elementor\\Widget_Heading', 'core-hash' ) ],
				'atomic'   => static fn(): array => [ 'items' => [], 'issues' => [] ],
				'abilities'=> static fn(): array => [],
			];
			$callbacks[ $throwing ] = static function () use ( $throwing ): never {
				throw new \RuntimeException( 'private provider detail for ' . $throwing );
			};
			$router = new ProviderRouter(
				static fn(): array => self::architecture( 'v3' ),
				$callbacks['v3'],
				$callbacks['atomic'],
				$callbacks['abilities']
			);

			$result = $router->inspect( 42 );

			self::assertSame(
				[ 'code' => 'provider_discovery_failed', 'provider' => $throwing, 'error_class' => \RuntimeException::class ],
				$result['issues'][0],
				$throwing
			);
			self::assertStringNotContainsString( 'private provider detail', (string) wp_json_encode( $result ), $throwing );
			if ( 'v3' !== $throwing ) {
				self::assertSame( [ 'elementor-core' ], array_column( $result['providers'], 'id' ), $throwing );
			}
		}
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

		self::assertSame( 'unsupported', $result['selection']['status'] );
		self::assertSame( [ 'v4' ], $provider['architectures'] );
		self::assertSame( 'untrusted', $provider['trust'] );
		self::assertSame( 'discovered', $provider['certification'] );
		self::assertFalse( $provider['capabilities'][0]['write_eligible'] );
		self::assertSame( 'atomic-hash', $provider['capabilities'][0]['schema_fingerprint'] );
		self::assertSame( 'live_elementor_runtime', $provider['capabilities'][0]['provenance']['schema'] );
	}

	public function test_third_party_atomic_provider_cannot_self_assert_write_certification(): void {
		$schema = [
			'atomic_type'            => 'e-certified-card',
			'kind'                   => 'widget',
			'source'                 => 'live_runtime',
			'source_plugin'          => 'certified-provider/bootstrap.php',
			'source_version'         => '1.2.0',
			'runtime_class'          => 'CertifiedProvider\\Atomic\\Card',
			'schema_fingerprint'     => 'certified-hash',
			'provider_id'            => 'plugin:certified-provider',
			'provider_trust'         => 'trusted',
			'provider_certification' => 'certified',
			'provenance'             => [ 'schema' => 'live_elementor_runtime', 'certification' => 'stonewright_explicit_certification' ],
		];

		$result = $this->router( 'v4', [], [ 'items' => [ $schema ], 'issues' => [] ] )->inspect();
		$provider = self::index_by( $result['providers'], 'id' )['plugin:certified-provider'];

		self::assertSame( 'unsupported', $result['selection']['status'] );
		self::assertSame( 'untrusted', $provider['trust'] );
		self::assertSame( 'discovered', $provider['certification'] );
		self::assertFalse( $provider['capabilities'][0]['write_eligible'] );
		self::assertTrue( $provider['read_only'] );
	}

	public function test_pro_elements_runtime_remains_third_party_without_stonewright_certification(): void {
		$schema = [
			'atomic_type'        => 'e-pro-elements-card',
			'kind'               => 'widget',
			'source'             => 'live_runtime',
			'source_plugin'      => 'pro-elements/pro-elements.php',
			'source_version'     => '3.30.0',
			'runtime_class'      => 'ElementorPro\\Modules\\Card\\Widget',
			'schema_fingerprint' => 'pro-elements-hash',
			'provider_id'        => 'elementor-pro',
			'provenance'         => [
				'schema'    => 'live_elementor_runtime',
				'ownership' => 'active_plugin_header',
			],
		];

		$result = $this->router( 'v4', [], [ 'items' => [ $schema ], 'issues' => [] ] )->inspect();
		$provider = self::index_by( $result['providers'], 'id' )['plugin:pro-elements'];

		self::assertSame( 'third-party', $provider['ownership'] );
		self::assertSame( 'untrusted', $provider['trust'] );
		self::assertSame( 'discovered', $provider['certification'] );
		self::assertFalse( $provider['capabilities'][0]['write_eligible'] );
	}

	public function test_adversarial_provider_diagnostics_are_capped_and_summarized_at_source(): void {
		$issues = [];
		for ( $index = 0; $index < 1000; ++$index ) {
			$issues[] = [ 'code' => 'schema_issue_' . $index, 'atomic_type' => 'e-broken-' . $index ];
		}

		$result = $this->router( 'v4', [], [ 'items' => [], 'issues' => $issues ] )->inspect();

		self::assertCount( 20, $result['issues'] );
		self::assertSame( 1000, $result['issues_count'] );
		self::assertTrue( $result['issues_truncated'] );
		self::assertSame( 'schema_issue_0', $result['issues'][0]['code'] );
		self::assertSame( 'schema_issue_19', $result['issues'][19]['code'] );
	}

	public function test_adversarial_provider_inventory_is_capped_with_total_metadata(): void {
		$schemas = [];
		for ( $index = 0; $index < 1000; ++$index ) {
			$schemas[] = self::v3_widget(
				'widget-' . $index,
				'provider-' . $index . '/bootstrap.php',
				'1.0.0',
				'Provider' . $index . '\\Widget',
				'hash-' . $index
			);
		}

		$result = $this->router( 'v3', $schemas )->inspect();

		self::assertCount( 50, $result['providers'] );
		self::assertSame( 1000, $result['providers_count'] );
		self::assertTrue( $result['providers_truncated'] );
		self::assertSame( 1000, $result['capabilities_count'] );
		self::assertTrue( $result['capabilities_truncated'] );
		self::assertLessThan( 200000, strlen( (string) wp_json_encode( $result ) ) );
	}

	public function test_huge_untrusted_schemas_and_capabilities_are_summary_only_and_bounded(): void {
		$deep = [ 'type' => 'string' ];
		for ( $depth = 0; $depth < 20; ++$depth ) {
			$deep = [ 'nested' => $deep ];
		}
		$huge = [ 'type' => 'object', 'properties' => [ 'deep' => $deep ] ];
		for ( $index = 0; $index < 1000; ++$index ) {
			$huge['properties'][ 'field_' . $index ] = [ 'type' => 'string', 'description' => str_repeat( 'x', 100 ) ];
		}
		$manage = self::authentic_manage_default_styles_ability();
		$manage['source_plugin'] = 'third-party/bootstrap.php';
		$manage['meta']['source_plugin'] = 'elementor/elementor.php';
		$manage['input_schema'] = $huge;
		$manage['output_schema'] = $huge;
		$abilities = [ $manage ];
		for ( $index = 1; $index < 1000; ++$index ) {
			$abilities[] = [
				'name' => 'elementor/third-party-' . $index,
				'description' => str_repeat( 'description-', 100 ),
				'input_schema' => $huge,
				'output_schema' => $huge,
				'meta' => [ 'annotations' => [ 'readonly' => true ] ],
				'source_plugin' => 'third-party/bootstrap.php',
				'source_version' => '1.0.0',
				'runtime_class' => 'ThirdParty\\Ability',
				'provenance' => [ 'ownership' => 'active_plugin_header', 'payload' => $huge ],
			];
		}

		$result = $this->router( 'v4', [], [ 'items' => [], 'issues' => [] ], $abilities )->inspect();
		$provider = self::index_by( $result['providers'], 'id' )['plugin:third-party'];
		$preference = $result['native_preferred']['elementor/manage-default-styles'];

		self::assertCount( 200, $provider['capabilities'] );
		self::assertSame( 1000, $provider['capabilities_count'] );
		self::assertTrue( $provider['capabilities_truncated'] );
		self::assertSame( 1000, $result['capabilities_count'] );
		self::assertTrue( $result['capabilities_truncated'] );
		self::assertArrayNotHasKey( 'input_schema', $preference );
		self::assertArrayNotHasKey( 'output_schema', $preference );
		self::assertGreaterThan( 256, $preference['input_schema_summary']['keys_count'] );
		self::assertGreaterThan( 8, $preference['input_schema_summary']['max_depth'] );
		self::assertGreaterThan( 32768, $preference['input_schema_summary']['bytes'] );
		self::assertTrue( $preference['input_schema_summary']['truncated'] );
		self::assertSame( 'summary_only_untrusted_or_rejected', $preference['schema_output'] );
		self::assertSame( [ 'max_depth' => 8, 'max_keys' => 256, 'max_bytes' => 32768 ], $result['schema_limits'] );
		self::assertLessThan( 200000, strlen( (string) wp_json_encode( $result ) ) );
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
		self::assertSame( 'rejected', $preference['certification'] );
		self::assertSame( 'unsupported', $preference['selection'] );
		self::assertSame( 'upstream_contract_not_certified', $preference['reason'] );
		$provider = self::index_by( $result['providers'], 'id' )['elementor-core'];
		self::assertTrue( $provider['read_only'] );
		self::assertSame( [], $provider['write_primitives'] );
	}

	public function test_manage_default_styles_rejects_every_exact_contract_mismatch(): void {
		$cases = [];
		$ability = self::authentic_manage_default_styles_ability();
		$ability['meta']['annotations']['idempotent'] = true;
		$cases['idempotence'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['input_schema']['type'] = 'array';
		$cases['input_object'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['output_schema']['properties']['results']['type'] = 'object';
		$cases['output_results'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['input_schema']['properties']['operations']['items']['required'] = [ 'action' ];
		$cases['required_tag'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['input_schema']['properties']['operations']['items']['properties']['action']['enum'][] = 'create';
		$cases['action_enum'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['input_schema']['properties']['operations']['items']['properties']['tag']['type'] = 'integer';
		$cases['tag_type'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['input_schema']['properties']['operations']['items']['properties']['css']['description'] = 'Plain CSS string.';
		$cases['css_semantics'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['input_schema']['properties']['operations']['items']['properties']['mode']['default'] = 'replace';
		$cases['mode_default'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['runtime_contract']['runtime_operation_limit'] = 21;
		$cases['batch_limit_21'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['runtime_contract']['runtime_operation_limit'] = 19;
		$cases['batch_limit_19'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['input_schema']['additionalProperties'] = false;
		$cases['additional_properties_keyword'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['runtime_contract']['class_type'] = 'selector';
		$cases['class_type'] = $ability;
		$ability = self::authentic_manage_default_styles_ability();
		$ability['runtime_class'] = 'Elementor\\Modules\\Mcp\\Abilities\\Other_Ability';
		$cases['runtime_class'] = $ability;

		foreach ( $cases as $label => $candidate ) {
			$result = $this->router( 'v4', [], [ 'items' => [], 'issues' => [] ], [ $candidate ] )->inspect();
			$preference = $result['native_preferred']['elementor/manage-default-styles'];
			$provider = self::index_by( $result['providers'], 'id' )['elementor-core'];
			self::assertSame( 'rejected', $preference['certification'], $label );
			self::assertSame( 'unsupported', $preference['selection'], $label );
			self::assertTrue( $provider['read_only'], $label );
			self::assertSame( [], $provider['write_primitives'], $label );
		}
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

		self::assertSame( 'rejected', $preference['certification'] );
		self::assertSame( 'unsupported', $preference['selection'] );
		self::assertSame( 'unverified', self::index_by( $result['providers'], 'id' )['elementor-core']['trust'] );
	}

	public function test_active_third_party_owner_cannot_certify_the_native_elementor_contract(): void {
		$ability = self::authentic_manage_default_styles_ability();
		$ability['source_plugin'] = 'third-party/bootstrap.php';
		$ability['meta']['source_plugin'] = 'third-party/bootstrap.php';

		$result = $this->router( 'v4', [], [ 'items' => [], 'issues' => [] ], [ $ability ] )->inspect();
		$preference = $result['native_preferred']['elementor/manage-default-styles'];
		$provider = self::index_by( $result['providers'], 'id' )['plugin:third-party'];

		self::assertSame( 'rejected', $preference['certification'] );
		self::assertSame( 'unsupported', $preference['selection'] );
		self::assertTrue( $provider['read_only'] );
		self::assertSame( [], $provider['write_primitives'] );
	}

	public function test_callback_inside_official_plugin_boundary_can_be_certified_without_admin_metadata_api(): void {
		$ability = self::authentic_manage_default_styles_ability();
		$ability['provenance']['ownership'] = 'active_plugin_boundary';

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
			'description'   => 'Bulk manage the active kit\'s site-wide default styles, keyed by HTML wrapper tag (h1..h6, p, a, section, div, ...). These styles apply to every V4 atomic element that renders that tag on the whole site, sitting on top of each widget\'s built-in base_styles and beneath any inline or global class overrides. Use action=update to upsert (patch or replace) a tag\'s variants via a raw CSS string (supports @media(--breakpoint) + &:hover/&:focus/&:active), and action=delete to remove a tag\'s default style entirely.',
			'input_schema'  => [
				'type' => 'object',
				'required' => [ 'operations' ],
				'properties' => [
					'operations' => [
						'type' => 'array',
						'description' => 'Bulk operations (1–20). Each item requires action and tag. update needs css (raw CSS string, same format as manage-classes) and applies site-wide to that HTML tag. Use mode to control merge behaviour on update (patch = upsert variants, replace = overwrite variants for the affected breakpoints). delete removes the tag\'s default style entirely.',
						'items' => [
							'type' => 'object',
							'required' => [ 'action', 'tag' ],
							'properties' => [
								'action' => [ 'type' => 'string', 'enum' => [ 'update', 'delete' ] ],
								'tag' => [
									'type' => 'string',
									'description' => 'HTML wrapper tag to target (e.g. h1, h2, p, a). Must be one of Elementor\'s allowed wrapper tags.',
								],
								'css' => [
									'type' => 'string',
									'description' => 'Plain CSS string. Supports &:hover/&:focus/&:active nesting and @media(--breakpoint) blocks. In patch mode: "prop: null" removes that prop; "all: null" wipes the variant.',
								],
								'mode' => [
									'type' => 'string',
									'enum' => [ 'patch', 'replace' ],
									'default' => 'patch',
									'description' => 'patch (default): upsert variants, preserving untouched ones; null/all:null deletions apply. replace: discard all variants for the affected breakpoints, then store new ones; null values have no effect.',
								],
							],
						],
					],
				],
			],
			'output_schema' => [
				'type' => 'object',
				'required' => [ 'status', 'results' ],
				'properties' => [
					'status' => [ 'type' => 'string' ],
					'results' => [ 'type' => 'array' ],
				],
			],
			'meta'          => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'source_plugin' => 'elementor/elementor.php',
				'source_version' => '3afafe33',
				'contract' => [ 'runtime_operation_limit' => 20, 'class_type' => 'class' ],
			],
			'runtime_contract' => [ 'runtime_operation_limit' => 20, 'class_type' => 'class' ],
			'runtime_class' => 'Elementor\\Modules\\Mcp\\Abilities\\Manage_Default_Styles_Ability',
			'provenance' => [
				'schema' => 'upstream_registered_ability',
				'ownership' => 'active_plugin_header',
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
