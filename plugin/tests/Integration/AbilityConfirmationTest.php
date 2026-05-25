<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors;
use Stonewright\WpMcp\Abilities\FSE\UpdateGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\UpdateTemplate;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxActivate;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxDelete;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Security\ConfirmationToken
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxActivate
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxDelete
 * @covers \Stonewright\WpMcp\Abilities\FSE\UpdateGlobalStyles
 * @covers \Stonewright\WpMcp\Abilities\FSE\UpdateTemplate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors
 */
final class AbilityConfirmationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [
			'read'               => true,
			'edit_posts'         => true,
			'edit_pages'         => true,
			'manage_options'     => true,
			'edit_plugins'       => true,
			'edit_themes'        => true,
			'upload_files'       => true,
			'edit_theme_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	private function set_production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
	}

	public function test_sandbox_activate_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$result = ( new SandboxActivate() )->execute( [ 'name' => 'test.php' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_sandbox_activate_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new SandboxActivate();
		$token   = ConfirmationToken::issue( $ability->name(), [ 'name' => 'never.php' ] );
		$result  = $ability->execute(
			[
				'name'               => 'never.php',
				'confirmation_token' => $token,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_sandbox_activate_development_mode_no_token_accepted(): void {
		$result = ( new SandboxActivate() )->execute( [ 'name' => 'never.php' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_sandbox_delete_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$result = ( new SandboxDelete() )->execute( [ 'name' => 'test.php' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_sandbox_delete_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new SandboxDelete();
		$token   = ConfirmationToken::issue( $ability->name(), [ 'name' => 'never.php' ] );
		$result  = $ability->execute(
			[
				'name'               => 'never.php',
				'confirmation_token' => $token,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_sandbox_delete_production_safe_wrong_token_rejected(): void {
		$this->set_production_safe();
		$ability = new SandboxDelete();
		$token   = ConfirmationToken::issue( $ability->name(), [ 'name' => 'other.php' ] );
		$result  = $ability->execute(
			[
				'name'               => 'test.php',
				'confirmation_token' => $token,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			[ 'stonewright_confirmation_invalid', 'stonewright_confirmation_args_mismatch' ]
		);
	}

	public function test_sandbox_delete_development_mode_no_token_passes_gate(): void {
		$result = ( new SandboxDelete() )->execute( [ 'name' => 'never.php' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_fse_update_global_styles_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$result = ( new UpdateGlobalStyles() )->execute( [ 'settings' => [ 'color' => [] ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_fse_update_global_styles_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new UpdateGlobalStyles();
		$args    = [ 'settings' => [ 'color' => [] ] ];
		$token   = ConfirmationToken::issue( $ability->name(), $args );
		$result  = $ability->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );

		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
			$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	public function test_fse_update_global_styles_development_mode_no_token_passes_gate(): void {
		$result = ( new UpdateGlobalStyles() )->execute( [ 'settings' => [ 'color' => [] ] ] );

		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	public function test_fse_update_template_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$result = ( new UpdateTemplate() )->execute( [ 'id' => 'theme//index', 'content' => '<!-- wp:paragraph -->' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_fse_update_template_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new UpdateTemplate();
		$args    = [ 'id' => 'theme//index', 'content' => '<!-- wp:paragraph -->' ];
		$token   = ConfirmationToken::issue( $ability->name(), $args );
		$result  = $ability->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );

		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
			$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	public function test_fse_update_template_development_mode_no_token_passes_gate(): void {
		$result = ( new UpdateTemplate() )->execute( [ 'id' => 'theme//index', 'content' => '<!-- wp:paragraph -->' ] );

		if ( $result instanceof \WP_Error ) {
			$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	public function test_elementor_update_kit_colors_production_safe_no_token_rejected(): void {
		$this->set_production_safe();
		$result = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => 'primary', 'color' => '#fff' ] ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_elementor_update_kit_colors_production_safe_valid_token_passes_gate(): void {
		$this->set_production_safe();
		$ability = new UpdateKitColors();
		$args    = [ 'colors' => [ [ 'id' => 'primary', 'color' => '#fff' ] ] ];
		$token   = ConfirmationToken::issue( $ability->name(), $args );
		$result  = $ability->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_elementor_update_kit_colors_development_mode_no_token_passes_gate(): void {
		$result = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => 'primary', 'color' => '#fff' ] ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}
}
