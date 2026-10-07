<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\RegisteredAbilityRig;

/**
 * On a core older than 6.9 the standalone Abilities API package owns WP_Ability. Its execute()
 * asks has_permission() first, which is where Stonewright validates input, so the lifecycle
 * filters Stonewright applies fire there too, each once per call.
 *
 * @covers \Stonewright\WpMcp\Core\RegisteredAbility
 */
final class RegisteredAbilityLegacyRuntimeFiltersTest extends TestCase {

	use RegisteredAbilityRig;

	protected function setUp(): void {
		$plugin_root = dirname( __DIR__, 3 );
		if ( ! class_exists( 'WP_Ability', false ) ) {
			require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
		}
		$this->install_rig();
	}

	protected function tearDown(): void {
		$this->remove_rig();
	}

	private static function assert_error( mixed $result, string $code ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
	}

	public function test_execute_applies_each_filter_once(): void {
		$this->count_filter( 'wp_ability_validate_input' );
		$this->count_filter( 'wp_ability_permission_result' );
		$this->count_filter( 'wp_ability_validate_output' );

		self::assertSame( [ 'ok' => true ], $this->ability()->execute( [ 'value' => 'x' ] ) );

		self::assertSame( 1, $this->calls['wp_ability_validate_input'] );
		self::assertSame( 1, $this->calls['wp_ability_permission_result'] );
		self::assertSame( 1, $this->calls['wp_ability_validate_output'] );
		self::assertSame( 1, $this->calls['permission'] );
		self::assertSame( 1, $this->calls['execute'] );
	}

	public function test_the_input_filter_can_refuse_but_cannot_accept(): void {
		$this->filter( 'wp_ability_validate_input', static fn (): \WP_Error => new \WP_Error( 'policy_input', 'Refused by site policy.' ) );
		self::assert_error( $this->ability()->has_permission( [ 'value' => 'x' ] ), 'policy_input' );
		// The standalone package hides every refusal but an invalid-input one behind this code.
		self::assert_error( $this->ability()->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
		self::assertSame( 0, $this->calls['execute'] );

		$this->filter( 'wp_ability_validate_input', static fn (): bool => true );
		self::assert_error( $this->ability()->execute( [ 'unknown' => 1 ] ), 'ability_invalid_input' );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_the_permission_filter_can_deny_but_cannot_grant(): void {
		$this->filter( 'wp_ability_permission_result', static fn (): bool => false );
		self::assertFalse( $this->ability()->has_permission( [ 'value' => 'x' ] ) );
		self::assert_error( $this->ability()->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
		self::assertSame( 0, $this->calls['execute'] );

		$this->filter( 'wp_ability_permission_result', static fn (): bool => true );
		$denying = $this->ability( [ 'permission_callback' => static fn (): bool => false ] );
		self::assertFalse( $denying->has_permission( [ 'value' => 'x' ] ) );
		self::assert_error( $denying->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
		self::assertSame( 0, $this->calls['execute'] );
	}

	public function test_the_output_filter_can_refuse_but_cannot_accept(): void {
		$this->filter( 'wp_ability_validate_output', static fn (): \WP_Error => new \WP_Error( 'policy_output', 'Output refused.' ) );
		self::assert_error( $this->ability()->execute( [ 'value' => 'x' ] ), 'policy_output' );

		$this->filter( 'wp_ability_validate_output', static fn (): bool => true );
		$invalid = $this->ability( [ 'execute_callback' => static fn (): array => [ 'ok' => 'yes' ] ] );
		self::assert_error( $invalid->execute( [ 'value' => 'x' ] ), 'ability_invalid_output' );
	}

	public function test_the_rest_controller_check_validates_before_the_permission_callback(): void {
		$this->count_filter( 'wp_ability_validate_input' );

		self::assert_error( $this->ability()->has_permission( [ 'unknown' => 1 ] ), 'ability_invalid_input' );

		self::assertSame( 0, $this->calls['permission'] );
		self::assertSame( 1, $this->calls['wp_ability_validate_input'] );
	}

	public function test_a_missing_or_throwing_permission_callback_denies(): void {
		self::assert_error( $this->ability( [ 'permission_callback' => null ] )->has_permission( [ 'value' => 'x' ] ), 'ability_invalid_permission_callback' );

		$throwing = $this->ability(
			[
				'permission_callback' => static function (): bool {
					throw new \RuntimeException( 'boom' );
				},
			]
		);
		self::assert_error( $throwing->has_permission( [ 'value' => 'x' ] ), 'ability_callback_exception' );
		self::assert_error( $throwing->execute( [ 'value' => 'x' ] ), 'ability_invalid_permissions' );
	}
}
