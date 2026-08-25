<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\CssRegenerator;
use Stonewright\WpMcp\Elementor\CssTarget;

/** @covers \Stonewright\WpMcp\Elementor\CssRegenerator */
final class CssRegeneratorTest extends TestCase {
	protected function tearDown(): void {
		Post::$factory = null;
	}

	public function test_uses_only_the_official_update_file_api(): void {
		$updated = 0;
		$update  = 0;
		$expected_path = rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
		Post::$factory = static function ( int $post_id ) use ( &$updated, &$update ): object {
			self::assertSame( 701, $post_id );
			return new class( $updated, $update ) {
				/** @var int */
				private $updated;
				/** @var int */
				private $update;

				public function __construct( int &$updated, int &$update ) {
					$this->updated = &$updated;
					$this->update  = &$update;
				}

				public function update_file(): void {
					++$this->updated;
				}

				public function update(): void {
					++$this->update;
				}

				public function get_path(): string {
					return rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
				}
			};
		};

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertTrue( $result['ok'] );
		self::assertSame( hash( 'sha256', $expected_path ), $result['path_sha256'] );
		self::assertSame( 'elementor_css_update_file', $result['method'] );
		self::assertSame( 1, $updated );
		self::assertSame( 0, $update );
		self::assertArrayNotHasKey( 'path', $result );
		self::assertArrayNotHasKey( 'url', $result );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['path_sha256'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['url_sha256'] );
	}

	public function test_uses_the_loop_runtime_class_and_filename(): void {
		$updated = 0;
		Post::$factory = static function ( int $post_id ) use ( &$updated ): object {
			self::assertSame( 202, $post_id );
			return new class( $updated ) {
				/** @var int */
				private $updated;

				public function __construct( int &$updated ) {
					$this->updated = &$updated;
				}

				public function update_file(): void {
					++$this->updated;
				}

				public function get_path(): string {
					return rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/loop-202.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/loop-202.css';
				}
			};
		};

		$target = $this->loop_target();
		$result = CssRegenerator::regenerate( $target );

		self::assertTrue( $result['ok'] );
		self::assertSame( 1, $updated );
		self::assertSame( hash( 'sha256', $target->path() ), $result['path_sha256'] );
	}

	public function test_accepts_elementor_query_versioned_css_url(): void {
		$updated = 0;
		Post::$factory = static function () use ( &$updated ): object {
			return new class( $updated ) {
				/** @var int */
				private $updated;

				public function __construct( int &$updated ) {
					$this->updated = &$updated;
				}

				public function update_file(): void {
					++$this->updated;
				}

				public function get_path(): string {
					return rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
				}

				public function get_url(): string {
					return add_query_arg( 'ver', '123', 'https://example.test/wp-content/uploads/elementor/css/post-701.css' );
				}
			};
		};

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'elementor_css_update_file', $result['method'] );
		self::assertSame( 1, $updated );
	}

	public function test_accepts_scheme_prefixed_filesystem_path(): void {
		$updated = 0;
		Post::$factory = static function () use ( &$updated ): object {
			return new class( $updated ) {
				/** @var int */
				private $updated;

				public function __construct( int &$updated ) {
					$this->updated = &$updated;
				}

				public function update_file(): void {
					++$this->updated;
				}

				public function get_path(): string {
					return 'http:///' . ltrim( rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css', '/' );
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
				}
			};
		};

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 1, $updated );
	}

	public function test_fails_closed_when_url_path_differs_on_the_same_host(): void {
		$updated = 0;
		Post::$factory = static function () use ( &$updated ): object {
			return new class( $updated ) {
				private int $updated;

				public function __construct( int &$updated ) {
					$this->updated = &$updated;
				}

				public function get_path(): string {
					return rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
				}

				public function get_url(): string {
					return add_query_arg( 'ver', '123', 'https://example.test/wp-content/uploads/elementor/css/post-999.css' );
				}

				public function update_file(): void {
					++$this->updated;
				}
			};
		};

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'url_mismatch', $result['detail'] );
		self::assertSame( 0, $updated );
	}

	public function test_fails_closed_when_elementor_reports_a_different_path_before_update(): void {
		$updated = 0;
		Post::$factory = static function () use ( &$updated ): object {
			return new class( $updated ) {
				private int $updated;

				public function __construct( int &$updated ) {
					$this->updated = &$updated;
				}

				public function get_path(): string {
					return '/tmp/not-the-elementor-upload-path/post-701.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
				}

				public function update_file(): void {
					++$this->updated;
				}
			};
		};

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'path_mismatch', $result['detail'] );
		self::assertSame( 0, $updated );
		self::assertArrayNotHasKey( 'path', $result );
	}

	public function test_fails_closed_when_elementor_reports_a_different_url_after_update(): void {
		$updated = 0;
		Post::$factory = static function () use ( &$updated ): object {
			return new class( $updated ) {
				private int $updated;

				public function __construct( int &$updated ) {
					$this->updated = &$updated;
				}

				public function get_path(): string {
					return rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
				}

				public function get_url(): string {
					return $this->updated > 0
						? 'https://evil.example.test/post-701.css'
						: 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
				}

				public function update_file(): void {
					++$this->updated;
				}
			};
		};

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'url_mismatch', $result['detail'] );
		self::assertSame( 1, $updated );
		self::assertArrayNotHasKey( 'url', $result );
	}

	public function test_fails_closed_when_update_file_is_missing(): void {
		Post::$factory = static function (): object {
			return new class() {
				public function update(): void {
				}

				public function get_path(): string {
					return rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
				}
			};
		};

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'elementor_css_object_invalid', $result['detail'] );
	}

	public function test_fails_closed_when_the_official_api_throws(): void {
		Post::$factory = static function (): object {
			throw new \RuntimeException( 'synthetic failure' );
		};

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'exception', $result['method'] );
		self::assertSame( 'update_failed', $result['detail'] );
		self::assertSame( \RuntimeException::class, $result['error_class'] );
		self::assertStringNotContainsString( 'synthetic failure', (string) wp_json_encode( $result ) );
	}

	private function post_target( int $post_id = 701 ): CssTarget {
		$filename = 'post-' . $post_id . '.css';
		$uploads  = wp_upload_dir();
		return new CssTarget(
			$post_id,
			'post',
			Post::class,
			$filename,
			rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css/' . $filename,
			rtrim( (string) $uploads['baseurl'], '/' ) . '/elementor/css/' . $filename
		);
	}

	private function loop_target( int $post_id = 202 ): CssTarget {
		$filename = 'loop-' . $post_id . '.css';
		$uploads  = wp_upload_dir();
		return new CssTarget(
			$post_id,
			'loop',
			Post::class,
			$filename,
			rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css/' . $filename,
			rtrim( (string) $uploads['baseurl'], '/' ) . '/elementor/css/' . $filename
		);
	}
}
