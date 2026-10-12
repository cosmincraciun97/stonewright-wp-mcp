<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

/**
 * Every tool name that a bundled skill, a Direct-mode built-in skill, a bundled expertise pack or a
 * specialization names must exist in the frozen contracts, and the skills must not tell an agent to
 * do what the product reserves for an administrator. A renamed or removed ability fails here instead
 * of reaching a site as stale instructions.
 *
 * @coversNothing
 */
final class BundledSkillReferencesTest extends TestCase {

	/** Matches `stonewright/some-ability` and `stonewright-some-tool`. */
	private const TOOL_TOKEN = '/\bstonewright[-\/][a-z0-9]+(?:-[a-z0-9]+)*/';

	/** @var array<string, true> */
	private static array $tools = [];

	/** @var array<string, true> */
	private static array $skill_names = [];

	/** @var array<string, list<string>> text sources keyed by repository-relative path */
	private static array $sources = [];

	public static function setUpBeforeClass(): void {
		$repo = self::repo_root();

		$public = json_decode( (string) file_get_contents( $repo . '/docs/contracts/public-api-v1.json' ), true );
		foreach ( (array) ( $public['abilities'] ?? [] ) as $ability ) {
			self::$tools[ (string) $ability['ability_name'] ] = true;
			self::$tools[ (string) $ability['mcp_name'] ]     = true;
		}
		$direct = json_decode( (string) file_get_contents( $repo . '/docs/contracts/direct-tools-v1.json' ), true );
		foreach ( (array) ( $direct['tools'] ?? [] ) as $tool ) {
			self::$tools[ (string) $tool['name'] ] = true;
		}
		// The companion's local tools (the permanent gateways and the WP-CLI tools) are not abilities.
		$gateways = (string) file_get_contents( $repo . '/companion/src/connection/permanent-gateways.ts' );
		$server   = (string) file_get_contents( $repo . '/companion/src/mcp-server.ts' );
		$block    = (string) substr( $server, (int) strpos( $server, 'const LOCAL_RECOVERY_TOOL_NAMES' ), 600 );
		preg_match_all( "/'(stonewright-[a-z0-9-]+)'/", $gateways . $block, $local );
		foreach ( $local[1] as $name ) {
			self::$tools[ $name ] = true;
		}
		// The Visual server adds its own gateway tool beside the plugin and companion tools.
		preg_match_all( '/name:\s*"(stonewright-[a-z0-9-]+)"/', (string) file_get_contents( $repo . '/visual/src/index.ts' ), $visual );
		foreach ( $visual[1] as $name ) {
			self::$tools[ $name ] = true;
		}

		foreach ( glob( $repo . '/skills/*', GLOB_ONLYDIR ) ?: [] as $dir ) {
			$name = basename( $dir );
			self::$skill_names[ $name ]                  = true;
			self::$skill_names[ 'stonewright-' . $name ] = true;
		}
		foreach ( glob( $repo . '/skills/playbooks/*.md' ) ?: [] as $file ) {
			self::$skill_names[ 'playbook-' . basename( $file, '.md' ) ] = true;
		}

		$files = array_merge(
			self::markdown_under( $repo . '/skills' ),
			self::markdown_under( $repo . '/companion/skills-builtin' ),
			[
				$repo . '/plugin/includes/Expertise/BundledPacks.php',
				$repo . '/plugin/includes/Context/SpecializationCatalog.php',
			]
		);
		foreach ( $files as $file ) {
			self::$sources[ ltrim( str_replace( [ $repo, '\\' ], [ '', '/' ], $file ), '/' ) ] = preg_split( '/\R/', (string) file_get_contents( $file ) ) ?: [];
		}
	}

	public function test_every_tool_named_in_a_skill_pack_or_specialization_exists(): void {
		$unknown = [];
		foreach ( self::$sources as $path => $lines ) {
			foreach ( $lines as $number => $line ) {
				// A docblock annotation such as "@stonewright-status" is not a tool name; blank it out, keeping offsets.
				$line = (string) preg_replace_callback( '/@stonewright-[a-z-]+/', static fn( array $m ): string => str_repeat( ' ', strlen( $m[0] ) ), $line );
				preg_match_all( self::TOOL_TOKEN, $line, $matches, PREG_OFFSET_CAPTURE );
				foreach ( $matches[0] as [ $token, $offset ] ) {
					// A directory in a file path (`~/.codex/skills/stonewright/stonewright/`) is not a tool name.
					if ( $offset > 0 && in_array( $line[ $offset - 1 ], [ '/', '\\', '.' ], true ) ) {
						continue;
					}
					if ( self::is_known( $token, substr( $line, $offset + strlen( $token ), 3 ) ) ) {
						continue;
					}
					$unknown[] = sprintf( '%s:%d %s', $path, $number + 1, $token );
				}
			}
		}

		self::assertSame( [], $unknown, "Names that are neither an ability, a Direct tool nor a skill:\n - " . implode( "\n - ", $unknown ) );
	}

	public function test_no_skill_tells_an_agent_to_switch_to_the_full_profile(): void {
		$offending = [];
		foreach ( self::$sources as $path => $lines ) {
			foreach ( $lines as $number => $line ) {
				if ( preg_match( '/profile["\']?\s*[:=]\s*["\']?full\b|--profile\s+full\b|activate[^.\n]{0,40}\bfull\b profile/i', $line ) ) {
					$offending[] = $path . ':' . ( $number + 1 );
				}
			}
		}

		self::assertSame( [], $offending, 'Only an administrator chooses the Full profile; a skill must not ask an agent to activate it.' );
	}

	public function test_no_skill_places_php_execute_on_the_essential_profile(): void {
		$offending = [];
		foreach ( self::$sources as $path => $lines ) {
			foreach ( $lines as $number => $line ) {
				if ( preg_match( '/php-execute/', $line ) && preg_match( '/\b(?:full\/essential|essential\/full|full or essential|essential or full)\b/i', $line ) ) {
					$offending[] = $path . ':' . ( $number + 1 );
				}
			}
		}

		self::assertSame( [], $offending, 'php-execute is on the full profile only.' );
	}

	public function test_no_skill_uses_a_retired_page_label(): void {
		$retired = [ 'Block Editor Queue' ];
		$found   = [];
		foreach ( self::$sources as $path => $lines ) {
			foreach ( $lines as $number => $line ) {
				foreach ( $retired as $label ) {
					if ( str_contains( $line, $label ) ) {
						$found[] = sprintf( '%s:%d %s', $path, $number + 1, $label );
					}
				}
			}
		}

		self::assertSame( [], $found );
	}

	public function test_the_pack_inventory_names_only_registered_abilities(): void {
		$packs = array_merge( ...array_map( static fn( array $pack ): array => $pack['required_capabilities'], \Stonewright\WpMcp\Expertise\BundledPacks::all() ) );
		foreach ( array_unique( $packs ) as $ability ) {
			self::assertArrayHasKey( $ability, self::$tools, 'Bundled pack requires an unknown ability.' );
		}
	}

	/** A family prefix such as `stonewright/elementor-add-*` is known when a tool starts with it. */
	private static function is_known( string $token, string $next ): bool {
		if ( isset( self::$tools[ $token ] ) || isset( self::$skill_names[ $token ] ) ) {
			return true;
		}
		if ( str_starts_with( $next, '-*' ) || str_starts_with( $next, '*' ) || str_starts_with( $next, '/*' ) ) {
			foreach ( array_keys( self::$tools ) as $tool ) {
				if ( str_starts_with( $tool, $token . '-' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @return list<string> */
	private static function markdown_under( string $dir ): array {
		$found    = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'md' === $file->getExtension() ) {
				$found[] = $file->getPathname();
			}
		}
		sort( $found );
		return $found;
	}

	private static function repo_root(): string {
		return dirname( __DIR__, 4 );
	}
}
