<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\RestApi;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Verifies that the sandbox library REST API endpoint enforces the
 * can_view_sandbox() permission gate.
 *
 * @covers \Stonewright\WpMcp\Admin\RestApi
 * @covers \Stonewright\WpMcp\Security\Permissions::can_view_sandbox
 */
final class SandboxRestApiTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
		RestApi::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
		RestApi::reset_for_tests();
	}

	// -------------------------------------------------------------------------
	// Permission gate: can_view_sandbox() requires manage_options
	// -------------------------------------------------------------------------

	/** @dataProvider non_capable_roles_provider */
	public function test_permission_denied_for_non_capable_user( string $role, array $caps ): void {
		$GLOBALS['stonewright_test_user_logged_in'] = ! empty( $caps );
		$GLOBALS['stonewright_test_user_caps']      = array_fill_keys( $caps, true );

		$can_view = Permissions::can_view_sandbox();

		$this->assertFalse(
			$can_view,
			sprintf( 'Role %s should not have can_view_sandbox()', $role )
		);
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public function non_capable_roles_provider(): array {
		return [
			'anonymous'   => [ 'anonymous', [] ],
			'subscriber'  => [ 'subscriber', [ 'read' ] ],
			'contributor' => [ 'contributor', [ 'read', 'edit_posts' ] ],
			'author'      => [ 'author', [ 'read', 'edit_posts', 'publish_posts', 'upload_files' ] ],
			'editor'      => [
				'editor',
				[ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'manage_categories' ],
			],
		];
	}

	public function test_permission_granted_for_manage_options(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [
			'read'           => true,
			'manage_options' => true,
		];

		$this->assertTrue( Permissions::can_view_sandbox() );
	}

	// -------------------------------------------------------------------------
	// Endpoint returns correct JSON structure for capable user
	// -------------------------------------------------------------------------

	public function test_handle_sandbox_library_returns_expected_keys_for_capable_user(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [
			'read'           => true,
			'manage_options' => true,
		];

		// Use the WP_REST_Request stub.
		$request = new \WP_REST_Request( 'GET', '/stonewright/v1/admin/sandbox/library' );

		$result = RestApi::handle_sandbox_library( $request );

		// rest_ensure_response is stubbed to pass through in test bootstrap —
		// so $result is the response object (or the raw array if the stub just
		// returns the value). Either way, convert to array for inspection.
		if ( is_object( $result ) && method_exists( $result, 'get_data' ) ) {
			$data = $result->get_data();
		} elseif ( is_array( $result ) ) {
			$data = $result;
		} else {
			// rest_ensure_response may wrap in WP_REST_Response; check for it.
			$this->fail( 'Unexpected return type from handle_sandbox_library: ' . get_class( $result ) );
			return;
		}

		$this->assertIsArray( $data, 'Response data should be an array' );
		$this->assertArrayHasKey( 'files', $data, 'Response should contain "files" key' );
		$this->assertArrayHasKey( 'count', $data, 'Response should contain "count" key' );
		$this->assertArrayHasKey( 'widgets', $data, 'Response should contain "widgets" key' );
		$this->assertIsArray( $data['files'], '"files" should be an array' );
		$this->assertIsInt( $data['count'], '"count" should be an integer' );
	}

	public function test_handle_sandbox_library_file_entries_have_no_absolute_paths(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];

		// Plant a file in the sandbox dir so list_files() has something to return.
		$draft_dir = \Stonewright\WpMcp\Sandbox\SandboxFiles::draft_dir();
		$file_path = $draft_dir . '/rest-api-test.php';
		file_put_contents( $file_path, "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$request = new \WP_REST_Request( 'GET', '/stonewright/v1/admin/sandbox/library' );
		$result  = RestApi::handle_sandbox_library( $request );

		if ( is_object( $result ) && method_exists( $result, 'get_data' ) ) {
			$data = $result->get_data();
		} elseif ( is_array( $result ) ) {
			$data = $result;
		} else {
			@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->fail( 'Unexpected return type' );
			return;
		}

		foreach ( $data['files'] as $file ) {
			$this->assertArrayNotHasKey( 'path', $file, 'Enriched file entry must not expose absolute path' );
		}

		@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	// -------------------------------------------------------------------------
	// Idempotent registration
	// -------------------------------------------------------------------------

	public function test_register_does_not_throw_on_double_call(): void {
		RestApi::register();
		RestApi::register();
		$this->assertTrue( true );
	}
}
