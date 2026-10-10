<?php
/**
 * The diff of one change in the change ledger.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support\Diff;

use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * Reads the before and after images of a ledger row through the ledger, chooses the engine that fits them and
 * returns one document for a page or an ability to print.
 *
 * Result (a plain array, safe to encode as JSON):
 *
 *     status       'ok' | 'before_only' | 'no_images' | 'unreadable'
 *     message      '' for ok, else a short sentence that says why there is nothing to compare
 *     sections     list of { id: 'content' | 'elements' | 'fields', title, result } where result is a result
 *                  of TextDiff, BlockDiff, ElementorTreeDiff or FieldDiff
 *     changed      bool, whether any section found a difference
 *     truncated    bool, whether any engine left something out
 *     masked       int, values the engines replaced by [redacted]
 *     image_masked bool, whether the ledger masked the image before it stored it
 *     deleted      bool, whether the change removed the resource: there is no after image and the diff is the content
 *                  before against nothing
 *
 * The image of a file (the text of a theme file, a snippet, a stylesheet) is one text section named after the
 * resource. The image of anything else is an array of fields: `post_content` is compared as block markup when
 * it has block comments and as text otherwise, `_elementor_data` as an element tree, and everything left as
 * fields. Both keys are looked for at the top of the image and under `post`, `fields`, `meta` and `post_meta`.
 * A missing before image is read as empty, so the creation of a resource shows everything as added. Nothing
 * here reads a blob directly and nothing returns a raw image.
 */
final class ChangeDiff {

	/** Where a key of a post image may sit, besides the top. */
	private const CONTAINERS = [ 'post', 'fields', 'meta', 'post_meta' ];

	/** Statuses of a change that did not finish, or did not happen: no after image is expected, and none means nothing yet. */
	private const UNFINISHED = [ 'armed', 'failed', 'incident', 'rollback_failed' ];

	/** Families whose text image is a file: the section is named after the path. */
	private const FILE_FAMILIES = [ 'theme_file', 'custom_code', 'sandbox' ];

	/**
	 * @param array<string, mixed> $row     A row of ChangeLedger.
	 * @param array<string, mixed> $options `text`, `blocks`, `elementor` and `fields` hold the options of each engine
	 *                                      (they can lower its caps); `parser` replaces the block parser.
	 * @return array{status: string, message: string, sections: list<array{id: string, title: string, result: array<string, mixed>}>, changed: bool, truncated: bool, masked: int, image_masked: bool, deleted: bool}
	 */
	public static function for_row( array $row, array $options = [] ): array {
		$id           = (string) ( $row['change_id'] ?? '' );
		$image_masked = 'masked_secret' === (string) ( $row['restorable_reason'] ?? '' );
		if ( ! ChangeLedger::is_valid_id( $id ) ) {
			return self::empty_result( 'unreadable', __( 'This change cannot be read.', 'stonewright' ), $image_masked );
		}

		$before_ref = (string) ( $row['before_ref'] ?? '' );
		$after_ref  = (string) ( $row['after_ref'] ?? '' );
		if ( '' === $before_ref && '' === $after_ref ) {
			return self::empty_result( 'no_images', __( 'No content is kept for this change. Either Stonewright does not store content for this kind of resource, or the content could not be stored.', 'stonewright' ), $image_masked );
		}
		// A settled change with a before image and no after image at all removed the resource.
		$deleted = '' === $after_ref && '' === (string) ( $row['after_sha256'] ?? '' ) && ! in_array( (string) ( $row['status'] ?? 'armed' ), self::UNFINISHED, true );
		if ( '' === $after_ref && ! $deleted ) {
			return self::empty_result( 'before_only', __( 'Only the content before the change was recorded, so there is nothing to compare. The change may not have finished.', 'stonewright' ), $image_masked );
		}

		$before = null;
		if ( '' !== $before_ref ) {
			$before = ChangeLedger::read_image( $id, 'before' );
			if ( $before instanceof \WP_Error ) {
				return self::empty_result( 'unreadable', self::unreadable_message(), $image_masked );
			}
		}
		$after = $deleted ? null : ChangeLedger::read_image( $id, 'after' );
		if ( $after instanceof \WP_Error ) {
			return self::empty_result( 'unreadable', self::unreadable_message(), $image_masked );
		}

		return self::compare( $row, $before, $after, $options, $image_masked, $deleted );
	}

	/**
	 * The diff between two images that are not the stored ones of the row, for example the live state and the before
	 * image, which is what an undo would change. Both sides are read as the images the ledger would store: a caller
	 * passes images that were masked already (ChangeImage). The row only says which engine fits.
	 *
	 * @param array<string, mixed>     $row
	 * @param array<mixed>|string|null $from    The image on the left (what is there now); null reads as empty.
	 * @param array<mixed>|string|null $to      The image on the right (what it would become); null reads as empty.
	 * @param array<string, mixed>     $options As for_row().
	 * @return array{status: string, message: string, sections: list<array{id: string, title: string, result: array<string, mixed>}>, changed: bool, truncated: bool, masked: int, image_masked: bool, deleted: bool}
	 */
	public static function for_images( array $row, array|string|null $from, array|string|null $to, array $options = [] ): array {
		$image_masked = 'masked_secret' === (string) ( $row['restorable_reason'] ?? '' );
		if ( null === $from && null === $to ) {
			return self::empty_result( 'no_images', __( 'There is nothing to compare.', 'stonewright' ), $image_masked );
		}

		return self::compare( $row, $from, $to, $options, $image_masked );
	}

	/**
	 * @param array<string, mixed>     $row
	 * @param array<mixed>|string|null $before
	 * @param array<mixed>|string|null $after
	 * @param array<string, mixed>     $options
	 * @return array{status: string, message: string, sections: list<array{id: string, title: string, result: array<string, mixed>}>, changed: bool, truncated: bool, masked: int, image_masked: bool, deleted: bool}
	 */
	private static function compare( array $row, array|string|null $before, array|string|null $after, array $options, bool $image_masked, bool $deleted = false ): array {
		if ( null === $before ) {
			$before = is_array( $after ) ? [] : '';
		}
		if ( null === $after ) {
			$after = is_array( $before ) ? [] : '';
		}
		if ( is_array( $before ) !== is_array( $after ) ) {
			$before = self::as_text( $before );
			$after  = self::as_text( $after );
		}

		$sections = is_array( $before ) && is_array( $after )
			? self::array_sections( $before, $after, $options )
			: [ self::text_section( (string) $before, (string) $after, $row, $options ) ];

		$changed   = false;
		$truncated = false;
		$masked    = 0;
		foreach ( $sections as $section ) {
			$result    = $section['result'];
			$changed   = $changed || 'identical' !== (string) ( $result['status'] ?? '' );
			$truncated = $truncated || ! empty( $result['truncated'] );
			$masked   += (int) ( $result['masked'] ?? 0 );
		}

		return [
			'status'       => 'ok',
			'message'      => '',
			'sections'     => $sections,
			'changed'      => $changed,
			'truncated'    => $truncated,
			'masked'       => $masked,
			'image_masked' => $image_masked,
			'deleted'      => $deleted,
		];
	}

	/**
	 * @return array{status: string, message: string, sections: list<array{id: string, title: string, result: array<string, mixed>}>, changed: bool, truncated: bool, masked: int, image_masked: bool, deleted: bool}
	 */
	private static function empty_result( string $status, string $message, bool $image_masked ): array {
		return [
			'status'       => $status,
			'message'      => $message,
			'sections'     => [],
			'changed'      => false,
			'truncated'    => false,
			'masked'       => 0,
			'image_masked' => $image_masked,
			'deleted'      => false,
		];
	}

	private static function unreadable_message(): string {
		return __( 'The stored content of this change is no longer available. Retention may have removed it, or the file cannot be read.', 'stonewright' );
	}

	/**
	 * @param array<mixed>|string $image
	 */
	private static function as_text( array|string $image ): string {
		if ( is_string( $image ) ) {
			return $image;
		}
		$json = wp_json_encode( $image, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	// -----------------------------------------------------------------------------------------------
	// Sections
	// -----------------------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $options
	 * @return array{id: string, title: string, result: array<string, mixed>}
	 */
	private static function text_section( string $before, string $after, array $row, array $options ): array {
		$family = (string) ( $row['family'] ?? '' );
		if ( 'elementor' === $family && self::looks_like_tree( $before ) && self::looks_like_tree( $after ) ) {
			return [ 'id' => 'elements', 'title' => __( 'Elementor elements', 'stonewright' ), 'result' => ElementorTreeDiff::diff( $before, $after, self::engine( $options, 'elementor' ) ) ];
		}
		$title = in_array( $family, self::FILE_FAMILIES, true ) && '' !== (string) ( $row['resource_id'] ?? '' ) ? (string) $row['resource_id'] : __( 'Content', 'stonewright' );

		return [ 'id' => 'content', 'title' => $title, 'result' => self::content_result( $before, $after, $options ) ];
	}

	/**
	 * @param array<mixed>         $before
	 * @param array<mixed>         $after
	 * @param array<string, mixed> $options
	 * @return list<array{id: string, title: string, result: array<string, mixed>}>
	 */
	private static function array_sections( array $before, array $after, array $options ): array {
		$sections = [];

		$old_content = self::take( $before, 'post_content' );
		$new_content = self::take( $after, 'post_content' );
		if ( null !== $old_content || null !== $new_content ) {
			$sections[] = [
				'id'     => 'content',
				'title'  => __( 'Content', 'stonewright' ),
				'result' => self::content_result( is_string( $old_content ) ? $old_content : '', is_string( $new_content ) ? $new_content : '', $options ),
			];
		}

		$old_tree = self::take( $before, '_elementor_data' );
		$new_tree = self::take( $after, '_elementor_data' );
		if ( null !== $old_tree || null !== $new_tree ) {
			$sections[] = [
				'id'     => 'elements',
				'title'  => __( 'Elementor elements', 'stonewright' ),
				'result' => ElementorTreeDiff::diff( self::tree_input( $old_tree ), self::tree_input( $new_tree ), self::engine( $options, 'elementor' ) ),
			];
		}

		$sections[] = [
			'id'     => 'fields',
			'title'  => __( 'Fields', 'stonewright' ),
			'result' => FieldDiff::diff( $before, $after, self::engine( $options, 'fields' ) ),
		];

		// Sections that found nothing add noise, but a diff always shows something.
		$differing = array_values( array_filter( $sections, static fn ( array $section ): bool => 'identical' !== (string) ( $section['result']['status'] ?? '' ) ) );

		return [] !== $differing ? $differing : [ $sections[0] ];
	}

	/**
	 * Block markup is compared block by block, anything else line by line.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private static function content_result( string $before, string $after, array $options ): array {
		if ( str_contains( $before, '<!-- wp:' ) || str_contains( $after, '<!-- wp:' ) ) {
			$parser = isset( $options['parser'] ) && is_callable( $options['parser'] ) ? $options['parser'] : [ WordPressBlockParser::class, 'parse' ];
			return BlockDiff::diff( $before, $after, $parser, self::engine( $options, 'blocks' ) );
		}
		return TextDiff::diff( $before, $after, self::engine( $options, 'text' ) );
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, int>
	 */
	private static function engine( array $options, string $name ): array {
		$given = $options[ $name ] ?? [];
		if ( ! is_array( $given ) ) {
			return [];
		}
		$out = [];
		foreach ( $given as $key => $value ) {
			if ( is_string( $key ) && is_int( $value ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private static function looks_like_tree( string $text ): bool {
		$start = ltrim( $text );
		return '' === $start || '[' === $start[0] || '{' === $start[0];
	}

	/** @return array<int, mixed>|string */
	private static function tree_input( mixed $value ): array|string {
		if ( is_array( $value ) || is_string( $value ) ) {
			return $value;
		}
		return [];
	}

	/**
	 * The value of a key of an image, looked for at the top and in the usual containers; the key is removed
	 * from the image so the rest can be compared as fields.
	 *
	 * @param array<mixed> $image
	 */
	private static function take( array &$image, string $key ): mixed {
		if ( array_key_exists( $key, $image ) ) {
			$value = $image[ $key ];
			unset( $image[ $key ] );
			return $value;
		}
		foreach ( self::CONTAINERS as $container ) {
			if ( isset( $image[ $container ] ) && is_array( $image[ $container ] ) && array_key_exists( $key, $image[ $container ] ) ) {
				$value = $image[ $container ][ $key ];
				unset( $image[ $container ][ $key ] );
				return $value;
			}
		}
		return null;
	}
}
