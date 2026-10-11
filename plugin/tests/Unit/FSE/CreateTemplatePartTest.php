<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\FSE;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\CreateTemplatePart;

/**
 * @covers \Stonewright\WpMcp\Abilities\FSE\CreateTemplatePart
 */
final class CreateTemplatePartTest extends TestCase {

	private const CONTENT = '<!-- wp:paragraph --><p>Part</p><!-- /wp:paragraph -->';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_next_post_id']    = 4301;
		$GLOBALS['stonewright_test_inserted_posts']  = [];
		$GLOBALS['stonewright_test_object_terms']    = [];
		$GLOBALS['stonewright_test_stylesheet']      = 'active-theme';

		// Resolve "theme//slug" ids the way core does.
		$GLOBALS['stonewright_test_block_template_lookup'] = static function ( string $id, string $type ): ?object {
			[ $theme, $slug ] = array_pad( explode( '//', $id, 2 ), 2, '' );
			foreach ( $GLOBALS['stonewright_test_posts'] as $post ) {
				if ( $type !== (string) ( $post->post_type ?? '' ) || $slug !== (string) ( $post->post_name ?? '' ) ) {
					continue;
				}
				if ( ! in_array( $theme, (array) ( $GLOBALS['stonewright_test_object_terms'][ (int) $post->ID ]['wp_theme'] ?? [] ), true ) ) {
					continue;
				}
				return (object) [ 'id' => $id, 'wp_id' => (int) $post->ID, 'type' => $type, 'slug' => $slug, 'theme' => $theme, 'content' => (string) $post->post_content ];
			}
			return null;
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_block_template_lookup'], $GLOBALS['stonewright_test_stylesheet'] );
		$GLOBALS['stonewright_test_posts']        = [];
		$GLOBALS['stonewright_test_object_terms'] = [];
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_user_caps']    = [];
	}

	/** @return array<string, mixed> */
	private function args( string $content = self::CONTENT ): array {
		return [ 'slug' => 'promo-bar', 'title' => 'Promo bar', 'content' => $content, 'area' => 'header' ];
	}

	public function test_it_creates_a_template_part_that_core_can_find(): void {
		$result = ( new CreateTemplatePart() )->execute( $this->args() );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertSame( 'active-theme//promo-bar', $result['id'] );
		$post = $GLOBALS['stonewright_test_posts'][ $result['post_id'] ];
		self::assertSame( 'wp_template_part', $post->post_type );
		self::assertSame( 'promo-bar', $post->post_name );
		self::assertSame( 'Promo bar', $post->post_title );
		self::assertSame( $result['post_id'], get_block_template( $result['id'], 'wp_template_part' )->wp_id );
		self::assertSame( [ 'header' ], array_values( (array) $GLOBALS['stonewright_test_object_terms'][ $result['post_id'] ]['wp_template_part_area'] ) );
	}

	public function test_a_second_create_with_the_same_slug_is_refused_and_names_the_existing_part(): void {
		$first = ( new CreateTemplatePart() )->execute( $this->args() );
		self::assertIsArray( $first );

		$second = ( new CreateTemplatePart() )->execute( $this->args( '<!-- wp:paragraph --><p>Other</p><!-- /wp:paragraph -->' ) );

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_template_part_exists', $second->get_error_code() );
		self::assertSame( $first['post_id'], $second->get_error_data()['post_id'], 'The error says which part holds the slug.' );
		self::assertCount( 1, $GLOBALS['stonewright_test_inserted_posts'], 'No second post was created.' );
		self::assertStringContainsString( 'Part', (string) $GLOBALS['stonewright_test_posts'][ $first['post_id'] ]->post_content, 'The existing part is untouched.' );
	}
}
