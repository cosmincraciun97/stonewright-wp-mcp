<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\AtomicWidgetDefine;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * The atomic widget define ability follows the V4 feature flag and DISALLOW_FILE_MODS, and its text inputs are bounded.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\AtomicWidgetDefine
 */
final class AtomicWidgetDefineGuardsTest extends TestCase {

	protected function setUp(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_elementor_v4_atomic' => true ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_plugins' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$this->remove_staged_widgets();
	}

	protected function tearDown(): void {
		$this->remove_staged_widgets();
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	private function remove_staged_widgets(): void {
		foreach ( glob( SandboxFiles::draft_dir() . '/atomic-guards-*' ) ?: [] as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/** @return array<string, mixed> */
	private function args( string $slug ): array {
		return [
			'slug'     => $slug,
			'title'    => 'Guards card',
			'template' => '<div class="guards"><span data-prop="label"></span></div>',
			'props'    => [ [ 'name' => 'label', 'type' => 'string', 'default' => 'Hello' ] ],
		];
	}

	public function test_the_v4_feature_flag_gates_the_ability(): void {
		$ability = new AtomicWidgetDefine();
		self::assertTrue( $ability->permission_callback( $this->args( 'atomic-guards-a' ) ) );

		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;
		$refused = $ability->permission_callback( $this->args( 'atomic-guards-a' ) );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_feature_disabled', $refused->get_error_code() );
	}

	public function test_the_sandbox_permission_is_still_required(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_plugins' => true ];

		self::assertFalse( ( new AtomicWidgetDefine() )->permission_callback( $this->args( 'atomic-guards-b' ) ) );
	}

	public function test_slug_title_and_template_are_bounded(): void {
		$properties = ( new AtomicWidgetDefine() )->input_schema()['properties'];

		self::assertArrayHasKey( 'maxLength', $properties['slug'] );
		self::assertArrayHasKey( 'maxLength', $properties['title'] );
		self::assertArrayHasKey( 'maxLength', $properties['template'] );
		self::assertLessThanOrEqual( 40, $properties['slug']['maxLength'] );
		self::assertLessThanOrEqual( 65536, $properties['template']['maxLength'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nothing_is_written_when_file_modifications_are_disallowed(): void {
		define( 'DISALLOW_FILE_MODS', true );
		// The audit wrapper logs through error_log(); keep that out of the output of the isolated process.
		ini_set( 'error_log', sys_get_temp_dir() . '/stonewright-atomic-define-guards.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$result = ( new AtomicWidgetDefine() )->execute( $this->args( 'atomic-guards-c' ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_sandbox_file_mods_disabled', $result->get_error_code() );
		self::assertFileDoesNotExist( SandboxFiles::stored_path( 'atomic-atomic-guards-c.php' ) );
	}
}
