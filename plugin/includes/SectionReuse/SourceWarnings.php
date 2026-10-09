<?php
/**
 * Warnings about the source post of a reuse candidate.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * A draft or a password-protected page is a source section reuse offers, and its text is not public. Copying a
 * section from one can put that text on a page visitors can read, so find and extract say so before the copy.
 * The warning names the source post and never carries the password.
 */
final class SourceWarnings {

	public const DRAFT              = 'draft_source';
	public const PASSWORD_PROTECTED = 'password_protected_source';

	/**
	 * @return list<array{code:string,count:int,items:list<string>}>
	 */
	public static function for_post( object $post ): array {
		$warnings = [];
		$id       = (string) (int) ( $post->ID ?? 0 );
		if ( 'draft' === SectionSource::field( $post, 'post_status' ) ) {
			$warnings[] = [ 'code' => self::DRAFT, 'count' => 1, 'items' => [ $id ] ];
		}
		if ( '' !== SectionSource::field( $post, 'post_password' ) ) {
			$warnings[] = [ 'code' => self::PASSWORD_PROTECTED, 'count' => 1, 'items' => [ $id ] ];
		}

		return $warnings;
	}
}
