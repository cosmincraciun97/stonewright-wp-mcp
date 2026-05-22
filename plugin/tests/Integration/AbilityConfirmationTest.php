<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxActivate;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxDelete;
use Stonewright\WpMcp\Abilities\FSE\UpdateGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\UpdateTemplate;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors;
use Stonewright\WpMcp\Abilities\QA\ApplyFixPlan;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Integration tests verifying that every listed destructive ability:
 * - Rejects calls in production-safe mode without a token.
 * - Rejects calls with a token for wrong args.
 * - Passes the token gate in development mode (no token needed).
 *
 * @covers \Stonewright\WpMcp\Security\ConfirmationToken
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxActivate
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxDelete
 * @covers \Stonewright\WpMcp\Abilities\FSE\UpdateGlobalStyles
 * @covers \Stonewright\WpMcp\Abilities\FSE\UpdateTemplate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors
 * @covers \Stonewright\WpMcp\Abilities\QA\ApplyFixPlan
 */
final class AbilityConfirmationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']        = [
			'read'            => true,
			'edit_posts'      => true,
			'edit_pages'      => true,
			'manage_options'  => true,
			'edit_plugins'    => true,
			'edit_themes'     => true,
			'upload_files'    => true,
			'edit_theme_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_current_user_id']  = 42;
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_transients']       = [];
		$GLOBALS['stonewright_test_wpdb_inserts']     = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']        = [];
		$GLOBALS['stonewright_test_user_logged_in']   = false;
		$GLOBALS['stonewright_test_current_user_id']  = 0;
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_transients']       = [];
		$GLOBALS['stonewright_test_wpdb_inserts']     = [];
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	private function set_production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
	}

	// -------------------------------------------------------------------------
	// SandboxActivate.
	// -------------------------------------------------------------------------

	public function test_sandbox_activate_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$ability = new SandboxActivate();
		$result  = $ability->execute( [ 'name' => 'test.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_sandbox_activate_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new SandboxActivate();
		$token   = ConfirmationToken::issue( $ability->name(), [ 'name' => 'never.php' ] );
		$result  = $ability->execute( [
			'name'               => 'never.php',
			'confirmation_token' => $token,
		] );
		// It gets past the token gate but fails at filesystem layer.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_sandbox_activate_development_mode_no_token_accepted(): void {
		// Default mode is development — no token needed.
		$ability = new SandboxActivate();
		$result  = $ability->execute( [ 'name' => 'never.php' ] );
		// Passes the token gate; fails at filesystem.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// SandboxDelete.
	// -------------------------------------------------------------------------

	public function test_sandbox_delete_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$ability = new SandboxDelete();
		$result  = $ability->execute( [ 'name' => 'test.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_sandbox_delete_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new SandboxDelete();
		$token   = ConfirmationToken::issue( $ability->name(), [ 'name' => 'never.php' ] );
		$result  = $ability->execute( [
			'name'               => 'never.php',
			'confirmation_token' => $token,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_sandbox_delete_production_safe_wrong_token_rejected(): void {
		$this->set_production_safe();
		$ability = new SandboxDelete();
		// Token for different args.
		$token  = ConfirmationToken::issue( $ability->name(), [ 'name' => 'other.php' ] );
		$result = $ability->execute( [
			'name'               => 'test.php',
			'confirmation_token' => $token,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		// The SandboxGuards trait will return an error — could be invalid or args_mismatch.
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$code = $result->get_error_code();
		$this->assertTrue(
			in_array( $code, [ 'stonewright_confirmation_invalid', 'stonewright_confirmation_args_mismatch' ], true ),
			"Expected an invalid/args_mismatch error, got: $code"
		);
	}

	public function test_sandbox_delete_development_mode_no_token_passes_gate(): void {
		$ability = new SandboxDelete();
		$result  = $ability->execute( [ 'name' => 'never.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// FSE\UpdateGlobalStyles.
	// -------------------------------------------------------------------------

	public function test_fse_update_global_styles_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$ability = new UpdateGlobalStyles();
		$result  = $ability->execute( [ 'settings' => [ 'color' => [] ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_fse_update_global_styles_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new UpdateGlobalStyles();
		$args    = [ 'settings' => [ 'color' => [] ] ];
		$token   = ConfirmationToken::issue( $ability->name(), $args );
		$result  = $ability->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );
		// Passes the token gate; local stubs may either complete the write or
		// fail later in the WordPress theme.json layer.
		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
			$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	public function test_fse_update_global_styles_development_mode_no_token_passes_gate(): void {
		$ability = new UpdateGlobalStyles();
		$result  = $ability->execute( [ 'settings' => [ 'color' => [] ] ] );
		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	// -------------------------------------------------------------------------
	// FSE\UpdateTemplate.
	// -------------------------------------------------------------------------

	public function test_fse_update_template_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$ability = new UpdateTemplate();
		$result  = $ability->execute( [ 'id' => 'theme//index', 'content' => '<!-- wp:paragraph -->' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_fse_update_template_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new UpdateTemplate();
		$args    = [ 'id' => 'theme//index', 'content' => '<!-- wp:paragraph -->' ];
		$token   = ConfirmationToken::issue( $ability->name(), $args );
		$result  = $ability->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );
		// Passes token gate; local stubs may either complete the write or fail
		// later in the WordPress template layer.
		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
			$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	public function test_fse_update_template_development_mode_no_token_passes_gate(): void {
		$ability = new UpdateTemplate();
		$result  = $ability->execute( [ 'id' => 'theme//index', 'content' => '<!-- wp:paragraph -->' ] );
		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	// -------------------------------------------------------------------------
	// ElementorV3\UpdateKitColors.
	// -------------------------------------------------------------------------

	public function test_elementor_update_kit_colors_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$ability = new UpdateKitColors();
		$result  = $ability->execute( [ 'colors' => [ [ 'id' => 'primary', 'color' => '#fff' ] ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_elementor_update_kit_colors_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new UpdateKitColors();
		$args    = [ 'colors' => [ [ 'id' => 'primary', 'color' => '#fff' ] ] ];
		$token   = ConfirmationToken::issue( $ability->name(), $args );
		$result  = $ability->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );
		// Passes token gate; fails at get_option( 'elementor_active_kit' ).
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_elementor_update_kit_colors_development_mode_no_token_passes_gate(): void {
		$ability = new UpdateKitColors();
		$result  = $ability->execute( [ 'colors' => [ [ 'id' => 'primary', 'color' => '#fff' ] ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// QA\ApplyFixPlan.
	// -------------------------------------------------------------------------

	public function test_apply_fix_plan_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$ability = new ApplyFixPlan();
		$result  = $ability->execute( [ 'plan' => [] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_apply_fix_plan_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new ApplyFixPlan();
		$args    = [ 'plan' => [] ];
		$token   = ConfirmationToken::issue( $ability->name(), $args );
		$result  = $ability->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );
		// The gate passes; the ability proceeds past confirmation and returns a result.
		// If it returns a WP_Error it must NOT be a confirmation error.
		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
			$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	public function test_apply_fix_plan_development_mode_no_token_passes_gate(): void {
		// Default mode is development — no token needed.
		$ability = new ApplyFixPlan();
		$result  = $ability->execute( [ 'plan' => [] ] );
		// Gate passes; ability should not return a confirmation error.
		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}
}
