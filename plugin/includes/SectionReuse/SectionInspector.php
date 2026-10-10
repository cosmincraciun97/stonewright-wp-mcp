<?php
/**
 * What a section contains: outline, references and reuse flags.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\V4\AtomicTextProp;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * One walk over a section in its builder's own format. It collects:
 *
 * - an outline for people: the first heading, truncated, and counts of headings, images, buttons and forms;
 * - the references the section carries to things that live elsewhere: global colors and fonts, global classes
 *   and variables, dynamic tags, media, forms, synced patterns, nested templates, global widgets and third-party
 *   widgets or blocks, each with where it is used and how often;
 * - reuse flags derived from the references;
 * - signals the role guess reads, and the anchors of a Gutenberg section.
 *
 * Nothing here reads the database; whether a reference still resolves is {@see ReferenceCatalog}'s question.
 */
final class SectionInspector {

	private const MAX_HEADING_CHARS = 60;
	private const MAX_AT            = 5;
	private const MAX_SETTINGS_DEPTH = 6;

	/** @var array<string, string> Reference type to the flag it raises. */
	private const FLAGS = [
		'dynamic_tag'        => 'dynamic_tags',
		'form'               => 'forms',
		'global_widget'      => 'global_widgets',
		'synced_pattern'     => 'synced_patterns',
		'third_party_widget' => 'third_party_widgets',
		'nested_template'    => 'nested_templates',
	];

	/**
	 * @param string                                       $builder One of the Builder constants.
	 * @param array<string, mixed>                         $node    An Elementor element or a parsed block.
	 * @param array{max_elements?:int,max_depth?:int}|null $limits  Caps; the Elementor route limits by default.
	 * @param int                                          $root_index Position of a Gutenberg block among the blocks it is copied with; paths start there.
	 * @return array{outline:array{heading:string,headings:int,images:int,buttons:int,forms:int},references:list<array{type:string,id:string,count:int,at:list<string>}>,flags:list<string>,signals:array<string,int>,anchors:list<array{path:list<int>,anchor:string}>,capped:bool}
	 */
	public static function inspect( string $builder, array $node, ?array $limits = null, int $root_index = 0 ): array {
		$limits = array_merge( ProviderRouter::element_limits(), $limits ?? [] );
		$state  = [
			'builder'    => $builder,
			'max'        => max( 1, (int) $limits['max_elements'] ),
			'max_depth'  => max( 1, (int) $limits['max_depth'] ),
			'count'      => 0,
			'capped'     => false,
			'heading'    => '',
			'headings'   => 0,
			'images'     => 0,
			'buttons'    => 0,
			'forms'      => 0,
			'refs'       => [],
			'anchors'    => [],
			'signals'    => [ 'testimonial' => 0, 'pricing' => 0, 'faq' => 0, 'gallery' => 0, 'cta' => 0, 'currency' => 0, 'h1' => 0 ],
		];
		self::visit( $node, 1, [ max( 0, $root_index ) ], $state );

		$references = array_values( $state['refs'] );
		usort(
			$references,
			static fn( array $left, array $right ): int => [ $left['type'], $left['id'] ] <=> [ $right['type'], $right['id'] ]
		);
		$flags = [];
		foreach ( $references as $reference ) {
			if ( isset( self::FLAGS[ $reference['type'] ] ) ) {
				$flags[ self::FLAGS[ $reference['type'] ] ] = true;
			}
		}
		$flags = array_keys( $flags );
		sort( $flags );

		return [
			'outline'    => [
				'heading'  => $state['heading'],
				'headings' => $state['headings'],
				'images'   => $state['images'],
				'buttons'  => $state['buttons'],
				'forms'    => $state['forms'],
			],
			'references' => $references,
			'flags'      => $flags,
			'signals'    => $state['signals'],
			'anchors'    => $state['anchors'],
			'capped'     => $state['capped'],
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @param list<int>            $path  Index path inside the section (Gutenberg) .
	 * @param array<string, mixed> $state
	 */
	private static function visit( array $node, int $depth, array $path, array &$state ): void {
		if ( $state['count'] >= $state['max'] ) {
			$state['capped'] = true;
			return;
		}
		++$state['count'];
		if ( Builder::GUTENBERG === $state['builder'] ) {
			self::visit_block( $node, $path, $state );
			$children = is_array( $node['innerBlocks'] ?? null ) ? array_values( $node['innerBlocks'] ) : [];
		} else {
			self::visit_element( $node, $state );
			$children = is_array( $node['elements'] ?? null ) ? array_values( $node['elements'] ) : [];
		}
		if ( [] !== $children && $depth >= $state['max_depth'] ) {
			$state['capped'] = true;
			return;
		}
		foreach ( $children as $index => $child ) {
			if ( is_array( $child ) ) {
				self::visit( $child, $depth + 1, array_merge( $path, [ (int) $index ] ), $state );
			}
		}
	}

	// ---------------------------------------------------------------- Elementor

	/**
	 * @param array<string, mixed> $element
	 * @param array<string, mixed> $state
	 */
	private static function visit_element( array $element, array &$state ): void {
		$type = Builder::element_type( $element );
		$id   = (string) ( $element['id'] ?? '' );
		if ( 'widget' === ( $element['elType'] ?? '' ) ) {
			self::note_widget( $type, $element, $id, $state );
		}
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		if ( Builder::ELEMENTOR_V4 === $state['builder'] || Builder::is_atomic( $element ) ) {
			self::visit_atomic( $element, $settings, $id, $state );
			return;
		}
		self::visit_v3_settings( $settings, $id, $state );
	}

	/**
	 * @param array<string, mixed> $element
	 * @param array<string, mixed> $state
	 */
	private static function note_widget( string $type, array $element, string $id, array &$state ): void {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$lower    = strtolower( $type );
		if ( in_array( $type, [ 'heading', 'e-heading' ], true ) ) {
			++$state['headings'];
			$level = 'e-heading' === $type ? $settings['tag'] ?? '' : $settings['header_size'] ?? '';
			if ( 'h1' === strtolower( is_array( $level ) ? (string) ( $level['value'] ?? '' ) : (string) ( is_scalar( $level ) ? $level : '' ) ) ) {
				++$state['signals']['h1'];
			}
			self::note_heading( self::atomic_or_plain_text( $settings['title'] ?? '' ), $state );
		}
		if ( in_array( $lower, [ 'image', 'e-image', 'image-box', 'image-carousel', 'image-gallery', 'gallery', 'testimonial', 'testimonial-carousel' ], true ) ) {
			++$state['images'];
		}
		if ( in_array( $lower, [ 'button', 'e-button', 'call-to-action', 'price-table' ], true ) ) {
			++$state['buttons'];
		}
		if ( in_array( $lower, [ 'testimonial', 'testimonial-carousel', 'reviews' ], true ) ) {
			++$state['signals']['testimonial'];
		}
		if ( in_array( $lower, [ 'price-table', 'price-list' ], true ) ) {
			++$state['signals']['pricing'];
		}
		if ( in_array( $lower, [ 'accordion', 'toggle', 'nested-accordion', 'e-accordion' ], true ) ) {
			++$state['signals']['faq'];
		}
		if ( in_array( $lower, [ 'image-gallery', 'gallery', 'image-carousel', 'media-carousel' ], true ) ) {
			++$state['signals']['gallery'];
		}
		if ( 'call-to-action' === $lower ) {
			++$state['signals']['cta'];
		}
		if ( 'global' === $lower ) {
			$template = $element['templateID'] ?? $settings['templateID'] ?? '';
			if ( is_scalar( $template ) && '' !== (string) $template ) {
				self::add_ref( $state, 'global_widget', (string) $template, $id );
			}
			return;
		}
		if ( 'template' === $lower ) {
			$template = $settings['template_id'] ?? '';
			if ( is_scalar( $template ) && '' !== (string) $template ) {
				self::add_ref( $state, 'nested_template', (string) $template, $id );
			}
			return;
		}
		if ( str_contains( $lower, 'form' ) ) {
			++$state['forms'];
			$identity = $settings['form_name'] ?? $settings['form_id'] ?? $type;
			self::add_ref( $state, 'form', is_scalar( $identity ) && '' !== (string) $identity ? (string) $identity : $type, $id );
			return;
		}
		if ( ! str_starts_with( $lower, 'e-' ) && '' !== $type && ! WidgetCatalog::has( $type ) ) {
			self::add_ref( $state, 'third_party_widget', $type, $id );
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $state
	 */
	private static function visit_v3_settings( array $settings, string $id, array &$state ): void {
		$globals = is_array( $settings['__globals__'] ?? null ) ? $settings['__globals__'] : [];
		foreach ( $globals as $value ) {
			if ( is_string( $value ) && 1 === preg_match( '/^globals\/(colors|typography)\?id=([A-Za-z0-9_-]+)$/D', $value, $match ) ) {
				self::add_ref( $state, 'colors' === $match[1] ? 'global_color' : 'global_font', $match[2], $id );
			}
		}
		$dynamic = is_array( $settings['__dynamic__'] ?? null ) ? $settings['__dynamic__'] : [];
		foreach ( $dynamic as $tag ) {
			$name = is_string( $tag ) && 1 === preg_match( '/name="([^"]+)"/', $tag, $match ) ? $match[1] : 'dynamic';
			self::add_ref( $state, 'dynamic_tag', $name, $id );
		}
		foreach ( self::media_ids( $settings, 0 ) as $media ) {
			self::add_ref( $state, 'media', (string) $media, $id );
		}
		if ( self::has_currency( $settings ) ) {
			++$state['signals']['currency'];
		}
	}

	/**
	 * Attachment ids held in settings: any map with a positive integer `id` and a `url`.
	 *
	 * @param array<mixed> $settings
	 * @return list<int>
	 */
	private static function media_ids( array $settings, int $depth ): array {
		if ( $depth > self::MAX_SETTINGS_DEPTH ) {
			return [];
		}
		$found = [];
		if ( isset( $settings['id'], $settings['url'] ) && is_numeric( $settings['id'] ) && (int) $settings['id'] > 0 && is_string( $settings['url'] ) ) {
			$found[] = (int) $settings['id'];
		}
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) && ! in_array( $key, [ '__globals__', '__dynamic__' ], true ) ) {
				array_push( $found, ...self::media_ids( $value, $depth + 1 ) );
			}
		}

		return $found;
	}

	/** @param array<mixed> $settings */
	private static function has_currency( array $settings ): bool {
		foreach ( $settings as $value ) {
			if ( is_string( $value ) && 1 === preg_match( '/[$€£]\s?\d/u', $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $element
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $state
	 */
	private static function visit_atomic( array $element, array $settings, string $id, array &$state ): void {
		$local   = array_map( 'strval', array_keys( is_array( $element['styles'] ?? null ) ? $element['styles'] : [] ) );
		$classes = is_array( $settings['classes'] ?? null ) && is_array( $settings['classes']['value'] ?? null ) ? $settings['classes']['value'] : [];
		foreach ( $classes as $class ) {
			if ( is_string( $class ) && '' !== $class && ! in_array( $class, $local, true ) ) {
				self::add_ref( $state, 'global_class', $class, $id );
			}
		}
		self::visit_envelopes( $settings, $id, $state, 0 );
		self::visit_envelopes( is_array( $element['styles'] ?? null ) ? $element['styles'] : [], $id, $state, 0 );
	}

	/**
	 * Typed props anywhere below a settings or styles value: variables, dynamic tags and attachment ids.
	 *
	 * @param array<mixed>         $value
	 * @param array<string, mixed> $state
	 */
	private static function visit_envelopes( array $value, string $id, array &$state, int $depth ): void {
		if ( $depth > 12 ) {
			return;
		}
		$type = $value['$$type'] ?? null;
		if ( is_string( $type ) && array_key_exists( 'value', $value ) ) {
			if ( 1 === preg_match( '/^global-[a-z-]*variable$/D', $type ) && is_string( $value['value'] ) && '' !== $value['value'] ) {
				self::add_ref( $state, 'variable', $value['value'], $id );
			} elseif ( 'dynamic' === $type && is_array( $value['value'] ) ) {
				$name = $value['value']['name'] ?? 'dynamic';
				self::add_ref( $state, 'dynamic_tag', is_string( $name ) && '' !== $name ? $name : 'dynamic', $id );
			} elseif ( str_ends_with( $type, 'attachment-id' ) && is_numeric( $value['value'] ) && (int) $value['value'] > 0 ) {
				self::add_ref( $state, 'media', (string) (int) $value['value'], $id );
			}
		}
		foreach ( $value as $member ) {
			if ( is_array( $member ) ) {
				self::visit_envelopes( $member, $id, $state, $depth + 1 );
			}
		}
	}

	/** The text of a heading setting: a plain string, or the text of an Atomic text envelope of any of its types. */
	private static function atomic_or_plain_text( mixed $title ): string {
		if ( is_string( $title ) ) {
			return $title;
		}

		return AtomicTextProp::text_of( $title ) ?? '';
	}

	// ---------------------------------------------------------------- Gutenberg

	/**
	 * @param array<string, mixed> $block
	 * @param list<int>            $path
	 * @param array<string, mixed> $state
	 */
	private static function visit_block( array $block, array $path, array &$state ): void {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$id    = implode( '.', $path );
		if ( isset( $attrs['anchor'] ) && is_string( $attrs['anchor'] ) && '' !== $attrs['anchor'] ) {
			$state['anchors'][] = [ 'path' => $path, 'anchor' => $attrs['anchor'] ];
		}
		if ( 'core/block' === $name ) {
			if ( isset( $attrs['ref'] ) && is_numeric( $attrs['ref'] ) ) {
				self::add_ref( $state, 'synced_pattern', (string) (int) $attrs['ref'], $id );
			}
			return;
		}
		if ( 'core/template-part' === $name ) {
			if ( isset( $attrs['slug'] ) && is_string( $attrs['slug'] ) ) {
				self::add_ref( $state, 'nested_template', $attrs['slug'], $id );
			}
			return;
		}
		$bindings = is_array( $attrs['metadata'] ?? null ) && is_array( $attrs['metadata']['bindings'] ?? null ) ? $attrs['metadata']['bindings'] : [];
		foreach ( $bindings as $binding ) {
			$source = is_array( $binding ) && is_string( $binding['source'] ?? null ) ? $binding['source'] : 'binding';
			self::add_ref( $state, 'dynamic_tag', $source, $id );
		}
		foreach ( [ 'id', 'mediaId' ] as $key ) {
			if ( isset( $attrs[ $key ] ) && is_numeric( $attrs[ $key ] ) && (int) $attrs[ $key ] > 0 && in_array( $name, [ 'core/image', 'core/cover', 'core/media-text', 'core/video', 'core/audio', 'core/file' ], true ) ) {
				self::add_ref( $state, 'media', (string) (int) $attrs[ $key ], $id );
			}
		}
		if ( is_array( $attrs['ids'] ?? null ) ) {
			foreach ( $attrs['ids'] as $media ) {
				if ( is_numeric( $media ) && (int) $media > 0 ) {
					self::add_ref( $state, 'media', (string) (int) $media, $id );
				}
			}
		}
		$html = (string) ( $block['innerHTML'] ?? '' );
		if ( 'core/heading' === $name ) {
			++$state['headings'];
			if ( 1 === (int) ( $attrs['level'] ?? 0 ) || 1 === preg_match( '/<h1[\s>]/i', $html ) ) {
				++$state['signals']['h1'];
			}
			self::note_heading( $html, $state );
		}
		if ( in_array( $name, [ 'core/image', 'core/gallery', 'core/cover', 'core/media-text' ], true ) ) {
			++$state['images'];
		}
		if ( 'core/button' === $name ) {
			++$state['buttons'];
		}
		if ( in_array( $name, [ 'core/quote', 'core/pullquote' ], true ) ) {
			++$state['signals']['testimonial'];
		}
		if ( 'core/details' === $name ) {
			++$state['signals']['faq'];
		}
		if ( 'core/gallery' === $name ) {
			++$state['signals']['gallery'];
		}
		if ( 1 === preg_match( '/[$€£]\s?\d/u', wp_strip_all_tags( $html ) ) ) {
			++$state['signals']['currency'];
		}
		if ( 1 === preg_match( '/(^|\/)[a-z0-9-]*form[a-z0-9-]*$/', $name ) ) {
			++$state['forms'];
			self::add_ref( $state, 'form', $name, $id );
		}
		if ( '' !== $name && ! str_starts_with( $name, 'core/' ) ) {
			self::add_ref( $state, 'third_party_widget', $name, $id );
		}
	}

	// ---------------------------------------------------------------- shared

	/** @param array<string, mixed> $state */
	private static function note_heading( string $html, array &$state ): void {
		if ( '' !== $state['heading'] ) {
			return;
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) );
		$state['heading'] = mb_substr( $text, 0, self::MAX_HEADING_CHARS );
	}

	/** @param array<string, mixed> $state */
	private static function add_ref( array &$state, string $type, string $id, string $at ): void {
		$key = $type . ':' . $id;
		if ( ! isset( $state['refs'][ $key ] ) ) {
			$state['refs'][ $key ] = [ 'type' => $type, 'id' => $id, 'count' => 0, 'at' => [] ];
		}
		++$state['refs'][ $key ]['count'];
		if ( '' !== $at && count( $state['refs'][ $key ]['at'] ) < self::MAX_AT && ! in_array( $at, $state['refs'][ $key ]['at'], true ) ) {
			$state['refs'][ $key ]['at'][] = $at;
		}
	}
}
