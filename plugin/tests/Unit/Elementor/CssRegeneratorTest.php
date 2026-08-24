<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\CssRegenerator;

/** @covers \Stonewright\WpMcp\Elementor\CssRegenerator */
final class CssRegeneratorTest extends TestCase {
	protected function tearDown(): void {
		Post::$factory = null;
	}

	public function test_uses_only_the_official_post_css_update_api(): void {
		$updated = 0;
		$expected_path = rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
		Post::$factory = static function ( int $post_id ) use ( &$updated ): object {
			self::assertSame( 701, $post_id );
			return new class( $updated ) {
				/** @var int */
				private $updated;

				public function __construct( int &$updated ) {
					$this->updated = &$updated;
				}

				public function update(): void {
					++$this->updated;
				}

				public function get_path(): string {
					return rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
				}
			};
		};

		$result = CssRegenerator::regenerate_post( 701 );

		self::assertTrue( $result['ok'] );
		self::assertSame( hash( 'sha256', $expected_path ), $result['path_sha256'] );
		self::assertSame( 'elementor_post_css_update', $result['method'] );
		self::assertSame( 1, $updated );
		self::assertArrayNotHasKey( 'path', $result );
		self::assertArrayNotHasKey( 'url', $result );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['path_sha256'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['url_sha256'] );
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

				public function update(): void {
					++$this->updated;
				}
			};
		};

		$result = CssRegenerator::regenerate_post( 701 );

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

				public function update(): void {
					++$this->updated;
				}
			};
		};

		$result = CssRegenerator::regenerate_post( 701 );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'url_mismatch', $result['detail'] );
		self::assertSame( 1, $updated );
		self::assertArrayNotHasKey( 'url', $result );
	}

	public function test_fails_closed_when_the_official_api_throws(): void {
		Post::$factory = static function (): object {
			throw new \RuntimeException( 'synthetic failure' );
		};

		$result = CssRegenerator::regenerate_post( 701 );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'exception', $result['method'] );
		self::assertSame( 'update_failed', $result['detail'] );
		self::assertSame( \RuntimeException::class, $result['error_class'] );
		self::assertStringNotContainsString( 'synthetic failure', (string) wp_json_encode( $result ) );
	}
}
