<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\QA\ApplyFixPlan;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\QA\ApplyFixPlan
 */
final class ApplyFixPlanTest extends TestCase {

	protected function setUp(): void {
		// Reset all test globals to a clean baseline.
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	protected function tearDown(): void {
		// Restore all test globals so other tests are unaffected.
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	public function test_apply_fix_plan_is_truthful_until_patch_paths_exist(): void {
		$ability = new ApplyFixPlan();

		$result = $ability->execute(
			[
				'plan' => [
					[
						'kind' => 'block_update',
					],
				],
			]
		);

		$this->assertSame(
			[
				'ok'           => false,
				'applied'      => 0,
				'skipped'      => 1,
				'audit_log_id' => 0,
				'status'       => 'not_implemented',
			],
			$result
		);
	}

	// -------------------------------------------------------------------------
	// ConfirmationGuard tests — production-safe mode.
	// -------------------------------------------------------------------------

	public function test_returns_confirmation_required_error_in_production_safe_mode_without_token(): void {
		// Set production-safe mode.
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$ability = new ApplyFixPlan();
		$result  = $ability->execute(
			[
				'post_id' => 1,
				'plan'    => [
					[ 'kind' => 'block_update' ],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 403, $data['status'] );
	}

	public function test_returns_not_implemented_in_production_safe_mode_with_valid_token(): void {
		// Set production-safe mode.
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		// The ability strips 'confirmation_token' from verify_args, so we sign over
		// the remaining args only.
		$verify_args = [
			'post_id' => 1,
			'plan'    => [ [ 'kind' => 'block_update' ] ],
		];
		$token = ConfirmationToken::issue( 'stonewright/qa-apply-fix-plan', $verify_args );

		$ability = new ApplyFixPlan();
		$result  = $ability->execute(
			array_merge( $verify_args, [ 'confirmation_token' => $token ] )
		);

		// Token gate passes; stub fires and returns not_implemented.
		$this->assertIsArray( $result );
		$this->assertSame( 'not_implemented', $result['status'] );
		$this->assertFalse( $result['ok'] );
	}

	public function test_returns_invalid_token_error_in_production_safe_mode_with_bogus_token(): void {
		// Set production-safe mode.
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$ability = new ApplyFixPlan();
		$result  = $ability->execute(
			[
				'post_id'            => 1,
				'plan'               => [ [ 'kind' => 'block_update' ] ],
				'confirmation_token' => 'swc_total.garbage',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_invalid', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 403, $data['status'] );
	}
}
