<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode\Fixtures;

/**
 * The WPCode snippet cache, as WpCodeProvider reads it: rebuilding it keeps the data of every
 * published `wpcode` post, by id.
 */
final class FakeWpCodeCache {

	/** @var array<int, array<string, mixed>> */
	private array $by_id = [];

	public function cache_all_loaded_snippets(): void {
		$this->by_id = [];
		foreach ( (array) ( $GLOBALS['stonewright_test_posts'] ?? [] ) as $id => $post ) {
			if ( is_object( $post ) && 'wpcode' === ( $post->post_type ?? '' ) && 'publish' === ( $post->post_status ?? '' ) ) {
				$this->by_id[ (int) $id ] = ( new FakeWpCodeSnippet( (int) $id ) )->get_data_for_caching();
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_cached_snippets_by_id(): array {
		return $this->by_id;
	}
}
