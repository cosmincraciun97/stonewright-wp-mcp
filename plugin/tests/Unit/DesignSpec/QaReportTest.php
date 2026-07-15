<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\DesignSpec;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignSpec\QaReport;

/**
 * @covers \Stonewright\WpMcp\DesignSpec\QaReport
 */
final class QaReportTest extends TestCase {

	public function test_good_spec_scores_high(): void {
		$spec = [
			'tokens'   => [
				'colors' => [ 'text' => '#111827', 'primary' => '#4F46E5' ],
			],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[ 'type' => 'heading', 'level' => 1, 'text' => 'Hello world landing' ],
						[ 'type' => 'paragraph', 'text' => 'Body copy that is long enough for the page.' ],
						[ 'type' => 'image', 'url' => 'https://example.com/a.jpg', 'alt' => 'Hero photo' ],
						[ 'type' => 'button', 'text' => 'Primary' ],
					],
				],
				[
					'id'     => 'more',
					'blocks' => [
						[ 'type' => 'heading', 'level' => 2, 'text' => 'Second section' ],
						[ 'type' => 'image', 'url' => 'https://example.com/b.jpg', 'alt' => 'Detail' ],
						[ 'type' => 'paragraph', 'text' => 'More copy for thickness.' ],
					],
				],
			],
		];
		$qa = QaReport::for_spec( $spec );
		self::assertSame( 100, $qa['score'] );
		self::assertSame( [], $qa['issues'] );
	}

	public function test_flags_missing_alt_and_no_media(): void {
		$spec = [
			'sections' => [
				[
					'id'     => 'only',
					'blocks' => [
						[ 'type' => 'heading', 'level' => 1, 'text' => 'Title only thin' ],
					],
				],
			],
		];
		$qa    = QaReport::for_spec( $spec );
		$codes = array_column( $qa['issues'], 'code' );
		self::assertContains( 'no_media', $codes );
		self::assertContains( 'section_too_thin', $codes );
		self::assertLessThan( 100, $qa['score'] );
	}
}
