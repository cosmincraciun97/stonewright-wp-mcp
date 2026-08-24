<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\CssAssetTransaction;

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
		unset( $GLOBALS['stonewright_test_home_url'], $GLOBALS['stonewright_test_asset_responses'] );
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_allows_only_the_target_post_css_and_probes_protected_assets(): void {
		$this->write( 'post-701.css', 'old-post' );
		$this->write( 'post-999.css', 'sibling' );
		$this->write( 'custom-frontend.min.css', 'frontend' );
		$this->write( 'custom-pro-widget-nav-menu.min.css', 'pro-nav' );

		$result = CssAssetTransaction::run(
			701,
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
			701,
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
			701,
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
			701,
			function (): array {
				update_post_meta( 701, '_elementor_css', [ 'revision' => 6 ] );
				return [ 'ok' => false, 'error_code' => 'synthetic_failure' ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertArrayNotHasKey( '_elementor_css', $GLOBALS['stonewright_test_posts'][701]->meta );
	}

	public function test_blocks_wrong_origin_before_operation(): void {
		$this->write( 'custom-frontend.min.css', 'frontend' );
		$GLOBALS['stonewright_test_home_url'] = 'https://other.test/';
		$called = false;

		$result = CssAssetTransaction::run(
			701,
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
			return [ 'response' => [ 'code' => 1 === $calls ? 200 : 404 ], 'headers' => [], 'body' => '' ];
		};

		$result = CssAssetTransaction::run(
			701,
			function (): array {
				$this->write( 'post-701.css', 'new-post' );
				return [ 'ok' => true ];
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_probe_failed', $result->get_error_code() );
		self::assertSame( 'old-post', $this->read( 'post-701.css' ) );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
	}

	public function test_requires_the_target_post_css_after_regeneration(): void {
		$result = CssAssetTransaction::run( 701, static fn(): array => [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_collateral_change', $result->get_error_code() );
		self::assertFalse( is_file( $this->css_dir . '/post-701.css' ) );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
	}

	public function test_restores_the_target_when_the_operation_throws(): void {
		$this->write( 'post-701.css', 'old-post' );

		$result = CssAssetTransaction::run(
			701,
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

	public function test_rejects_a_symlink_before_running_the_operation(): void {
		$outside = tempnam( sys_get_temp_dir(), 'stonewright-css-outside-' );
		self::assertIsString( $outside );
		file_put_contents( $outside, 'outside' );
		$link = $this->css_dir . '/unsafe-link.css';
		self::assertTrue( symlink( $outside, $link ) );
		$called = false;

		try {
			$result = CssAssetTransaction::run(
				701,
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
			$result = CssAssetTransaction::run( 701, static fn(): array => [ 'ok' => true ] );

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

	public function test_rejects_unsafe_direct_child_filenames(): void {
		$this->write( 'safe.css', 'safe' );
		self::assertTrue( file_put_contents( $this->css_dir . '/unsafe name.css', 'unsafe' ) > 0 );

		$result = CssAssetTransaction::run( 701, static fn(): array => [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_manifest_unsafe', $result->get_error_code() );
	}

	public function test_holds_and_releases_the_shared_css_directory_lease(): void {
		$seen_lease = false;
		$result = CssAssetTransaction::run(
			701,
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

	private function write( string $name, string $bytes ): void {
		file_put_contents( $this->css_dir . '/' . $name, $bytes );
	}

	private function read( string $name ): string {
		return (string) file_get_contents( $this->css_dir . '/' . $name );
	}

	private function remove_test_assets(): void {
		foreach ( [ 'post-701.css', 'post-999.css', 'custom-frontend.min.css', 'custom-pro-widget-nav-menu.min.css', 'unsafe-link.css', 'created-during-transaction.css', 'safe.css', 'unsafe name.css' ] as $name ) {
			$path = $this->css_dir . '/' . $name;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}
}
