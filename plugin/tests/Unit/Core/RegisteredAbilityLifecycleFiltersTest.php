<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\RegisteredAbilityRig;

/**
 * On a core that runs the Abilities API lifecycle filters, Stonewright abilities run them too,
 * so site policy and security plugins can govern them. A filter can refuse a call, reword a
 * refusal or transform a result; it never turns one of Stonewright's own refusals into approval.
 *
 * Every test runs in its own process because it loads a synthetic core ability class.
 *
 * @covers \Stonewright\WpMcp\Core\RegisteredAbility
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RegisteredAbilityLifecycleFiltersTest extends TestCase {

	use RegisteredAbilityRig;

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/Compatibility/core-lifecycle-ability.php';
		$this->install_rig();
	}

	protected function tearDown(): void {
		$this->remove_rig();
	}

	private static function assert_error( mixed $result, string $code ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
	}

	public function test_a_call_without_filters_runs_unchanged(): void {
		$result = $this->ability()->execute( [ 'value' => 'x' ] );

		self::assertSame( [ 'ok' => true ], $result );
		self::assertSame( 1, $this->calls['permission'] );
		self::assertSame( 1, $this->calls['execute'] );
	}

	public function test_the_input_filter_can_refuse_input_that_the_schema_accepts(): void {
		$this->filter( 'wp_ability_validate_input', static fn (): \WP_Error => new \WP_Error( 'policy_input', 'Refused by site policy.' ) );

		self::assert_error( $this->ability()->execute( [ 'value' => 'x' ] ), 'policy_input' );
		self::assertSame( 0, $this->calls['permission'] );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_an_input_filter_that_returns_false_refuses_the_call(): void {
		$this->filter( 'wp_ability_validate_input', static fn (): bool => false );

		self::assert_error( $this->ability()->execute( [ 'value' => 'x' ] ), 'ability_invalid_input' );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_the_input_filter_cannot_accept_input_that_the_schema_refuses(): void {
		$this->filter( 'wp_ability_validate_input', static fn (): bool => true );

		self::assert_error( $this->ability()->execute( [ 'unknown' => 1 ] ), 'ability_invalid_input' );
		self::assertSame( 0, $this->calls['permission'] );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_the_input_filter_can_reword_a_schema_refusal_but_the_call_stays_refused(): void {
		$seen = null;
		$this->filter(
			'wp_ability_validate_input',
			static function ( mixed $is_valid ) use ( &$seen ): \WP_Error {
				$seen = $is_valid;
				return new \WP_Error( 'policy_reworded', 'Reworded.' );
			}
		);

		self::assert_error( $this->ability()->execute( [ 'unknown' => 1 ] ), 'policy_reworded' );
		self::assertInstanceOf( \WP_Error::class, $seen, 'The filter sees the schema refusal.' );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_the_input_filter_receives_the_input_and_the_ability_name(): void {
		$captured = [];
		$this->filter(
			'wp_ability_validate_input',
			static function ( mixed $is_valid, mixed $input, string $name ) use ( &$captured ): mixed {
				$captured = [ $is_valid, $input, $name ];
				return $is_valid;
			}
		);

		$this->ability()->execute( [ 'value' => 'x' ] );

		self::assertSame( [ true, [ 'value' => 'x' ], 'stonewright/test-lifecycle' ], $captured );
	}

	public function test_one_call_applies_each_input_filter_once(): void {
		$this->count_filter( 'wp_ability_normalize_input' );
		$this->count_filter( 'wp_ability_validate_input' );
		$this->count_filter( 'wp_ability_permission_result' );
		$this->count_filter( 'wp_ability_validate_output' );
		$this->count_filter( 'wp_ability_execute_result' );

		self::assertSame( [ 'ok' => true ], $this->ability()->execute( [ 'value' => 'x' ] ) );

		self::assertSame( 1, $this->calls['wp_ability_normalize_input'] );
		self::assertSame( 1, $this->calls['wp_ability_validate_input'], 'Permission checking must not validate the input a second time.' );
		self::assertSame( 1, $this->calls['wp_ability_permission_result'] );
		self::assertSame( 1, $this->calls['wp_ability_validate_output'] );
		self::assertSame( 1, $this->calls['wp_ability_execute_result'] );
		self::assertSame( 1, $this->calls['permission'] );
	}

	public function test_the_permission_filter_can_deny_a_granted_call(): void {
		$this->filter( 'wp_ability_permission_result', static fn (): bool => false );

		self::assert_error( $this->ability()->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
		self::assertSame( 1, $this->calls['permission'], 'The ability own check still ran.' );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_the_permission_filter_can_deny_with_an_error(): void {
		$this->filter( 'wp_ability_permission_result', static fn (): \WP_Error => new \WP_Error( 'policy_denied', 'Denied by site policy.' ) );
		$ability = $this->ability();

		$direct = $ability->check_permissions( [ 'value' => 'x' ] );
		self::assert_error( $direct, 'policy_denied' );
		self::assert_error( $ability->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_the_permission_filter_cannot_grant_a_call_the_ability_denied(): void {
		$this->filter( 'wp_ability_permission_result', static fn (): bool => true );
		$ability = $this->ability(
			[
				'permission_callback' => function (): bool {
					++$this->calls['permission'];
					return false;
				},
			]
		);

		self::assertFalse( $ability->check_permissions( [ 'value' => 'x' ] ) );
		self::assert_error( $ability->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
		self::assertSame( 0, $this->calls['execute'], 'A filter must not unlock an ability its own permission check refused.' );
	}

	public function test_the_permission_filter_cannot_turn_a_refusal_error_into_a_grant(): void {
		$this->filter( 'wp_ability_permission_result', static fn (): bool => true );
		$ability = $this->ability(
			[
				'permission_callback' => static fn (): \WP_Error => new \WP_Error( 'stonewright_forbidden', 'No.' ),
			]
		);

		self::assert_error( $ability->check_permissions( [ 'value' => 'x' ] ), 'stonewright_forbidden' );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_the_permission_filter_receives_the_result_the_name_the_input_and_the_ability(): void {
		$captured = [];
		$this->filter(
			'wp_ability_permission_result',
			static function ( mixed $permission, string $name, mixed $input, mixed $ability ) use ( &$captured ): mixed {
				$captured = [ $permission, $name, $input, $ability ];
				return $permission;
			}
		);
		$ability = $this->ability();

		$ability->execute( [ 'value' => 'x' ] );

		self::assertTrue( $captured[0] );
		self::assertSame( 'stonewright/test-lifecycle', $captured[1] );
		self::assertSame( [ 'value' => 'x' ], $captured[2] );
		self::assertSame( $ability, $captured[3] );
	}

	public function test_a_direct_permission_check_validates_the_input_before_the_callback_runs(): void {
		$this->count_filter( 'wp_ability_validate_input' );
		$this->count_filter( 'wp_ability_permission_result' );

		self::assert_error( $this->ability()->check_permissions( [ 'unknown' => 1 ] ), 'ability_invalid_input' );

		self::assertSame( 0, $this->calls['permission'] );
		self::assertSame( 1, $this->calls['wp_ability_validate_input'] );
		self::assertSame( 0, $this->calls['wp_ability_permission_result'] );
	}

	public function test_a_direct_permission_check_turns_null_adapter_input_into_the_empty_object(): void {
		$ability = $this->ability( [ 'input_schema' => [ 'type' => 'object', 'properties' => [ 'value' => [ 'type' => 'string' ] ] ] ] );

		self::assertTrue( $ability->check_permissions( null ) );
		self::assertSame( [ [ 'permission', [] ] ], $this->seen );
	}

	public function test_a_direct_permission_check_halts_on_a_normalisation_error(): void {
		$this->filter( 'wp_ability_normalize_input', static fn (): \WP_Error => new \WP_Error( 'policy_normalize', 'Refused.' ) );
		$this->count_filter( 'wp_ability_validate_input' );
		$ability = $this->ability();

		self::assert_error( $ability->check_permissions( [ 'value' => 'x' ] ), 'policy_normalize' );
		self::assert_error( $ability->execute( [ 'value' => 'x' ] ), 'policy_normalize' );
		self::assertSame( 0, $this->calls['wp_ability_validate_input'] );
		self::assertSame( 0, $this->calls['permission'] );
	}

	public function test_the_filtered_input_is_what_the_permission_callback_and_the_ability_receive(): void {
		$this->filter( 'wp_ability_normalize_input', static fn ( mixed $input ): array => $input + [ 'value' => 'from-policy' ] );

		$this->ability()->execute( [] );

		self::assertSame( [ [ 'permission', [ 'value' => 'from-policy' ] ], [ 'execute', [ 'value' => 'from-policy' ] ] ], $this->seen );
	}

	public function test_the_adapter_sequence_checks_then_executes_and_applies_each_filter_twice(): void {
		$this->count_filter( 'wp_ability_normalize_input' );
		$this->count_filter( 'wp_ability_validate_input' );
		$this->count_filter( 'wp_ability_permission_result' );
		$ability = $this->ability();

		// The MCP adapter asks check_permissions() with the raw arguments, then calls execute().
		self::assertTrue( $ability->check_permissions( [ 'value' => 'x' ] ) );
		self::assertSame( [ 'ok' => true ], $ability->execute( [ 'value' => 'x' ] ) );

		self::assertSame( 2, $this->calls['wp_ability_normalize_input'] );
		self::assertSame( 2, $this->calls['wp_ability_validate_input'] );
		self::assertSame( 2, $this->calls['wp_ability_permission_result'] );
		self::assertSame( 2, $this->calls['permission'] );
		self::assertSame( 1, $this->calls['execute'] );
	}

	public function test_the_output_filter_can_refuse_output_that_the_schema_accepts(): void {
		$this->filter( 'wp_ability_validate_output', static fn (): \WP_Error => new \WP_Error( 'policy_output', 'Output refused.' ) );

		self::assert_error( $this->ability()->execute( [ 'value' => 'x' ] ), 'policy_output' );
		self::assertSame( 1, $this->calls['execute'], 'The ability ran; its result is withheld.' );
	}

	public function test_the_output_filter_cannot_accept_output_that_the_schema_refuses(): void {
		$this->filter( 'wp_ability_validate_output', static fn (): bool => true );
		$ability = $this->ability( [ 'execute_callback' => static fn (): array => [ 'ok' => 'yes' ] ] );

		self::assert_error( $ability->execute( [ 'value' => 'x' ] ), 'ability_invalid_output' );
	}

	public function test_the_core_result_and_short_circuit_filters_still_run_through_the_override(): void {
		$this->filter( 'wp_ability_execute_result', static fn ( mixed $result ): array => $result + [ 'policy' => 'annotated' ] );
		self::assertSame( [ 'ok' => true, 'policy' => 'annotated' ], $this->ability()->execute( [ 'value' => 'x' ] ) );

		$GLOBALS['stonewright_test_filters'] = [];
		$this->calls['execute']              = 0;
		$this->filter( 'wp_pre_execute_ability', static fn (): array => [ 'cached' => true ] );
		self::assertSame( [ 'cached' => true ], $this->ability()->execute( [ 'value' => 'x' ] ) );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_an_ability_without_a_callable_permission_callback_is_denied(): void {
		$ability = $this->ability( [ 'permission_callback' => null ] );

		self::assert_error( $ability->check_permissions( [ 'value' => 'x' ] ), 'ability_invalid_permission_callback' );
		self::assert_error( $ability->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_a_permission_callback_that_throws_denies_the_call(): void {
		$ability = $this->ability(
			[
				'permission_callback' => static function (): bool {
					throw new \RuntimeException( 'boom' );
				},
			]
		);

		self::assert_error( $ability->check_permissions( [ 'value' => 'x' ] ), 'ability_callback_exception' );
		self::assert_error( $ability->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_a_permission_callback_that_answers_neither_true_nor_an_error_denies(): void {
		foreach ( [ null, 1, 'yes', 'true', [ 'ok' ], [], new \stdClass(), 1.0 ] as $answer ) {
			$ability = $this->ability(
				[
					'permission_callback' => static fn () => $answer,
				]
			);

			self::assertFalse( $ability->check_permissions( [ 'value' => 'x' ] ), 'Answer: ' . var_export( $answer, true ) );
			self::assert_error( $ability->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
		}
		self::assertSame( 0, $this->calls['execute'], 'No ability ran on a loose answer.' );
	}
}
