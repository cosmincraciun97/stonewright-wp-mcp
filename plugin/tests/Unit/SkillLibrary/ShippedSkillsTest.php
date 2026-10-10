<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\SensitiveContent;

/**
 * A skill that ships with the product is stored by the pack refresh, which refuses text that looks like credential
 * material; a phrase such as "the token is issued" is enough to make it refuse the whole pack.
 *
 * @covers \Stonewright\WpMcp\Security\SensitiveContent
 */
final class ShippedSkillsTest extends TestCase {

	public function test_no_shipped_skill_reads_as_credential_material(): void {
		$root = dirname( __DIR__, 4 ) . '/skills';
		if ( ! is_dir( $root ) ) {
			self::markTestSkipped( 'The skills folder is not part of this checkout.' );
		}
		$files = glob( $root . '/*/SKILL.md' );
		self::assertNotEmpty( $files );
		foreach ( $files as $file ) {
			self::assertFalse( SensitiveContent::contains( (string) file_get_contents( $file ) ), basename( dirname( $file ) ) . ' reads as credential material.' );
		}
	}
}
