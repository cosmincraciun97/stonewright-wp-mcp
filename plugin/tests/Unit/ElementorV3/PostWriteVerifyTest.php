<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\PostWriteVerify;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\PostWriteVerify
 */
final class PostWriteVerifyTest extends TestCase {

	private object $elementor_instance;

	protected function setUp(): void {
		$this->elementor_instance = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_posts'][ 701 ] = (object) [
			'ID'           => 701,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Verify target',
			'post_content' => '',
			'post_excerpt' => '',
			'meta'         => [
				'_elementor_element_cache' => '<div>stale</div>',
				'_elementor_css'           => [ 'time' => 1 ],
			],
		];
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true ];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->elementor_instance;
		unset( $GLOBALS['stonewright_test_posts'][ 701 ] );
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_ability_is_registered_in_elementor_profile(): void {
		self::assertContains( PostWriteVerify::class, AbilityRegistry::list() );
		self::assertContains( 'stonewright/elementor-post-write-verify', ToolProfile::profile_tools( 'elementor-design' ) );
	}

	public function test_invalidates_post_cache_warms_render_and_checks_ids_without_returning_html(): void {
		$css_updates = 0;
		\Elementor\Plugin::$instance = (object) [
			'frontend' => new class() {
				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					return $with_css && 701 === $post_id
						? '<div class="elementor-element-hero01">Fresh marker</div>'
						: '';
				}
			},
			'posts_css_manager' => new class() {
				public function clear_cache_post( int $post_id ): void {
				}
			},
			'files_manager' => new class( $css_updates ) {
				/** @var int */
				private $updates;

				public function __construct( int &$updates ) {
					$this->updates = &$updates;
				}

				public function on_delete_post( int $post_id ): void {
				}

				public function get_css_file( int $post_id ): object {
					return new class( $this->updates ) {
						/** @var int */
						private $updates;

						public function __construct( int &$updates ) {
							$this->updates = &$updates;
						}

						public function update(): void {
							++$this->updates;
						}
					};
				}
			},
		];

		$result = ( new PostWriteVerify() )->execute(
			[
				'post_id'       => 701,
				'element_ids'   => [ 'hero01' ],
				'html_contains' => [ 'Fresh marker' ],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'passed', $result['verification_status'] );
		self::assertTrue( $result['cache']['element_cache']['existed'] );
		self::assertTrue( $result['cache']['element_cache']['deleted'] );
		self::assertTrue( $result['element_checks'][0]['present'] );
		self::assertTrue( $result['content_checks'][0]['present'] );
		self::assertSame( 1, $css_updates );
		self::assertArrayNotHasKey( 'html', $result );
		self::assertStringNotContainsString( 'Fresh marker', (string) wp_json_encode( $result ) );
		self::assertArrayNotHasKey( '_elementor_element_cache', $GLOBALS['stonewright_test_posts'][701]->meta );
	}

	public function test_missing_assertion_returns_failed_not_false_success(): void {
		\Elementor\Plugin::$instance = (object) [
			'frontend' => new class() {
				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					return '<div class="elementor-element-other">Rendered</div>';
				}
			},
		];

		$result = ( new PostWriteVerify() )->execute(
			[
				'post_id'       => 701,
				'element_ids'   => [ 'expected' ],
				'regenerate_css'=> false,
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertFalse( $result['effect_verified'] );
		self::assertSame( 'failed', $result['verification_status'] );
	}
}
