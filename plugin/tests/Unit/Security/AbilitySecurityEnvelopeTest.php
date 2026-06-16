<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * Registry-wide proof that mutating abilities keep Stonewright's security
 * envelope: explicit Permissions callbacks, no __return_true shortcut, and
 * task context tokens in their public schema.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilitySecurityEnvelopeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => false,
			'stonewright_essential_extra_abilities' => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	/**
	 * @dataProvider mutating_ability_provider
	 * @param class-string<Ability> $class
	 */
	public function test_mutating_abilities_use_explicit_permissions_callbacks( string $class ): void {
		$ability = new $class();
		$method  = new ReflectionMethod( $ability, 'permission_callback' );

		$this->assertNotSame(
			AbilityKernel::class,
			$method->getDeclaringClass()->getName(),
			$ability->name() . ' must override the read-only default permission callback.'
		);

		$this->assertStringContainsString(
			'Permissions::',
			$this->method_source( $method ),
			$ability->name() . ' permission callback must call a Permissions helper.'
		);
	}

	/**
	 * @dataProvider mutating_ability_provider
	 * @param class-string<Ability> $class
	 */
	public function test_mutating_ability_schemas_require_context_token( string $class ): void {
		$ability_name = ( new $class() )->name();
		$metadata     = $this->ability_metadata_by_name()[ $ability_name ] ?? null;

		$this->assertNotNull( $metadata, $ability_name . ' must be present in registry metadata.' );

		$schema     = $metadata['input_schema'];
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] )
			? $schema['properties']
			: [];
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] )
			? $schema['required']
			: [];

		$this->assertArrayHasKey(
			'stonewright_context_token',
			$properties,
			$ability_name . ' must expose the Stonewright context token property.'
		);
		$this->assertContains(
			'stonewright_context_token',
			$required,
			$ability_name . ' must require the Stonewright context token.'
		);
	}

	public function test_ability_sources_do_not_use_return_true_shortcut(): void {
		$matches = [];
		foreach ( $this->ability_source_files() as $file ) {
			$contents = file_get_contents( $file );
			if ( false !== $contents && str_contains( $contents, '__return_true' ) ) {
				$matches[] = $file;
			}
		}

		$this->assertSame(
			[],
			$matches,
			'Ability source files must not use __return_true as a permission shortcut.'
		);
	}

	/**
	 * @return array<string, array{class-string<Ability>}>
	 */
	public static function mutating_ability_provider(): array {
		$abilities = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$ability = new $class();
			if ( self::is_mutating_name( $ability->name() ) ) {
				$abilities[ $ability->name() ] = [ $class ];
			}
		}

		ksort( $abilities );
		return $abilities;
	}

	private static function is_mutating_name( string $name ): bool {
		return 1 === preg_match(
			'/-(?:create|update|write|delete|remove|apply|insert|save|set|move|upload|optimize|activate|deactivate|toggle|register|define|bulk|record|duplicate)\b/',
			$name
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function ability_metadata_by_name(): array {
		$metadata = [];
		foreach ( AbilityRegistry::enabled_abilities() as $ability ) {
			$metadata[ (string) $ability['name'] ] = $ability;
		}
		return $metadata;
	}

	private function method_source( ReflectionMethod $method ): string {
		$file = $method->getFileName();
		if ( false === $file ) {
			return '';
		}

		$lines = file( $file );
		if ( false === $lines ) {
			return '';
		}

		return implode(
			'',
			array_slice(
				$lines,
				$method->getStartLine() - 1,
				$method->getEndLine() - $method->getStartLine() + 1
			)
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function ability_source_files(): array {
		$files = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$reflection = new \ReflectionClass( $class );
			$file       = $reflection->getFileName();
			if ( false !== $file ) {
				$files[] = $file;
			}
		}

		return array_values( array_unique( $files ) );
	}
}
