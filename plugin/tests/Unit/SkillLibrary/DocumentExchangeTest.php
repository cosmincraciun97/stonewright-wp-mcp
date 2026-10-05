<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\DocumentCodec;

/** @covers \Stonewright\WpMcp\SkillLibrary\DocumentCodec */
final class DocumentExchangeTest extends TestCase {

	public function test_reads_public_pack_front_matter_without_changing_body(): void {
		$markdown = "---\r\nname: Example workflow\r\ndescription: >\r\n  Use when writing\r\n  example content.\r\nenable_agentic: false\r\nenable_prompt: true\r\nversion_constraints: {\"elementor\": \">=3.16\"}\r\n---\r\n\r\n# Example\r\n\r\nKeep `body` text.\r\n";
		$record = DocumentCodec::read( $markdown, 'example-workflow' );
		$this->assertIsArray( $record );
		$this->assertSame( 'example-workflow', $record['slug'] );
		$this->assertSame( 'Example workflow', $record['title'] );
		$this->assertSame( 'Use when writing example content.', $record['description'] );
		$this->assertSame( "# Example\n\nKeep `body` text.\n", $record['content'] );
		$this->assertFalse( $record['metadata']['enable_agentic'] );
		$this->assertTrue( $record['metadata']['enable_prompt'] );
		$this->assertSame( [ 'elementor' => '>=3.16' ], $record['metadata']['version_constraints'] );
	}

	public function test_quotes_and_literal_blocks_are_data(): void {
		$record = DocumentCodec::read( "---\nname: 'Editor''s guide'\ndescription: |\n  Use with examples.\n  Keep both lines.\ntopic: \"Editor: example\"\n---\nBody\n" );
		$this->assertIsArray( $record );
		$this->assertSame( "Editor's guide", $record['title'] );
		$this->assertSame( "Use with examples.\nKeep both lines.", $record['description'] );
		$this->assertSame( 'Editor: example', $record['metadata']['topic'] );
	}

	/** @dataProvider invalid_documents */
	public function test_rejects_ambiguous_or_nontext_documents( string $markdown ): void {
		$result = DocumentCodec::read( $markdown );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_skill_document_invalid', $result->get_error_code() );
	}

	public static function invalid_documents(): array {
		return [
			'no metadata' => [ '# Body' ],
			'missing description' => [ "---\nname: Example\n---\nBody" ],
			'duplicate key' => [ "---\nname: Example\nname: Other\ndescription: Use with examples.\n---\nBody" ],
			'YAML alias' => [ "---\nname: *alias\ndescription: Use with examples.\n---\nBody" ],
			'YAML object tag' => [ "---\nname: !!php/object:example\ndescription: Use with examples.\n---\nBody" ],
			'nested YAML' => [ "---\nname: Example\ndescription:\n  key: value\n---\nBody" ],
			'bad JSON' => [ "---\nname: Example\ndescription: Use with examples.\nversion_constraints: {bad}\n---\nBody" ],
			'constraints array' => [ "---\nname: Example\ndescription: Use with examples.\nversion_constraints: [\"example\"]\n---\nBody" ],
			'invalid flag' => [ "---\nname: Example\ndescription: Use with examples.\nenable_agentic: perhaps\n---\nBody" ],
			'binary' => [ "---\nname: Example\ndescription: Use with examples.\n---\nBody\0" ],
			'invalid UTF-8' => [ "---\nname: Example\ndescription: Use with examples.\n---\n\xff" ],
		];
	}

	public function test_accepts_limit_and_rejects_one_byte_more(): void {
		$header = "---\nname: Example\ndescription: Use with examples.\n---\n";
		$at_limit = $header . str_repeat( 'a', 1048576 - strlen( $header ) );
		$this->assertIsArray( DocumentCodec::read( $at_limit ) );
		$this->assertInstanceOf( \WP_Error::class, DocumentCodec::read( $at_limit . 'a' ) );
	}

	public function test_export_round_trip_carries_hash_and_inert_provenance(): void {
		$record = [ 'slug' => 'example-guide', 'title' => 'Example "guide"', 'description' => 'Use when writing examples.', 'content' => "# Example\n", 'source' => 'user', 'id' => 42 ];
		$markdown = DocumentCodec::write( $record );
		$this->assertStringContainsString( hash( 'sha256', "# Example\n" ), $markdown );
		$decoded = DocumentCodec::read( $markdown );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'Example "guide"', $decoded['title'] );
		$this->assertSame( "# Example\n", $decoded['content'] );
		$this->assertArrayNotHasKey( 'source', $decoded );
		$this->assertArrayNotHasKey( 'id', $decoded );
	}
}
