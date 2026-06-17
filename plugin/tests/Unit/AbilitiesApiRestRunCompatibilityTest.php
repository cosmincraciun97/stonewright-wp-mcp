<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RegisteredAbility;

/**
 * Regression guard for mixed Abilities API runtimes.
 *
 * WordPress 6.9+ defines the global WP_Ability class before Stonewright's
 * vendored Abilities API can load its own class. The vendored wp/v2 run
 * controller still calls WP_Ability::has_permission(), so Stonewright must
 * register abilities with a compatibility ability class that provides it.
 *
 * - standalone package: WP_Ability::has_permission()
 * - WordPress core:     WP_Ability::check_permissions()
 */
final class AbilitiesApiRestRunCompatibilityTest extends TestCase {

	private const ABILITY_REGISTRY = __DIR__ . '/../../includes/Core/AbilityRegistry.php';
	private const VENDOR_ABILITY   = __DIR__ . '/../../vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';

	public function test_ability_registry_uses_stonewright_compatibility_class(): void {
		$this->assertFileExists( self::ABILITY_REGISTRY );

		$contents = (string) file_get_contents( self::ABILITY_REGISTRY );

		$this->assertStringContainsString(
			"'ability_class'       => RegisteredAbility::class",
			$contents,
			'Stonewright abilities must use RegisteredAbility so the vendored wp/v2 run controller cannot fatal on WordPress core 6.9+.'
		);
	}

	public function test_registered_ability_exposes_has_permission_for_vendor_rest_controller(): void {
		if ( ! class_exists( \WP_Ability::class ) ) {
			require_once self::VENDOR_ABILITY;
		}

		$this->assertTrue( is_subclass_of( RegisteredAbility::class, \WP_Ability::class ) );
		$this->assertTrue( method_exists( RegisteredAbility::class, 'has_permission' ) );

		$ability = new RegisteredAbility(
			'stonewright/test-compat',
			[
				'label'               => 'Compatibility test',
				'description'         => 'Compatibility test ability.',
				'output_schema'       => [],
				'permission_callback' => static fn ( array $input ): bool => true === ( $input['allowed'] ?? false ),
				'execute_callback'    => static fn ( array $input ): array => $input,
			]
		);

		$this->assertFalse( $ability->has_permission( [ 'allowed' => false ] ) );
		$this->assertTrue( $ability->has_permission( [ 'allowed' => true ] ) );
	}

	public function test_registered_ability_accepts_null_input_from_mcp_adapters(): void {
		if ( ! class_exists( \WP_Ability::class ) ) {
			require_once self::VENDOR_ABILITY;
		}

		$this->assertTrue( method_exists( RegisteredAbility::class, 'check_permissions' ) );

		$ability = new RegisteredAbility(
			'stonewright/test-null-input',
			[
				'label'               => 'Null input compatibility test',
				'description'         => 'Compatibility test ability.',
				'output_schema'       => [],
				'permission_callback' => static fn ( array $input ): bool => [] === $input,
				'execute_callback'    => static fn ( array $input ): array => [ 'input' => $input ],
			]
		);

		$this->assertTrue( $ability->has_permission( null ) );
		$this->assertTrue( $ability->check_permissions( null ) );
		$this->assertSame( [ 'input' => [] ], $ability->execute( null ) );
	}

	public function test_registered_ability_validates_runtime_schema_placeholders_without_stdclass_fatal(): void {
		if ( ! class_exists( \WP_Ability::class ) ) {
			require_once self::VENDOR_ABILITY;
		}

		$ability = new RegisteredAbility(
			'stonewright/test-runtime-schema-placeholders',
			[
				'label'               => 'Runtime schema placeholder test',
				'description'         => 'Compatibility test ability.',
				'output_schema'       => [
					'type'       => 'object',
					'required'   => [ 'items' ],
					'properties' => [
						'items' => [
							'type'  => 'array',
							'items' => new \stdClass(),
						],
					],
				],
				'permission_callback' => static fn ( array $input ): bool => true,
				'execute_callback'    => static fn ( array $input ): array => [
					'items' => [
						[ 'name' => 'context-bootstrap' ],
					],
				],
			]
		);

		$this->assertSame(
			[
				'items' => [
					[ 'name' => 'context-bootstrap' ],
				],
			],
			$ability->execute( [] )
		);
	}
}
