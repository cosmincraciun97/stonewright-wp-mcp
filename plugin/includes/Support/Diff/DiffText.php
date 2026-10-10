<?php
/**
 * A diff of ChangeDiff as plain lines of text, for a terminal.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support\Diff;

/**
 * Prints what ChangeDiff returned: unified hunks for text, one line per block, element or field for the others. It prints
 * what the engines gave it and never reads an image: the values are already masked and cut. A part that was cut or that could
 * not be computed says so.
 */
final class DiffText {

	/**
	 * @param array<string, mixed> $diff A result of ChangeDiff::for_row().
	 * @return list<string>
	 */
	public static function lines( array $diff ): array {
		if ( 'ok' !== (string) ( $diff['status'] ?? '' ) ) {
			return [ (string) ( $diff['message'] ?? 'There is no diff to show.' ) ];
		}
		$out = [];
		foreach ( (array) ( $diff['sections'] ?? [] ) as $section ) {
			$result = is_array( $section['result'] ?? null ) ? $section['result'] : [];
			$out[]  = '--- ' . (string) ( $section['title'] ?? '' );
			array_push( $out, ...self::result( $result ) );
		}
		if ( ! empty( $diff['masked'] ) ) {
			$out[] = sprintf( '%d value(s) were masked.', (int) $diff['masked'] );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<string>
	 */
	private static function result( array $result ): array {
		$status = (string) ( $result['status'] ?? 'ok' );
		if ( 'ok' !== $status ) {
			return [ (string) ( $result['message'] ?? $status ) ];
		}
		$out = match ( (string) ( $result['kind'] ?? '' ) ) {
			'text'      => self::text( $result ),
			'blocks'    => self::blocks( $result ),
			'elementor' => self::elements( $result ),
			default     => self::fields( (array) ( $result['fields'] ?? [] ), '' ),
		};
		if ( ! empty( $result['truncated'] ) ) {
			$out[] = 'Only part of the diff is shown.';
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<string>
	 */
	private static function text( array $result ): array {
		$out = [];
		foreach ( (array) ( $result['hunks'] ?? [] ) as $hunk ) {
			$out[] = sprintf( '@@ -%d,%d +%d,%d @@', (int) $hunk['old_start'], (int) $hunk['old_lines'], (int) $hunk['new_start'], (int) $hunk['new_lines'] );
			foreach ( (array) $hunk['lines'] as $line ) {
				$out[] = ( [ 'add' => '+', 'del' => '-' ][ $line['op'] ] ?? ' ' ) . (string) $line['text'];
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<string>
	 */
	private static function blocks( array $result ): array {
		$out = [];
		foreach ( (array) ( $result['items'] ?? [] ) as $item ) {
			$out[] = self::marker( (string) $item['op'] ) . ' ' . (string) ( $item['name'] ?? '' ) . ' [' . implode( '.', array_map( 'strval', (array) ( $item['path'] ?? [] ) ) ) . ']';
			array_push( $out, ...self::fields( (array) ( $item['attrs'] ?? [] ), '  ' ) );
			if ( is_array( $item['text'] ?? null ) ) {
				foreach ( self::text( $item['text'] ) as $line ) {
					$out[] = '  ' . $line;
				}
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<string>
	 */
	private static function elements( array $result ): array {
		$out = [];
		foreach ( (array) ( $result['elements'] ?? [] ) as $element ) {
			$out[] = self::marker( (string) $element['op'] ) . ' ' . (string) ( $element['type'] ?? '' ) . ' ' . (string) ( $element['id'] ?? '' ) . ( isset( $element['label'] ) ? ' "' . (string) $element['label'] . '"' : '' );
			array_push( $out, ...self::fields( (array) ( $element['settings'] ?? [] ), '  ' ) );
			array_push( $out, ...self::fields( (array) ( $element['other'] ?? [] ), '  ' ) );
		}
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $fields
	 * @return list<string>
	 */
	private static function fields( array $fields, string $indent ): array {
		$out = [];
		foreach ( $fields as $field ) {
			$out[] = $indent . self::marker( (string) ( $field['op'] ?? 'changed' ) ) . ' ' . (string) ( $field['path'] ?? '' ) . ': ' . self::value( $field['before'] ?? null ) . ' -> ' . self::value( $field['after'] ?? null );
		}
		return $out;
	}

	private static function marker( string $op ): string {
		return [ 'added' => '+', 'removed' => '-', 'moved' => '>' ][ $op ] ?? '~';
	}

	private static function value( mixed $value ): string {
		return null === $value ? '(none)' : (string) ( is_scalar( $value ) ? $value : wp_json_encode( $value ) );
	}
}
