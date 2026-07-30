<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignSpec;

/**
 * Resolves top-level DesignSpec assets into the nodes that reference them.
 */
final class AssetReferences {

	/**
	 * @param array<string, mixed> $spec
	 * @return array{spec: array<string, mixed>, sideloaded_assets: array<int, int>}
	 */
	public static function resolve( array $spec, bool $sideload ): array {
		$assets            = self::build_asset_map( $spec, $sideload );
		$spec              = $assets['spec'];
		$sideloaded_assets = $assets['sideloaded_assets'];
		$asset_map         = $assets['asset_map'];
		$url_map           = $assets['url_map'];

		self::rewrite_node( $spec, $asset_map, $url_map );

		return [
			'spec'              => $spec,
			'sideloaded_assets' => $sideloaded_assets,
		];
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array{
	 *   spec: array<string, mixed>,
	 *   sideloaded_assets: array<int, int>,
	 *   asset_map: array<string, array{url: string, source_url: string, attachment_id: int|null}>,
	 *   url_map: array<string, string>
	 * }
	 */
	private static function build_asset_map( array $spec, bool $sideload ): array {
		$sideloaded_assets = [];
		$asset_map         = [];
		$url_map           = [];

		if ( empty( $spec['assets'] ) || ! is_array( $spec['assets'] ) ) {
			return [
				'spec'              => $spec,
				'sideloaded_assets' => $sideloaded_assets,
				'asset_map'         => $asset_map,
				'url_map'           => $url_map,
			];
		}

		foreach ( $spec['assets'] as $index => $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$asset_id   = isset( $asset['id'] ) ? (string) $asset['id'] : '';
			$source_url = isset( $asset['url'] ) ? (string) $asset['url'] : (string) ( $asset['src'] ?? '' );
			if ( '' === $asset_id || '' === $source_url ) {
				continue;
			}

			$resolved_url  = $source_url;
			$attachment_id = isset( $asset['attachment_id'] ) ? (int) $asset['attachment_id'] : null;

			if ( $sideload && preg_match( '#^https?://#i', $source_url ) ) {
				$result = AssetSideloader::sideload( $source_url );
				if ( is_wp_error( $result ) ) {
					$spec['warnings'][] = sprintf( 'Asset sideload failed for "%s": %s', $source_url, $result->get_error_message() );
				} else {
					$attachment_id      = (int) $result;
					$sideloaded_assets[] = $attachment_id;
					$wp_url             = wp_get_attachment_url( $attachment_id );
					if ( false !== $wp_url && '' !== $wp_url ) {
						$resolved_url = $wp_url;
						$url_map[ $source_url ] = $resolved_url;
					}
					$spec['assets'][ $index ]['id']            = $asset_id;
					$spec['assets'][ $index ]['url']           = $resolved_url;
					$spec['assets'][ $index ]['attachment_id'] = $attachment_id;
				}
			}

			$asset_map[ $asset_id ] = [
				'url'           => $resolved_url,
				'source_url'    => $source_url,
				'attachment_id' => $attachment_id,
			];
		}

		return [
			'spec'              => $spec,
			'sideloaded_assets' => $sideloaded_assets,
			'asset_map'         => $asset_map,
			'url_map'           => $url_map,
		];
	}

	/**
	 * @param mixed                                                                 $node
	 * @param array<string, array{url: string, source_url: string, attachment_id: int|null}> $asset_map
	 * @param array<string, string>                                                 $url_map
	 */
	private static function rewrite_node( mixed &$node, array $asset_map, array $url_map ): void {
		if ( is_string( $node ) ) {
			if ( isset( $url_map[ $node ] ) ) {
				$node = $url_map[ $node ];
			}
			return;
		}

		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['assetRef'] ) ) {
			$asset = $asset_map[ (string) $node['assetRef'] ] ?? null;
			if ( null !== $asset ) {
				$type                = (string) ( $node['type'] ?? '' );
				$looks_like_media_ref = 'image' === $type || ( '' === $type && ( isset( $node['url'] ) || isset( $node['src'] ) ) );
				if ( $looks_like_media_ref ) {
					if ( empty( $node['url'] ) && empty( $node['src'] ) ) {
						$node['url'] = $asset['url'];
					}
					if ( null !== $asset['attachment_id'] && empty( $node['id'] ) ) {
						$node['id'] = $asset['attachment_id'];
					}
				}
				if ( isset( $node['url'] ) && $node['url'] === $asset['source_url'] ) {
					$node['url'] = $asset['url'];
				}
				if ( isset( $node['src'] ) && $node['src'] === $asset['source_url'] ) {
					$node['src'] = $asset['url'];
				}
			}
		}

		if ( isset( $node['background'] ) && is_array( $node['background'] ) ) {
			$background = $node['background'];
			if ( isset( $background['imageRef'] ) ) {
				$asset = $asset_map[ (string) $background['imageRef'] ] ?? null;
				if ( null !== $asset ) {
					$background['image'] = $asset['url'];
					if ( null !== $asset['attachment_id'] ) {
						$background['image_id'] = $asset['attachment_id'];
					}
				}
			}
			if ( isset( $background['image'] ) && is_string( $background['image'] ) && isset( $url_map[ $background['image'] ] ) ) {
				$background['image'] = $url_map[ $background['image'] ];
			}
			$node['background'] = $background;
		}

		foreach ( $node as &$child ) {
			self::rewrite_node( $child, $asset_map, $url_map );
		}
	}
}
