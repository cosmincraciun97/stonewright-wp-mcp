<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\IngestFigma;

/**
 * Integration tests for the stonewright/design.ingest_figma ability.
 *
 * Mocks CompanionClient via stonewright_test_companion_responses global.
 * Asserts:
 *   - Happy path: validated spec + stable spec_sha8 + asset_count.
 *   - Companion contract violation propagates WP_Error.
 *   - Spec validation failure propagates WP_Error.
 *   - Permission denial works correctly.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\IngestFigma
 * @covers \Stonewright\WpMcp\DesignSpec\Validator
 * @covers \Stonewright\WpMcp\Companion\CompanionContract
 */
final class DesignIngestionTest extends TestCase {

	private static IngestFigma $ability;

	/** @var array<string, mixed> */
	private static array $valid_spec = [
		'version'  => '1.0.0',
		'page'     => [ 'title' => 'Figma Hero' ],
		'sections' => [
			[
				'id'     => 'hero',
				'blocks' => [
					[ 'type' => 'heading', 'text' => 'Welcome', 'level' => 1 ],
				],
			],
		],
	];

	public static function setUpBeforeClass(): void {
		self::$ability = new IngestFigma();
	}

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			'edit_pages'     => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_companion_url'   => 'http://127.0.0.1:8765',
			'stonewright_companion_token' => 'test-token',
		];
		$GLOBALS['stonewright_test_transients']      = [
			// Seed contract version so CompanionClient skips health check.
			'stonewright_companion_contract_version' => '1.0.0',
		];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_companion_responses'] = [];
	}

	// ── Happy path ────────────────────────────────────────────────────────────

	public function test_happy_path_returns_spec_and_sha8(): void {
		$this->mock_companion( self::$valid_spec, [], 0 );

		$result = self::$ability->execute( [
			'file_key' => 'ABC123',
			'node_id'  => '1:2',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'spec', $result );
		$this->assertArrayHasKey( 'spec_sha8', $result );
		$this->assertArrayHasKey( 'asset_count', $result );
		$this->assertArrayHasKey( 'warnings', $result );

		$this->assertIsArray( $result['spec'] );
		$this->assertIsString( $result['spec_sha8'] );
		$this->assertSame( 8, strlen( $result['spec_sha8'] ) );
		$this->assertSame( 0, $result['asset_count'] );
	}

	public function test_spec_sha8_stable_across_two_calls(): void {
		$this->mock_companion( self::$valid_spec, [], 0 );
		$r1 = self::$ability->execute( [ 'file_key' => 'ABC123', 'node_id' => '1:2' ] );

		// Reset companion mock — must produce same spec.
		$this->mock_companion( self::$valid_spec, [], 0 );
		$r2 = self::$ability->execute( [ 'file_key' => 'ABC123', 'node_id' => '1:2' ] );

		$this->assertIsArray( $r1 );
		$this->assertIsArray( $r2 );
		$this->assertSame( $r1['spec_sha8'], $r2['spec_sha8'], 'spec_sha8 must be stable for identical specs' );
	}

	public function test_warnings_forwarded_from_companion(): void {
		$this->mock_companion( self::$valid_spec, [ 'Unsupported node type: POLYGON' ], 0 );

		$result = self::$ability->execute( [ 'file_key' => 'ABC123', 'node_id' => '1:2' ] );

		$this->assertIsArray( $result );
		$this->assertContains( 'Unsupported node type: POLYGON', $result['warnings'] );
	}

	public function test_asset_count_forwarded_from_companion(): void {
		$this->mock_companion( self::$valid_spec, [], 3 );

		$result = self::$ability->execute( [ 'file_key' => 'ABC123', 'node_id' => '1:2' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['asset_count'] );
	}

	// ── figma_url path ────────────────────────────────────────────────────────

	public function test_accepts_figma_url_field(): void {
		$this->mock_companion( self::$valid_spec, [], 0 );

		$result = self::$ability->execute( [
			'figma_url' => 'https://www.figma.com/file/ABC123/Title?node-id=1-2',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'spec', $result );
	}

	// ── Error paths ───────────────────────────────────────────────────────────

	public function test_missing_target_returns_wp_error(): void {
		$result = self::$ability->execute( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_missing_figma_target', $result->get_error_code() );
	}

	public function test_invalid_spec_from_companion_returns_wp_error(): void {
		// Companion returns a spec missing required 'page.title'.
		$bad_spec = [
			'version'  => '1.0.0',
			'page'     => [],  // Missing title.
			'sections' => [
				[ 'blocks' => [ [ 'type' => 'heading', 'text' => 'Hi' ] ] ],
			],
		];
		$this->mock_companion( $bad_spec, [], 0 );

		$result = self::$ability->execute( [ 'file_key' => 'ABC', 'node_id' => '1:2' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
	}

	public function test_companion_wp_error_propagates(): void {
		// Inject a WP_Error directly as the companion /figma-ingest response.
		// The bootstrap stub for wp_safe_remote_post passes WP_Error instances through
		// unmodified so CompanionClient::post() hits its is_wp_error($response) branch,
		// which returns the error upward. IngestFigma must propagate it without throwing.
		$GLOBALS['stonewright_test_companion_responses']['/figma-ingest'] = new \WP_Error(
			'stonewright_companion_error',
			'Connection refused'
		);

		$result = self::$ability->execute( [ 'file_key' => 'ABC', 'node_id' => '1:2' ] );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'A companion WP_Error must propagate upward through IngestFigma::execute()'
		);
		// The error code may be wrapped or forwarded — assert it is NOT a successful result.
		$this->assertNotEmpty( $result->get_error_code(), 'Propagated error must have a non-empty error code' );
	}

	// ── Permission check ─────────────────────────────────────────────────────

	public function test_permission_denied_without_caps(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$perm = self::$ability->permission_callback( [ 'file_key' => 'ABC', 'node_id' => '1:2' ] );

		$this->assertFalse( $perm );
	}

	public function test_permission_requires_manage_options_and_edit_pages(): void {
		// Only manage_options — missing edit_pages.
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$this->assertFalse( self::$ability->permission_callback( [] ) );

		// Only edit_pages — missing manage_options.
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_pages' => true ];
		$this->assertFalse( self::$ability->permission_callback( [] ) );

		// Both present.
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true, 'edit_pages' => true ];
		$this->assertTrue( self::$ability->permission_callback( [] ) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $spec
	 * @param array<int, string>   $warnings
	 */
	private function mock_companion( array $spec, array $warnings, int $asset_count ): void {
		$GLOBALS['stonewright_test_companion_responses']['/figma-ingest'] = [
			'spec'        => $spec,
			'warnings'    => $warnings,
			'asset_count' => $asset_count,
		];
	}
}
