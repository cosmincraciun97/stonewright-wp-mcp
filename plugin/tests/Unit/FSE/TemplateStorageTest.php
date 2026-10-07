<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\FSE;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\ReadTemplate;
use Stonewright\WpMcp\Abilities\FSE\UpdateTemplate;
use Stonewright\WpMcp\Abilities\FSE\WriteTemplate;
use Stonewright\WpMcp\Abilities\FSE\WriteTemplatePart;

/**
 * Templates and template parts are stored the way WordPress core stores them:
 * post_name is the slug and the wp_theme term names the theme. WordPress then
 * finds them through get_block_template(), which is also how read and update
 * resolve them.
 *
 * @covers \Stonewright\WpMcp\Abilities\FSE\AbstractTemplateWriter
 * @covers \Stonewright\WpMcp\Abilities\FSE\ReadTemplate
 * @covers \Stonewright\WpMcp\Abilities\FSE\UpdateTemplate
 * @covers \Stonewright\WpMcp\Abilities\FSE\WriteTemplate
 * @covers \Stonewright\WpMcp\Abilities\FSE\WriteTemplatePart
 * @covers \Stonewright\WpMcp\FSE\TemplateStore
 */
final class TemplateStorageTest extends TestCase {

	private const CONTENT = '<!-- wp:paragraph --><p>Landing</p><!-- /wp:paragraph -->';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'edit_theme_options' => true, 'edit_pages' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_next_post_id']    = 4001;
		$GLOBALS['stonewright_test_inserted_posts']  = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_object_terms']    = [];
		$GLOBALS['stonewright_test_stylesheet']      = 'active-theme';
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );

		// Resolve "theme//slug" ids the way core does: post_name is the slug and
		// the wp_theme term is the theme.
		$GLOBALS['stonewright_test_block_template_lookup'] = static function ( string $id, string $type ): ?object {
			[ $theme, $slug ] = array_pad( explode( '//', $id, 2 ), 2, '' );
			foreach ( $GLOBALS['stonewright_test_posts'] as $post ) {
				if ( $type !== (string) ( $post->post_type ?? '' ) || $slug !== (string) ( $post->post_name ?? '' ) ) {
					continue;
				}
				if ( ! in_array( $theme, (array) ( $GLOBALS['stonewright_test_object_terms'][ (int) $post->ID ]['wp_theme'] ?? [] ), true ) ) {
					continue;
				}
				return (object) [
					'id'      => $id,
					'wp_id'   => (int) $post->ID,
					'type'    => $type,
					'slug'    => $slug,
					'theme'   => $theme,
					'content' => (string) $post->post_content,
					'source'  => 'custom',
				];
			}
			return null;
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_block_template_lookup'], $GLOBALS['stonewright_test_stylesheet'] );
		$GLOBALS['stonewright_test_posts']        = [];
		$GLOBALS['stonewright_test_object_terms'] = [];
		$GLOBALS['stonewright_test_options']      = [];
	}

	/** @return list<string> */
	private function terms( int $post_id, string $taxonomy ): array {
		return array_values( (array) ( $GLOBALS['stonewright_test_object_terms'][ $post_id ][ $taxonomy ] ?? [] ) );
	}

	/** @return array<string, mixed> */
	private function write_template( string $slug = 'qa-landing', string $theme = 'twentytwentyfive', string $content = self::CONTENT ): array {
		$result = ( new WriteTemplate() )->execute( [ 'template_slug' => $slug, 'theme' => $theme, 'content' => $content ] );
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		return $result;
	}

	public function test_write_template_stores_the_slug_as_post_name_and_the_theme_as_wp_theme_term(): void {
		$result = $this->write_template();

		self::assertSame( 'created', $result['action'] );
		$post = $GLOBALS['stonewright_test_posts'][ $result['post_id'] ];
		self::assertSame( 'wp_template', $post->post_type );
		self::assertSame( 'qa-landing', $post->post_name );
		self::assertSame( [ 'twentytwentyfive' ], $this->terms( $result['post_id'], 'wp_theme' ) );
	}

	public function test_core_finds_the_written_template_by_theme_and_slug(): void {
		$result = $this->write_template();

		$found = get_block_template( 'twentytwentyfive//qa-landing', 'wp_template' );
		self::assertNotNull( $found );
		self::assertSame( $result['post_id'], $found->wp_id );
	}

	public function test_read_template_returns_what_write_template_stored(): void {
		$result = $this->write_template();

		$read = ( new ReadTemplate() )->execute( [ 'template_slug' => 'qa-landing', 'theme' => 'twentytwentyfive' ] );
		self::assertIsArray( $read );
		self::assertTrue( $read['exists'] );
		self::assertSame( self::CONTENT, $read['content'] );
		self::assertSame( $result['post_id'], $read['post_id'] );
	}

	public function test_a_second_write_updates_the_same_template_after_a_snapshot(): void {
		$first  = $this->write_template();
		$second = $this->write_template( 'qa-landing', 'twentytwentyfive', '<!-- wp:paragraph --><p>Changed</p><!-- /wp:paragraph -->' );

		self::assertSame( 'updated', $second['action'] );
		self::assertSame( $first['post_id'], $second['post_id'] );
		self::assertNotEmpty( $second['snapshot_id'] );
		self::assertCount( 1, $GLOBALS['stonewright_test_inserted_posts'] );
		self::assertStringContainsString( 'Changed', (string) $GLOBALS['stonewright_test_posts'][ $first['post_id'] ]->post_content );
	}

	public function test_the_same_slug_in_two_themes_is_two_templates(): void {
		$a = $this->write_template( 'index', 'theme-a' );
		$b = $this->write_template( 'index', 'theme-b' );

		self::assertNotSame( $a['post_id'], $b['post_id'] );
		self::assertSame( 'created', $b['action'] );
		self::assertSame( [ 'theme-b' ], $this->terms( $b['post_id'], 'wp_theme' ) );
	}

	public function test_write_template_part_stores_slug_theme_and_area_terms(): void {
		$result = ( new WriteTemplatePart() )->execute(
			[ 'template_slug' => 'qa-note', 'theme' => 'twentytwentyfive', 'area' => 'footer', 'content' => self::CONTENT ]
		);

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$post = $GLOBALS['stonewright_test_posts'][ $result['post_id'] ];
		self::assertSame( 'wp_template_part', $post->post_type );
		self::assertSame( 'qa-note', $post->post_name );
		self::assertSame( [ 'twentytwentyfive' ], $this->terms( $result['post_id'], 'wp_theme' ) );
		self::assertSame( [ 'footer' ], $this->terms( $result['post_id'], 'wp_template_part_area' ) );
		$found = get_block_template( 'twentytwentyfive//qa-note', 'wp_template_part' );
		self::assertSame( $result['post_id'], $found->wp_id );
	}

	public function test_write_template_part_defaults_the_area_to_uncategorized(): void {
		$result = ( new WriteTemplatePart() )->execute(
			[ 'template_slug' => 'qa-note', 'theme' => 'twentytwentyfive', 'content' => self::CONTENT ]
		);

		self::assertIsArray( $result );
		self::assertSame( [ 'uncategorized' ], $this->terms( $result['post_id'], 'wp_template_part_area' ) );
	}

	public function test_update_template_finds_a_template_that_write_template_created(): void {
		$written = $this->write_template();

		$updated = ( new UpdateTemplate() )->execute(
			[ 'id' => 'twentytwentyfive//qa-landing', 'type' => 'wp_template', 'content' => '<!-- wp:paragraph --><p>Via update</p><!-- /wp:paragraph -->' ]
		);

		self::assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_code() : '' );
		self::assertSame( $written['post_id'], $updated['post_id'] );
		self::assertStringContainsString( 'Via update', (string) $GLOBALS['stonewright_test_posts'][ $written['post_id'] ]->post_content );
	}

	public function test_update_template_customizing_a_theme_file_template_stores_it_the_core_way(): void {
		$GLOBALS['stonewright_test_block_template_lookup'] = static fn( string $id, string $type ): object => (object) [
			'id'     => $id,
			'wp_id'  => null,
			'type'   => $type,
			'slug'   => 'single',
			'theme'  => 'twentytwentyfive',
			'title'  => 'Single Post',
			'source' => 'theme',
		];

		$updated = ( new UpdateTemplate() )->execute(
			[ 'id' => 'twentytwentyfive//single', 'type' => 'wp_template', 'content' => self::CONTENT ]
		);

		self::assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_code() : '' );
		$post = $GLOBALS['stonewright_test_posts'][ $updated['post_id'] ];
		self::assertSame( 'single', $post->post_name );
		self::assertSame( [ 'twentytwentyfive' ], $this->terms( $updated['post_id'], 'wp_theme' ) );
	}

	public function test_a_record_written_with_the_old_name_is_found_and_repaired_on_update(): void {
		$GLOBALS['stonewright_test_posts'][4100] = (object) [
			'ID'           => 4100,
			'post_type'    => 'wp_template',
			'post_name'    => 'twentytwentyfive-qa-landing',
			'post_status'  => 'publish',
			'post_title'   => 'qa-landing',
			'post_content' => '<!-- old -->',
			'post_excerpt' => '',
			'meta'         => [],
		];

		$read = ( new ReadTemplate() )->execute( [ 'template_slug' => 'qa-landing', 'theme' => 'twentytwentyfive' ] );
		self::assertTrue( $read['exists'] );
		self::assertSame( 4100, $read['post_id'] );

		$result = $this->write_template();
		self::assertSame( 'updated', $result['action'] );
		self::assertSame( 4100, $result['post_id'] );
		$post = $GLOBALS['stonewright_test_posts'][4100];
		self::assertSame( 'qa-landing', $post->post_name );
		self::assertSame( [ 'twentytwentyfive' ], $this->terms( 4100, 'wp_theme' ) );
		self::assertSame( 4100, get_block_template( 'twentytwentyfive//qa-landing', 'wp_template' )->wp_id );
	}

	public function test_the_production_safe_token_gate_still_applies(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new WriteTemplate() )->execute( [ 'template_slug' => 'qa-landing', 'theme' => 'twentytwentyfive', 'content' => self::CONTENT ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'] );
	}
}
