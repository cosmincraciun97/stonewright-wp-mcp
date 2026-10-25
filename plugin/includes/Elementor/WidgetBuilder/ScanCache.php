<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetBuilder;

/**
 * The static-guard verdicts of the generated widget files, so the Loader scans a file once per version.
 *
 * An entry belongs to one slug and to the sha256 of the exact bytes that were scanned: a verdict is
 * returned only for that slug and that hash, so a changed file never inherits it and a verdict never
 * turns a failing scan into a pass for other content. An entry with findings is a rejection and the
 * Loader keeps skipping the file on every request. The raw code is never stored, only the digest and
 * up to MAX_FINDINGS short finding lines.
 *
 * The option is read on the Elementor widget hook of a request, so it is autoloaded (see BootOptions)
 * and bounded: at most MAX_ENTRIES slugs, the oldest written entry goes first. A write happens only
 * when a verdict is new or changes, never on a plain hit.
 */
final class ScanCache {

	public const OPTION = 'stonewright_widget_scan_cache';

	public const MAX_ENTRIES        = 100;
	public const MAX_FINDINGS       = 3;
	public const MAX_FINDING_LENGTH = 100;

	/**
	 * The verdict stored for this slug and these exact bytes.
	 *
	 * @param string $slug   Widget slug.
	 * @param string $sha256 Lower-case hex sha256 of the file content that is about to be loaded.
	 * @return array{findings:list<string>,logged:bool}|null Null when there is no verdict for that slug and hash.
	 */
	public static function verdict( string $slug, string $sha256 ): ?array {
		if ( ! self::is_sha256( $sha256 ) ) {
			return null;
		}
		$entry = self::read()[ $slug ] ?? null;
		if ( null === $entry || $entry['sha256'] !== $sha256 ) {
			return null;
		}
		return [
			'findings' => $entry['findings'],
			'logged'   => $entry['logged'],
		];
	}

	/**
	 * Stores the verdict of a scan: no findings is a pass, any finding is a rejection.
	 *
	 * @param string       $slug     Widget slug.
	 * @param string       $sha256   Lower-case hex sha256 of the scanned content.
	 * @param array<mixed> $findings What the guard reported.
	 * @param bool         $logged   Whether the rejection has been logged and announced for this hash.
	 */
	public static function remember( string $slug, string $sha256, array $findings, bool $logged = false ): void {
		if ( '' === $slug || ! self::is_sha256( $sha256 ) ) {
			return;
		}
		$entry = [
			'sha256'   => $sha256,
			'findings' => self::shorten( $findings ),
			'logged'   => $logged,
		];
		$map   = self::read();
		if ( ( $map[ $slug ] ?? null ) === $entry ) {
			return;
		}
		unset( $map[ $slug ] );
		$map[ $slug ] = $entry;
		if ( count( $map ) > self::MAX_ENTRIES ) {
			$map = array_slice( $map, count( $map ) - self::MAX_ENTRIES, null, true );
		}
		update_option( self::OPTION, $map, true );
	}

	/** Drops the verdict of a slug, for example when the widget is no longer registered. */
	public static function forget( string $slug ): void {
		$map = self::read();
		if ( ! isset( $map[ $slug ] ) ) {
			return;
		}
		unset( $map[ $slug ] );
		update_option( self::OPTION, $map, true );
	}

	private static function is_sha256( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	/**
	 * A few short single-line strings. A failing scan keeps at least one, so a rejection never reads
	 * back as a pass.
	 *
	 * @param array<mixed> $findings
	 * @return list<string>
	 */
	private static function shorten( array $findings ): array {
		$out = [];
		foreach ( $findings as $finding ) {
			if ( ! is_scalar( $finding ) ) {
				continue;
			}
			$line = trim( (string) preg_replace( '/\s+/', ' ', (string) $finding ) );
			if ( '' === $line ) {
				continue;
			}
			$out[] = strlen( $line ) > self::MAX_FINDING_LENGTH ? substr( $line, 0, self::MAX_FINDING_LENGTH - 3 ) . '...' : $line;
			if ( count( $out ) >= self::MAX_FINDINGS ) {
				break;
			}
		}
		if ( [] === $out && [] !== $findings ) {
			$out[] = 'rejected';
		}
		return $out;
	}

	/**
	 * The stored entries that are well formed, in the order they were written.
	 *
	 * @return array<string, array{sha256:string,findings:list<string>,logged:bool}>
	 */
	private static function read(): array {
		$raw = get_option( self::OPTION, [] );
		$map = [];
		foreach ( is_array( $raw ) ? $raw : [] as $slug => $entry ) {
			if ( ! is_string( $slug ) || ! is_array( $entry ) || ! isset( $entry['sha256'], $entry['findings'] ) || ! is_string( $entry['sha256'] ) || ! self::is_sha256( $entry['sha256'] ) || ! is_array( $entry['findings'] ) ) {
				continue;
			}
			$findings = [];
			foreach ( $entry['findings'] as $finding ) {
				if ( ! is_string( $finding ) ) {
					continue 2;
				}
				$findings[] = $finding;
			}
			$map[ $slug ] = [
				'sha256'   => $entry['sha256'],
				'findings' => $findings,
				'logged'   => true === ( $entry['logged'] ?? false ),
			];
		}
		return $map;
	}
}
