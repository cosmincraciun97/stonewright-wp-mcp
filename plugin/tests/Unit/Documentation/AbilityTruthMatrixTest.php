<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * Asserts that every ability registered in AbilityRegistry::list() appears
 * in docs/ability-truth-matrix.md, and that the matrix is internally consistent.
 *
 * If a slug is registered but not documented the test fails with the missing
 * slug, prompting the developer to regenerate the matrix:
 *
 *   composer docs:matrix
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityTruthMatrixTest extends TestCase {

	private static string $matrix_path;
	private static string $matrix_content;

	/** @var array<array{slug:string,columns:string[]}> */
	private static array $matrix_rows;

	/** @var string */
	private static string $readme_path;

	/** @var string */
	private static string $changelog_path;

	public static function setUpBeforeClass(): void {
		$repo_root          = dirname( __DIR__, 4 );
		self::$matrix_path  = $repo_root . '/docs/ability-truth-matrix.md';
		self::$matrix_content = file_exists( self::$matrix_path )
			? (string) file_get_contents( self::$matrix_path )
			: '';
		self::$matrix_rows  = self::parse_matrix_rows( self::$matrix_content );

		// Paths for stale-text tests.
		self::$readme_path    = dirname( __DIR__, 3 ) . '/README.md';
		self::$changelog_path = $repo_root . '/CHANGELOG.md';
	}

	// -------------------------------------------------------------------------
	// Existing tests (slug presence)
	// -------------------------------------------------------------------------

	public function test_matrix_file_exists(): void {
		self::assertFileExists(
			self::$matrix_path,
			'docs/ability-truth-matrix.md is missing. Run `composer docs:matrix` to generate it.'
		);
	}

	/**
	 * @dataProvider registered_ability_provider
	 */
	public function test_ability_slug_appears_in_matrix( string $slug ): void {
		self::assertStringContainsString(
			$slug,
			self::$matrix_content,
			sprintf(
				"Ability slug '%s' is registered in AbilityRegistry::list() but does not appear in " .
				"docs/ability-truth-matrix.md. Run `composer docs:matrix` to regenerate the matrix.",
				$slug
			)
		);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function registered_ability_provider(): iterable {
		foreach ( AbilityRegistry::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			/** @var \Stonewright\WpMcp\Abilities\Ability $ability */
			$ability = new $class();
			$slug    = $ability->name();
			yield $slug => [ $slug ];
		}
	}

	public function test_all_registered_abilities_are_documented(): void {
		$missing = [];

		foreach ( AbilityRegistry::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			/** @var \Stonewright\WpMcp\Abilities\Ability $ability */
			$ability = new $class();
			$slug    = $ability->name();

			if ( ! str_contains( self::$matrix_content, $slug ) ) {
				$missing[] = $slug;
			}
		}

		self::assertEmpty(
			$missing,
			sprintf(
				"The following %d ability slug(s) are missing from the truth matrix:\n  %s\n\n" .
				"Run `composer docs:matrix` to regenerate docs/ability-truth-matrix.md.",
				count( $missing ),
				implode( "\n  ", $missing )
			)
		);
	}

	// -------------------------------------------------------------------------
	// New tests — column count, Status column, no empty/??? cells
	// -------------------------------------------------------------------------

	public function test_every_ability_row_has_eleven_columns(): void {
		$bad = [];
		foreach ( self::$matrix_rows as $row ) {
			if ( count( $row['columns'] ) !== 11 ) {
				// 11 cells = slug + MCP tool + class + desc + R/W + perm + token + backup + validator + status + tests
				$bad[] = sprintf(
					'%s has %d columns (expected 11)',
					$row['slug'],
					count( $row['columns'] )
				);
			}
		}

		self::assertEmpty(
			$bad,
			"Some matrix rows do not have exactly 11 columns (including MCP Tool and Status):\n  " .
			implode( "\n  ", $bad ) . "\n\nRun `composer docs:matrix` to regenerate."
		);
	}

	public function test_context_bootstrap_row_documents_mcp_tool_name(): void {
		$row = $this->find_row( 'stonewright/context-bootstrap' );

		self::assertNotNull(
			$row,
			'stonewright/context-bootstrap is missing from the matrix. Run `composer docs:matrix`.'
		);
		self::assertSame(
			'`stonewright-context-bootstrap`',
			trim( $row['columns'][1] ?? '' ),
			'Matrix must show the MCP tool name agents actually call.'
		);
	}

	public function test_status_column_contains_at_least_one_experimental(): void {
		$has_experimental = false;
		foreach ( self::$matrix_rows as $row ) {
			// Status is the 10th column (index 9, 0-based).
			if ( isset( $row['columns'][9] ) && trim( $row['columns'][9] ) === 'experimental' ) {
				$has_experimental = true;
				break;
			}
		}

		self::assertTrue(
			$has_experimental,
			'No ability in the truth matrix is marked "experimental". ' .
			'At least the ElementorV4 abilities should be experimental. ' .
			'Run `composer docs:matrix` to regenerate.'
		);
	}

	public function test_status_column_contains_at_least_one_sandboxed(): void {
		$has_sandboxed = false;
		foreach ( self::$matrix_rows as $row ) {
			if ( isset( $row['columns'][9] ) && trim( $row['columns'][9] ) === 'sandboxed' ) {
				$has_sandboxed = true;
				break;
			}
		}

		self::assertTrue(
			$has_sandboxed,
			'No ability in the truth matrix is marked "sandboxed". ' .
			'WidgetDefine and WidgetRegister should be sandboxed. ' .
			'Run `composer docs:matrix` to regenerate.'
		);
	}

	public function test_write_template_row_shows_correct_signals(): void {
		$row = $this->find_row( 'stonewright/fse-write-template' );

		self::assertNotNull(
			$row,
			'stonewright/fse.write_template is missing from the matrix. Run `composer docs:matrix`.'
		);

		// Columns (0-indexed): slug, mcp tool, class, desc, R/W(4), perm(5), token(6), backup(7), validator(8), status(9), tests(10)
		self::assertStringContainsString(
			'can_manage_fse',
			$row['columns'][5] ?? '',
			'WriteTemplate permission column should contain Permissions::can_manage_fse().'
		);
		self::assertSame(
			'Yes',
			trim( $row['columns'][6] ?? '' ),
			'WriteTemplate should have Token=Yes (uses ConfirmationGuard via AbstractTemplateWriter).'
		);
		self::assertSame(
			'Yes',
			trim( $row['columns'][7] ?? '' ),
			'WriteTemplate should have Backup=Yes (calls Backup::snapshot_post via AbstractTemplateWriter).'
		);
	}

	public function test_memory_save_shows_write(): void {
		$row = $this->find_row( 'stonewright/memory-save' );

		self::assertNotNull(
			$row,
			'stonewright/memory.save is missing from the matrix. Run `composer docs:matrix`.'
		);

		self::assertSame(
			'Write',
			trim( $row['columns'][4] ?? '' ),
			'MemorySave R/W column should be "Write" (delegates to Memory::put_typed which uses wpdb->insert/update).'
		);
	}

	public function test_design_apply_to_post_shows_write(): void {
		$row = $this->find_row( 'stonewright/design-apply-to-post' );

		self::assertNotNull(
			$row,
			'stonewright/design-apply-to-post is missing from the matrix. Run `composer docs:matrix`.'
		);

		self::assertSame(
			'Write',
			trim( $row['columns'][4] ?? '' ),
			'ApplyToPost should be Write because it delegates to ElementorWriter::write().'
		);
	}

	public function test_wp_cli_run_shows_write(): void {
		$row = $this->find_row( 'stonewright/wp-cli-run' );

		self::assertNotNull(
			$row,
			'stonewright/wp-cli-run is missing from the matrix. Run `composer docs:matrix`.'
		);

		self::assertSame(
			'Write',
			trim( $row['columns'][4] ?? '' ),
			'WP-CLI Run should be Write because commands may mutate WordPress state through the guarded companion.'
		);
	}

	public function test_workflow_preflight_shows_read_only(): void {
		$row = $this->find_row( 'stonewright/workflow-preflight' );

		self::assertNotNull(
			$row,
			'stonewright/workflow-preflight is missing from the matrix. Run `composer docs:matrix`.'
		);

		self::assertSame(
			'Read',
			trim( $row['columns'][4] ?? '' ),
			'WorkflowPreflight only returns compact context and recommendations; string references to write tools must not mark the ability itself as Write.'
		);
		self::assertStringContainsString(
			'read',
			$row['columns'][5] ?? '',
			'WorkflowPreflight permission column should contain Permissions::read().'
		);
	}

	public function test_no_ability_row_contains_empty_or_placeholder_cells(): void {
		$bad = [];
		foreach ( self::$matrix_rows as $row ) {
			foreach ( $row['columns'] as $idx => $cell ) {
				$trimmed = trim( $cell );
				if ( '' === $trimmed || '???' === $trimmed ) {
					$bad[] = sprintf(
						'%s column %d is empty or "???"',
						$row['slug'],
						$idx
					);
				}
			}
		}

		self::assertEmpty(
			$bad,
			"Some matrix rows have empty or placeholder cells:\n  " . implode( "\n  ", $bad )
		);
	}

	// -------------------------------------------------------------------------
	// Stale text tests (Fix 6 and Fix 7)
	// -------------------------------------------------------------------------

	public function test_readme_does_not_contain_stale_67_abilities(): void {
		if ( ! file_exists( self::$readme_path ) ) {
			self::markTestSkipped( 'plugin/README.md not found.' );
		}
		$content = (string) file_get_contents( self::$readme_path );
		self::assertStringNotContainsString(
			'67 abilities',
			$content,
			'plugin/README.md still contains the stale "67 abilities" text. Regenerate docs and update the README.'
		);
	}

	public function test_changelog_does_not_contain_stale_962_assertions(): void {
		if ( ! file_exists( self::$changelog_path ) ) {
			self::markTestSkipped( 'CHANGELOG.md not found.' );
		}
		$content = (string) file_get_contents( self::$changelog_path );
		self::assertStringNotContainsString(
			'962+',
			$content,
			'CHANGELOG.md still contains the stale "962+" assertion count. Update it to reflect the current test count.'
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse all data rows from the matrix markdown.
	 * Returns rows as { slug, columns[] } where columns are the pipe-separated cells.
	 *
	 * @return array<array{slug:string,columns:string[]}>
	 */
	private static function parse_matrix_rows( string $content ): array {
		$rows  = [];
		$lines = explode( "\n", $content );
		foreach ( $lines as $line ) {
			// Data rows start and end with |, are not headers (contain ---|), not separators.
			if ( ! str_starts_with( trim( $line ), '|' ) ) {
				continue;
			}
			if ( str_contains( $line, '---|' ) || str_contains( $line, '|---|' ) ) {
				continue;
			}
			// Split on | and strip leading/trailing empty strings from the outer pipes.
			$parts = explode( '|', $line );
			// Remove first and last empty strings from outer pipes.
			$parts = array_slice( $parts, 1, count( $parts ) - 2 );

			if ( count( $parts ) < 2 ) {
				continue;
			}

			// Slug cell: looks like ` `stonewright/foo` ` — strip backticks and spaces.
			$slug = trim( $parts[0] ?? '', " `\t" );
			if ( '' === $slug ) {
				continue;
			}
			// Skip header rows: "Slug", "Class", etc.
			if ( in_array( $slug, [ 'Slug', 'Class', 'Description' ], true ) ) {
				continue;
			}

			$rows[] = [
				'slug'    => $slug,
				'columns' => $parts,
			];
		}
		return $rows;
	}

	/**
	 * @return array{slug:string,columns:string[]}|null
	 */
	private function find_row( string $slug ): ?array {
		foreach ( self::$matrix_rows as $row ) {
			if ( trim( $row['slug'], " `\t" ) === $slug ) {
				return $row;
			}
		}
		return null;
	}
}
