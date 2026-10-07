<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\StatusPage;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * The Overview page: what it shows for a given state of the site.
 *
 * @covers \Stonewright\WpMcp\Admin\Pages\StatusPage
 */
final class OverviewPageTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	/** @param array<string, mixed> $override @return array<string, mixed> */
	private function facts( array $override = [] ): array {
		return array_merge(
			[
				'enabled'            => true,
				'sign_in_ready'      => true,
				'client_count'       => 2,
				'password_count'     => 0,
				'connection_seen'    => true,
				'audit_incidents'    => 0,
				'rescue_open'        => 0,
				'rescue_unconfirmed' => 0,
				'queue_queued'       => 0,
				'queue_failed'       => 0,
			],
			$override
		);
	}

	/** @param array<string, mixed> $override @return array<string, mixed> */
	private function view( array $override = [] ): array {
		return array_merge(
			[
				'facts'           => $this->facts(),
				'effective_state' => 'enabled',
				'mode'            => 'production-safe',
				'tool_count'      => 389,
				'surface'         => 'essential',
				'last_activity'   => gmdate( 'Y-m-d H:i:s', time() - 180 ),
				'calls_14d'       => 245,
				'daily_counts'    => [ '2026-07-14' => 3, '2026-07-15' => 5 ],
				'last_client_use' => time() - 240,
				'companion'       => [ 'state' => 'Not used', 'host' => '', 'detail' => 'No bridge URL set' ],
				'recent'          => [
					[ 'ability_name' => 'stonewright/blocks-update', 'result_status' => 'ok', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ],
					[ 'ability_name' => 'stonewright/content-create-post', 'result_status' => 'error', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ],
				],
				'pulse'           => [ 'score' => 55, 'grade' => 'F' ],
				'elementor'       => [ 'version' => '3.30.0', 'pro' => false ],
				'skills'          => 42,
				'memory'          => 7,
				'version'         => '1.0.0-test',
			],
			$override
		);
	}

	private function html( array $override = [] ): string {
		return StatusPage::overview_html( $this->view( $override ) );
	}

	public function test_the_status_band_answers_is_it_working(): void {
		$html = $this->html();

		self::assertStringContainsString( 'class="sw-ui-stats" role="group" aria-label="Status summary"', $html );
		foreach ( [ 'Connection', 'Mode', 'Tool surface', 'Last activity', 'Companion' ] as $label ) {
			self::assertStringContainsString( '<span class="sw-ui-stat__label">' . $label . '</span>', $html, $label );
		}
		self::assertStringContainsString( '<span class="sw-ui-stat__value">2 clients</span>', $html );
		self::assertStringContainsString( '<span class="sw-ui-stat__value">Production-safe</span>', $html );
		self::assertStringContainsString( 'Confirmation tokens on', $html );
		self::assertStringContainsString( '<span class="sw-ui-stat__value">389</span>', $html );
		self::assertStringContainsString( 'Essential profile', $html );
		self::assertStringContainsString( '<span class="sw-ui-stat__value">3 mins ago</span>', $html );
		self::assertStringContainsString( '245 changes in 14 days', $html );
	}

	public function test_the_connection_stat_says_when_nothing_has_connected(): void {
		$html = $this->html( [ 'facts' => $this->facts( [ 'client_count' => 0, 'password_count' => 0, 'connection_seen' => false ] ), 'last_client_use' => null, 'last_activity' => '' ] );

		self::assertStringContainsString( '<span class="sw-ui-stat__value">None yet</span>', $html );
		self::assertStringContainsString( '<span class="sw-ui-stat__value">No activity yet</span>', $html );
	}

	public function test_a_password_connection_is_counted(): void {
		$html = $this->html( [ 'facts' => $this->facts( [ 'client_count' => 0, 'password_count' => 1 ] ) ] );

		self::assertStringContainsString( '<span class="sw-ui-stat__value">1 client</span>', $html );
	}

	public function test_the_bridge_tile_shows_a_state_and_never_the_stored_value(): void {
		$html = $this->html( [ 'companion' => [ 'state' => 'Configured', 'host' => 'bridge.example.test:9443', 'detail' => '' ] ] );

		self::assertStringContainsString( '<span class="sw-ui-stat__value">Configured</span>', $html );
		self::assertStringContainsString( 'bridge.example.test:9443', $html );
		self::assertStringNotContainsString( 'secret', $html );
		self::assertStringNotContainsString( '/private/path', $html );
	}

	public function test_nothing_needing_attention_says_so_instead_of_an_empty_table(): void {
		$html = $this->html();

		self::assertStringContainsString( '>Needs attention<', $html );
		self::assertStringContainsString( 'Nothing needs you right now', $html );
		self::assertStringNotContainsString( 'Items that need attention', $html, 'No table without rows.' );
	}

	public function test_items_that_need_attention_are_a_table_with_a_state_word_and_one_action_each(): void {
		$html = $this->html( [ 'facts' => $this->facts( [ 'audit_incidents' => 4, 'queue_queued' => 2, 'connection_seen' => false ] ) ] );

		self::assertStringContainsString( '<caption class="sw-ui-visually-hidden">Items that need attention</caption>', $html );
		self::assertStringContainsString( '4 open incidents', $html );
		self::assertStringContainsString( '2 block changes waiting', $html );
		self::assertStringContainsString( 'Connection not verified yet', $html );
		self::assertMatchesRegularExpression( '/<span class="sw-ui-badge sw-ui-badge--danger">.*?Open<\/span>/s', $html );
		self::assertMatchesRegularExpression( '/<span class="sw-ui-badge sw-ui-badge--warn">.*?Queued<\/span>/s', $html );
		self::assertMatchesRegularExpression( '/<span class="sw-ui-badge sw-ui-badge--info">.*?Setup<\/span>/s', $html );
		self::assertStringContainsString( 'Review incidents<span class="sw-ui-visually-hidden"> in the audit log</span>', $html );
		self::assertStringContainsString( '<span class="sw-ui-count sw-ui-num">3</span>', $html, 'The heading carries the number of items.' );
	}

	public function test_a_site_that_is_set_up_has_no_finish_setup_card_and_no_primary_button(): void {
		$html = $this->html();

		self::assertStringNotContainsString( 'Finish setup', $html );
		self::assertStringNotContainsString( 'sw-ui-btn--primary', $html );
	}

	public function test_an_unfinished_setup_offers_the_next_step_as_the_one_primary_action(): void {
		$html = $this->html( [ 'facts' => $this->facts( [ 'connection_seen' => false ] ) ] );

		self::assertStringContainsString( '>Finish setup<', $html );
		self::assertStringContainsString( '<span class="sw-ui-badge sw-ui-badge--accent">3 of 4</span>', $html );
		self::assertStringContainsString( '<ol class="sw-ui-lineage" aria-label="Setup steps">', $html );
		self::assertSame( 1, preg_match( '/<ol class="sw-ui-lineage".*?<\/ol>/s', $html, $steps ) );
		self::assertSame( 3, substr_count( $steps[0], 'sw-ui-badge--ok' ), 'Three steps are done, each with the word Done.' );
		self::assertSame( 3, substr_count( $steps[0], 'Done</span>' ) );
		self::assertMatchesRegularExpression( '/<li class="sw-ui-lineage__node" aria-current="step">.*?Next.*?Verify the connection<\/li>/s', $html );
		self::assertSame( 1, substr_count( $html, 'sw-ui-btn--primary' ), 'One primary action on the page.' );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-btn sw-ui-btn--primary" href="https:\/\/example\.test\/wp-admin\/admin\.php\?page=stonewright">Verify connection<\/a>/', $html );
	}

	public function test_a_site_with_nothing_set_up_starts_with_enabling(): void {
		$html = $this->html( [ 'facts' => $this->facts( [ 'enabled' => false, 'sign_in_ready' => false, 'client_count' => 0, 'connection_seen' => false ] ) ] );

		self::assertStringContainsString( '0 of 4', $html );
		self::assertMatchesRegularExpression( '/>Enable abilities<\/a>/', $html );
		self::assertSame( 1, substr_count( $html, 'sw-ui-btn--primary' ) );
	}

	public function test_recent_activity_lists_what_agents_did_with_the_outcome_in_words(): void {
		$html = $this->html();

		self::assertStringContainsString( '>Recent activity<', $html );
		self::assertStringContainsString( '<caption class="sw-ui-visually-hidden">Recent changes by agents</caption>', $html );
		self::assertStringContainsString( 'stonewright/blocks-update', $html );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-link" href="[^"]*ability=stonewright%2Fblocks-update"><code>/', $html, 'The link is a 24px target, not a bare 17px line.' );
		self::assertMatchesRegularExpression( '/<span class="sw-ui-badge sw-ui-badge--ok">.*?OK<\/span>/s', $html );
		self::assertMatchesRegularExpression( '/<span class="sw-ui-badge sw-ui-badge--danger">.*?Error<\/span>/s', $html );
		self::assertMatchesRegularExpression( '/<time datetime="[0-9-]+ [0-9:]+">1 min ago<\/time>/', $html );
		self::assertStringContainsString( 'page=stonewright-audit-log', $html );
		self::assertStringContainsString( 'role="img" aria-label="Changes over the last 14 days: 8 in total"', $html );
	}

	public function test_no_activity_teaches_what_will_appear_and_what_to_do(): void {
		$html = $this->html( [ 'recent' => [], 'daily_counts' => [], 'calls_14d' => 0 ] );

		self::assertStringContainsString( 'No activity yet', $html );
		self::assertStringContainsString( 'Every change an agent makes appears here, with secrets removed.', $html );
		self::assertStringContainsString( 'Browse the prompt library', $html, 'A site that is set up is pointed at what to ask for.' );
	}

	public function test_while_setup_is_unfinished_the_empty_activity_card_does_not_repeat_the_next_step(): void {
		$html = $this->html( [ 'recent' => [], 'daily_counts' => [], 'calls_14d' => 0, 'facts' => $this->facts( [ 'client_count' => 0, 'connection_seen' => false ] ) ] );

		self::assertSame( 1, substr_count( $html, '>Connect a client</a>' ), 'Only the Finish setup card offers it as a button.' );
		self::assertSame( 1, substr_count( $html, 'sw-ui-btn--primary' ) );
		self::assertStringNotContainsString( 'Browse the prompt library', $html );
	}

	public function test_this_site_card_keeps_the_facts_the_dashboard_had(): void {
		$html = $this->html();

		self::assertStringContainsString( '>This site<', $html );
		self::assertStringContainsString( '<dt>Site pulse</dt><dd>55 (F)</dd>', $html );
		self::assertStringContainsString( '<dt>Elementor</dt><dd>3.30.0</dd>', $html );
		self::assertMatchesRegularExpression( '/<dt>Skills<\/dt><dd><a class="sw-ui-link" href="[^"]*page=stonewright-skills">42<\/a><\/dd>/', $html );
		self::assertMatchesRegularExpression( '/<dt>Memory<\/dt><dd><a class="sw-ui-link" href="[^"]*page=stonewright-memory">7<\/a><\/dd>/', $html );
	}

	public function test_this_site_card_omits_what_is_not_known(): void {
		$html = $this->html( [ 'pulse' => null, 'elementor' => [ 'version' => '', 'pro' => false ] ] );

		self::assertStringNotContainsString( 'Site pulse', $html );
		self::assertStringContainsString( '<dt>Elementor</dt><dd>Not detected</dd>', $html );
	}

	public function test_the_header_status_is_a_word_and_a_dot_not_a_colour(): void {
		$on  = StatusPage::header_status_html( $this->view() );
		$off = StatusPage::header_status_html( $this->view( [ 'effective_state' => 'disabled_by_operator' ] ) );
		$bad = StatusPage::header_status_html( $this->view( [ 'effective_state' => 'blocked_domain_mismatch' ] ) );

		self::assertStringContainsString( '<span class="sw-ui-badge sw-ui-badge--ok sw-ui-badge--dot">AI abilities on</span>', $on );
		self::assertStringContainsString( 'Production-safe', $on );
		self::assertStringContainsString( 'v1.0.0-test', $on );
		self::assertStringContainsString( '>AI abilities off<', $off );
		self::assertStringNotContainsString( 'sw-ui-badge--ok', $off );
		self::assertStringContainsString( 'sw-ui-badge--danger', $bad );
		self::assertStringContainsString( '>AI abilities blocked<', $bad );
	}

	public function test_content_is_escaped_and_carries_no_inline_style_or_script(): void {
		$html = $this->html( [ 'recent' => [ [ 'ability_name' => '<script>alert(1)</script>', 'result_status' => 'ok', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] ], 'elementor' => [ 'version' => '<b>1</b>', 'pro' => false ] ] );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<b>1</b>', $html );
		self::assertStringNotContainsString( ' style=', $html );
		self::assertStringNotContainsString( 'onclick', $html );
	}

	public function test_the_content_has_no_heading_one_and_a_heading_per_region(): void {
		$html = $this->html( [ 'facts' => $this->facts( [ 'connection_seen' => false ] ) ] );

		self::assertStringNotContainsString( '<h1', $html, 'The page header prints the h1.' );
		self::assertSame( 4, substr_count( $html, '<h2 class="sw-ui-card__title"' ) );
		self::assertMatchesRegularExpression( '/<section class="sw-ui-card" aria-labelledby="sw-ui-card-title-\d+">/', $html );
	}

	public function test_the_page_is_scoped_to_the_layer(): void {
		$html = $this->html();

		self::assertStringStartsWith( '<div class="sw-ui sw-ui-page sw-overview">', $html );
	}

	public function test_only_administrators_can_open_the_page(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->expectException( \RuntimeException::class );
		StatusPage::render();
	}
}
