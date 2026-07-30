<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Abilities\ContentModel\CptAcfLoopGridFlow;
use Stonewright\WpMcp\Abilities\Site\Info;
use Stonewright\WpMcp\Abilities\System\WorkflowPreflight;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\ResponseProjection;

/**
 * Projection has to be a property of the call, not of the ability.
 *
 * Ability instances are reused across calls in the registry, so anything stored
 * on an instance leaks from one caller's request into the next one's response.
 * These tests pin the seam: the parameter is advertised on every strict schema,
 * it is consumed before execute() ever sees it, and two consecutive calls on the
 * same instance cannot influence each other.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::execute_with_context_guard
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::all_abilities
 */
final class ResponseProjectionRegistryTest extends TestCase {

	protected function setUp(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_disabled_abilities'   => [],
			'stonewright_essential_tools_mode' => true,
		];
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_every_strict_schema_advertises_the_projection_parameter(): void {
		$missing = [];
		foreach ( AbilityRegistry::all_abilities() as $entry ) {
			$properties = $entry['input_schema']['properties'] ?? [];
			if ( ! is_array( $properties ) || ! isset( $properties[ ResponseProjection::PARAM ] ) ) {
				$missing[] = $entry['name'];
			}
		}

		self::assertSame( [], $missing );
	}

	public function test_the_projection_parameter_is_never_required(): void {
		foreach ( AbilityRegistry::all_abilities() as $entry ) {
			$required = $entry['input_schema']['required'] ?? [];
			self::assertNotContains(
				ResponseProjection::PARAM,
				is_array( $required ) ? $required : [],
				$entry['name']
			);
		}
	}

	public function test_injection_does_not_collide_with_an_ability_owned_fields_input(): void {
		// cpt-acf-loop-grid-flow takes a required `fields` input describing ACF
		// field definitions. A projection parameter named `fields` would have
		// overwritten it and silently dropped the caller's field list.
		$schema = ( new CptAcfLoopGridFlow() )->input_schema();
		self::assertArrayHasKey( 'fields', $schema['properties'] );
		self::assertContains( 'fields', $schema['required'] );
		self::assertNotSame( 'fields', ResponseProjection::PARAM );
	}

	public function test_read_ability_response_is_projected(): void {
		$result = AbilityRegistry::execute_with_context_guard(
			new Info(),
			[ ResponseProjection::PARAM => [ 'name', 'url' ] ]
		);

		self::assertIsArray( $result );
		self::assertSame( [ 'name', 'url', 'wp_version' ], array_keys( $result ) );
	}

	public function test_the_projection_parameter_never_reaches_execute(): void {
		$ability = new class() implements Ability {
			/** @var array<int, array<string, mixed>> */
			public array $seen = [];

			public function name(): string {
				return 'stonewright/test-projection-probe';
			}

			public function label(): string {
				return 'Projection probe';
			}

			public function description(): string {
				return 'Records the arguments it was handed.';
			}

			public function category(): string {
				return 'site';
			}

			public function input_schema(): array {
				return [ 'type' => 'object' ];
			}

			public function output_schema(): array {
				return [ 'type' => 'object' ];
			}

			public function meta(): array {
				return [];
			}

			public function permission_callback( array $args ): bool|\WP_Error {
				return true;
			}

			public function execute( array $args ) {
				$this->seen[] = $args;

				return [
					'ok'    => true,
					'alpha' => 1,
					'beta'  => 2,
				];
			}
		};

		$projected = AbilityRegistry::execute_with_context_guard(
			$ability,
			[
				ResponseProjection::PARAM => [ 'alpha' ],
				'keep'                    => 'yes',
			]
		);

		self::assertSame( [ [ 'keep' => 'yes' ] ], $ability->seen );
		self::assertSame(
			[
				'ok'    => true,
				'alpha' => 1,
			],
			$projected
		);

		// Same instance, no projection: the previous call must not have left
		// anything behind that trims this response too.
		$full = AbilityRegistry::execute_with_context_guard( $ability, [] );

		self::assertSame(
			[
				'ok'    => true,
				'alpha' => 1,
				'beta'  => 2,
			],
			$full
		);
	}

	public function test_task_start_projection_keeps_its_required_output_envelope(): void {
		$result = AbilityRegistry::execute_with_context_guard(
			new WorkflowPreflight(),
			[
				'task'                    => 'Inspect the current site.',
				'responseMode'            => 'compact',
				ResponseProjection::PARAM => [ 'context_token' ],
			]
		);

		self::assertIsArray( $result );
		foreach ( [ 'ok', 'context_token', 'mode', 'auth_guidance', 'fast_path' ] as $required ) {
			self::assertArrayHasKey( $required, $result );
		}
	}

	public function test_error_responses_are_not_projected(): void {
		$ability = new class() implements Ability {
			public function name(): string {
				return 'stonewright/test-projection-failure';
			}

			public function label(): string {
				return 'Projection failure probe';
			}

			public function description(): string {
				return 'Always fails.';
			}

			public function category(): string {
				return 'site';
			}

			public function input_schema(): array {
				return [ 'type' => 'object' ];
			}

			public function output_schema(): array {
				return [ 'type' => 'object' ];
			}

			public function meta(): array {
				return [];
			}

			public function permission_callback( array $args ): bool|\WP_Error {
				return true;
			}

			public function execute( array $args ) {
				return new \WP_Error( 'stonewright_probe_failed', 'Nope', [ 'status' => 400 ] );
			}
		};

		$result = AbilityRegistry::execute_with_context_guard(
			$ability,
			[ ResponseProjection::PARAM => [ 'nothing' ] ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_probe_failed', $result->get_error_code() );
	}

	public function test_a_projected_read_still_carries_the_task_start_hint(): void {
		// The nudge is how an agent learns to bootstrap. Projection is about
		// payload size and must not be able to hide it.
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'projection-session-a';

		$result = AbilityRegistry::execute_with_context_guard(
			new Info(),
			[ ResponseProjection::PARAM => [ 'name' ] ]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'name', $result );
		self::assertArrayHasKey( 'task_start_hint', $result );
	}
}
