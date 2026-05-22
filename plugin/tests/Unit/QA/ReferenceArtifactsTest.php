<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\QA;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\QA\ReferenceArtifacts;

final class ReferenceArtifactsTest extends TestCase {
	public function test_register_and_resolve_returns_artifact_id(): void {
		$id = ReferenceArtifacts::register(
			'home-hero-desktop',
			__DIR__ . '/fixtures/sample.png',
			[ 'viewport' => 'desktop' ]
		);
		$this->assertNotEmpty( $id );
		$meta = ReferenceArtifacts::resolve( 'home-hero-desktop' );
		$this->assertSame( $id, $meta['artifact_id'] );
		$this->assertSame( 'desktop', $meta['viewport'] );
	}

	public function test_resolve_unknown_label_returns_null(): void {
		$this->assertNull( ReferenceArtifacts::resolve( 'never-existed' ) );
	}
}
