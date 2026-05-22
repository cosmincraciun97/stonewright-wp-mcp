<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\QA;

/**
 * Lightweight label -> reference-image registry, backed by an option store
 * so MCP callers can reuse a Figma export or uploaded baseline by name.
 */
final class ReferenceArtifacts {

	private const OPTION_KEY = 'stonewright_reference_artifacts';

	public static function register( string $label, string $path, array $meta = [] ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$artifact_id   = wp_generate_uuid4();
		$all           = self::all();
		$all[ $label ] = array_merge(
			$meta,
			[
				'artifact_id' => $artifact_id,
				'path'        => $path,
				'registered'  => time(),
			]
		);
		update_option( self::OPTION_KEY, $all, false );
		return $artifact_id;
	}

	/** @return array<string, mixed>|null */
	public static function resolve( string $label ): ?array {
		$all = self::all();
		return $all[ $label ] ?? null;
	}

	/** @return array<string, array<string, mixed>> */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return is_array( $stored ) ? $stored : [];
	}
}
