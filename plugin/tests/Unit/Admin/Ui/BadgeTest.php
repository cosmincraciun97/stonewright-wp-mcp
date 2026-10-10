<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Badge
 */
final class BadgeTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	/** @dataProvider badges */
	public function test_a_badge_says_its_state_in_a_word( array $args, string $expected ): void {
		self::assertSame( $expected, Badge::render( 'Active', $args ) );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: string}> */
	public static function badges(): array {
		return [
			'neutral is the default'  => [ [], '<span class="sw-ui-badge">Active</span>' ],
			'ok'                      => [ [ 'variant' => 'ok' ], '<span class="sw-ui-badge sw-ui-badge--ok">Active</span>' ],
			'warn'                    => [ [ 'variant' => 'warn' ], '<span class="sw-ui-badge sw-ui-badge--warn">Active</span>' ],
			'danger'                  => [ [ 'variant' => 'danger' ], '<span class="sw-ui-badge sw-ui-badge--danger">Active</span>' ],
			'info'                    => [ [ 'variant' => 'info' ], '<span class="sw-ui-badge sw-ui-badge--info">Active</span>' ],
			'accent with a dot'       => [ [ 'variant' => 'accent', 'dot' => true ], '<span class="sw-ui-badge sw-ui-badge--accent sw-ui-badge--dot">Active</span>' ],
			'unknown is neutral'      => [ [ 'variant' => 'sparkly' ], '<span class="sw-ui-badge">Active</span>' ],
			'with an icon'            => [ [ 'variant' => 'ok', 'icon' => 'check' ], '<span class="sw-ui-badge sw-ui-badge--ok"><svg class="sw-ui-icon" aria-hidden="true"><use href="#sw-ui-icon-check"></use></svg>Active</span>' ],
		];
	}

	public function test_the_word_is_escaped_and_attributes_are_filtered(): void {
		$html = Badge::render( '<b>x</b>', [ 'attrs' => [ 'title' => 'Mode', 'onclick' => 'evil()', 'class' => 'x' ] ] );

		self::assertSame( '<span class="sw-ui-badge" title="Mode">&lt;b&gt;x&lt;/b&gt;</span>', $html );
	}

	public function test_a_tag_is_an_outline_that_never_carries_a_state(): void {
		self::assertSame( '<span class="sw-ui-tag">Command line</span>', Badge::tag( 'Command line' ) );
		self::assertSame( '<span class="sw-ui-tag">&lt;i&gt;</span>', Badge::tag( '<i>' ) );
	}

	public function test_a_count_is_a_tabular_number(): void {
		self::assertSame( '<span class="sw-ui-count sw-ui-num">3</span>', Badge::count( 3 ) );
		self::assertSame( '<span class="sw-ui-count sw-ui-num">99+</span>', Badge::count( '99+' ) );
	}

	public function test_a_status_is_a_dot_and_a_word(): void {
		self::assertSame( '<span class="sw-ui-status sw-ui-status--ok">Active</span>', Badge::status( 'Active', 'ok' ) );
		self::assertSame( '<span class="sw-ui-status sw-ui-status--danger">Crashed</span>', Badge::status( 'Crashed', 'danger' ) );
		self::assertSame( '<span class="sw-ui-status">Idle</span>', Badge::status( 'Idle' ) );
		self::assertSame( '<span class="sw-ui-status">Idle</span>', Badge::status( 'Idle', 'info' ), 'Only ok, warn and danger have a dot colour.' );
	}
}
