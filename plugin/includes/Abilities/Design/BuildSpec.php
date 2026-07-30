<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Validates and normalises a raw design spec payload before rendering.
 *
 * @stonewright-status stable
 */
final class BuildSpec extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-build-spec';
	}

	public function label(): string {
		return __( 'Build design spec', 'stonewright' );
	}

	public function description(): string {
		return __( 'Assembles a Stonewright Design Spec from a list of section descriptors and an optional token bundle.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'page'     => [ 'type' => 'object' ],
				'tokens'   => [ 'type' => 'object' ],
				'sections' => [ 'type' => 'array' ],
				'source'   => [ 'type' => 'object' ],
			],
			'required'             => [ 'page', 'sections' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'version'  => [ 'type' => 'string' ],
				'source'   => [ 'type' => 'object' ],
				'page'     => [ 'type' => 'object' ],
				'tokens'   => [ 'type' => 'object' ],
				'sections' => [ 'type' => 'array' ],
			],
			'required'   => [ 'version', 'page', 'sections' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		// Contract decision: Validator::validate() now returns the normalized
		// spec array directly, or WP_Error(stonewright_spec_invalid).
		$spec = [
			'version'  => '1.0.0',
			'source'   => isset( $args['source'] ) && is_array( $args['source'] ) ? $args['source'] : new \stdClass(),
			'page'     => (array) $args['page'],
			'tokens'   => isset( $args['tokens'] ) && is_array( $args['tokens'] ) ? $args['tokens'] : new \stdClass(),
			'sections' => self::normalize_sections( (array) $args['sections'] ),
		];

		$result = Validator::validate( $spec );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * @param array<int|string, mixed> $sections
	 * @return list<array<string, mixed>>
	 */
	private static function normalize_sections( array $sections ): array {
		return array_values(
			array_map(
				static fn( mixed $section ): array => self::normalize_section( is_array( $section ) ? $section : [] ),
				$sections
			)
		);
	}

	/**
	 * @param array<string, mixed> $section
	 * @return array<string, mixed>
	 */
	private static function normalize_section( array $section ): array {
		if ( isset( $section['blocks'] ) && is_array( $section['blocks'] ) && [] !== $section['blocks'] ) {
			if ( isset( $section['type'] ) && ! isset( $section['name'] ) ) {
				$section['name'] = sanitize_text_field( (string) $section['type'] );
			}
			unset( $section['type'] );
			return $section;
		}

		$blocks = [];
		if ( isset( $section['heading'] ) && '' !== trim( (string) $section['heading'] ) ) {
			$blocks[] = [
				'type' => 'heading',
				'text' => sanitize_text_field( (string) $section['heading'] ),
			];
		}
		$paragraph = (string) ( $section['paragraph'] ?? $section['text'] ?? '' );
		if ( '' !== trim( $paragraph ) ) {
			$blocks[] = [
				'type' => 'paragraph',
				'text' => sanitize_textarea_field( $paragraph ),
			];
		}
		if ( isset( $section['button_text'] ) && '' !== trim( (string) $section['button_text'] ) ) {
			$button = [
				'type'  => 'button',
				'text'  => sanitize_text_field( (string) $section['button_text'] ),
			];
			if ( isset( $section['button_url'] ) && '' !== trim( (string) $section['button_url'] ) ) {
				$button['url'] = esc_url_raw( (string) $section['button_url'] );
			}
			$blocks[] = $button;
		}

		if ( [] !== $blocks ) {
			$section['blocks'] = $blocks;
		}
		if ( isset( $section['type'] ) && ! isset( $section['name'] ) ) {
			$section['name'] = sanitize_text_field( (string) $section['type'] );
		}
		unset( $section['type'], $section['heading'], $section['paragraph'], $section['text'], $section['button_text'], $section['button_url'] );

		return $section;
	}
}
