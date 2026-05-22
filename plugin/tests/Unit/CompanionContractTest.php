<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Companion\CompanionContract;

/**
 * @covers \Stonewright\WpMcp\Companion\CompanionContract
 * @covers \Stonewright\WpMcp\Companion\Contracts\Screenshot
 * @covers \Stonewright\WpMcp\Companion\Contracts\Diff
 * @covers \Stonewright\WpMcp\Companion\Contracts\Axe
 * @covers \Stonewright\WpMcp\Companion\Contracts\Layout
 * @covers \Stonewright\WpMcp\Companion\Contracts\Lighthouse
 * @covers \Stonewright\WpMcp\Companion\Contracts\Health
 */
final class CompanionContractTest extends TestCase {

	private const UUID = '550e8400-e29b-41d4-a716-446655440000';
	private const ARTIFACT_PATH = '/tmp/wp-content/uploads/stonewright-qa/550e8400-e29b-41d4-a716-446655440000';

	// ---------------------------------------------------------------------------
	// screenshot
	// ---------------------------------------------------------------------------

	public function test_screenshot_request_valid(): void {
		$result = CompanionContract::validate( 'screenshot', 'request', [
			'request_id'    => self::UUID,
			'url'           => 'https://example.com/page',
			'artifact_path' => self::ARTIFACT_PATH,
		] );
		$this->assertTrue( $result );
	}

	public function test_screenshot_request_missing_required_fields(): void {
		$result = CompanionContract::validate( 'screenshot', 'request', [
			'url' => 'https://example.com',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_contract_violation', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'screenshot', $data['endpoint'] );
		$this->assertSame( 'request', $data['direction'] );
		$this->assertSame( 502, $data['status'] );
		$this->assertNotEmpty( $data['errors'] );
	}

	public function test_screenshot_response_valid(): void {
		$result = CompanionContract::validate( 'screenshot', 'response', [
			'request_id'  => self::UUID,
			'artifact_id' => self::ARTIFACT_PATH . '/screenshot.png',
			'path'        => self::ARTIFACT_PATH . '/screenshot.png',
			'url'         => 'https://example.com/wp-content/uploads/stonewright-qa/screenshot.png',
			'width'       => 1440,
			'height'      => 900,
			'viewport'    => [ 'width' => 1440, 'height' => 900 ],
			'created_at'  => '2026-05-22T00:00:00.000Z',
		] );
		$this->assertTrue( $result );
	}

	public function test_screenshot_response_missing_fields(): void {
		$result = CompanionContract::validate( 'screenshot', 'response', [
			'request_id' => self::UUID,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'response', $data['direction'] );
	}

	// ---------------------------------------------------------------------------
	// diff
	// ---------------------------------------------------------------------------

	public function test_diff_request_valid(): void {
		$result = CompanionContract::validate( 'diff', 'request', [
			'request_id'            => self::UUID,
			'reference_artifact_id' => self::ARTIFACT_PATH . '/ref.png',
			'actual_artifact_id'    => self::ARTIFACT_PATH . '/actual.png',
			'artifact_path'         => self::ARTIFACT_PATH,
		] );
		$this->assertTrue( $result );
	}

	public function test_diff_request_missing_artifact_ids(): void {
		$result = CompanionContract::validate( 'diff', 'request', [
			'request_id' => self::UUID,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_diff_response_needs_reference(): void {
		$result = CompanionContract::validate( 'diff', 'response', [
			'request_id'      => self::UUID,
			'needs_reference' => true,
		] );
		$this->assertTrue( $result );
	}

	public function test_diff_response_with_diff_data(): void {
		$result = CompanionContract::validate( 'diff', 'response', [
			'request_id'      => self::UUID,
			'needs_reference' => false,
			'diff_ratio'      => 0.005,
			'passed'          => true,
			'threshold'       => 0.1,
			'diff_url'        => self::ARTIFACT_PATH . '/diff.png',
			'mismatch_regions' => [],
		] );
		$this->assertTrue( $result );
	}

	// ---------------------------------------------------------------------------
	// axe
	// ---------------------------------------------------------------------------

	public function test_axe_request_valid(): void {
		$result = CompanionContract::validate( 'axe', 'request', [
			'request_id' => self::UUID,
			'url'        => 'https://example.com',
			'ruleset'    => 'wcag2aa',
		] );
		$this->assertTrue( $result );
	}

	public function test_axe_request_missing_url(): void {
		$result = CompanionContract::validate( 'axe', 'request', [
			'request_id' => self::UUID,
		] );
		// url is required
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_axe_response_valid(): void {
		$result = CompanionContract::validate( 'axe', 'response', [
			'request_id'   => self::UUID,
			'violations'   => [],
			'passes_count' => 10,
		] );
		$this->assertTrue( $result );
	}

	// ---------------------------------------------------------------------------
	// layout
	// ---------------------------------------------------------------------------

	public function test_layout_request_valid(): void {
		$result = CompanionContract::validate( 'layout', 'request', [
			'request_id' => self::UUID,
			'url'        => 'https://example.com',
		] );
		$this->assertTrue( $result );
	}

	public function test_layout_response_valid(): void {
		$result = CompanionContract::validate( 'layout', 'response', [
			'request_id'              => self::UUID,
			'sections'               => [],
			'alignment_diffs'        => [],
			'has_horizontal_overflow' => false,
			'has_element_overlap'    => false,
		] );
		$this->assertTrue( $result );
	}

	public function test_layout_response_missing_fields(): void {
		$result = CompanionContract::validate( 'layout', 'response', [
			'request_id' => self::UUID,
			'sections'   => [],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ---------------------------------------------------------------------------
	// lighthouse
	// ---------------------------------------------------------------------------

	public function test_lighthouse_request_valid(): void {
		$result = CompanionContract::validate( 'lighthouse', 'request', [
			'request_id' => self::UUID,
			'url'        => 'https://example.com',
			'categories' => [ 'performance', 'accessibility' ],
		] );
		$this->assertTrue( $result );
	}

	public function test_lighthouse_response_available_false(): void {
		$result = CompanionContract::validate( 'lighthouse', 'response', [
			'request_id' => self::UUID,
			'available'  => false,
		] );
		$this->assertTrue( $result );
	}

	public function test_lighthouse_response_available_true(): void {
		$result = CompanionContract::validate( 'lighthouse', 'response', [
			'request_id'    => self::UUID,
			'available'     => true,
			'scores'        => [ 'performance' => 0.98, 'accessibility' => 1.0 ],
			'report_url'    => self::ARTIFACT_PATH . '/report.html',
			'audits_failed' => [],
		] );
		$this->assertTrue( $result );
	}

	// ---------------------------------------------------------------------------
	// health
	// ---------------------------------------------------------------------------

	public function test_health_response_valid(): void {
		$result = CompanionContract::validate( 'health', 'response', [
			'status'           => 'ok',
			'contract_version' => '1.0.0',
		] );
		$this->assertTrue( $result );
	}

	public function test_health_response_missing_contract_version(): void {
		$result = CompanionContract::validate( 'health', 'response', [
			'status' => 'ok',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ---------------------------------------------------------------------------
	// version mismatch
	// ---------------------------------------------------------------------------

	public function test_validate_version_same_major_passes(): void {
		$result = CompanionContract::validate_version( '1.0.0' );
		$this->assertTrue( $result );
	}

	public function test_validate_version_same_major_minor_bump_passes(): void {
		$result = CompanionContract::validate_version( '1.99.0' );
		$this->assertTrue( $result );
	}

	public function test_validate_version_major_mismatch_fails(): void {
		$result = CompanionContract::validate_version( '2.0.0' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_version_mismatch', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 502, $data['status'] );
		$this->assertSame( '2.0.0', $data['received'] );
	}

	public function test_validate_version_old_major_fails(): void {
		$result = CompanionContract::validate_version( '0.9.0' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_version_mismatch', $result->get_error_code() );
	}

	// ---------------------------------------------------------------------------
	// unknown endpoint / direction
	// ---------------------------------------------------------------------------

	public function test_unknown_endpoint_returns_error(): void {
		$result = CompanionContract::validate( 'nonexistent', 'request', [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_contract_violation', $result->get_error_code() );
	}

	public function test_unknown_direction_returns_error(): void {
		$result = CompanionContract::validate( 'screenshot', 'wrong', [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
