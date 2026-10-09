<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Admin\RescuePage;
use Stonewright\WpMcp\Admin\Setup\SetupTabs;
use Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueConsole;
use Stonewright\WpMcp\Support\PromptCatalog;

/**
 * Keeps the prompt library true to the product.
 *
 * Every tool a prompt names must exist in the surface of the modes the prompt is tagged for, every admin page it
 * points to must be registered, and it must not mention a feature that was removed or renamed. The names come from
 * the generated ability matrix, the Direct tool contract and the companion's own tool lists, never from a list kept
 * here. The checks are plain functions of a prompt list, so the last tests feed them a catalog that is wrong on
 * purpose and expect every kind of drift to be reported.
 *
 * @covers \Stonewright\WpMcp\Support\PromptCatalog
 */
final class PromptCatalogGuardTest extends TestCase {

	private const ROW_KEYS = [ 'id', 'title', 'outcome', 'summary', 'prerequisites', 'modes', 'tools', 'prompt', 'verification' ];

	/** Features that no longer exist, or no longer have a starter, as a pattern and the reason it is refused. */
	private const REMOVED = [
		'/design\s+library/i'                                  => 'The Design Library admin group is disabled.',
		'/design\s+studio/i'                                   => 'The Design Studio page is disabled.',
		'/visual\s+workspace/i'                                => 'The Visual Workspace page is disabled.',
		'/\bblueprints?\b/i'                                   => 'Blueprint starters were removed with the Design Library.',
		'/brand[\s-]+kits?/i'                                  => 'Brand kit starters were removed with the Design Library.',
		'/block\s+editor\s+queue/i'                            => 'The page is the block change queue console, shown as the Block queue tab.',
		'/gutenberg[\s-]+migrat/i'                             => 'There is no Elementor to Gutenberg migration.',
		'/migrat\w*\s+(?:\w+\s+){0,3}(?:to|into)\s+gutenberg/i' => 'There is no Elementor to Gutenberg migration.',
		'/elementor\s*(?:to|->|\x{2192}|-to-)\s*gutenberg/iu'  => 'There is no Elementor to Gutenberg migration.',
		'/\bPRO\b/'                                            => 'Nothing in Stonewright is a paid tier.',
		'/\b(?:upgrade\s+to|coming\s+soon|license\s+key)\b/i'  => 'No upsell or licence text belongs in a starter.',
		'/stonewright[-\/](?:context-bootstrap|workflow-preflight)/' => 'The canonical first call is stonewright-task-start; the others are compatibility paths.',
	];

	protected function setUp(): void {
		MenuRegistry::reset_for_tests();
		// The two pages that register themselves when WordPress initialises.
		RescuePage::add_to_menu_registry();
		QueueConsole::add_to_menu_registry();
	}

	protected function tearDown(): void {
		MenuRegistry::reset_for_tests();
	}

	// ---------------------------------------------------------------------
	// The real catalog.
	// ---------------------------------------------------------------------

	public function test_every_tool_a_prompt_names_exists_in_the_modes_it_is_tagged_for(): void {
		self::assertSame( [], self::tool_problems( PromptCatalog::all() ) );
	}

	public function test_every_tool_is_written_with_its_full_name(): void {
		self::assertSame( [], self::bare_name_problems( PromptCatalog::all() ) );
	}

	public function test_every_admin_page_a_prompt_points_to_is_registered(): void {
		self::assertSame( [], self::page_problems( PromptCatalog::all() ) );
	}

	public function test_no_prompt_mentions_a_removed_or_renamed_feature(): void {
		self::assertSame( [], self::removed_problems( PromptCatalog::all() ) );
	}

	public function test_every_option_a_prompt_names_is_read_by_the_plugin(): void {
		self::assertSame( [], self::option_problems( PromptCatalog::all() ) );
	}

	public function test_prompts_keep_the_page_promise_of_no_site_data(): void {
		self::assertSame( [], self::site_data_problems( PromptCatalog::all() ) );
	}

	public function test_rows_keep_the_fields_the_page_reads(): void {
		$raw = json_decode( (string) file_get_contents( PromptCatalog::catalog_path() ), true );
		self::assertIsArray( $raw );

		$ids = [];
		foreach ( $raw['prompts'] as $row ) {
			self::assertSame( [], array_values( array_diff( array_keys( $row ), self::ROW_KEYS ) ), (string) ( $row['id'] ?? '' ) );
			self::assertArrayHasKey( 'id', $row );
			self::assertArrayNotHasKey( $row['id'], $ids, 'Prompt ids are unique.' );
			$ids[ $row['id'] ] = true;
			foreach ( (array) ( $row['modes'] ?? [ 'plugin' ] ) as $mode ) {
				self::assertContains( $mode, [ 'plugin', 'direct' ], $row['id'] );
			}
		}
	}

	/**
	 * A prompt for a feature of this release exists, so the library cannot fall behind it silently.
	 */
	public function test_the_features_of_this_release_have_a_starter(): void {
		$text = '';
		foreach ( PromptCatalog::all() as $prompt ) {
			$text .= ' ' . self::prompt_text( $prompt );
		}

		$missing = [];
		foreach (
			[
				'stonewright-rescue-status',
				'stonewright-rescue-rollback',
				'stonewright-change-restore',
				'stonewright-change-log',
				'stonewright-section-reuse-find',
				'stonewright-section-reuse-extract',
				'stonewright_section_reuse',
				'stonewright-elementor-native-execute',
				'stonewright-design-direction-brief',
				'repair_of',
				'Stonewright > Rescue',
				'Stonewright > Audit log',
				'profile=inspect',
			] as $feature
		) {
			if ( ! str_contains( $text, $feature ) ) {
				$missing[] = $feature;
			}
		}

		self::assertSame( [], $missing, 'No prompt covers these features of the release.' );
	}

	// ---------------------------------------------------------------------
	// The checks themselves: a catalog that is wrong on purpose.
	// ---------------------------------------------------------------------

	public function test_the_checks_report_every_kind_of_drift(): void {
		$bad = [
			self::row( 'direct-uses-plugin-tool', [ 'direct' ], 'Call stonewright-task-start, then stonewright-site-capabilities.' ),
			self::row( 'unknown-tool', [ 'plugin' ], 'Call stonewright-task-start, then stonewright-made-up-ability.' ),
			self::row( 'renamed-tool', [ 'plugin' ], 'Call stonewright-task-start, then stonewright-elementor-add-google_maps.' ),
			self::row( 'bare-name', [ 'plugin' ], 'Call stonewright-task-start, then wp-cli-status.' ),
			self::row( 'unknown-page', [ 'plugin' ], 'Call stonewright-task-start. Open Stonewright > Marketplace and pick one.' ),
			self::row( 'unknown-subpage', [ 'plugin' ], 'Call stonewright-task-start. Open Stonewright > Rescue > Nowhere.' ),
			self::row( 'unknown-slug', [ 'plugin' ], 'Call stonewright-task-start. Open page=stonewright-design-studio.' ),
			self::row( 'unknown-option', [ 'plugin' ], 'Call stonewright-task-start. Set stonewright_no_such_option to on.' ),
			self::row( 'removed', [ 'plugin' ], 'Call stonewright-task-start. Open the Design Library, migrate the Elementor page to Gutenberg with a brand kit, coming soon in PRO.' ),
			self::row( 'site-data', [ 'plugin' ], 'Call stonewright-task-start. Open https://shop.example.net/wp-admin.' ),
		];

		$tools = implode( "\n", self::tool_problems( $bad ) );
		self::assertStringContainsString( 'direct-uses-plugin-tool: stonewright-site-capabilities', $tools );
		self::assertStringContainsString( 'unknown-tool: stonewright-made-up-ability', $tools );
		self::assertStringContainsString( 'renamed-tool: stonewright-elementor-add-google_maps', $tools );
		self::assertStringNotContainsString( 'stonewright-task-start', $tools );

		self::assertStringContainsString( 'bare-name: wp-cli-status', implode( "\n", self::bare_name_problems( $bad ) ) );

		$pages = implode( "\n", self::page_problems( $bad ) );
		self::assertStringContainsString( 'unknown-page: Stonewright > Marketplace', $pages );
		self::assertStringContainsString( 'unknown-subpage: Stonewright > Rescue > Nowhere', $pages );

		self::assertStringContainsString( 'unknown-slug: stonewright-design-studio', implode( "\n", self::tool_problems( $bad ) ) );
		self::assertStringContainsString( 'unknown-option: stonewright_no_such_option', implode( "\n", self::option_problems( $bad ) ) );

		$removed = implode( "\n", self::removed_problems( $bad ) );
		foreach ( [ 'Design Library admin group', 'Elementor to Gutenberg migration', 'Brand kit starters', 'Nothing in Stonewright is a paid tier', 'No upsell' ] as $reason ) {
			self::assertStringContainsString( $reason, $removed );
		}

		self::assertStringContainsString( 'site-data: https://shop.example.net', implode( "\n", self::site_data_problems( $bad ) ) );
	}

	public function test_the_checks_accept_the_current_page_paths_and_both_tool_name_forms(): void {
		$good = [
			self::row(
				'good',
				[ 'plugin' ],
				'Call stonewright-task-start and stonewright/site-snapshot. Open Stonewright > Rescue, Stonewright > Setup > Settings, '
				. 'Stonewright > Activity > Block queue, Stonewright > Troubleshoot or Stonewright > Audit log.'
			),
			self::row( 'good-direct', [ 'direct' ], 'Call stonewright-task-start, stonewright-site-discover and stonewright-wc-products.' ),
			self::row( 'good-both', [ 'plugin', 'direct' ], 'Call stonewright-task-start, stonewright-wp-cli-status and stonewright-setup-profile.' ),
		];

		self::assertSame( [], self::tool_problems( $good ) );
		self::assertSame( [], self::page_problems( $good ) );
		self::assertSame( [], self::bare_name_problems( $good ) );
	}

	// ---------------------------------------------------------------------
	// The checks.
	// ---------------------------------------------------------------------

	/**
	 * @param list<array<string, mixed>> $prompts
	 * @return list<string>
	 */
	private static function tool_problems( array $prompts ): array {
		$plugin  = self::plugin_tool_names();
		$direct  = self::direct_tool_names();
		$local   = self::companion_local_tool_names();
		$pages   = array_keys( MenuRegistry::pages() );
		$renamed = self::renamed_names();
		self::assertNotEmpty( $plugin );
		self::assertNotEmpty( $direct );
		self::assertContains( 'stonewright-task-start', $local );

		$known_plugin = array_flip( $plugin );
		$known_direct = array_flip( $direct );

		$problems = [];
		foreach ( $prompts as $prompt ) {
			$modes   = (array) ( $prompt['modes'] ?? [ 'plugin' ] );
			$allowed = $local;
			if ( in_array( 'plugin', $modes, true ) ) {
				$allowed = array_merge( $allowed, $plugin );
			}
			if ( in_array( 'direct', $modes, true ) ) {
				$allowed = array_merge( $allowed, $direct );
			}
			$allowed = array_flip( $allowed );

			foreach ( self::tokens( self::prompt_text( $prompt ) . ' ' . implode( ' ', (array) ( $prompt['tools'] ?? [] ) ) ) as $token ) {
				if ( isset( $allowed[ $token ] ) || in_array( $token, $pages, true ) ) {
					continue;
				}
				$hint = '';
				if ( isset( $renamed[ $token ] ) ) {
					$hint = ' (renamed to ' . $renamed[ $token ] . ')';
				} elseif ( isset( $known_plugin[ $token ] ) || isset( $known_direct[ $token ] ) ) {
					$hint = ' (not available in the ' . implode( ' and ', $modes ) . ' mode of this prompt)';
				}
				$problems[ $prompt['id'] . ': ' . $token ] = $prompt['id'] . ': ' . $token . $hint;
			}
		}

		return array_values( $problems );
	}

	/**
	 * Tool names written without the `stonewright-` prefix.
	 *
	 * @param list<array<string, mixed>> $prompts
	 * @return list<string>
	 */
	private static function bare_name_problems( array $prompts ): array {
		$shorts = [];
		foreach ( array_merge( self::plugin_tool_names(), self::direct_tool_names(), self::companion_local_tool_names() ) as $name ) {
			$short = substr( $name, strlen( 'stonewright-' ) );
			if ( str_contains( $short, '-' ) ) {
				$shorts[ $short ] = true;
			}
		}
		$shorts = array_keys( $shorts );
		usort( $shorts, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$pattern = '/(?<![A-Za-z0-9_-])(' . implode( '|', array_map( static fn ( string $s ): string => preg_quote( $s, '/' ), $shorts ) ) . ')(?![A-Za-z0-9_-])/';

		$problems = [];
		foreach ( $prompts as $prompt ) {
			$text = preg_replace( '/(?<![A-Za-z0-9_.\/-])stonewright[-\/][a-z0-9_]+(?:[-\/][a-z0-9_]+)*/', ' ', self::prompt_text( $prompt ) );
			if ( preg_match_all( $pattern, (string) $text, $found ) ) {
				foreach ( array_unique( $found[1] ) as $short ) {
					$problems[] = $prompt['id'] . ': ' . $short . ' (write stonewright-' . $short . ')';
				}
			}
		}

		return $problems;
	}

	/**
	 * "Stonewright > Page" or "Stonewright > Hub > Tab" paths must name a registered page or tab.
	 *
	 * @param list<array<string, mixed>> $prompts
	 * @return list<string>
	 */
	private static function page_problems( array $prompts ): array {
		$problems = [];
		foreach ( $prompts as $prompt ) {
			$text = self::prompt_text( $prompt );
			if ( ! preg_match_all( '/Stonewright\s*(?:>|\x{2192})\s*([^\n.;,:()]+)/u', $text, $found ) ) {
				continue;
			}
			foreach ( $found[0] as $index => $path ) {
				$resolved = self::resolve_page_path( $found[1][ $index ] );
				if ( null !== $resolved ) {
					$problems[] = $prompt['id'] . ': ' . trim( 'Stonewright > ' . $resolved );
				}
			}
		}

		return $problems;
	}

	/**
	 * @param list<array<string, mixed>> $prompts
	 * @return list<string>
	 */
	private static function removed_problems( array $prompts ): array {
		$renamed  = self::renamed_names();
		$problems = [];
		foreach ( $prompts as $prompt ) {
			$text = self::prompt_text( $prompt );
			foreach ( self::REMOVED as $pattern => $reason ) {
				if ( 1 === preg_match( $pattern, $text, $match ) ) {
					$problems[] = $prompt['id'] . ': "' . trim( $match[0] ) . '" - ' . $reason;
				}
			}
			foreach ( self::tokens( $text ) as $token ) {
				if ( isset( $renamed[ $token ] ) ) {
					$problems[] = $prompt['id'] . ': ' . $token . ' was renamed to ' . $renamed[ $token ];
				}
			}
		}

		return $problems;
	}

	/**
	 * Option names (`stonewright_*`) must be strings the plugin source uses.
	 *
	 * @param list<array<string, mixed>> $prompts
	 * @return list<string>
	 */
	private static function option_problems( array $prompts ): array {
		$names = [];
		foreach ( $prompts as $prompt ) {
			if ( preg_match_all( '/(?<![A-Za-z0-9_-])stonewright_[a-z0-9_]+/', self::prompt_text( $prompt ), $found ) ) {
				foreach ( $found[0] as $name ) {
					$names[ $name ][] = (string) $prompt['id'];
				}
			}
		}
		if ( [] === $names ) {
			return [];
		}

		$source = '';
		$files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$source .= (string) file_get_contents( $file->getPathname() );
			}
		}

		$problems = [];
		foreach ( $names as $name => $ids ) {
			if ( ! str_contains( $source, "'" . $name . "'" ) && ! str_contains( $source, '"' . $name . '"' ) ) {
				$problems[] = implode( ', ', array_unique( $ids ) ) . ': ' . $name;
			}
		}

		return $problems;
	}

	/**
	 * @param list<array<string, mixed>> $prompts
	 * @return list<string>
	 */
	private static function site_data_problems( array $prompts ): array {
		$problems = [];
		foreach ( $prompts as $prompt ) {
			if ( preg_match_all( '#https?://[^\s"\')]+#i', self::prompt_text( $prompt ), $found ) ) {
				foreach ( $found[0] as $url ) {
					if ( ! preg_match( '#^https?://(?:[a-z0-9-]+\.)*example\.(?:com|test|org)\b#i', $url ) ) {
						$problems[] = $prompt['id'] . ': ' . $url;
					}
				}
			}
		}

		return $problems;
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * @param list<string> $modes
	 * @return array<string, mixed>
	 */
	private static function row( string $id, array $modes, string $prompt ): array {
		return [
			'id'            => $id,
			'title'         => $id,
			'outcome'       => 'inspect',
			'summary'       => '',
			'prerequisites' => [],
			'modes'         => $modes,
			'tools'         => [ 'stonewright-task-start' ],
			'prompt'        => $prompt,
			'verification'  => '',
		];
	}

	/**
	 * Everything a reader of the card sees.
	 *
	 * @param array<string, mixed> $prompt
	 */
	private static function prompt_text( array $prompt ): string {
		return implode(
			"\n",
			array_merge(
				[ (string) ( $prompt['title'] ?? '' ), (string) ( $prompt['summary'] ?? '' ), (string) ( $prompt['prompt'] ?? '' ), (string) ( $prompt['verification'] ?? '' ) ],
				array_map( 'strval', (array) ( $prompt['prerequisites'] ?? [] ) )
			)
		);
	}

	/**
	 * Tool names in either form (`stonewright-x-y` or `stonewright/x-y`), as MCP names.
	 *
	 * @return list<string>
	 */
	private static function tokens( string $text ): array {
		if ( ! preg_match_all( '/(?<![A-Za-z0-9_.\/-])stonewright[-\/][a-z0-9_]+(?:[-\/][a-z0-9_]+)*/', $text, $found ) ) {
			return [];
		}

		return array_values( array_unique( array_map( static fn ( string $token ): string => str_replace( '/', '-', $token ), $found[0] ) ) );
	}

	/**
	 * Resolves what follows "Stonewright >". Returns null when it is a page or tab that exists, otherwise the text
	 * that does not resolve.
	 */
	private static function resolve_page_path( string $rest ): ?string {
		$rest   = trim( $rest );
		$first  = self::longest_label( $rest, self::page_labels() );
		if ( null === $first ) {
			return $rest;
		}
		$after = ltrim( substr( $rest, strlen( $first ) ) );
		if ( '' === $after || ! preg_match( '/^(?:>|\x{2192})\s*(.+)$/su', $after, $next ) ) {
			return null;
		}

		$second = self::longest_label( trim( $next[1] ), self::tab_labels( $first ) );
		if ( null === $second ) {
			return $first . ' > ' . trim( $next[1] );
		}

		return null;
	}

	/**
	 * The longest label that opens the text and ends on a word boundary.
	 *
	 * @param list<string> $labels
	 */
	private static function longest_label( string $text, array $labels ): ?string {
		usort( $labels, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		foreach ( $labels as $label ) {
			if ( 0 === stripos( $text, $label ) && ( strlen( $text ) === strlen( $label ) || ! ctype_alnum( $text[ strlen( $label ) ] ) ) ) {
				return substr( $text, 0, strlen( $label ) );
			}
		}

		return null;
	}

	/**
	 * Every name a page goes by in the sidebar, the tab bar or its heading, and the hub names.
	 *
	 * @return list<string>
	 */
	private static function page_labels(): array {
		$labels = array_column( MenuRegistry::hubs(), 'label' );
		foreach ( MenuRegistry::entries() as $entry ) {
			$labels[] = $entry['label'];
			$labels[] = $entry['title'];
			if ( '' !== $entry['menu_label'] ) {
				$labels[] = $entry['menu_label'];
			}
		}

		return array_values( array_unique( array_filter( $labels ) ) );
	}

	/**
	 * The tabs under a hub or page: the other pages of its hub, and for Setup its four views.
	 *
	 * @return list<string>
	 */
	private static function tab_labels( string $first ): array {
		$hubs = [];
		foreach ( MenuRegistry::hubs() as $hub ) {
			if ( 0 === strcasecmp( $hub['label'], $first ) ) {
				$hubs[] = $hub['id'];
			}
		}
		foreach ( MenuRegistry::entries() as $entry ) {
			if ( 0 === strcasecmp( $entry['label'], $first ) || 0 === strcasecmp( $entry['title'], $first ) ) {
				$hubs[] = $entry['hub'];
			}
		}

		$labels = [];
		foreach ( array_unique( $hubs ) as $hub ) {
			foreach ( MenuRegistry::hub_entries( $hub ) as $entry ) {
				$labels[] = $entry['label'];
			}
			if ( 'setup' === $hub ) {
				foreach ( SetupTabs::ids() as $view ) {
					$labels[] = ucfirst( str_replace( '-', ' ', $view ) );
				}
			}
		}

		return array_values( array_unique( $labels ) );
	}

	/** @return list<string> MCP names of the plugin abilities, from the generated matrix. */
	private static function plugin_tool_names(): array {
		$matrix = (string) file_get_contents( dirname( __DIR__, 4 ) . '/docs/ability-truth-matrix.md' );
		$names  = [];
		foreach ( explode( "\n", $matrix ) as $line ) {
			if ( ! str_starts_with( trim( $line ), '|' ) || str_contains( $line, '---|' ) ) {
				continue;
			}
			$parts = array_slice( explode( '|', $line ), 1, -1 );
			$name  = trim( $parts[1] ?? '', " `\t" );
			if ( str_starts_with( $name, 'stonewright-' ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/** @return list<string> The Direct tool contract the companion generates. */
	private static function direct_tool_names(): array {
		$contract = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/docs/contracts/direct-tools-v1.json' ), true );
		self::assertIsArray( $contract );

		return array_values( array_map( static fn ( array $tool ): string => (string) $tool['name'], (array) $contract['tools'] ) );
	}

	/**
	 * Tools the companion process registers itself, in both modes: the permanent gateways and the WP-CLI tools.
	 *
	 * @return list<string>
	 */
	private static function companion_local_tool_names(): array {
		$root  = dirname( __DIR__, 4 ) . '/companion/src/';
		$names = [];
		foreach ( [ 'connection/permanent-gateways.ts' => 'PERMANENT_GATEWAY_TOOL_NAMES', 'mcp-server.ts' => 'LOCAL_RECOVERY_TOOL_NAMES' ] as $file => $const ) {
			$source = (string) file_get_contents( $root . $file );
			if ( 1 === preg_match( '/const\s+' . $const . '\s*=\s*\[(.*?)\]\s*as\s+const/s', $source, $block ) ) {
				preg_match_all( "/'(stonewright-[a-z0-9-]+)'/", $block[1], $found );
				$names = array_merge( $names, $found[1] );
			}
		}

		return array_values( array_unique( $names ) );
	}

	/** @return array<string, string> Old MCP name to new MCP name, from the public API contract's allowlist. */
	private static function renamed_names(): array {
		$contract = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/docs/contracts/public-api-v1.json' ), true );
		self::assertIsArray( $contract );

		$out = [];
		foreach ( (array) ( $contract['allowlist']['renamed'] ?? [] ) as $old => $new ) {
			$out[ str_replace( '/', '-', (string) $old ) ] = str_replace( '/', '-', (string) $new );
		}
		foreach ( (array) ( $contract['allowlist']['removed'] ?? [] ) as $old ) {
			$out[ str_replace( '/', '-', (string) $old ) ] = '(removed)';
		}

		return $out;
	}
}
