<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\CssAssetTransaction;
use Stonewright\WpMcp\Elementor\CssTarget;

/** @covers \Stonewright\WpMcp\Elementor\CssAssetTransaction */
final class CssAssetTransactionTest extends TestCase {
	private string $css_dir;

	protected function setUp(): void {
		$uploads       = wp_upload_dir();
		$this->css_dir = rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css';
		wp_mkdir_p( $this->css_dir );
		$GLOBALS['stonewright_test_home_url']       = 'https://example.test/';
		$GLOBALS['stonewright_test_asset_responses'] = [];
		$GLOBALS['stonewright_test_options']        = [];
		$this->remove_test_assets();
	}

	protected function tearDown(): void {
		$this->remove_test_assets();
		unset(
			$GLOBALS['stonewright_test_home_url'],
			$GLOBALS['stonewright_test_asset_responses'],
			$GLOBALS['stonewright_test_upload_dir'],
			$GLOBALS['stonewright_test_before_option_update'],
			$GLOBALS['stonewright_test_option_cas_miss_remaining']
		);
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_allows_only_the_target_post_css_and_probes_protected_assets(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );
		$this->write( 'custom-frontend.min.css', 'frontend' );
		$this->write( 'custom-pro-widget-nav-menu.min.css', 'pro-nav' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true, 'method' => 'post_css_update' ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'new-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( 4, $result['css_evidence']['before_file_count'] );
		self::assertSame( 4, $result['css_evidence']['after_file_count'] );
		self::assertSame( 3, count( $result['css_evidence']['protected_probes_before'] ) );
		self::assertSame( 3, count( $result['css_evidence']['protected_probes_after'] ) );
	}

	public function test_restores_deleted_siblings_and_reports_collateral_change(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );
		$this->write( 'custom-frontend.min.css', 'frontend' );
		$this->write( 'custom-pro-widget-nav-menu.min.css', 'pro-nav' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				foreach ( [ 'post-701.css', 'post-999.css', 'custom-frontend.min.css', 'custom-pro-widget-nav-menu.min.css' ] as $name ) {
					unlink( $this->css_dir . '/' . $name );
				}
				$this->write( 'post-701.css', 'new-post' );
				$this->write( 'created-during-transaction.css', 'must-be-removed' );
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_collateral_change', $result->get_error_code() );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( 'frontend', $this->read( 'custom-frontend.min.css' ) );
		self::assertSame( 'pro-nav', $this->read( 'custom-pro-widget-nav-menu.min.css' ) );
		self::assertFalse( is_file( $this->css_dir . '/created-during-transaction.css' ) );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
	}

	public function test_rolls_back_elementor_css_metadata_with_exact_presence(): void {
		$GLOBALS['stonewright_test_posts'][701] = (object) [
			'ID'   => 701,
			'meta' => [ '_elementor_css' => [ 'revision' => 4 ] ],
		];
		$this->write( 'post-701.css', 'old-post' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				update_post_meta( 701, '_elementor_css', [ 'revision' => 5 ] );
				$this->write( 'post-701.css', 'partial-post' );
				return [ 'ok' => false, 'error_code' => 'synthetic_failure' ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [ 'revision' => 4 ], get_post_meta( 701, '_elementor_css', true ) );
		self::assertSame( 'succeeded', $result->get_error_data()['metadata_rollback_status'] ?? null );

		unset( $GLOBALS['stonewright_test_posts'][701]->meta['_elementor_css'] );
		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				update_post_meta( 701, '_elementor_css', [ 'revision' => 6 ] );
				return [ 'ok' => false, 'error_code' => 'synthetic_failure' ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertArrayNotHasKey( '_elementor_css', $GLOBALS['stonewright_test_posts'][701]->meta );
	}

	public function test_metadata_restore_succeeds_when_update_post_meta_reports_unchanged(): void {
		$GLOBALS['stonewright_test_posts'][701] = (object) [
			'ID'   => 701,
			'meta' => [ '_elementor_css' => [ 'revision' => 4 ] ],
		];
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'partial-post' );
				unlink( $this->css_dir . '/post-999.css' );
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( [ 'revision' => 4 ], get_post_meta( 701, '_elementor_css', true ) );
		self::assertSame( 'succeeded', $result->get_error_data()['metadata_rollback_status'] ?? null );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
	}

	public function test_blocks_wrong_origin_before_operation(): void {
		$this->write( 'custom-frontend.min.css', 'frontend' );
		$GLOBALS['stonewright_test_home_url'] = 'https://other.test/';
		$called = false;

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			static function () use ( &$called ): array {
				$called = true;
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_probe_unsafe_origin', $result->get_error_code() );
		self::assertFalse( $called );
	}

	public function test_rolls_back_when_a_protected_asset_returns_404_after_operation(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$calls = 0;
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function () use ( &$calls ): array {
			++$calls;
			if ( 1 === $calls ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'text/css' ],
					'body'     => '',
				];
			}
			return [ 'response' => [ 'code' => 404 ], 'headers' => [], 'body' => '' ];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_probe_failed', $result->get_error_code() );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
		self::assertSame( 'verified', $result->get_error_data()['generation_status'] ?? null );
		self::assertSame( 'failed', $result->get_error_data()['delivery_status'] ?? null );
		self::assertSame( 'delivery', $result->get_error_data()['failed_check'] ?? null );
		self::assertSame( 'stonewright_elementor_css_probe_failed', $result->get_error_data()['root_error_code'] ?? null );
	}

	public function test_requires_the_target_post_css_after_regeneration(): void {
		$result = CssAssetTransaction::run( $this->target( 701 ), static fn(): array => [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_collateral_change', $result->get_error_code() );
		self::assertFalse( is_file( $this->css_dir . '/post-701.css' ) );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
	}

	public function test_page_with_empty_css_regenerates_without_a_target_file(): void {
		$this->write( 'post-999.css', 'sibling' );
		$this->write( 'custom-frontend.min.css', 'frontend' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			static fn(): array => [ 'ok' => true, 'css_content' => 'empty' ]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFalse( is_file( $this->css_dir . '/post-701.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( 'not_produced', $result['css_evidence']['css_file_status'] ?? null );
		self::assertSame( 'empty_css', $result['css_evidence']['css_file_reason'] ?? null );
		self::assertSame( 'verified', $result['css_evidence']['generation_status'] ?? null );
		self::assertSame( 'not_applicable', $result['css_evidence']['delivery_status'] ?? null );
		self::assertSame( 'not_needed', $result['css_evidence']['rollback_status'] ?? null );
		$probed = array_column( $result['css_evidence']['protected_probes_after'], 'asset' );
		self::assertSame( [ 'custom-frontend.min.css' ], $probed );
	}

	public function test_empty_css_page_whose_old_target_file_is_removed_is_not_collateral(): void {
		$this->write( 'post-701.css', 'old-post' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				unlink( $this->css_dir . '/post-701.css' );
				return [ 'ok' => true, 'css_content' => 'empty' ];
			}
		);

		self::assertIsArray( $result );
		self::assertSame( 'not_produced', $result['css_evidence']['css_file_status'] ?? null );
	}

	public function test_empty_css_does_not_excuse_collateral_changes(): void {
		$this->write( 'post-999.css', 'sibling' );
		$this->write( 'custom-frontend.min.css', 'frontend' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				unlink( $this->css_dir . '/post-999.css' );
				$this->write( 'custom-frontend.min.css', 'changed' );
				return [ 'ok' => true, 'css_content' => 'empty' ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_collateral_change', $result->get_error_code() );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( 'frontend', $this->read( 'custom-frontend.min.css' ) );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
	}

	public function test_missing_target_without_empty_css_evidence_still_fails(): void {
		foreach ( [ [ 'ok' => true ], [ 'ok' => true, 'css_content' => 'present' ] ] as $operation_result ) {
			$result = CssAssetTransaction::run( $this->target( 701 ), static fn(): array => $operation_result );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_elementor_css_collateral_change', $result->get_error_code() );
			self::assertFalse( $result->get_error_data()['target_present'] ?? true );
		}
	}

	public function test_inline_print_method_without_a_file_is_reported_as_such(): void {
		$this->write( 'post-999.css', 'sibling' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			static fn(): array => [ 'ok' => true, 'css_content' => 'present', 'print_method' => 'internal' ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_inline_print_method', $result->get_error_code() );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( 'failed', $result->get_error_data()['generation_status'] ?? null );
		self::assertSame( 'generation', $result->get_error_data()['failed_check'] ?? null );
	}

	public function test_restores_the_target_when_the_operation_throws(): void {
		$this->write( 'post-701.css', 'old-post' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): never {
				$this->write( 'post-701.css', 'partial-post' );
				throw new \RuntimeException( 'private failure detail' );
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_operation_failed', $result->get_error_code() );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'operation_throwable', $result->get_error_data()['root_error_code'] ?? null );
		self::assertStringNotContainsString( 'private failure detail', $result->get_error_message() );
	}

	public function test_revalidates_css_location_immediately_before_the_operation(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url      = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$real_dir = $this->css_dir . '-real';
		$called   = false;
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = function () use ( $real_dir ) {
			if ( is_dir( $this->css_dir ) && ! is_link( $this->css_dir ) ) {
				rename( $this->css_dir, $real_dir );
				symlink( $real_dir, $this->css_dir );
			}
			return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '' ];
		};

		try {
			$result = CssAssetTransaction::run(
				$this->target( 701 ),
				function () use ( &$called ): array {
					$called = true;
					$this->write( 'post-701.css', 'new-post' );
					return [ 'ok' => true ];
				}
			);

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_elementor_css_path_unsafe', $result->get_error_code() );
			self::assertFalse( $called );
			self::assertSame( 'old-post', (string) file_get_contents( $real_dir . '/post-701.css' ) );
		} finally {
			if ( is_link( $this->css_dir ) ) {
				unlink( $this->css_dir );
			}
			if ( is_dir( $real_dir ) ) {
				rename( $real_dir, $this->css_dir );
			}
		}
	}

	/**
	 * The restore temp exists only between its write and its rename, so the test inspects it
	 * from inside that rename (Fixtures/RestoreRenameSpy.php). The spy is a function in the
	 * production namespace that a process cannot unload, hence the separate process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_atomic_restore_uses_same_directory_temps_with_ignored_prefix(): void {
		require_once __DIR__ . '/Fixtures/RestoreRenameSpy.php';

		$payload = str_repeat( "old-post-\n", 65536 );
		$this->write( 'post-701.css', $payload );
		$this->write( 'post-999.css', 'sibling' );
		$css_dir = wp_normalize_path( $this->css_dir );
		$renames = [];
		$GLOBALS['stonewright_test_rename_spy'] = static function ( string $from, string $to ) use ( $css_dir, &$renames ): void {
			if ( dirname( $from ) !== $css_dir ) {
				return;
			}
			$renames[] = [
				'from'    => basename( $from ),
				'to'      => $to,
				'bytes'   => (string) file_get_contents( $from ),
				'listing' => array_values( array_diff( (array) scandir( $css_dir ), [ '.', '..' ] ) ),
			];
		};

		try {
			$result = CssAssetTransaction::run(
				$this->target( 701 ),
				function () use ( $payload ): array {
					unlink( $this->css_dir . '/post-999.css' );
					$this->write( 'post-701.css', str_replace( 'old-post', 'new-post', $payload ) );
					return [ 'ok' => true ];
				}
			);
		} finally {
			unset( $GLOBALS['stonewright_test_rename_spy'] );
		}

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( $payload, $this->read( 'post-701.css' ) );

		$restored = [];
		foreach ( $renames as $rename ) {
			self::assertMatchesRegularExpression( '/\A\.stonewright-css-restore-[A-Za-z0-9]+\z/D', $rename['from'], 'the temp is created in the CSS directory under the ignored prefix' );
			$restored[ basename( $rename['to'] ) ] = $rename['bytes'];
			self::assertSame( $css_dir, dirname( $rename['to'] ), 'the temp is renamed within the same directory' );
			foreach ( $rename['listing'] as $name ) {
				if ( $name === $rename['from'] ) {
					continue;
				}
				self::assertMatchesRegularExpression( '/\.css$/i', $name, 'no file other than the temp and the CSS assets exists during the restore' );
			}
		}
		ksort( $restored );
		self::assertSame( [ 'post-701.css' => $payload, 'post-999.css' => 'sibling' ], $restored, 'every restored asset is written through a temp holding its complete bytes' );
		foreach ( scandir( $this->css_dir ) ?: [] as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			self::assertMatchesRegularExpression( '/\.css$/i', $name );
		}
	}

	public function test_rejects_a_symlink_before_running_the_operation(): void {
		$outside = tempnam( sys_get_temp_dir(), 'stonewright-css-outside-' );
		self::assertIsString( $outside );
		file_put_contents( $outside, 'outside' );
		$link = $this->css_dir . '/unsafe-link.css';
		self::assertTrue( symlink( $outside, $link ) );
		$called = false;

		try {
			$result = CssAssetTransaction::run(
				$this->target( 701 ),
				static function () use ( &$called ): array {
					$called = true;
					return [ 'ok' => true ];
				}
			);

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_elementor_css_manifest_unsafe', $result->get_error_code() );
			self::assertFalse( $called );
		} finally {
			if ( is_link( $link ) ) {
				unlink( $link );
			}
			if ( is_file( $outside ) ) {
				unlink( $outside );
			}
		}
	}

	public function test_rejects_a_symlinked_css_directory_even_when_it_stays_inside_uploads(): void {
		$real_dir = $this->css_dir . '-real';
		self::assertTrue( rename( $this->css_dir, $real_dir ) );
		self::assertTrue( symlink( $real_dir, $this->css_dir ) );

		try {
			$result = CssAssetTransaction::run( $this->target( 701 ), static fn(): array => [ 'ok' => true ] );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_elementor_css_path_unsafe', $result->get_error_code() );
		} finally {
			if ( is_link( $this->css_dir ) ) {
				unlink( $this->css_dir );
			}
			if ( is_dir( $real_dir ) ) {
				rename( $real_dir, $this->css_dir );
			}
		}
	}

	public function test_allows_a_stable_uploads_basedir_symlink(): void {
		$real = sys_get_temp_dir() . '/stonewright-uploads-real-' . bin2hex( random_bytes( 4 ) );
		$link = sys_get_temp_dir() . '/stonewright-uploads-link-' . bin2hex( random_bytes( 4 ) );
		self::assertTrue( mkdir( $real, 0700, true ) );
		self::assertTrue( symlink( $real, $link ) );
		$css = $link . '/elementor/css';
		self::assertTrue( wp_mkdir_p( $css ) );
		$GLOBALS['stonewright_test_upload_dir'] = [
			'basedir' => $link,
			'baseurl' => 'https://example.test/wp-content/uploads',
		];
		$previous = $this->css_dir;
		$this->css_dir = $css;

		try {
			$this->write( 'post-701.css', 'old-post' );
			$result = CssAssetTransaction::run(
				$this->target( 701 ),
				function (): array {
					$this->write( 'post-701.css', 'new-post' );
					return [ 'ok' => true ];
				}
			);

			self::assertIsArray( $result );
			self::assertTrue( $result['ok'] );
			self::assertSame( 'new-post', $this->read( 'post-701.css' ) );
		} finally {
			unset( $GLOBALS['stonewright_test_upload_dir'] );
			$this->css_dir = $previous;
			if ( is_file( $css . '/post-701.css' ) ) {
				unlink( $css . '/post-701.css' );
			}
			if ( is_dir( $css ) ) {
				rmdir( $css );
			}
			if ( is_dir( $link . '/elementor' ) ) {
				rmdir( $link . '/elementor' );
			}
			if ( is_link( $link ) ) {
				unlink( $link );
			}
			if ( is_dir( $real ) ) {
				rmdir( $real );
			}
		}
	}

	public function test_rejects_unsafe_direct_child_filenames(): void {
		$this->write( 'safe.css', 'safe' );
		self::assertTrue( file_put_contents( $this->css_dir . '/unsafe name.css', 'unsafe' ) > 0 );

		$result = CssAssetTransaction::run( $this->target( 701 ), static fn(): array => [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_manifest_unsafe', $result->get_error_code() );
	}

	public function test_expired_lease_without_successor_still_restores_files_and_metadata(): void {
		$GLOBALS['stonewright_test_posts'][701] = (object) [
			'ID'   => 701,
			'meta' => [ '_elementor_css' => [ 'revision' => 4 ] ],
		];
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'partial-post' );
				unlink( $this->css_dir . '/post-999.css' );
				update_post_meta( 701, '_elementor_css', [ 'revision' => 5 ] );
				$this->expire_css_leases();
				return [ 'ok' => false, 'error_code' => 'synthetic_failure' ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( [ 'revision' => 4 ], get_post_meta( 701, '_elementor_css', true ) );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
		self::assertSame( 'succeeded', $result->get_error_data()['manifest_rollback_status'] ?? null );
		self::assertSame( 'succeeded', $result->get_error_data()['metadata_rollback_status'] ?? null );
	}

	public function test_skips_restore_after_successor_commits_and_releases(): void {
		$this->write( 'post-701.css', 's0-post' );
		$this->write( 'post-999.css', 's0-sibling' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->expire_css_leases();
				$successor = CssAssetTransaction::run(
					$this->target( 999 ),
					function (): array {
						$this->write( 'post-999.css', 't2-committed' );
						return [ 'ok' => true ];
					}
				);
				self::assertIsArray( $successor );
				self::assertTrue( $successor['ok'] );
				self::assertSame( 't2-committed', $this->read( 'post-999.css' ) );
				return [ 'ok' => false, 'error_code' => 'synthetic_failure' ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 't2-committed', $this->read( 'post-999.css' ) );
		self::assertSame( 's0-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'not_attempted_lock_lost', $result->get_error_data()['manifest_rollback_status'] ?? null );
		self::assertSame( 'not_attempted_lock_lost', $result->get_error_data()['metadata_rollback_status'] ?? null );
	}

	public function test_capture_ignores_leftover_restore_temp_prefix(): void {
		$this->write( 'post-701.css', 'old-post' );
		$dot_temp = $this->css_dir . '/.stonewright-css-restore-deadbeef';
		$tmp_temp = $this->css_dir . '/stonewright-css-restore-deadbeef.tmp';
		self::assertNotFalse( file_put_contents( $dot_temp, 'stale-dot-temp' ) );
		self::assertNotFalse( file_put_contents( $tmp_temp, 'stale-tmp-temp' ) );

		try {
			$result = CssAssetTransaction::run(
				$this->target( 701 ),
				function (): array {
					$this->write( 'post-701.css', 'new-post' );
					return [ 'ok' => true ];
				}
			);

			self::assertIsArray( $result );
			self::assertTrue( $result['ok'] );
			self::assertSame( 'new-post', $this->read( 'post-701.css' ) );
		} finally {
			foreach ( [ $dot_temp, $tmp_temp ] as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		}
	}

	public function test_restore_unlinks_leftover_restore_temps_and_keeps_css_files(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );
		$dot_temp = $this->css_dir . '/.stonewright-css-restore-deadbeef';
		self::assertNotFalse( file_put_contents( $dot_temp, 'stale-dot-temp' ) );

		try {
			$result = CssAssetTransaction::run(
				$this->target( 701 ),
				function (): array {
					unlink( $this->css_dir . '/post-999.css' );
					$this->write( 'post-701.css', 'partial-post' );
					return [ 'ok' => true ];
				}
			);

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
			self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
			self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
			self::assertFalse( is_file( $dot_temp ) );
		} finally {
			if ( is_file( $dot_temp ) ) {
				unlink( $dot_temp );
			}
		}
	}

	public function test_skips_restore_when_a_different_live_owner_holds_the_lease(): void {
		$this->write( 'post-701.css', 'old-post' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'partial-post' );
				foreach ( array_keys( $GLOBALS['stonewright_test_options'] ?? [] ) as $key ) {
					if ( ! str_starts_with( (string) $key, 'stonewright_elementor_css_lease_' ) ) {
						continue;
					}
					$current = $GLOBALS['stonewright_test_options'][ $key ];
					if ( ! is_array( $current ) ) {
						continue;
					}
					$GLOBALS['stonewright_test_options'][ $key ] = [
						'scope'       => $current['scope'] ?? '',
						'owner'       => 'foreign-owner',
						'acquired_at' => time(),
						'expires_at'  => time() + 120,
						'ttl'         => 120,
					];
				}
				return [ 'ok' => false, 'error_code' => 'synthetic_failure' ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'partial-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'not_attempted_lock_lost', $result->get_error_data()['manifest_rollback_status'] ?? null );
		self::assertSame( 'not_attempted_lock_lost', $result->get_error_data()['metadata_rollback_status'] ?? null );
		self::assertSame( 'failed', $result->get_error_data()['generation_status'] ?? null );
		self::assertSame( 'blocked', $result->get_error_data()['delivery_status'] ?? null );
		self::assertSame( 'lease', $result->get_error_data()['failed_check'] ?? null );
	}

	public function test_css_directory_lease_ttl_covers_the_probe_budget(): void {
		$ttl = 0;
		$this->write( 'post-701.css', 'old-post' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function () use ( &$ttl ): array {
				foreach ( $GLOBALS['stonewright_test_options'] ?? [] as $key => $value ) {
					if ( str_starts_with( (string) $key, 'stonewright_elementor_css_lease_' ) && is_array( $value ) ) {
						$ttl = (int) ( $value['ttl'] ?? 0 );
					}
				}
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertSame( 120, $ttl );
	}

	public function test_holds_and_releases_the_shared_css_directory_lease(): void {
		$seen_lease = false;
		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function () use ( &$seen_lease ): array {
				foreach ( array_keys( $GLOBALS['stonewright_test_options'] ?? [] ) as $key ) {
					if ( str_starts_with( (string) $key, 'stonewright_elementor_css_lease_' ) ) {
						$seen_lease = true;
						break;
					}
				}
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $seen_lease );
		self::assertSame( [], array_filter( array_keys( $GLOBALS['stonewright_test_options'] ?? [] ), static fn( string $key ): bool => str_starts_with( $key, 'stonewright_elementor_css_lease_' ) ) );
	}

	public function test_continues_when_lease_renew_cas_misses_but_lease_is_still_ours(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'custom-frontend.min.css', 'frontend-safe' );
		$GLOBALS['stonewright_test_option_cas_miss_remaining'] = 1;

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true, 'method' => 'post_css_update' ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'new-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'frontend-safe', $this->read( 'custom-frontend.min.css' ) );
	}

	public function test_allows_only_the_resolved_loop_css_and_probes_loop_asset(): void {
		$this->write( 'loop-202.css', 'old-loop' );
		$this->write( 'post-202.css', 'same-id-post' );
		$this->write( 'post-999.css', 'sibling' );
		$this->write( 'custom-frontend.min.css', 'frontend' );

		$result = CssAssetTransaction::run(
			$this->target( 202, 'loop' ),
			function (): array {
				$this->write( 'loop-202.css', 'new-loop' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'loop-202.css', $result['css_evidence']['target'] );
		self::assertSame( 'new-loop', $this->read( 'loop-202.css' ) );
		self::assertSame( 'same-id-post', $this->read( 'post-202.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		$probed = array_column( $result['css_evidence']['protected_probes_after'], 'asset' );
		self::assertContains( 'loop-202.css', $probed );
		self::assertNotContains( 'post-202.css', $probed );
	}

	public function test_treats_same_id_post_css_as_collateral_during_a_loop_write(): void {
		$this->write( 'loop-202.css', 'old-loop' );
		$this->write( 'post-202.css', 'same-id-post' );

		$result = CssAssetTransaction::run(
			$this->target( 202, 'loop' ),
			function (): array {
				$this->write( 'loop-202.css', 'new-loop' );
				$this->write( 'post-202.css', 'mutated-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_collateral_change', $result->get_error_code() );
		self::assertSame( 'old-loop', $this->read( 'loop-202.css' ) );
		self::assertSame( 'same-id-post', $this->read( 'post-202.css' ) );
	}

	public function test_head_405_uses_one_bounded_get_fallback(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url     = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$methods = [];
		$get_limits = [];
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function ( string $request_url, array $args = [] ) use ( &$methods, &$get_limits ): array {
			$methods[] = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
			if ( 'HEAD' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
				return [ 'response' => [ 'code' => 405 ], 'headers' => [], 'body' => '' ];
			}
			$get_limits[] = (int) ( $args['limit_response_size'] ?? 0 );
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'text/css; charset=UTF-8' ],
				'body'     => '.elementor-701{color:red}',
			];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'verified', $result['css_evidence']['generation_status'] ?? null );
		self::assertSame( 'verified', $result['css_evidence']['delivery_status'] ?? null );
		self::assertContains( 'HEAD', $methods );
		self::assertContains( 'GET', $methods );
		self::assertNotContains( 0, $get_limits );
		foreach ( $get_limits as $limit ) {
			self::assertGreaterThan( 1, $limit );
			self::assertLessThanOrEqual( 8192, $limit );
		}
	}

	public function test_head_200_json_is_not_available_and_rolls_back_after_css(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url     = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$serving = 'css';
		$methods = [];
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function ( string $request_url, array $args = [] ) use ( &$serving, &$methods ): array {
			unset( $request_url );
			$methods[] = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
			if ( 'css' === $serving ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'text/css' ],
					'body'     => '.elementor-701{color:red}',
				];
			}
			if ( 'HEAD' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => '',
				];
			}
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => '{"ok":true}',
			];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function () use ( &$serving ): array {
				$this->write( 'post-701.css', 'new-post' );
				$serving = 'json';
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_probe_failed', $result->get_error_code() );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'failed', $result->get_error_data()['delivery_status'] ?? null );
		self::assertSame( 'verified', $result->get_error_data()['generation_status'] ?? null );
		self::assertContains( 'GET', $methods );
		self::assertNotSame( 'verified', $result->get_error_data()['delivery_status'] ?? 'verified' );
	}

	public function test_head_200_pdf_and_unknown_body_are_not_verified_delivery(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function (): array {
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/pdf' ],
				'body'     => '%PDF-1.4',
			];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'new-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'verified', $result['css_evidence']['generation_status'] ?? null );
		self::assertNotSame( 'verified', $result['css_evidence']['delivery_status'] ?? 'verified' );
	}

	public function test_missing_head_mime_uses_bounded_get_for_css(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url     = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$methods = [];
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function ( string $request_url, array $args = [] ) use ( &$methods ): array {
			unset( $request_url );
			$methods[] = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
			if ( 'HEAD' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
				return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '' ];
			}
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'text/css' ],
				'body'     => '.elementor-701{color:navy}',
			];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'verified', $result['css_evidence']['delivery_status'] ?? null );
		self::assertContains( 'HEAD', $methods );
		self::assertContains( 'GET', $methods );
	}

	public function test_head_and_get_failures_reject_commit(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$calls = 0;
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function ( string $request_url, array $args = [] ) use ( &$calls ): array {
			++$calls;
			if ( $calls < 2 ) {
				return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'content-type' => 'text/css' ], 'body' => '' ];
			}
			$code = 'HEAD' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ? 405 : 500;
			return [ 'response' => [ 'code' => $code ], 'headers' => [], 'body' => '' ];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_probe_failed', $result->get_error_code() );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
		self::assertSame( 'failed', $result->get_error_data()['delivery_status'] ?? null );
	}

	public function test_target_redirect_rejects_commit(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function (): array {
			return [
				'response' => [ 'code' => 302 ],
				'headers'  => [ 'location' => 'https://evil.test/steal' ],
				'body'     => '',
			];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_probe_unsafe_redirect', $result->get_error_code() );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
	}

	public function test_login_redirect_keeps_generated_file_and_blocks_delivery(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );
		$this->write( 'custom-frontend.min.css', 'frontend' );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$seen_urls = [];
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function ( string $request_url, array $args = [] ) use ( &$seen_urls ): array {
			$seen_urls[] = $request_url;
			self::assertSame( 0, (int) ( $args['redirection'] ?? -1 ) );
			self::assertSame( [], $args['cookies'] ?? null );
			self::assertArrayNotHasKey( 'Authorization', $args['headers'] ?? [] );
			self::assertArrayNotHasKey( 'authorization', $args['headers'] ?? [] );
			return [
				'response' => [ 'code' => 302 ],
				'headers'  => [ 'location' => 'https://example.test/wp-login.php?redirect_to=css' ],
				'body'     => '<html><body>Secret private page title XYZ</body></html>',
			];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'new-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( 'frontend', $this->read( 'custom-frontend.min.css' ) );
		self::assertSame( 'verified', $result['css_evidence']['generation_status'] ?? null );
		self::assertSame( 'blocked', $result['css_evidence']['delivery_status'] ?? null );
		self::assertSame( 'not_checked', $result['css_evidence']['frontend_verification_status'] ?? null );
		self::assertSame( 'stonewright_elementor_css_delivery_protected', $result['css_evidence']['root_error_code'] ?? null );
		self::assertSame( 'not_needed', $result['css_evidence']['rollback_status'] ?? null );
		$encoded = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( 'Secret private page title XYZ', $encoded );
		self::assertStringNotContainsString( '<html', $encoded );
		self::assertNotContains( 'https://example.test/wp-login.php?redirect_to=css', $seen_urls );
	}

	public function test_head_405_html_get_is_not_valid_css_and_does_not_store_private_body(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'custom-frontend.min.css', 'frontend' );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function ( string $request_url, array $args = [] ): array {
			if ( 'HEAD' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
				return [ 'response' => [ 'code' => 405 ], 'headers' => [], 'body' => '' ];
			}
			self::assertGreaterThan( 1, (int) ( $args['limit_response_size'] ?? 0 ) );
			self::assertLessThanOrEqual( 8192, (int) ( $args['limit_response_size'] ?? 0 ) );
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'text/html; charset=UTF-8' ],
				'body'     => '<!DOCTYPE html><html><body class="login"><form name="loginform">Secret private page title XYZ</form></body></html>',
			];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'new-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'frontend', $this->read( 'custom-frontend.min.css' ) );
		self::assertSame( 'verified', $result['css_evidence']['generation_status'] ?? null );
		self::assertSame( 'blocked', $result['css_evidence']['delivery_status'] ?? null );
		self::assertSame( 'stonewright_elementor_css_delivery_protected', $result['css_evidence']['root_error_code'] ?? null );
		$encoded = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( 'Secret private page title XYZ', $encoded );
		self::assertStringNotContainsString( 'loginform', $encoded );
	}

	public function test_preexisting_http_unavailability_is_baseline_not_corruption(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function (): \WP_Error {
			return new \WP_Error( 'http_request_failed', 'Could not connect to example.test' );
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'new-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( 'verified', $result['css_evidence']['generation_status'] ?? null );
		self::assertSame( 'not_checked', $result['css_evidence']['delivery_status'] ?? null );
		self::assertSame( 'not_needed', $result['css_evidence']['rollback_status'] ?? null );
	}

	public function test_rolls_back_a_zero_byte_target_and_keeps_siblings(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );
		$this->write( 'custom-frontend.min.css', 'frontend' );

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', '' );
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_empty_target', $result->get_error_code() );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( 'frontend', $this->read( 'custom-frontend.min.css' ) );
		self::assertSame( 'failed', $result->get_error_data()['generation_status'] ?? null );
		self::assertSame( 'not_checked', $result->get_error_data()['delivery_status'] ?? null );
		self::assertSame( 'generation', $result->get_error_data()['failed_check'] ?? null );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
	}

	public function test_same_origin_redirect_is_protected_delivery_not_a_failure(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function (): array {
			return [
				'response' => [ 'code' => 302 ],
				'headers'  => [ 'location' => 'https://example.test/members-only/' ],
				'body'     => '',
			];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'new-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'blocked', $result['css_evidence']['delivery_status'] ?? null );
		self::assertSame( 'stonewright_elementor_css_delivery_protected', $result['css_evidence']['root_error_code'] ?? null );
	}

	public function test_unsafe_redirect_error_names_the_status_and_target_origin_only(): void {
		$this->write( 'post-701.css', 'old-post' );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function (): array {
			return [
				'response' => [ 'code' => 307 ],
				'headers'  => [ 'location' => 'https://elsewhere.test:8443/collect?ref=private-value' ],
				'body'     => '',
			];
		};

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			static fn (): array => [ 'ok' => true ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_probe_unsafe_redirect', $result->get_error_code() );
		$data = (array) $result->get_error_data();
		self::assertSame( 307, $data['http_status'] ?? null );
		self::assertSame( 'https://elsewhere.test:8443', $data['redirect_origin'] ?? null );
		self::assertSame( 'cross_origin', $data['redirect_kind'] ?? null );
		self::assertStringContainsString( 'HTTP 307', $result->get_error_message() );
		self::assertStringContainsString( 'https://elsewhere.test:8443', $result->get_error_message() );
		$encoded = (string) wp_json_encode( [ $result->get_error_message(), $data ] );
		self::assertStringNotContainsString( 'collect', $encoded );
		self::assertStringNotContainsString( 'private-value', $encoded );
	}

	public function test_rejects_http_downgrade_loop_and_disallowed_redirects_without_following(): void {
		$this->write( 'post-701.css', 'old-post' );
		$cases = [
			'https://example.test/wp-content/uploads/elementor/css/post-701.css',
			'http://example.test/wp-content/uploads/elementor/css/post-701.css',
			'file:///etc/passwd',
			'http://169.254.169.254/latest/meta-data/',
		];
		foreach ( $cases as $location ) {
			$seen = [];
			$GLOBALS['stonewright_test_asset_responses']['https://example.test/wp-content/uploads/elementor/css/post-701.css'] = static function ( string $request_url, array $args = [] ) use ( &$seen, $location ): array {
				$seen[] = $request_url;
				self::assertSame( 0, (int) ( $args['redirection'] ?? -1 ) );
				self::assertSame( [], $args['cookies'] ?? null );
				self::assertArrayNotHasKey( 'Authorization', $args['headers'] ?? [] );
				return [
					'response' => [ 'code' => 302 ],
					'headers'  => [ 'location' => $location ],
					'body'     => '',
				];
			};
			$called = false;
			$result = CssAssetTransaction::run(
				$this->target( 701 ),
				static function () use ( &$called ): array {
					$called = true;
					return [ 'ok' => true ];
				}
			);
			self::assertInstanceOf( \WP_Error::class, $result, $location );
			self::assertSame( 'stonewright_elementor_css_probe_unsafe_redirect', $result->get_error_code(), $location );
			self::assertFalse( $called, $location );
			self::assertSame( [ 'https://example.test/wp-content/uploads/elementor/css/post-701.css' ], array_values( array_unique( $seen ) ) );
			self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		}
	}

	public function test_restores_collateral_file_bytes_and_modes(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );
		self::assertTrue( chmod( $this->css_dir . '/post-999.css', 0600 ) );
		$mode = fileperms( $this->css_dir . '/post-999.css' ) & 0777;

		$result = CssAssetTransaction::run(
			$this->target( 701 ),
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				unlink( $this->css_dir . '/post-999.css' );
				$this->write( 'post-999.css', 'rewritten' );
				chmod( $this->css_dir . '/post-999.css', 0644 );
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'sibling', $this->read( 'post-999.css' ) );
		self::assertSame( $mode, fileperms( $this->css_dir . '/post-999.css' ) & 0777 );
	}

	private function expire_css_leases(): void {
		foreach ( array_keys( $GLOBALS['stonewright_test_options'] ?? [] ) as $key ) {
			if ( str_starts_with( (string) $key, 'stonewright_elementor_css_lease_' ) && is_array( $GLOBALS['stonewright_test_options'][ $key ] ) ) {
				$GLOBALS['stonewright_test_options'][ $key ]['expires_at'] = time() - 1;
			}
		}
	}

	private function target( int $post_id, string $kind = 'post' ): CssTarget {
		$filename = $kind . '-' . $post_id . '.css';
		$uploads  = wp_upload_dir();
		$dir      = rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css';
		$url      = rtrim( (string) $uploads['baseurl'], '/' ) . '/elementor/css/' . $filename;
		$class    = 'loop' === $kind
			? 'ElementorPro\\Modules\\LoopBuilder\\Files\\Css\\Loop'
			: \Elementor\Core\Files\CSS\Post::class;

		return new CssTarget( $post_id, $kind, $class, $filename, $dir . '/' . $filename, $url );
	}

	private function write( string $name, string $bytes ): void {
		file_put_contents( $this->css_dir . '/' . $name, $bytes );
	}

	private function read( string $name ): string {
		return (string) file_get_contents( $this->css_dir . '/' . $name );
	}

	private function remove_test_assets(): void {
		foreach ( [ 'post-701.css', 'post-999.css', 'post-202.css', 'loop-202.css', 'custom-frontend.min.css', 'custom-pro-widget-nav-menu.min.css', 'unsafe-link.css', 'created-during-transaction.css', 'safe.css', 'unsafe name.css' ] as $name ) {
			$path = $this->css_dir . '/' . $name;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}
}
