<?php
/**
 * Read-only inventory of packaged generic skill entries.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

/** Keeps physical pack paths separate from persistent skill identities. */
final class PackInventory {

	/** @return array{entries: array<int, array<string, mixed>>, diagnostics: array<int, array<string, string>>}|\WP_Error */
	public static function scan( string $root ): array|\WP_Error {
		$resolved = realpath( $root );
		if ( false === $resolved || ! is_dir( $resolved ) || ! is_readable( $resolved ) ) {
			return new \WP_Error( 'stonewright_skill_pack_missing', 'The packaged skills directory is unavailable.' );
		}
		$candidates = [];
		$diagnostics = [];
		foreach ( new \DirectoryIterator( $resolved ) as $directory ) {
			if ( $directory->isDot() ) {
				continue;
			}
			if ( $directory->isLink() ) {
				$diagnostics[] = [ 'pack_key' => $directory->getFilename(), 'code' => 'symbolic_link' ];
				continue;
			}
			if ( ! $directory->isDir() ) {
				continue;
			}
			$key = $directory->getFilename();
			if ( 'playbooks' === $key ) {
				foreach ( new \DirectoryIterator( $directory->getPathname() ) as $playbook ) {
					if ( $playbook->isDot() || ! str_ends_with( $playbook->getFilename(), '.md' ) ) {
						continue;
					}
					$candidates[ 'playbooks/' . substr( $playbook->getFilename(), 0, -3 ) ] = $playbook->getPathname();
				}
			} elseif ( file_exists( $directory->getPathname() . '/SKILL.md' ) ) {
				$candidates[ $key ] = $directory->getPathname() . '/SKILL.md';
			}
		}
		// Digit-only directory names become integer array keys; pack keys are always compared as text.
		ksort( $candidates, SORT_STRING );
		$entries = [];
		foreach ( $candidates as $key => $path ) {
			$key = (string) $key;
			$resolved_file = realpath( $path );
			$prefix = rtrim( str_replace( '\\', '/', $resolved ), '/' ) . '/';
			if ( false === $resolved_file || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path )
				|| ! str_starts_with( str_replace( '\\', '/', $resolved_file ), $prefix ) ) {
				$diagnostics[] = [ 'pack_key' => $key, 'code' => 'unsafe_path' ];
				continue;
			}
			// The length bound prevents product inventory from reading arbitrary-size files.
			$markdown = file_get_contents( $path, false, null, 0, DocumentCodec::MAX_BYTES + 1 );
			if ( ! is_string( $markdown ) ) {
				$diagnostics[] = [ 'pack_key' => $key, 'code' => 'read_failed' ];
				continue;
			}
			$record = DocumentCodec::read( $markdown );
			if ( is_wp_error( $record ) ) {
				$diagnostics[] = [ 'pack_key' => $key, 'code' => $record->get_error_code() ];
				continue;
			}
			$entries[] = [ 'pack_key' => $key, 'kind' => str_starts_with( $key, 'playbooks/' ) ? 'playbook' : 'builtin', 'record' => $record ];
		}
		return [ 'entries' => $entries, 'diagnostics' => $diagnostics ];
	}
}
