<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * Renders a DesignSpec `call-to-action` / `cta` node as Elementor Pro Call to Action.
 *
 * Cover skin only when the live (or catalog) schema accepts `skin=cover`.
 * Never silently substitutes image-box — unavailable widget/control returns a
 * structured diagnostic and null so the dispatcher can skip the write path.
 *
 * Spec shape:
 *   {
 *     type: "call-to-action",
 *     skin: "cover",
 *     title, description, button, url,
 *     image: { url, id },
 *     min_height: 320,
 *     border_radius: 20,
 *     alignment, vertical_position, overlay_color
 *   }
 */
final class CallToAction {

	public const ERROR_CODE = 'stonewright_elementor_cta_unsupported_schema';

	public const WIDGET_TYPE = 'call-to-action';

	/**
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>|null
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path, array &$diagnostics = [] ): ?array {
		if ( ! ProGate::active() && ! WidgetCatalog::has( self::WIDGET_TYPE ) ) {
			$diagnostics[] = self::diagnostic(
				$canonical_path,
				'widget_unavailable',
				'The Call to Action widget is not registered. Activate Elementor Pro (or a compatible build) — never substitute image-box.',
				[ 'candidates' => [ self::WIDGET_TYPE ] ]
			);
			return null;
		}

		$skin = strtolower( (string) ( $node['skin'] ?? 'cover' ) );
		if ( 'cover' !== $skin ) {
			// Explicit non-cover skins are allowed when schema accepts them;
			// default remains cover for the native-parity path.
			if ( ! in_array( $skin, [ 'classic', 'cover' ], true ) ) {
				$diagnostics[] = self::diagnostic(
					$canonical_path,
					'unsupported_skin',
					sprintf( 'CTA skin "%s" is not supported. Use cover (preferred) or classic.', $skin ),
					[ 'requested_skin' => $skin, 'allowed' => [ 'cover', 'classic' ] ]
				);
				return null;
			}
		}

		// Schema gate: when catalog is present, require skin control with cover option.
		if ( WidgetCatalog::has( self::WIDGET_TYPE ) ) {
			$entry    = WidgetCatalog::entry( self::WIDGET_TYPE );
			$controls = is_array( $entry['settings_index'] ?? null ) ? $entry['settings_index'] : [];
			if ( ! isset( $controls['skin'] ) ) {
				$diagnostics[] = self::diagnostic(
					$canonical_path,
					'missing_skin_control',
					'Live/catalog schema for call-to-action lacks a skin control; cover CTA refused.',
					[ 'widget_type' => self::WIDGET_TYPE ]
				);
				return null;
			}
			if ( 'cover' === $skin ) {
				// Cover is documented in Elementor Pro catalog; refuse only when
				// options are present and explicitly omit cover.
				$options = $controls['skin']['options'] ?? null;
				if ( is_array( $options ) && [] !== $options && ! array_key_exists( 'cover', $options ) ) {
					$diagnostics[] = self::diagnostic(
						$canonical_path,
						'cover_skin_unavailable',
						'This Elementor version does not accept skin=cover on call-to-action.',
						[ 'available_skins' => array_keys( $options ) ]
					);
					return null;
				}
			}
		}

		$image = isset( $node['image'] ) && is_array( $node['image'] ) ? $node['image'] : [];
		$bg    = isset( $node['background'] ) && is_array( $node['background'] ) ? $node['background'] : [];

		$bg_url = (string) ( $image['url'] ?? $bg['image'] ?? $node['bg_image'] ?? '' );
		$bg_id  = $image['id'] ?? $bg['image_id'] ?? $node['bg_image_id'] ?? '';

		$min_height = isset( $node['min_height'] ) ? (int) $node['min_height'] : 320;
		if ( $min_height < 1 ) {
			$min_height = 320;
		}

		$radius = isset( $node['border_radius'] ) ? (int) $node['border_radius'] : 20;
		if ( $radius < 0 ) {
			$radius = 20;
		}

		$settings = [
			'skin'       => $skin,
			'title'      => (string) ( $node['title'] ?? $node['heading'] ?? '' ),
			'description'=> (string) ( $node['description'] ?? $node['text'] ?? '' ),
			'button'     => (string) ( $node['button'] ?? $node['button_text'] ?? '' ),
			'link'       => [
				'url'         => (string) ( $node['url'] ?? $node['link']['url'] ?? '' ),
				'is_external' => ! empty( $node['external'] ) || ! empty( $node['link']['is_external'] ),
				'nofollow'    => ! empty( $node['nofollow'] ) || ! empty( $node['link']['nofollow'] ),
			],
			// Elementor Pro uses hyphenated min-height control key.
			'min-height' => [
				'unit' => 'px',
				'size' => $min_height,
			],
			'alignment'  => (string) ( $node['alignment'] ?? $node['align'] ?? 'center' ),
			'border_radius' => [
				'unit'     => 'px',
				'top'      => (string) $radius,
				'right'    => (string) $radius,
				'bottom'   => (string) $radius,
				'left'     => (string) $radius,
				'isLinked' => true,
			],
		];

		if ( '' !== $bg_url || '' !== (string) $bg_id ) {
			$settings['bg_image'] = [
				'url' => $bg_url,
				'id'  => is_numeric( $bg_id ) ? (int) $bg_id : $bg_id,
			];
		}

		if ( isset( $node['vertical_position'] ) ) {
			$settings['vertical_position'] = (string) $node['vertical_position'];
		} elseif ( 'cover' === $skin ) {
			$settings['vertical_position'] = 'middle';
		}

		if ( isset( $node['overlay_color'] ) ) {
			$settings['overlay_color'] = (string) $resolver->resolve( (string) $node['overlay_color'] );
		} elseif ( isset( $node['overlay'] ) ) {
			$settings['overlay_color'] = (string) $resolver->resolve( (string) $node['overlay'] );
		}

		if ( isset( $node['title_tag'] ) ) {
			$settings['title_tag'] = (string) $node['title_tag'];
		}

		// Responsive min-height / alignment when provided as viewport maps.
		if ( isset( $node['min_height'] ) && is_array( $node['min_height'] ) ) {
			$settings = Responsive::apply( $settings, 'min-height', self::size_map( $node['min_height'] ) );
		}
		if ( isset( $node['alignment'] ) && is_array( $node['alignment'] ) ) {
			$settings = Responsive::apply( $settings, 'alignment', $node['alignment'] );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => self::WIDGET_TYPE,
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	/**
	 * @param array<string, mixed> $map
	 * @return array<string, array<string, mixed>>
	 */
	private static function size_map( array $map ): array {
		$out = [];
		foreach ( $map as $bp => $value ) {
			if ( is_array( $value ) && isset( $value['size'] ) ) {
				$out[ $bp ] = $value;
				continue;
			}
			$out[ $bp ] = [
				'unit' => 'px',
				'size' => (int) $value,
			];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private static function diagnostic( string $path, string $reason, string $message, array $extra = [] ): array {
		return array_merge(
			[
				'code'     => self::ERROR_CODE,
				'reason'   => $reason,
				'type'     => 'call-to-action',
				'path'     => $path,
				'renderer' => 'elementor_v3',
				'message'  => $message,
			],
			$extra
		);
	}
}
