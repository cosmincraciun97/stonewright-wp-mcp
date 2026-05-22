<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class TransformHtml extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-transform-html';
	}

	public function label(): string {
		return __( 'Transform HTML into blocks', 'stonewright' );
	}

	public function description(): string {
		return __( 'Converts raw HTML into a Gutenberg block tree by heuristic mapping.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'html' => [ 'type' => 'string' ],
			],
			'required'             => [ 'html' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'blocks' => [ 'type' => 'array' ],
			],
			'required'   => [ 'blocks' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$html  = wp_kses_post( (string) $args['html'] );
		$dom   = new \DOMDocument();
		$flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING;
		$loaded = @$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, $flags );

		$blocks = [];
		if ( $loaded ) {
			foreach ( $dom->childNodes as $node ) {
				$mapped = $this->map_node( $node );
				if ( null !== $mapped ) {
					$blocks[] = $mapped;
				}
			}
		}

		if ( empty( $blocks ) ) {
			$blocks[] = [
				'name'        => 'core/html',
				'attrs'       => new \stdClass(),
				'innerHTML'   => $html,
				'innerBlocks' => [],
			];
		}

		return [ 'blocks' => $blocks ];
	}

	private function map_node( \DOMNode $node ): ?array {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( $node->nodeValue );
			if ( '' === $text ) {
				return null;
			}
			return [
				'name'        => 'core/paragraph',
				'attrs'       => new \stdClass(),
				'innerHTML'   => '<p>' . esc_html( $text ) . '</p>',
				'innerBlocks' => [],
			];
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return null;
		}

		$tag  = strtolower( $node->nodeName );
		$html = $this->inner_html( $node );

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				return [
					'name'        => 'core/heading',
					'attrs'       => [ 'level' => $level ],
					'innerHTML'   => '<' . $tag . '>' . $html . '</' . $tag . '>',
					'innerBlocks' => [],
				];
			case 'p':
				return [
					'name'        => 'core/paragraph',
					'attrs'       => new \stdClass(),
					'innerHTML'   => '<p>' . $html . '</p>',
					'innerBlocks' => [],
				];
			case 'img':
				$src = $node instanceof \DOMElement ? $node->getAttribute( 'src' ) : '';
				$alt = $node instanceof \DOMElement ? $node->getAttribute( 'alt' ) : '';
				return [
					'name'        => 'core/image',
					'attrs'       => [ 'url' => esc_url_raw( $src ) ],
					'innerHTML'   => '<figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/></figure>',
					'innerBlocks' => [],
				];
			case 'ul':
			case 'ol':
				return [
					'name'        => 'core/list',
					'attrs'       => 'ol' === $tag ? [ 'ordered' => true ] : new \stdClass(),
					'innerHTML'   => '<' . $tag . '>' . $html . '</' . $tag . '>',
					'innerBlocks' => [],
				];
			default:
				return [
					'name'        => 'core/html',
					'attrs'       => new \stdClass(),
					'innerHTML'   => '<' . $tag . '>' . $html . '</' . $tag . '>',
					'innerBlocks' => [],
				];
		}
	}

	private function inner_html( \DOMNode $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}
}
