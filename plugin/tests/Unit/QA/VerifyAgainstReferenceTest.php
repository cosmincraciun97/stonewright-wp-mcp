<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\QA;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\QA\VerifyAgainstReference;

final class VerifyAgainstReferenceTest extends TestCase {
	public function test_schema_requires_post_or_url_and_reference_label(): void {
		$schema   = ( new VerifyAgainstReference() )->input_schema();
		$required = $schema['required'] ?? [];
		$this->assertContains( 'reference_label', $required );
	}

	public function test_name_uses_stonewright_prefix(): void {
		$this->assertSame( 'stonewright/qa-verify-against-reference', ( new VerifyAgainstReference() )->name() );
	}
}
