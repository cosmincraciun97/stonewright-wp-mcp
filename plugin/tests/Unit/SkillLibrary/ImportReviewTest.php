<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\ImportReview;

/** @covers \Stonewright\WpMcp\SkillLibrary\ImportReview */
final class ImportReviewTest extends TestCase {

	private const DOCUMENT = "---\nname: Example\ndescription: Use when writing examples.\nenabled: true\nsource: builtin\n---\n# Example\n";

	public function test_confirmation_rederives_a_disabled_draft_despite_forged_claims(): void {
		$review = ImportReview::examine( 'example.md', self::DOCUMENT );
		$this->assertIsArray( $review );
		$review['record']['source'] = 'builtin';
		$review['record']['enabled'] = true;
		$review['record']['content'] = 'Forged body';
		$review['lint'] = [ 'errors' => [], 'warnings' => [] ];
		$record = ImportReview::confirm( $review );
		$this->assertIsArray( $record );
		$this->assertSame( "# Example\n", $record['content'] );
		$this->assertSame( 'uploaded', $record['source'] );
		$this->assertSame( 'draft', $record['status'] );
		$this->assertFalse( $record['enabled'] );
		$this->assertFalse( $record['enable_agentic'] );
		$this->assertFalse( $record['enable_prompt'] );
	}

	public function test_hash_binds_the_exact_reviewed_bytes(): void {
		$review = ImportReview::examine( 'example.md', self::DOCUMENT );
		$this->assertIsArray( $review );
		$this->assertSame( hash( 'sha256', self::DOCUMENT ), $review['content_hash'] );
		$review['content'] .= 'Changed';
		$this->assertInstanceOf( \WP_Error::class, ImportReview::confirm( $review ) );
	}

	/** @dataProvider invalid_file_names */
	public function test_file_name_cannot_be_a_path_or_nonmarkdown_file( string $filename ): void {
		$this->assertInstanceOf( \WP_Error::class, ImportReview::examine( $filename, self::DOCUMENT ) );
	}

	public static function invalid_file_names(): array {
		return [ [ '../example.md' ], [ 'C:\\example.md' ], [ 'example.php' ], [ '' ] ];
	}

	public function test_no_private_content_is_persisted_during_inspection(): void {
		$review = ImportReview::examine( 'example.md', self::DOCUMENT );
		$this->assertIsArray( $review );
		$this->assertSame( 'example', $review['slug'] );
		$this->assertArrayHasKey( 'trust', $review );
		$this->assertArrayHasKey( 'lint', $review );
		$this->assertArrayNotHasKey( 'skill_id', $review );
	}
}
