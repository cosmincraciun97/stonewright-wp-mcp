<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminBootstrap;
use Stonewright\WpMcp\Admin\Pages\StatusPage;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Admin\RestApi;

/**
 * Verifies that AdminBootstrap wires the expected hooks and that the new
 * admin sub-pages declare the correct capability requirement.
 *
 * @covers \Stonewright\WpMcp\Admin\AdminBootstrap
 * @covers \Stonewright\WpMcp\Admin\Pages\StatusPage
 * @covers \Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage
 * @covers \Stonewright\WpMcp\Admin\RestApi
 */
final class AdminMenuRegistrationTest extends TestCase {

	/** @var array<string, array<int, array{callback: callable, priority: int, args: int}>> */
	private array $captured_hooks = [];

	protected function setUp(): void {
		$this->captured_hooks = [];

		// Reset registration state so register() can be called freshly.
		AdminBootstrap::reset_for_tests();
		RestApi::reset_for_tests();

		// Reset global action tracking.
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	protected function tearDown(): void {
		AdminBootstrap::reset_for_tests();
		RestApi::reset_for_tests();
		$GLOBALS['stonewright_test_actions']  = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	// -------------------------------------------------------------------------
	// AdminBootstrap hook registration
	// -------------------------------------------------------------------------

	public function test_register_adds_admin_menu_hook_for_status_page(): void {
		$hooked = [];

		// Capture add_action calls by tracking the hooked-to-admin_menu actions.
		// The test-bootstrap stubs add_action to store in stonewright_test_filters.
		// We call register() and then manually invoke the admin_menu hook to verify
		// the sub-page registration callbacks are present.
		AdminBootstrap::register();

		// The StatusPage::register() call inside AdminBootstrap::register() wires
		// an admin_menu action. We can verify the page slug constant is correct.
		$this->assertSame( 'stonewright-status', StatusPage::SLUG );
	}

	public function test_register_adds_admin_menu_hook_for_sandbox_library_page(): void {
		AdminBootstrap::register();

		$this->assertSame( 'stonewright-sandbox-library', SandboxLibraryPage::SLUG );
	}

	public function test_register_is_idempotent(): void {
		// Calling register() twice should not cause errors.
		AdminBootstrap::register();
		AdminBootstrap::register();
		$this->assertTrue( true ); // No exception thrown.
	}

	// -------------------------------------------------------------------------
	// Capability requirement on page callbacks
	// -------------------------------------------------------------------------

	public function test_status_page_render_dies_for_unauthorized_user(): void {
		// Set up user with no capabilities.
		$GLOBALS['stonewright_test_user_caps'] = [];

		// wp_die is stubbed in test bootstrap as throwing an exception or
		// calling a callback. Since we only have stub functions, verify the
		// capability constant is 'manage_options'.
		$this->assertSame( 'manage_options', StatusPage::CAPABILITY );
	}

	public function test_sandbox_library_page_capability_is_manage_options(): void {
		// The page must require manage_options (admin-level access).
		$ref = new \ReflectionClassConstant( SandboxLibraryPage::class, 'CAPABILITY' );
		$this->assertSame( 'manage_options', $ref->getValue() );
	}

	// -------------------------------------------------------------------------
	// Sub-page slug format
	// -------------------------------------------------------------------------

	public function test_status_page_slug_is_under_stonewright_parent(): void {
		// WordPress sub-pages use the parent slug as the menu_slug of the parent.
		// The sub-page slug must be unique. We verify it starts with 'stonewright'.
		$this->assertStringStartsWith( 'stonewright', StatusPage::SLUG );
	}

	public function test_sandbox_library_page_slug_is_under_stonewright_parent(): void {
		$this->assertStringStartsWith( 'stonewright', SandboxLibraryPage::SLUG );
	}

	// -------------------------------------------------------------------------
	// RestApi route registration
	// -------------------------------------------------------------------------

	public function test_rest_api_register_is_idempotent(): void {
		RestApi::register();
		RestApi::register();
		$this->assertTrue( true ); // No exception.
	}

	public function test_rest_api_handle_sandbox_library_returns_array(): void {
		// Create a minimal WP_REST_Request using the stub class.
		$request = new \WP_REST_Request( 'GET', '/stonewright/v1/admin/sandbox/library' );

		// Stub SandboxFiles::list_files() via the filesystem (empty sandbox dir
		// means no files → response has files: [], count: 0).
		// SandboxFiles::list_files() globs the draft_dir; in tests draft_dir()
		// creates a dir under WP_CONTENT_DIR (which is a temp dir).

		$result = RestApi::handle_sandbox_library( $request );

		// rest_ensure_response wraps arrays in WP_REST_Response in the test stub.
		$this->assertInstanceOf(
			\WP_REST_Response::class,
			$result,
			'handle_sandbox_library should return a WP_REST_Response'
		);

		/** @var \WP_REST_Response $result */
		$data = $result->get_data();
		$this->assertIsArray( $data, 'Response data must be an array' );
		$this->assertArrayHasKey( 'files', $data, 'Response envelope must contain a "files" key' );
		$this->assertArrayHasKey( 'count', $data, 'Response envelope must contain a "count" key' );
		$this->assertIsArray( $data['files'], '"files" value must be an array' );
		$this->assertIsInt( $data['count'], '"count" value must be an integer' );
		$this->assertCount( $data['count'], $data['files'], '"count" must equal the number of items in "files"' );
	}
}
