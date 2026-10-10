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
		unset( $GLOBALS['stonewright_test_posts'][ 701 ] );
	}

	public function test_uses_the_official_update_api(): void {
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
		self::assertSame( 'elementor_css_update', $result['method'] );
		self::assertSame( 0, $updated );
		self::assertSame( 1, $update );
		self::assertArrayNotHasKey( 'path', $result );
		self::assertArrayNotHasKey( 'url', $result );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['path_sha256'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['url_sha256'] );
	}

	public function test_reports_when_elementor_produced_empty_css(): void {
		$css_dir = rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css';
		Post::$factory = static fn( int $post_id ): object => new class( $css_dir ) {
			public function __construct( private string $css_dir ) {
			}

			public function update_file(): void {
			}

			public function get_content(): string {
				return '';
			}

			public function get_path(): string {
				return $this->css_dir . '/post-701.css';
			}

			public function get_url(): string {
				return 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
			}
		};

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'empty', $result['css_content'] ?? null );
	}

	public function test_reports_present_css_content_and_print_method(): void {
		$css_dir = rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css';
		$GLOBALS['stonewright_test_options']['elementor_css_print_method'] = 'internal';
		Post::$factory = static fn( int $post_id ): object => new class( $css_dir ) {
			public function __construct( private string $css_dir ) {
			}

			public function update_file(): void {
			}

			public function get_content(): string {
				return '.elementor-701 .x{color:red}';
			}

			public function get_path(): string {
				return $this->css_dir . '/post-701.css';
			}

			public function get_url(): string {
				return 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
			}
		};

		try {
			$result = CssRegenerator::regenerate( $this->post_target() );
		} finally {
			unset( $GLOBALS['stonewright_test_options']['elementor_css_print_method'] );
		}

		self::assertTrue( $result['ok'] );
		self::assertSame( 'present', $result['css_content'] ?? null );
		self::assertSame( 'internal', $result['print_method'] ?? null );
	}

	public function test_leaves_css_content_unreported_when_the_object_cannot_say(): void {
		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertArrayNotHasKey( 'css_content', $result );
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

	public function test_fails_closed_on_a_zero_byte_generated_file(): void {
		$expected_path = rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
		wp_mkdir_p( dirname( $expected_path ) );
		Post::$factory = static function () use ( $expected_path ): object {
			return new class( $expected_path ) {
				public function __construct( private string $path ) {
				}

				public function update_file(): void {
					file_put_contents( $this->path, '' );
				}

				public function get_path(): string {
					return $this->path;
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-701.css';
				}
			};
		};

		try {
			$result = CssRegenerator::regenerate( $this->post_target() );
			self::assertFalse( $result['ok'] );
			self::assertSame( 'empty_css_file', $result['detail'] );
		} finally {
			if ( is_file( $expected_path ) ) {
				unlink( $expected_path );
			}
		}
	}

	public function test_does_not_publish_or_update_the_post(): void {
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$updated = 0;
		Post::$factory = static function () use ( &$updated ): object {
			return new class( $updated ) {
				public function __construct( private int &$updated ) {
				}

				public function update_file(): void {
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

		CssRegenerator::regenerate( $this->post_target() );

		self::assertSame( 1, $updated );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );
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

	public function test_falls_back_to_update_file_when_update_is_not_public(): void {
		$updated = 0;
		Post::$factory = static function () use ( &$updated ): object {
			return new class( $updated ) {
				public function __construct( private int &$updated ) {
				}

				public function update_file(): void {
					++$this->updated;
				}

				private function update(): void {
					throw new \RuntimeException( 'update() is not public' );
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
		self::assertSame( 'elementor_css_update_file', $result['method'] );
		self::assertSame( 1, $updated );
		self::assertArrayNotHasKey( 'css_version', $result );
	}

	public function test_reports_the_css_version_when_regeneration_advances_it(): void {
		$this->seed_css_meta( [ 'time' => 1000, 'status' => 'file' ] );
		Post::$factory = fn( int $post_id ): object => $this->versioned_css( $post_id, 2000 );

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 1000, $result['css_version_before'] );
		self::assertSame( 2000, $result['css_version'] );
		self::assertTrue( $result['css_version_changed'] );
		self::assertSame( 2000, $this->stored_css_meta()['time'] );
	}

	public function test_advances_the_css_version_when_regeneration_lands_in_the_same_second(): void {
		$this->seed_css_meta( [ 'time' => 3000, 'status' => 'file', 'fonts' => [ 'Fixture Sans' ] ] );
		Post::$factory = fn( int $post_id ): object => $this->versioned_css( $post_id, 3000 );

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 3000, $result['css_version_before'] );
		self::assertSame( 3001, $result['css_version'] );
		self::assertTrue( $result['css_version_changed'] );
		$meta = $this->stored_css_meta();
		self::assertSame( 3001, $meta['time'] );
		self::assertSame( 'file', $meta['status'] );
		self::assertSame( [ 'Fixture Sans' ], $meta['fonts'] );
	}

	public function test_advances_the_css_version_past_a_stored_time_ahead_of_the_clock(): void {
		$this->seed_css_meta( [ 'time' => 5000, 'status' => 'file' ] );
		Post::$factory = fn( int $post_id ): object => $this->versioned_css( $post_id, 4000 );

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 5001, $result['css_version'] );
		self::assertTrue( $result['css_version_changed'] );
	}

	public function test_first_generation_without_css_metadata_reports_a_changed_version(): void {
		$GLOBALS['stonewright_test_posts'][ 701 ] = (object) [ 'ID' => 701, 'post_type' => 'page', 'meta' => [] ];
		Post::$factory = fn( int $post_id ): object => $this->versioned_css( $post_id, 2000 );

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 0, $result['css_version_before'] );
		self::assertSame( 2000, $result['css_version'] );
		self::assertTrue( $result['css_version_changed'] );
	}

	public function test_fails_closed_when_the_css_version_cannot_be_advanced(): void {
		$this->seed_css_meta( [ 'time' => 3000, 'status' => 'file' ] );
		Post::$factory = fn( int $post_id ): object => $this->versioned_css( $post_id, 3000, true );

		$result = CssRegenerator::regenerate( $this->post_target() );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'css_version_not_advanced', $result['detail'] );
	}

	/** @param array<string,mixed> $meta */
	private function seed_css_meta( array $meta ): void {
		$GLOBALS['stonewright_test_posts'][ 701 ] = (object) [
			'ID'        => 701,
			'post_type' => 'page',
			'meta'      => [ '_elementor_css' => $meta ],
		];
	}

	/** @return array<string,mixed> */
	private function stored_css_meta(): array {
		return (array) get_post_meta( 701, '_elementor_css', true );
	}

	/** An Elementor-like CSS object whose update() stores a new meta time, as the real class does. */
	private function versioned_css( int $post_id, int $new_time, bool $frozen_meta = false ): object {
		return new class( $post_id, $new_time, $frozen_meta ) {
			public function __construct( private int $post_id, private int $new_time, private bool $frozen ) {
			}

			public function update_file(): void {
			}

			public function update(): void {
				$meta         = (array) get_post_meta( $this->post_id, '_elementor_css', true );
				$meta['time'] = $this->new_time;
				update_post_meta( $this->post_id, '_elementor_css', $meta );
			}

			/** @return mixed */
			public function get_meta( ?string $property = null ) {
				$meta = (array) get_post_meta( $this->post_id, '_elementor_css', true );
				if ( $this->frozen ) {
					$meta['time'] = 3000;
				}
				return null === $property ? $meta : ( $meta[ $property ] ?? null );
			}

			public function get_path(): string {
				return rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css/post-701.css';
			}

			public function get_url(): string {
				return 'https://example.test/wp-content/uploads/elementor/css/post-701.css?ver=' . $this->get_meta( 'time' );
			}
		};
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
