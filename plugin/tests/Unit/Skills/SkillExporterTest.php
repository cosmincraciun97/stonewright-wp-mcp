<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Skills\SkillExporter;
use Stonewright\WpMcp\Skills\SkillImporter;

/**
 * Export exists so a skill can leave the site and come back — or land on
 * another site — without losing where it came from. The file therefore carries
 * provenance and a content hash, and it has to survive its own importer.
 *
 * @covers \Stonewright\WpMcp\Skills\SkillExporter
 */
final class SkillExporterTest extends TestCase {

	private const BODY = "# Spacing rules\n\nKeep the vertical rhythm on a four-point scale.";

	/** @var mixed Saved $wpdb reference restored in tearDown. */
	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_unknown_skill_cannot_be_exported(): void {
		$GLOBALS['wpdb'] = $this->wpdb( [] );

		$result = SkillExporter::markdown( 404 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_skill_not_found', $result->get_error_code() );
	}

	public function test_export_carries_identity_provenance_and_a_content_hash(): void {
		$GLOBALS['wpdb'] = $this->wpdb( [ $this->row() ] );

		$markdown = SkillExporter::markdown( 11 );

		$this->assertIsString( $markdown );
		$front = $this->front_matter( $markdown );

		$this->assertSame( 'Spacing rules', $front['name'] );
		$this->assertSame( 'Use when adjusting spacing on marketing pages.', $front['description'] );
		$this->assertSame( 'spacing-rules', $front['slug'] );
		$this->assertSame( 'user', $front['source'] );
		$this->assertSame( 'active', $front['status'] );
		$this->assertSame( '3', $front['revision'] );
		$this->assertNotSame( '', $front['origin'] );
		$this->assertNotSame( '', $front['exported_at'] );
		$this->assertSame( hash( 'sha256', self::BODY ), $front['content_sha256'] );
	}

	public function test_export_body_is_the_stored_content_and_matches_its_hash(): void {
		$GLOBALS['wpdb'] = $this->wpdb( [ $this->row() ] );

		$markdown = SkillExporter::markdown( 11 );
		$this->assertIsString( $markdown );

		$body = $this->body( $markdown );

		$this->assertSame( self::BODY, $body );
		$this->assertSame( $this->front_matter( $markdown )['content_sha256'], hash( 'sha256', $body ) );
	}

	public function test_export_normalizes_line_endings_and_trailing_whitespace(): void {
		$row            = $this->row();
		$row['content'] = "# Spacing rules\r\n\r\nKeep the vertical rhythm on a four-point scale.   \r\n\r\n\r\n";
		$GLOBALS['wpdb'] = $this->wpdb( [ $row ] );

		$markdown = SkillExporter::markdown( 11 );
		$this->assertIsString( $markdown );

		$this->assertStringNotContainsString( "\r", $markdown );
		$this->assertSame( self::BODY, $this->body( $markdown ) );
		$this->assertStringEndsWith( "\n", $markdown );
	}

	public function test_an_exported_skill_survives_its_own_importer(): void {
		$GLOBALS['wpdb'] = $this->wpdb( [ $this->row() ] );

		$markdown = SkillExporter::markdown( 11 );
		$this->assertIsString( $markdown );

		$report = SkillImporter::inspect( 'spacing-rules.md', $markdown );

		$this->assertIsArray( $report );
		$this->assertSame( 'spacing-rules', $report['slug'] );
		$this->assertSame( [], $report['lint']['errors'] );
		$this->assertSame( [], $report['trust']['findings'] );
		$this->assertTrue(
			$report['collision']['exists'],
			'Re-importing onto the site it came from is a collision, not a silent overwrite.'
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** @return array<string, string> */
	private function front_matter( string $markdown ): array {
		$this->assertStringStartsWith( "---\n", $markdown );
		$end = strpos( $markdown, "\n---\n", 4 );
		$this->assertIsInt( $end );

		$pairs = [];
		foreach ( explode( "\n", substr( $markdown, 4, $end - 4 ) ) as $line ) {
			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $key, $value ] = explode( ':', $line, 2 );
			$pairs[ trim( $key ) ] = trim( $value );
		}

		return $pairs;
	}

	private function body( string $markdown ): string {
		$end = strpos( $markdown, "\n---\n", 4 );
		$this->assertIsInt( $end );

		return trim( substr( $markdown, $end + 5 ) );
	}

	/** @return array<string, mixed> */
	private function row(): array {
		return [
			'id'          => 11,
			'slug'        => 'spacing-rules',
			'title'       => 'Spacing rules',
			'description' => 'Use when adjusting spacing on marketing pages.',
			'content'     => self::BODY,
			'enabled'     => 1,
			'source'      => 'user',
			'status'      => 'active',
			'revision'    => 3,
		];
	}

	/** @param list<array<string, mixed>> $rows */
	private function wpdb( array $rows ): object {
		return new class( $rows ) {
			public string $prefix = 'wp_';
			/** @var list<mixed> */
			private array $last_args = [];

			/** @param list<array<string, mixed>> $rows */
			public function __construct( private array $rows ) {}

			public function get_var( string $q ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $q, mixed ...$args ): string {
				$this->last_args = $args;
				return $q;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $q, string $output = 'OBJECT' ): ?array {
				$needle = (string) ( $this->last_args[0] ?? '' );
				foreach ( $this->rows as $row ) {
					if ( (string) $row['slug'] === $needle || (string) $row['id'] === $needle ) {
						return $row;
					}
				}
				return null;
			}

			/** @return list<array<string, mixed>> */
			public function get_results( string $q, string $output = 'OBJECT' ): array {
				return $this->rows;
			}
		};
	}
}
