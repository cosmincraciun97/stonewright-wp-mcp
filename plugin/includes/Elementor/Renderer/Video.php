<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;

/**
 * Renders DesignSpec `video` and `embed` nodes as an Elementor video widget.
 *
 * Elementor's video widget auto-detects YouTube/Vimeo from the URL.
 * For self-hosted URLs we use video_type=hosted.
 */
final class Video {

	private const YOUTUBE_PATTERN = '#(?:youtube\.com|youtu\.be)#i';
	private const VIMEO_PATTERN   = '#vimeo\.com#i';

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$url        = (string) ( $node['url'] ?? '' );
		$video_type = self::detect_type( $url );

		$settings = [
			'video_type' => $video_type,
		];

		switch ( $video_type ) {
			case 'youtube':
				$settings['youtube_url'] = $url;
				break;
			case 'vimeo':
				$settings['vimeo_url'] = $url;
				break;
			default:
				$settings['hosted_url'] = [ 'url' => $url ];
				break;
		}

		if ( isset( $node['autoplay'] ) ) {
			$settings['autoplay'] = ! empty( $node['autoplay'] ) ? 'yes' : '';
		}

		if ( isset( $node['mute'] ) ) {
			$settings['mute'] = ! empty( $node['mute'] ) ? 'yes' : '';
		}

		if ( isset( $node['loop'] ) ) {
			$settings['loop'] = ! empty( $node['loop'] ) ? 'yes' : '';
		}

		if ( isset( $node['controls'] ) && false === $node['controls'] ) {
			$settings['controls'] = '';
		}

		if ( isset( $node['aspect_ratio'] ) ) {
			$settings = Responsive::apply( $settings, 'aspect_ratio', $node['aspect_ratio'] );
		}

		if ( isset( $node['poster']['url'] ) ) {
			$settings['video_poster'] = [ 'url' => (string) $node['poster']['url'] ];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'video',
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	private static function detect_type( string $url ): string {
		if ( '' === $url ) {
			return 'youtube';
		}
		if ( preg_match( self::YOUTUBE_PATTERN, $url ) ) {
			return 'youtube';
		}
		if ( preg_match( self::VIMEO_PATTERN, $url ) ) {
			return 'vimeo';
		}
		return 'hosted';
	}
}
