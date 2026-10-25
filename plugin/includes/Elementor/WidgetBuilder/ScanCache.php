<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetBuilder;

use Stonewright\WpMcp\Sandbox\StaticGuard;

/**
 * The static-guard verdicts of the generated widget files, so the Loader scans a file once per version.
 *
 * An entry belongs to one slug and to the sha256 of the exact bytes that were scanned: a verdict is
 * returned only for that slug and that hash, so a changed file never inherits it and a verdict never
 * turns a failing scan into a pass for other content. An entry with findings is a rejection and the
 * Loader keeps skipping the file on every request. The raw code is never stored, only the digest and
 * up to MAX_FINDINGS short finding lines.
 *
 * An entry also carries the id of the guard that produced it (plugin version and StaticGuard rules
 * version), and an entry of another id is a miss, pass or rejection alike: a verdict never outlives the
 * rules that made it, and an entry written without an id is scanned again. A clean verdict is a stored
 * fact that someone who can write options could pre-seed; that is accepted, because they already
 * control the site.
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

	private static ?string $guard_id = null;

	/** The guard the verdicts are bound to: the plugin version and the StaticGuard rules version. */
	public static function guard_id(): string {
		return self::$guard_id ?? STONEWRIGHT_VERSION . ':' . StaticGuard::RULES_VERSION;
	}

	/**
	 * Replaces the guard id, for tests, which cannot vary STONEWRIGHT_VERSION. Null restores it.
	 */
	public static function set_guard_id( ?string $guard_id ): void {
		self::$guard_id = $guard_id;
	}

	/**
	 * The verdict stored for this slug and these exact bytes.
	 *
	 * @param string $slug   Widget slug.
	 * @param string $sha256 Lower-case hex sha256 of the file content that is about to be loaded.
	 * @return array{findings:list<string>,logged:bool}|null Null when there is no verdict for that slug, hash and guard.
	 */
	public static function verdict( string $slug, string $sha256 ): ?array {
		if ( ! self::is_sha256( $sha256 ) ) {
			return null;
		}
		$entry = self::read()[ $slug ] ?? null;
		if ( null === $entry || $entry['sha256'] !== $sha256 || $entry['guard'] !== self::guard_id() ) {
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
			'guard'    => self::guard_id(),
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
	 * @return array<string, array{sha256:string,findings:list<string>,logged:bool,guard:string}>
	 */
	private static function read(): array {
		$raw = get_option( self::OPTION, [] );
		$map = [];
		foreach ( is_array( $raw ) ? $raw : [] as $slug => $entry ) {
			if ( ! is_string( $slug ) || ! is_array( $entry ) || ! isset( $entry['sha256'], $entry['findings'], $entry['guard'] ) || ! is_string( $entry['guard'] ) || ! is_string( $entry['sha256'] ) || ! self::is_sha256( $entry['sha256'] ) || ! is_array( $entry['findings'] ) ) {
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
				'guard'    => $entry['guard'],
			];
		}
		return $map;
	}
}
