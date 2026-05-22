<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\QA\ScreenshotPage;
use Stonewright\WpMcp\Abilities\QA\DiffScreenshot;
use Stonewright\WpMcp\Abilities\QA\AccessibilityCheck;
use Stonewright\WpMcp\Abilities\QA\DiffLayout;
use Stonewright\WpMcp\Abilities\QA\Lighthouse;
use Stonewright\WpMcp\Abilities\QA\ResponsiveCheck;
use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Integration tests: QA abilities reject malformed companion responses.
 *
 * The companion is stubbed via wp_safe_remote_post so no real network calls happen.
 * Each test overrides $GLOBALS['stonewright_test_companion_response'] to inject
 * a bad response body and asserts WP_Error('stonewright_companion_contract_violation').
 *
 * @covers \Stonewright\WpMcp\Abilities\QA\ScreenshotPage
 * @covers \Stonewright\WpMcp\Abilities\QA\DiffScreenshot
 * @covers \Stonewright\WpMcp\Abilities\QA\AccessibilityCheck
 * @covers \Stonewright\WpMcp\Abilities\QA\DiffLayout
 * @covers \Stonewright\WpMcp\Abilities\QA\Lighthouse
 * @covers \Stonewright\WpMcp\Abilities\QA\ResponsiveCheck
 * @covers \Stonewright\WpMcp\Companion\CompanionContract
 */
final class QAContractTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = array_fill_keys(
			[ 'read', 'edit_posts', 'edit_pages', 'manage_options' ],
			true
		);
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_companion_url'   => 'http://127.0.0.1:8765',
			'stonewright_companion_token' => 'test-token',
		];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_posts']       = [
			1 => (object) [
				'ID'           => 1,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Test Page',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'test-page',
				'meta'         => [],
			],
		];
		// Default companion version check: respond with valid contract_version
		// so version gate passes. Individual tests override this per-endpoint.
		$GLOBALS['stonewright_test_companion_responses'] = [
			'/health'      => [ 'status' => 'ok', 'contract_version' => '1.0.0' ],
			'/screenshot'  => null, // override in test
			'/diff'        => null,
			'/axe'         => null,
			'/layout'      => null,
			'/lighthouse'  => null,
		];
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Override the wp_safe_remote_post stub to return bad data for the given path.
	 *
	 * @param array<string, mixed> $bad_body
	 */
	private function inject_bad_response( string $path, array $bad_body ): void {
		$GLOBALS['stonewright_test_companion_responses'][ $path ] = $bad_body;
	}

	private function inject_good_response( string $path, array $good_body ): void {
		$GLOBALS['stonewright_test_companion_responses'][ $path ] = $good_body;
	}

	// ---------------------------------------------------------------------------
	// ScreenshotPage
	// ---------------------------------------------------------------------------

	public function test_screenshot_page_rejects_malformed_response(): void {
		// Missing all required fields in companion response
		$this->inject_bad_response( '/screenshot', [ 'ok' => true, 'some_field' => 'value' ] );

		$ability = new ScreenshotPage();
		$result  = $ability->execute( [ 'post_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_contract_violation', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'screenshot', $data['endpoint'] );
		$this->assertSame( 'response', $data['direction'] );
	}

	public function test_screenshot_page_accepts_valid_response(): void {
		$uuid = wp_generate_uuid4();
		$this->inject_good_response( '/screenshot', [
			'request_id'  => $uuid,
			'artifact_id' => '/tmp/screenshot.png',
			'path'        => '/tmp/screenshot.png',
			'url'         => 'https://example.com/wp-content/uploads/stonewright-qa/screenshot.png',
			'width'       => 1440,
			'height'      => 900,
			'viewport'    => [ 'width' => 1440, 'height' => 900 ],
			'created_at'  => '2026-05-22T00:00:00.000Z',
		] );

		$ability = new ScreenshotPage();
		$result  = $ability->execute( [ 'post_id' => 1 ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['ok'] ?? false );
	}

	// ---------------------------------------------------------------------------
	// DiffScreenshot
	// ---------------------------------------------------------------------------

	public function test_diff_screenshot_rejects_malformed_response(): void {
		$this->inject_bad_response( '/diff', [ 'ratio' => 0.5 ] ); // missing request_id + needs_reference

		$ability = new DiffScreenshot();
		$result  = $ability->execute( [
			'reference_artifact_id' => '/tmp/ref.png',
			'actual_artifact_id'    => '/tmp/actual.png',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_contract_violation', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 'diff', $data['endpoint'] );
	}

	public function test_diff_screenshot_needs_reference_is_honest(): void {
		$uuid = wp_generate_uuid4();
		$this->inject_good_response( '/diff', [
			'request_id'      => $uuid,
			'needs_reference' => true,
		] );

		$ability = new DiffScreenshot();
		$result  = $ability->execute( [
			'reference_artifact_id' => '/tmp/missing-ref.png',
			'actual_artifact_id'    => '/tmp/actual.png',
		] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['ok'] ?? false );
		$this->assertTrue( $result['needs_reference'] ?? false );
	}

	// ---------------------------------------------------------------------------
	// AccessibilityCheck
	// ---------------------------------------------------------------------------

	public function test_accessibility_check_rejects_malformed_response(): void {
		$this->inject_bad_response( '/axe', [ 'some' => 'data' ] ); // missing required fields

		$ability = new AccessibilityCheck();
		$result  = $ability->execute( [ 'post_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_contract_violation', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 'axe', $data['endpoint'] );
	}

	public function test_accessibility_check_accepts_valid_response(): void {
		$uuid = wp_generate_uuid4();
		$this->inject_good_response( '/axe', [
			'request_id'   => $uuid,
			'violations'   => [],
			'passes_count' => 5,
		] );

		$ability = new AccessibilityCheck();
		$result  = $ability->execute( [ 'post_id' => 1 ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['ok'] ?? false );
	}

	// ---------------------------------------------------------------------------
	// DiffLayout
	// ---------------------------------------------------------------------------

	public function test_diff_layout_rejects_malformed_response(): void {
		$this->inject_bad_response( '/layout', [ 'sections' => [] ] ); // missing 4 required fields

		$ability = new DiffLayout();
		$result  = $ability->execute( [ 'spec' => [ 'sections' => [] ], 'post_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_contract_violation', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 'layout', $data['endpoint'] );
	}

	public function test_diff_layout_accepts_valid_response(): void {
		$uuid = wp_generate_uuid4();
		$this->inject_good_response( '/layout', [
			'request_id'              => $uuid,
			'sections'               => [ [ 'name' => 'hero', 'tag' => 'section', 'rect' => [ 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 600 ] ] ],
			'alignment_diffs'        => [],
			'has_horizontal_overflow' => false,
			'has_element_overlap'    => false,
		] );

		$ability = new DiffLayout();
		$result  = $ability->execute( [ 'spec' => [ 'sections' => [ [ 'name' => 'hero' ] ] ], 'post_id' => 1 ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['ok'] ?? false );
	}

	// ---------------------------------------------------------------------------
	// Lighthouse
	// ---------------------------------------------------------------------------

	public function test_lighthouse_rejects_malformed_response(): void {
		$this->inject_bad_response( '/lighthouse', [ 'scores' => [] ] ); // missing request_id + available

		$ability = new Lighthouse();
		$result  = $ability->execute( [ 'url' => 'https://example.com' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_contract_violation', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 'lighthouse', $data['endpoint'] );
	}

	public function test_lighthouse_unavailable_returns_wp_error_unavailable(): void {
		$uuid = wp_generate_uuid4();
		$this->inject_good_response( '/lighthouse', [
			'request_id' => $uuid,
			'available'  => false,
		] );

		$ability = new Lighthouse();
		$result  = $ability->execute( [ 'url' => 'https://example.com' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_unavailable', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 503, $data['status'] );
		$this->assertSame( 'lighthouse', $data['endpoint'] );
	}

	public function test_lighthouse_accepts_valid_response(): void {
		$uuid = wp_generate_uuid4();
		$this->inject_good_response( '/lighthouse', [
			'request_id'    => $uuid,
			'available'     => true,
			'scores'        => [ 'performance' => 0.98, 'accessibility' => 1.0, 'best-practices' => 0.95, 'seo' => 1.0 ],
			'report_url'    => '/tmp/report.html',
			'audits_failed' => [],
		] );

		$ability = new Lighthouse();
		$result  = $ability->execute( [ 'url' => 'https://example.com' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['ok'] ?? false );
	}

	// ---------------------------------------------------------------------------
	// ResponsiveCheck
	// ---------------------------------------------------------------------------

	/**
	 * Helper: returns a valid screenshot companion response.
	 *
	 * @param string $request_id
	 */
	private function valid_screenshot_response( string $request_id ): array {
		return [
			'request_id'  => $request_id,
			'artifact_id' => '/tmp/stonewright-qa/' . $request_id . '/screenshot.png',
			'path'        => '/tmp/stonewright-qa/' . $request_id . '/screenshot.png',
			'url'         => 'https://example.com/wp-content/uploads/stonewright-qa/' . $request_id . '/screenshot.png',
			'width'       => 375,
			'height'      => 812,
			'viewport'    => [ 'width' => 375, 'height' => 812 ],
			'created_at'  => '2026-05-22T00:00:00.000Z',
		];
	}

	public function test_responsive_check_rejects_malformed_response(): void {
		// Missing all required screenshot response fields.
		$this->inject_bad_response( '/screenshot', [ 'ok' => true, 'some_field' => 'value' ] );

		$ability = new ResponsiveCheck();
		$result  = $ability->execute( [
			'post_id'   => 1,
			'viewports' => [ [ 'w' => 375, 'h' => 812, 'label' => 'mobile' ] ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_contract_violation', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'screenshot', $data['endpoint'] );
		$this->assertSame( 'response', $data['direction'] );
	}

	public function test_responsive_check_reads_viewports_from_design_spec(): void {
		// The companion stub always returns a valid screenshot response, so we verify
		// viewport precedence by checking the structure of the result array:
		// design_spec has 3 breakpoints → 3 entries; caller-supplied viewports has 1 entry.
		// If design_spec takes priority we get 3, not 1.
		$design_spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Test' ],
			'sections' => [ [ 'id' => 's1', 'blocks' => [ [ 'type' => 'text', 'content' => 'Hi' ] ] ] ],
			'responsive' => [
				'breakpoints' => [
					'mobile'  => 390,
					'tablet'  => 810,
					'desktop' => 1280,
				],
			],
		];

		$ability = new ResponsiveCheck();
		$result  = $ability->execute( [
			'post_id'     => 1,
			'design_spec' => $design_spec,
			// Caller supplies only 1 viewport — if spec wins we'll see 3 results.
			'viewports'   => [ [ 'w' => 999, 'h' => 999, 'label' => 'should-be-ignored' ] ],
		] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertIsArray( $result );

		// design_spec.responsive.breakpoints has 3 entries → 3 screenshot calls.
		$this->assertCount( 3, $result, 'design_spec breakpoints should override caller-supplied viewports' );

		// Viewport labels reported in result must come from design_spec breakpoints.
		$result_labels = array_column( array_column( $result, 'viewport' ), 'label' );
		$this->assertContains( 'mobile', $result_labels );
		$this->assertContains( 'tablet', $result_labels );
		$this->assertContains( 'desktop', $result_labels );

		// Widths from spec (not caller's 999).
		$result_widths = array_column( array_column( $result, 'viewport' ), 'w' );
		$this->assertContains( 390, $result_widths );
		$this->assertContains( 810, $result_widths );
		$this->assertContains( 1280, $result_widths );
		$this->assertNotContains( 999, $result_widths );
	}

	public function test_responsive_check_falls_back_to_input_viewports_when_design_spec_absent(): void {
		$uuid = wp_generate_uuid4();
		// Single viewport from caller input.
		$this->inject_good_response( '/screenshot', $this->valid_screenshot_response( $uuid ) );

		$ability = new ResponsiveCheck();
		$result  = $ability->execute( [
			'post_id'   => 1,
			'viewports' => [ [ 'w' => 1024, 'h' => 768, 'label' => 'custom' ] ],
		] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 1024, $result[0]['viewport']['w'] );
	}

	public function test_responsive_check_falls_back_to_defaults_when_neither_present(): void {
		$uuid = wp_generate_uuid4();
		$this->inject_good_response( '/screenshot', $this->valid_screenshot_response( $uuid ) );

		$ability = new ResponsiveCheck();
		$result  = $ability->execute( [ 'post_id' => 1 ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertIsArray( $result );
		// Default has 3 viewports: mobile/tablet/desktop.
		$this->assertCount( 3, $result );
		$labels = array_column( $result, 'viewport' );
		$ws     = array_column( $labels, 'w' );
		$this->assertContains( 375, $ws );
		$this->assertContains( 768, $ws );
		$this->assertContains( 1440, $ws );
	}

	// ---------------------------------------------------------------------------
	// Version mismatch gate
	// ---------------------------------------------------------------------------

	public function test_version_mismatch_blocks_qa_call(): void {
		// Companion returns a future major version
		$GLOBALS['stonewright_test_companion_responses']['/health'] = [
			'status'           => 'ok',
			'contract_version' => '9.0.0',
		];

		$ability = new ScreenshotPage();
		$result  = $ability->execute( [ 'post_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_version_mismatch', $result->get_error_code() );
	}

	// ---------------------------------------------------------------------------
	// Stale-transient scenario (Item 7)
	// ---------------------------------------------------------------------------

	/**
	 * The companion version transient TTL must be bounded (≤ 5 minutes).
	 *
	 * CompanionClient::VERSION_TTL is 5 * MINUTE_IN_SECONDS (300 s).
	 * We verify it here by reading the class constant via reflection.
	 */
	public function test_version_transient_ttl_is_bounded_at_five_minutes(): void {
		$reflection = new \ReflectionClass( CompanionClient::class );
		$constant   = $reflection->getConstant( 'VERSION_TTL' );

		$this->assertIsInt( $constant, 'VERSION_TTL must be an integer' );
		$this->assertLessThanOrEqual(
			5 * MINUTE_IN_SECONDS,
			$constant,
			'VERSION_TTL must be ≤ 5 minutes (300 s) to bound stale-version risk'
		);
		$this->assertGreaterThan( 0, $constant, 'VERSION_TTL must be positive' );
	}

	/**
	 * Stale-transient scenario:
	 *
	 * 1. Companion advertises v1.0.0 — call succeeds, version is cached.
	 * 2. Companion restarts with v2.0.0 (major bump — breaking change).
	 * 3. Plugin reads from cache → still validates "1.0.0" which is compatible.
	 *    This is the known stale-window; it lasts at most VERSION_TTL seconds.
	 * 4. After cache is manually expired (simulating TTL expiry), next
	 *    check_version() fetches from companion and catches the mismatch.
	 */
	public function test_stale_transient_resolved_after_expiry(): void {
		// Step 1: Companion at v1.0.0 — populate the transient.
		$GLOBALS['stonewright_test_companion_responses']['/health'] = [
			'status'           => 'ok',
			'contract_version' => '1.0.0',
		];
		$version_check = CompanionClient::check_version();
		$this->assertTrue( $version_check, 'Initial check must pass for compatible version' );

		// Confirm transient was written.
		$cached = get_transient( 'stonewright_companion_contract_version' );
		$this->assertSame( '1.0.0', $cached, 'Version must be cached after first check' );

		// Step 2: Companion restarts with v2.0.0 (breaking major bump).
		$GLOBALS['stonewright_test_companion_responses']['/health'] = [
			'status'           => 'ok',
			'contract_version' => '2.0.0',
		];

		// Step 3: While cache is valid, check_version re-uses cached value.
		// The cached "1.0.0" is still compatible (major == 1 == EXPECTED_CONTRACT_MAJOR).
		$still_cached = CompanionClient::check_version();
		$this->assertTrue( $still_cached, 'Stale cached version is still valid in cache window' );

		// Confirm cache was not overwritten.
		$this->assertSame(
			'1.0.0',
			get_transient( 'stonewright_companion_contract_version' ),
			'Transient must not be overwritten while valid'
		);

		// Step 4: Simulate transient expiry — delete manually (as WP would after TTL).
		delete_transient( 'stonewright_companion_contract_version' );

		// Now check_version must hit /health again and detect the mismatch.
		$result = CompanionClient::check_version();

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'After transient expires, major version mismatch must produce WP_Error'
		);
		$this->assertSame(
			'stonewright_companion_version_mismatch',
			$result->get_error_code(),
			'Error code must be stonewright_companion_version_mismatch'
		);

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( '2.0.0', $data['received'], 'Error data must include the received version' );
	}

	/**
	 * Verify validate_version returns WP_Error with expected error code
	 * when companion reports an incompatible major version directly.
	 */
	public function test_validate_version_rejects_incompatible_major(): void {
		$result = CompanionContract::validate_version( '99.0.0' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_version_mismatch', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( '99.0.0', $data['received'] );
		$this->assertSame( '1.0.0', $data['expected'] );
	}
}
