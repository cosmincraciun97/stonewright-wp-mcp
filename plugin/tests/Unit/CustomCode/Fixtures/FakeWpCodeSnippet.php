<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode\Fixtures;

/**
 * The part of WPCode_Snippet that WpCodeProvider uses, read from the test post store the way
 * WPCode keeps a snippet: the code is the post content, the snippet is active while its post is
 * published, and the code type is the post's `wpcode_type` term (WPCode keeps no code-type post
 * meta). Like WPCode_Snippet, the properties are public, each getter loads its property on first
 * use, there is no set_code(), and save() sends only the properties that are set to
 * wp_update_post(), which expects slashed data.
 */
final class FakeWpCodeSnippet {

	/** @var int|null */
	public $id;

	/** @var string|null */
	public $title;

	/** @var string|null */
	public $code;

	/** @var string|null */
	public $code_type;

	/** @var bool|null */
	public $active;

	private ?object $post_data = null;

	public function __construct( int $id ) {
		$post = get_post( $id );
		if ( is_object( $post ) && 'wpcode' === ( $post->post_type ?? '' ) ) {
			$this->post_data = $post;
			$this->id        = $id;
		}
	}

	public function get_id(): int {
		if ( ! isset( $this->id ) ) {
			$this->id = isset( $this->post_data ) ? (int) $this->post_data->ID : 0;
		}
		return $this->id;
	}

	public function get_title(): string {
		if ( ! isset( $this->title ) ) {
			$this->title = isset( $this->post_data ) ? (string) $this->post_data->post_title : '';
		}
		return $this->title;
	}

	public function get_code(): string {
		if ( ! isset( $this->code ) ) {
			$this->code = isset( $this->post_data ) ? (string) $this->post_data->post_content : '';
		}
		return $this->code;
	}

	public function get_code_type(): string {
		if ( ! isset( $this->code_type ) ) {
			$this->code_type = '';
			$terms           = isset( $this->post_data ) ? get_the_terms( $this->get_id(), 'wpcode_type' ) : false;
			if ( is_array( $terms ) && isset( $terms[0]->slug ) ) {
				$this->code_type = (string) $terms[0]->slug;
			}
		}
		return $this->code_type;
	}

	public function is_active(): bool {
		if ( ! isset( $this->active ) ) {
			$this->active = isset( $this->post_data->post_status ) && 'publish' === $this->post_data->post_status;
		}
		return $this->active;
	}

	/**
	 * @return int|false
	 */
	public function save() {
		if ( ! isset( $this->post_data ) ) {
			return false;
		}
		$post_args = [ 'ID' => $this->get_id() ];
		if ( isset( $this->title ) ) {
			$post_args['post_title'] = $this->title;
		}
		if ( isset( $this->code ) ) {
			$post_args['post_content'] = $this->code;
		}
		if ( isset( $this->active ) ) {
			$post_args['post_status'] = $this->active ? 'publish' : 'draft';
		}
		$result = wp_update_post( $post_args );
		if ( 0 === $result || $result instanceof \WP_Error ) {
			return false;
		}
		if ( isset( $this->code_type ) ) {
			$GLOBALS['stonewright_test_object_terms'][ $this->get_id() ]['wpcode_type'] = [ $this->code_type ];
		}
		wpcode()->cache->cache_all_loaded_snippets();
		return $this->get_id();
	}

	/**
	 * The keys WPCode_Snippet::get_data_for_caching() returns; the settings this fake does not keep
	 * have the values WPCode reads for a snippet without them.
	 *
	 * @return array<string, mixed>
	 */
	public function get_data_for_caching(): array {
		return [
			'id'                   => $this->get_id(),
			'title'                => $this->get_title(),
			'code'                 => $this->get_code(),
			'code_type'            => $this->get_code_type(),
			'location'             => '',
			'auto_insert'          => 0,
			'insert_number'        => 1,
			'use_rules'            => false,
			'rules'                => [],
			'priority'             => 10,
			'location_extra'       => '',
			'shortcode_attributes' => [],
			'compiled_code'        => '',
			'modified'             => 0,
		];
	}
}
