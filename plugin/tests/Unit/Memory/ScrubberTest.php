<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Memory\Scrubber;

/**
 * Memory rows accumulate the site they were learned on: hostnames, post ids,
 * customer email addresses. The lesson is usually portable; the identifiers
 * never are. The scrubber has to strip the identifiers without destroying the
 * lesson, and it has to be idempotent so a resumed sweep cannot corrode text
 * it already cleaned.
 *
 * @covers \Stonewright\WpMcp\Memory\Scrubber
 */
final class ScrubberTest extends TestCase {

	/**
	 * @dataProvider text_cases
	 */
	public function test_scrub_text_removes_site_identity( string $input, string $expected ): void {
		self::assertSame( $expected, Scrubber::scrub_text( $input ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function text_cases(): array {
		return [
			'absolute url'        => [
				'Hero lives at https://client-a.test/about and loads slowly.',
				'Hero lives at https://' . Scrubber::HOST_PLACEHOLDER . '/about and loads slowly.',
			],
			'url with port'       => [
				'Staging is http://client-a.test:8443/wp-admin',
				'Staging is http://' . Scrubber::HOST_PLACEHOLDER . '/wp-admin',
			],
			'bare host'          => [
				'Client asked to keep client-a.test branding.',
				'Client asked to keep ' . Scrubber::HOST_PLACEHOLDER . ' branding.',
			],
			'email'              => [
				'Escalate to admin@client-a.test first.',
				'Escalate to ' . Scrubber::EMAIL_PLACEHOLDER . ' first.',
			],
			'post id assignment' => [
				'Fix post_id=4821 before touching the kit.',
				'Fix post_id=' . Scrubber::ID_PLACEHOLDER . ' before touching the kit.',
			],
			'prose id'           => [
				'Page 4821 and post 93 both regressed.',
				'Page ' . Scrubber::ID_PLACEHOLDER . ' and post ' . Scrubber::ID_PLACEHOLDER . ' both regressed.',
			],
			'hash id'            => [
				'Widget #7d3f9a1 broke, see element id: 512.',
				'Widget #7d3f9a1 broke, see element id: ' . Scrubber::ID_PLACEHOLDER . '.',
			],
			'lesson survives'    => [
				'Never double-encode _elementor_data.',
				'Never double-encode _elementor_data.',
			],
			'file names kept'    => [
				'The failure is in class-wp-query.php line 220.',
				'The failure is in class-wp-query.php line 220.',
			],
		];
	}

	/**
	 * @dataProvider text_cases
	 */
	public function test_scrub_text_is_idempotent( string $input, string $expected ): void {
		// A sweep can be resumed, retried, or run twice on the same row. Running
		// the scrubber on its own output must be a no-op, otherwise placeholders
		// would themselves get rewritten and the text would rot.
		self::assertSame( $expected, Scrubber::scrub_text( Scrubber::scrub_text( $input ) ) );
	}

	public function test_scrub_value_walks_nested_structures(): void {
		$value = [
			'lesson'  => 'Use https://client-a.test/wp-json for probing.',
			'context' => [
				'post_id'   => 4821,
				'permalink' => 'https://client-a.test/offers',
				'depth'     => 3,
				'ids'       => [ 11, 12 ],
			],
			'ok'      => true,
		];

		self::assertSame(
			[
				'lesson'  => 'Use https://' . Scrubber::HOST_PLACEHOLDER . '/wp-json for probing.',
				'context' => [
					'post_id'   => 0,
					'permalink' => 'https://' . Scrubber::HOST_PLACEHOLDER . '/offers',
					'depth'     => 3,
					'ids'       => [ 0, 0 ],
				],
				'ok'      => true,
			],
			Scrubber::scrub_value( $value )
		);
	}

	public function test_scrub_value_leaves_non_identifying_scalars_alone(): void {
		self::assertSame( 42, Scrubber::scrub_value( 42 ) );
		self::assertSame( 1.5, Scrubber::scrub_value( 1.5 ) );
		self::assertNull( Scrubber::scrub_value( null ) );
		self::assertFalse( Scrubber::scrub_value( false ) );
	}

	public function test_plan_generalizes_a_portable_lesson_to_global_scope(): void {
		$plan = Scrubber::plan(
			[
				'id'         => 7,
				'type'       => 'feedback',
				'scope'      => 'client-a.test',
				'memory_key' => 'hero-spacing-client-a.test',
				'name'       => 'Hero spacing on client-a.test',
				'topic'      => 'elementor',
				'value'      => [ 'lesson' => 'Client hates hero padding under 48px, see post 4821.' ],
			]
		);

		self::assertNotNull( $plan );
		self::assertSame( 7, $plan['id'] );
		self::assertSame( 'generalize', $plan['action'] );
		self::assertSame( '_global', $plan['changes']['scope'] );
		self::assertSame( 'hero-spacing-' . Scrubber::HOST_PLACEHOLDER, $plan['changes']['memory_key'] );
		self::assertSame( 'Hero spacing on ' . Scrubber::HOST_PLACEHOLDER, $plan['changes']['name'] );
		self::assertSame(
			[ 'lesson' => 'Client hates hero padding under 48px, see post ' . Scrubber::ID_PLACEHOLDER . '.' ],
			$plan['changes']['value']
		);
	}

	public function test_plan_never_moves_a_site_observation_out_of_its_scope(): void {
		$plan = Scrubber::plan(
			[
				'id'         => 8,
				'type'       => 'project',
				'scope'      => 'client-a.test',
				'memory_key' => 'homepage-uses-client-a.test-kit',
				'name'       => 'Homepage kit',
				'topic'      => '',
				'value'      => [ 'note' => 'Homepage hero pulls the kit from https://client-a.test/kit.' ],
			]
		);

		self::assertNotNull( $plan );
		self::assertSame( 'generalize', $plan['action'] );
		// A project row describes THIS site. Its identifiers get scrubbed, but
		// promoting it to _global would assert it about every site.
		self::assertArrayNotHasKey( 'scope', $plan['changes'] );
	}

	public function test_plan_reports_hollow_rows_for_explicit_deletion(): void {
		$plan = Scrubber::plan(
			[
				'id'         => 9,
				'type'       => 'reference',
				'scope'      => 'client-a.test',
				'memory_key' => 'client-a.test',
				'name'       => 'client-a.test',
				'topic'      => '',
				'value'      => [ 'url' => 'https://client-a.test' ],
			]
		);

		self::assertNotNull( $plan );
		// Nothing but the site's own name survives scrubbing, so there is no
		// lesson left to keep — but deletion stays a reviewed, explicit act.
		self::assertSame( 'review_for_deletion', $plan['action'] );
		self::assertSame( [], $plan['changes'] );
	}

	public function test_plan_returns_null_when_a_row_is_already_generic(): void {
		self::assertNull(
			Scrubber::plan(
				[
					'id'         => 10,
					'type'       => 'generic',
					'scope'      => '_global',
					'memory_key' => 'elementor-data-encoding',
					'name'       => 'Elementor data encoding',
					'topic'      => 'elementor',
					'value'      => [ 'lesson' => 'Never double-encode _elementor_data.' ],
				]
			)
		);
	}

	public function test_plan_is_idempotent(): void {
		$entry = [
			'id'         => 11,
			'type'       => 'feedback',
			'scope'      => 'client-a.test',
			'memory_key' => 'hero-spacing-client-a.test',
			'name'       => 'Hero spacing',
			'topic'      => '',
			'value'      => [ 'lesson' => 'Padding under 48px reads cramped on client-a.test.' ],
		];

		$first = Scrubber::plan( $entry );
		self::assertNotNull( $first );

		// Applying the plan and re-planning must converge: the second pass has
		// nothing left to change, so a resumed sweep cannot loop forever.
		$applied = array_merge( $entry, $first['changes'] );
		self::assertNull( Scrubber::plan( $applied ) );
	}

	public function test_plan_previews_are_bounded(): void {
		$plan = Scrubber::plan(
			[
				'id'         => 12,
				'type'       => 'feedback',
				'scope'      => 'client-a.test',
				'memory_key' => 'long',
				'name'       => 'Long',
				'topic'      => '',
				'value'      => [ 'lesson' => str_repeat( 'client-a.test is loud. ', 400 ) ],
			]
		);

		self::assertNotNull( $plan );
		foreach ( [ 'before', 'after' ] as $side ) {
			self::assertLessThanOrEqual(
				Scrubber::PREVIEW_MAX_LENGTH,
				mb_strlen( (string) $plan[ $side ] ),
				$side
			);
		}
	}
}
