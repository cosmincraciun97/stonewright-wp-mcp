<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider;
use Stonewright\WpMcp\CustomCode\WpCodeRuntimeValidator;
use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * @covers \Stonewright\WpMcp\CustomCode\WpCodeRuntimeValidator
 * @covers \Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider
 */
final class WpCodeRuntimeValidatorTest extends TestCase {

	/** @var list<string> */
	private array $calls = [];

	/** @var array<string, array{code:string,title:string,language:string,active:bool}> */
	private array $store = [];

	/** @var array<string, string> */
	private array $cache = [];

	private bool $native_missing = false;
	private bool $assemble_unavailable = false;
	private bool $fail_rebuild = false;
	private int $rebuilds = 0;
	private bool $fail_second_rebuild = false;
	private bool $verify_mismatch = false;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']         = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 9;
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_update_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$this->reset_backend_state();
		ProviderRegistry::set_for_tests(
			[
				'wpcode' => new WpCodeProvider( $this->backend() ),
			]
		);
	}

	protected function tearDown(): void {
		ProviderRegistry::reset_for_tests();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
	}

	public function test_active_save_preserves_object_operator_and_records_certified_order(): void {
		$candidate = "<?php\n\$service->run();\n";
		$applied   = $this->apply_granted( '12', $candidate );
		self::assertIsArray( $applied );
		self::assertTrue( $applied['effect_verified'] );
		self::assertSame( $candidate, $this->store['12']['code'] );
		self::assertStringContainsString( '->', $this->store['12']['code'] );
		self::assertArrayHasKey( '12', $this->cache );
		self::assertSame(
			[ 'read', 'list_active', 'assemble_runtime', 'lint_runtime', 'native_save', 'rebuild_cache', 'read', 'inspect_cache' ],
			$this->calls
		);
		self::assertArrayNotHasKey( 'code', $applied );
		foreach ( $applied as $value ) {
			if ( is_string( $value ) ) {
				self::assertStringNotContainsString( '<?php', $value );
			}
		}
	}

	public function test_invalid_candidate_syntax_never_saves(): void {
		$result = $this->apply_granted( '12', '<?php this is not valid php {{{' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_candidate_invalid', $result->get_error_code() );
		self::assertNotContains( 'native_save', $this->calls );
		self::assertSame( "<?php\necho 'before';\n", $this->store['12']['code'] );
	}

	public function test_unrelated_invalid_active_snippet_blocks_before_save(): void {
		$this->store['99'] = [
			'code'     => '<?php this other snippet is invalid {{{',
			'title'    => 'Broken neighbor',
			'language' => 'php',
			'active'   => true,
		];
		$candidate = "<?php\nreturn 1;\n";
		$result    = $this->apply_granted( '12', $candidate );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertContains( $result->get_error_code(), [ 'stonewright_php_candidate_invalid', 'stonewright_wpcode_runtime_invalid' ] );
		self::assertNotContains( 'native_save', $this->calls );
		self::assertSame( "<?php\necho 'before';\n", $this->store['12']['code'] );
	}

	public function test_native_api_missing_fails_closed_for_active_php(): void {
		$this->native_missing = true;
		$result               = $this->apply_granted( '12', "<?php\nreturn 2;\n" );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_wpcode_native_api_unavailable', $result->get_error_code() );
		self::assertSame( 503, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		self::assertSame( "<?php\necho 'before';\n", $this->store['12']['code'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_assemble_unavailable_fails_closed_for_active_php(): void {
		$this->assemble_unavailable = true;
		$result                     = $this->apply_granted( '12', "<?php\nreturn 3;\n" );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_wpcode_runtime_preflight_unavailable', $result->get_error_code() );
		self::assertNotContains( 'native_save', $this->calls );
	}

	public function test_cache_rebuild_failure_restores_snapshot(): void {
		$this->fail_rebuild = true;
		$before             = $this->store['12']['code'];
		$result             = $this->apply_granted( '12', "<?php\nreturn 4;\n" );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $before, $this->store['12']['code'] );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'rollback_status', $data );
		self::assertArrayNotHasKey( 'code', $data );
	}

	public function test_draft_save_uses_native_save_and_stays_out_of_cache(): void {
		$this->store['12']['active'] = false;
		$candidate                   = "<?php\nreturn 5;\n";
		$applied                     = $this->apply_granted( '12', $candidate );
		self::assertIsArray( $applied );
		self::assertTrue( $applied['effect_verified'] );
		self::assertSame( $candidate, $this->store['12']['code'] );
		self::assertArrayNotHasKey( '12', $this->cache );
		self::assertNotContains( 'list_active', $this->calls );
		self::assertNotContains( 'assemble_runtime', $this->calls );
		self::assertContains( 'native_save', $this->calls );
		self::assertContains( 'rebuild_cache', $this->calls );
		self::assertContains( 'inspect_cache', $this->calls );
	}

	public function test_active_save_includes_snippet_in_execution_cache(): void {
		$applied = $this->apply_granted( '12', "<?php\nreturn 6;\n" );
		self::assertIsArray( $applied );
		self::assertArrayHasKey( '12', $this->cache );
		self::assertSame( hash( 'sha256', "<?php\nreturn 6;\n" ), $this->cache['12'] );
	}

	public function test_verify_failure_restores_snapshot_rebuilds_and_rechecks(): void {
		$this->verify_mismatch = true;
		$before                = $this->store['12']['code'];
		$result                = $this->apply_granted( '12', "<?php\nreturn 7;\n" );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_wpcode_verify_failed_restored', $result->get_error_code() );
		self::assertSame( $before, $this->store['12']['code'] );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'failed', $data['verification_status'] );
		self::assertContains( $data['rollback_status'], [ 'restored', 'failed' ] );
		self::assertArrayNotHasKey( 'code', $data );
		self::assertGreaterThanOrEqual( 2, $this->rebuilds );
		$native_saves = array_values( array_filter( $this->calls, static fn( string $op ): bool => 'native_save' === $op ) );
		self::assertGreaterThanOrEqual( 2, count( $native_saves ) );
		self::assertSame( $before, $this->store['12']['code'] );
		self::assertArrayHasKey( '12', $this->cache );
	}

	public function test_rollback_rebuild_failure_is_reported_without_code_bodies(): void {
		$this->verify_mismatch     = true;
		$this->fail_second_rebuild = true;
		$before                    = $this->store['12']['code'];
		$result                    = $this->apply_granted( '12', "<?php\nreturn 8;\n" );
		self::assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'failed', $data['rollback_status'] );
		self::assertArrayNotHasKey( 'code', $data );
		self::assertSame( $before, $this->store['12']['code'] );
		$encoded = wp_json_encode( $data );
		self::assertIsString( $encoded );
		self::assertStringNotContainsString( '<?php', $encoded );
	}

	public function test_validator_returns_hashes_and_counts_only(): void {
		$preflight = WpCodeRuntimeValidator::preflight(
			$this->backend(),
			'12',
			"<?php\n\$service->run();\n"
		);
		self::assertIsArray( $preflight );
		self::assertArrayHasKey( 'runtime_sha256', $preflight );
		self::assertArrayHasKey( 'snippet_count', $preflight );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $preflight['runtime_sha256'] );
		self::assertArrayNotHasKey( 'payload', $preflight );
		self::assertArrayNotHasKey( 'code', $preflight );
	}

	private function reset_backend_state(): void {
		$this->calls                 = [];
		$this->cache                 = [];
		$this->native_missing        = false;
		$this->assemble_unavailable  = false;
		$this->fail_rebuild          = false;
		$this->rebuilds              = 0;
		$this->fail_second_rebuild   = false;
		$this->verify_mismatch       = false;
		$this->store                 = [
			'12' => [
				'code'     => "<?php\necho 'before';\n",
				'title'    => 'Demo snippet',
				'language' => 'php',
				'active'   => true,
			],
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply_granted( string $target_id, string $candidate ) {
		$provider = ProviderRegistry::get( 'wpcode' );
		self::assertNotNull( $provider );
		$dry = $provider->dry_run(
			[
				'target_id' => $target_id,
				'code'      => $candidate,
				'language'  => 'php',
			]
		);
		if ( $dry instanceof \WP_Error ) {
			return $dry;
		}
		self::assertIsArray( $dry );
		$grant = CustomCodeGrant::issue(
			[
				'path'         => 'wpcode/snippet/' . $target_id,
				'after_sha256' => $dry['after_sha256'],
				'language'     => 'php',
			]
		);
		self::assertIsArray( $grant );
		$this->calls = [];
		return $provider->apply(
			[
				'target_id'              => $target_id,
				'code'                   => $candidate,
				'language'               => 'php',
				'custom_code_grant'      => $grant['token'],
				'expected_before_sha256' => $dry['before_sha256'],
			]
		);
	}

	private function backend(): callable {
		return function ( string $op, array $args ) {
			$tracked = [ 'read', 'list_active', 'assemble_runtime', 'lint_runtime', 'native_save', 'rebuild_cache', 'inspect_cache' ];
			if ( in_array( $op, $tracked, true ) ) {
				$this->calls[] = $op;
			}

			if ( 'discover' === $op ) {
				return [ 'active' => true, 'version' => '2.2.0' ];
			}
			if ( 'list' === $op || 'list_active' === $op ) {
				$items = [];
				foreach ( $this->store as $id => $row ) {
					if ( 'list_active' === $op && ( empty( $row['active'] ) || 'php' !== $row['language'] ) ) {
						continue;
					}
					$items[] = [
						'id'       => (string) $id,
						'title'    => $row['title'],
						'language' => $row['language'],
						'active'   => $row['active'],
						'code'     => $row['code'],
						'path'     => 'wpcode/snippet/' . $id,
					];
				}
				return [ 'ok' => true, 'provider' => 'wpcode', 'count' => count( $items ), 'items' => $items ];
			}
			if ( 'read' === $op ) {
				$id = (string) ( $args['id'] ?? '' );
				if ( ! isset( $this->store[ $id ] ) ) {
					return new \WP_Error( 'stonewright_wpcode_not_found', 'missing', [ 'status' => 404 ] );
				}
				$row = $this->store[ $id ];
				$code = $row['code'];
				if ( $this->verify_mismatch && in_array( 'native_save', $this->calls, true ) && 1 === substr_count( implode( ',', $this->calls ), 'native_save' ) ) {
					$code = "<?php\necho 'mismatch';\n";
				}
				return [
					'id'       => $id,
					'title'    => $row['title'],
					'code'     => $code,
					'language' => $row['language'],
					'active'   => $row['active'],
				];
			}
			if ( 'assemble_runtime' === $op ) {
				if ( $this->assemble_unavailable ) {
					return new \WP_Error(
						'stonewright_wpcode_runtime_preflight_unavailable',
						'assembly unavailable',
						[ 'status' => 503, 'retryable' => false ]
					);
				}
				$snippets = is_array( $args['snippets'] ?? null ) ? $args['snippets'] : [];
				$parts    = [];
				foreach ( $snippets as $snippet ) {
					$parts[] = (string) ( $snippet['code'] ?? '' );
				}
				$payload = implode( "\n", $parts );
				return [
					'ok'      => true,
					'count'   => count( $snippets ),
					'sha256'  => hash( 'sha256', $payload ),
					'payload' => $payload,
				];
			}
			if ( 'lint_runtime' === $op ) {
				$payload = (string) ( $args['payload'] ?? '' );
				$lint    = \Stonewright\WpMcp\CustomCode\ProviderSupport::validate_code( $payload, 'php' );
				if ( $lint instanceof \WP_Error ) {
					return $lint;
				}
				return [ 'ok' => true, 'sha256' => hash( 'sha256', $payload ) ];
			}
			if ( 'native_save' === $op || 'save' === $op ) {
				if ( $this->native_missing ) {
					return new \WP_Error(
						'stonewright_wpcode_native_api_unavailable',
						'native save missing',
						[ 'status' => 503, 'retryable' => false ]
					);
				}
				$id = (string) ( $args['id'] ?? '' );
				$this->store[ $id ]['code'] = (string) ( $args['code'] ?? '' );
				if ( array_key_exists( 'active', $args ) ) {
					$this->store[ $id ]['active'] = (bool) $args['active'];
				}
				return true;
			}
			if ( 'rebuild_cache' === $op ) {
				++$this->rebuilds;
				if ( $this->fail_rebuild || ( $this->fail_second_rebuild && $this->rebuilds >= 2 ) ) {
					return new \WP_Error(
						'stonewright_wpcode_cache_rebuild_failed',
						'rebuild failed',
						[ 'status' => 500, 'retryable' => false ]
					);
				}
				$this->cache = [];
				foreach ( $this->store as $id => $row ) {
					if ( ! empty( $row['active'] ) ) {
						$this->cache[ $id ] = hash( 'sha256', $row['code'] );
					}
				}
				return true;
			}
			if ( 'inspect_cache' === $op ) {
				$id = (string) ( $args['id'] ?? '' );
				return [
					'ok'          => true,
					'member'      => isset( $this->cache[ $id ] ),
					'member_ids'  => array_map( 'strval', array_keys( $this->cache ) ),
					'member_hash' => $this->cache[ $id ] ?? '',
					'count'       => count( $this->cache ),
				];
			}
			return null;
		};
	}
}
