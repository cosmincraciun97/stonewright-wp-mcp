<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * An editor script that reads `wp.blocks`, `wp.element` and friends has to
 * declare those script handles as dependencies, otherwise WordPress can run
 * it before `window.wp` exists and the block never registers. WordPress reads
 * the dependency list of a `file:./edit.js` editorScript from the
 * `edit.asset.php` file next to it.
 *
 * @coversNothing
 */
final class BlockEditorScriptDependenciesTest extends TestCase {

	/** `wp.<namespace>` global => script handle that defines it. */
	private const HANDLES = [
		'apiFetch'         => 'wp-api-fetch',
		'blockEditor'      => 'wp-block-editor',
		'blocks'           => 'wp-blocks',
		'components'       => 'wp-components',
		'compose'          => 'wp-compose',
		'data'             => 'wp-data',
		'element'          => 'wp-element',
		'i18n'             => 'wp-i18n',
		'serverSideRender' => 'wp-server-side-render',
	];

	/** @return array<string, array{0: string, 1: string}> */
	public static function editor_scripts(): array {
		$cases = [];
		foreach ( glob( dirname( __DIR__, 2 ) . '/blocks/*/block.json' ) ?: [] as $file ) {
			$metadata = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $metadata ) ) {
				continue;
			}
			foreach ( (array) ( $metadata['editorScript'] ?? [] ) as $script ) {
				if ( is_string( $script ) && str_starts_with( $script, 'file:' ) ) {
					$cases[ basename( dirname( $file ) ) . ' ' . $script ] = [ dirname( $file ), substr( $script, 5 ) ];
				}
			}
		}
		return $cases;
	}

	public function test_the_blocks_register_at_least_one_editor_script(): void {
		self::assertNotEmpty( self::editor_scripts() );
	}

	/**
	 * @dataProvider editor_scripts
	 */
	public function test_an_editor_script_declares_every_wp_global_it_reads( string $dir, string $script ): void {
		$js_path    = $dir . '/' . ltrim( $script, './' );
		$asset_path = substr( $js_path, 0, -3 ) . '.asset.php';

		self::assertFileExists( $js_path );
		self::assertFileExists( $asset_path, 'WordPress reads the script dependencies from the .asset.php file next to the script.' );

		$asset = require $asset_path;
		self::assertIsArray( $asset );
		self::assertIsArray( $asset['dependencies'] ?? null );
		self::assertNotSame( '', (string) ( $asset['version'] ?? '' ) );

		preg_match_all( '/\bwp\.([A-Za-z]+)\b/', (string) file_get_contents( $js_path ), $matches );
		$used = array_values( array_unique( $matches[1] ) );
		self::assertNotEmpty( $used );
		foreach ( $used as $global ) {
			self::assertArrayHasKey( $global, self::HANDLES, 'Unknown wp global "' . $global . '"; add its script handle to HANDLES.' );
			self::assertContains( self::HANDLES[ $global ], $asset['dependencies'], 'wp.' . $global . ' needs the ' . self::HANDLES[ $global ] . ' dependency.' );
		}
	}
}
