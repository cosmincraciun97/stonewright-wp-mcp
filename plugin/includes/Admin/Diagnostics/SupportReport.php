<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Diagnostics;

/**
 * Allowlisted, secret-free support report.
 */
final class SupportReport {

	public const MAX_LINE_LENGTH = 240;

	public const MAX_BYTES = 16384;

	public const MAX_LINES = 200;

	/**
	 * @var list<string>
	 */
	private const VERSION_KEYS = [ 'plugin', 'companion_contract', 'wordpress', 'php', 'tool_count' ];

	/**
	 * @var list<string>
	 */
	private const EVIDENCE_KEYS = [ 'http_status', 'duration_ms', 'error_code', 'error_class', 'timeout' ];

	/**
	 * @var list<string>
	 */
	private const COUNT_KEYS = [ 'problem', 'warning', 'info', 'ok', 'skipped' ];

	/**
	 * Credential shapes removed from free text, as pattern => replacement.
	 *
	 * @var array<string, string>
	 */
	private const SECRET_PATTERNS = [
		// Bearer and Basic values (they carry a digit or a symbol, or are long), but not the words in "a Bearer challenge".
		'#\b(Bearer|Basic)\s+(?=[A-Za-z0-9._~+/=-]*[0-9=+/_~-]|[A-Za-z0-9]{16,})[A-Za-z0-9._~+/=-]{6,}#i' => '$1 [redacted]',
		// Passwords in an address.
		'#(https?://)[^/\s:@]+:[^/\s@]*@#i'             => '$1[redacted]@',
		// A named secret with a value, as a header or a query argument.
		'#\b(authorization|set-cookie|cookie|passwd|password|client_secret|secret|api[_-]?key|access_token|refresh_token|token)\s*[:=]\s*[^\s,;&]+#i' => '$1=[redacted]',
		// An Application Password: six groups of four.
		'#\b(?:[A-Za-z0-9]{4}\s){5}[A-Za-z0-9]{4}\b#'   => '[redacted]',
		// Any long opaque token. Lowercase names with underscores, such as hook names, are words and stay.
		'#\b(?=[A-Za-z0-9_-]*[0-9A-Z])[A-Za-z0-9_-]{32,}\b#' => '[redacted]',
	];

	/**
	 * @param array<string, mixed> $envelope Graph envelope plus optional correlation id.
	 */
	public static function render( array $envelope ): string {
		$lines   = [ 'Stonewright support report' ];
		$method  = sanitize_key( (string) ( $envelope['method'] ?? '' ) );
		if ( '' !== $method ) {
			$lines[] = 'Method: ' . $method;
		}

		$correlation = self::safe_token( (string) ( $envelope['correlation_id'] ?? '' ), 64 );
		if ( '' !== $correlation ) {
			$lines[] = 'Correlation: ' . $correlation;
		}

		$versions = is_array( $envelope['versions'] ?? null ) ? $envelope['versions'] : [];
		foreach ( self::VERSION_KEYS as $key ) {
			if ( ! array_key_exists( $key, $versions ) || ! is_scalar( $versions[ $key ] ) ) {
				continue;
			}
			$lines[] = self::bound_line( self::label_for( $key ) . ': ' . (string) $versions[ $key ] );
		}

		$counts = is_array( $envelope['counts'] ?? null ) ? $envelope['counts'] : [];
		$count_bits = [];
		foreach ( self::COUNT_KEYS as $key ) {
			if ( isset( $counts[ $key ] ) && is_numeric( $counts[ $key ] ) ) {
				$count_bits[] = $key . '=' . (int) $counts[ $key ];
			}
		}
		if ( [] !== $count_bits ) {
			$lines[] = 'Counts: ' . implode( ' ', $count_bits );
		}

		$checks = is_array( $envelope['checks'] ?? null ) ? $envelope['checks'] : [];
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$id     = sanitize_key( (string) ( $check['id'] ?? '' ) );
			$status = sanitize_key( (string) ( $check['status'] ?? '' ) );
			if ( '' === $id || '' === $status ) {
				continue;
			}
			$lines[] = self::bound_line( '[' . $status . '] ' . $id );
			$summary = trim( (string) ( $check['summary'] ?? '' ) );
			if ( '' !== $summary ) {
				$label   = trim( (string) ( $check['label'] ?? '' ) );
				$lines[] = self::bound_line( '  ' . ( '' !== $label ? self::redact( $label ) . ': ' : '' ) . self::redact( $summary ) );
			}
			$remedy = trim( (string) ( $check['remedy'] ?? '' ) );
			if ( '' !== $remedy && $remedy !== $summary && in_array( $status, [ 'problem', 'warning' ], true ) ) {
				$lines[] = self::bound_line( '  Fix: ' . self::redact( $remedy ) );
			}
			$evidence = is_array( $check['evidence'] ?? null ) ? $check['evidence'] : [];
			foreach ( self::EVIDENCE_KEYS as $key ) {
				if ( ! array_key_exists( $key, $evidence ) || ! is_scalar( $evidence[ $key ] ) || '' === (string) $evidence[ $key ] ) {
					continue;
				}
				$lines[] = self::bound_line( $key . ': ' . self::redact( (string) $evidence[ $key ] ) );
			}
			$duration = (int) ( $check['duration_ms'] ?? 0 );
			if ( $duration > 0 ) {
				$lines[] = 'duration_ms: ' . $duration;
			}
		}

		$lines = array_slice( $lines, 0, self::MAX_LINES );
		$text  = implode( "\n", $lines );
		if ( strlen( $text ) > self::MAX_BYTES ) {
			$text = substr( $text, 0, self::MAX_BYTES );
		}

		return $text;
	}

	/**
	 * Removes what looks like a credential from free text: Bearer and Basic values, assigned secrets, passwords in
	 * addresses, Application Password groups, long opaque tokens and the install path. Words and addresses a person
	 * needs to read the report stay.
	 */
	public static function redact( string $text ): string {
		foreach ( self::SECRET_PATTERNS as $pattern => $replacement ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}
		foreach ( [
			'ABSPATH'        => '[wp]',
			'WP_CONTENT_DIR' => '[wp-content]',
		] as $name => $mask ) {
			if ( defined( $name ) && '' !== (string) constant( $name ) ) {
				$text = str_replace( rtrim( (string) constant( $name ), '/\\' ), $mask, $text );
			}
		}

		return $text;
	}

	private static function label_for( string $key ): string {
		if ( 'wordpress' === $key ) { // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- envelope key is lowercase.
			return 'WordPress';
		}

		return match ( $key ) {
			'companion_contract' => 'Companion contract',
			'tool_count'         => 'Tool count',
			'php'                => 'PHP',
			default              => ucfirst( $key ),
		};
	}

	private static function safe_token( string $value, int $max ): string {
		$value = sanitize_text_field( $value );
		if ( strlen( $value ) <= $max ) {
			return $value;
		}

		return substr( $value, 0, $max );
	}

	private static function bound_line( string $value ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		$value = preg_replace( '/[^\P{C}\t]/u', '', $value ) ?? $value;
		if ( strlen( $value ) <= self::MAX_LINE_LENGTH ) {
			return $value;
		}

		return substr( $value, 0, self::MAX_LINE_LENGTH );
	}
}
