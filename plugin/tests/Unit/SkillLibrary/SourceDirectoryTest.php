<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\SourceDirectory;

/** @covers \Stonewright\WpMcp\SkillLibrary\SourceDirectory */
final class SourceDirectoryTest extends TestCase {

	public function test_reserved_and_local_ids_are_not_shadowed(): void {
		$catalog = SourceDirectory::combine( [ [ 'slug' => 'reserved-guide', 'title' => 'Product' ] ], [ [ 'slug' => 'reserved-guide', 'title' => 'Collision' ], [ 'slug' => 'local-guide', 'title' => 'Local' ] ], [ [ 'source_id' => 'example-plugin', 'record' => [ 'slug' => 'local-guide', 'title' => 'External collision' ] ], [ 'source_id' => 'example-plugin', 'record' => [ 'slug' => 'external-guide', 'title' => 'External' ] ] ] );
		$this->assertSame( [ 'Product', 'Local', 'External' ], array_column( $catalog['skills'], 'title' ) );
		$this->assertCount( 2, $catalog['conflicts'] );
		$this->assertSame( [ 'source_id' => 'example-plugin', 'slug' => 'external-guide' ], $catalog['skills'][2]['identity'] );
		$this->assertSame( 'external', $catalog['skills'][2]['source_kind'] );
	}

	public function test_external_identity_is_an_explicit_pair_and_malformed_rows_are_visible(): void {
		$catalog = SourceDirectory::combine( [], [], [ [ 'source_id' => '', 'record' => [ 'slug' => 'example' ] ], [ 'source_id' => 'source-a', 'record' => [ 'slug' => 'example' ] ], [ 'source_id' => 'source-b', 'record' => [ 'slug' => 'example' ] ] ] );
		$this->assertCount( 2, $catalog['skills'] );
		$this->assertCount( 1, $catalog['conflicts'] );
		$this->assertNotSame( $catalog['skills'][0]['identity'], $catalog['skills'][1]['identity'] );
	}

	public function test_external_provenance_cannot_claim_site_ids_or_verification(): void {
		$catalog = SourceDirectory::combine( [], [], [ [ 'source_id' => 'example-plugin', 'record' => [ 'slug' => 'example', 'source' => 'builtin', 'source_kind' => 'local', 'source_id' => 'forged', 'id' => 42, 'verification_count' => 99, 'trust' => [ 'trusted' => true ], 'trusted' => true, 'history' => [ 'forged' ], 'content' => '# Example' ] ] ] );
		$record = $catalog['skills'][0];
		$this->assertSame( 'external', $record['source'] );
		$this->assertSame( 'external', $record['source_kind'] );
		$this->assertSame( 'example-plugin', $record['source_id'] );
		$this->assertSame( 0, $record['verification_count'] );
		foreach ( [ 'id', 'trust', 'trusted', 'history' ] as $claim ) {
			$this->assertArrayNotHasKey( $claim, $record );
		}
		$this->assertSame( '# Example', $record['content'] );
	}
}
