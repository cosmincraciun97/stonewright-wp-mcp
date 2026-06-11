<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ComposerPolicyTest extends TestCase {

	public function test_abilities_api_compatibility_dependency_has_explicit_audit_policy(): void {
		$composer_path = dirname( __DIR__, 3 ) . '/composer.json';
		self::assertFileExists( $composer_path );

		$composer = json_decode( (string) file_get_contents( $composer_path ), true, 512, JSON_THROW_ON_ERROR );

		self::assertSame(
			'^0.1.0 || ^1.0',
			$composer['require']['wordpress/abilities-api'] ?? null,
			'Stonewright keeps the Abilities API package for pre-core compatibility until WordPress core provides the API everywhere it supports.'
		);
		self::assertSame(
			'report',
			$composer['config']['audit']['abandoned'] ?? null,
			'Composer audit must report the abandoned compatibility package without failing releases that have zero vulnerability advisories.'
		);
		self::assertSame(
			'composer audit --abandoned=report',
			$composer['scripts']['dependencies:audit'] ?? null,
			'Release checks need an explicit Composer dependency audit command.'
		);
	}
}
