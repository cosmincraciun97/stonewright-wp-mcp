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

	public function test_php_sources_do_not_use_php_82_literal_true_type(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$iterator    = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $plugin_root, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $file ): bool {
					return ! in_array( $file->getFilename(), [ 'vendor', '.phpunit.cache' ], true );
				}
			)
		);
		$violations  = [];
		$literal     = 'tr' . 'ue';

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$path = $file->getPathname();
			if ( __FILE__ === $path ) {
				continue;
			}

			$lines = file( $path, FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				continue;
			}

			foreach ( $lines as $line_number => $line ) {
				if ( 1 !== preg_match( '/\b(?:function|fn)\b.*\)\s*:\s*(.+)/', $line, $matches ) ) {
					continue;
				}

				$return_type = preg_split( '/\s*(?:\{|=>|;)/', $matches[1], 2 )[0] ?? '';
				if ( 1 === preg_match( '/\b' . $literal . '\b/', $return_type ) ) {
					$violations[] = str_replace( $plugin_root . DIRECTORY_SEPARATOR, '', $path ) . ':' . ( $line_number + 1 );
				}
			}
		}

		self::assertSame(
			[],
			$violations,
			'Stonewright supports PHP 8.1, so source and test stubs must not use the PHP 8.2 literal true type.'
		);
	}
}
