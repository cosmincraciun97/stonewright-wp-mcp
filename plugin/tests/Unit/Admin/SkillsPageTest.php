<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SkillsPage;
use Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site\SkillTablesDouble;

/**
 * @covers \Stonewright\WpMcp\Admin\SkillsPage
 */
final class SkillsPageTest extends TestCase {

	private mixed $original_wpdb;

	private SkillTablesDouble $tables;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->tables                                = new SkillTablesDouble();
		$GLOBALS['wpdb']                             = $this->tables;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		$_GET                                        = [];
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                             = $this->original_wpdb;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		$_GET                                        = [];
	}

	public function test_catalog_renders_skills_as_text_with_provenance_and_counts(): void {
		$this->tables->seed_skill( [ 'slug' => 'stonewright-alpha', 'title' => 'Alpha', 'description' => 'Use when testing alpha.', 'content' => '# Alpha', 'source' => 'builtin' ] );
		$this->tables->seed_skill( [ 'slug' => 'site-note', 'title' => '<img src=x onerror=alert(1)>', 'description' => '<script>alert(2)</script>', 'content' => '# Note', 'status' => 'draft', 'enabled' => 0 ] );
		$this->tables->seed_skill( [ 'slug' => 'binned', 'title' => 'Binned', 'content' => '# Binned', 'status' => 'trashed', 'enabled' => 0 ] );

		$html = $this->render();

		self::assertStringContainsString( 'data-sw-skills-ssr="catalog"', $html );
		self::assertStringContainsString( '2 skill(s) from 2 source(s). 1 in trash.', $html );
		self::assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(2)&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringNotContainsString( '<script>alert', $html );
		self::assertStringContainsString( '>built-in<', $html );
		self::assertStringContainsString( '>draft<', $html );
		self::assertStringNotContainsString( '>Binned<', $html );
		self::assertStringContainsString( 'name="content"', $html );
	}

	public function test_editor_prefills_the_requested_skill_and_locks_its_slug(): void {
		$this->tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Site note', 'description' => 'Use when noting "quotes".', 'content' => "# Note\n<b>body</b>", 'enable_prompt' => 0 ] );
		$_GET = [
			'view'  => 'editor',
			'skill' => 'site-note',
		];

		$html = $this->render();

		self::assertStringContainsString( 'value="Site note"', $html );
		self::assertStringContainsString( 'value="Use when noting &quot;quotes&quot;."', $html );
		self::assertStringContainsString( '&lt;b&gt;body&lt;/b&gt;', $html );
		self::assertMatchesRegularExpression( '/name="slug"[^>]*value="site-note"[^>]*readonly/s', $html );
		self::assertMatchesRegularExpression( '/name="enable_prompt" value="1"\s*>/', $html );
	}

	public function test_refused_saves_are_explained_without_reflecting_the_request(): void {
		$_GET = [ 'error' => 'stonewright_skill_lint_failed' ];
		self::assertStringContainsString( 'Save it disabled to keep a draft.', $this->render() );

		$_GET = [ 'error' => '<b>forged</b>' ];
		$html = $this->render();
		self::assertStringContainsString( 'The skill was not saved. Check the fields and try again.', $html );
		self::assertStringNotContainsString( 'forged', $html );
	}

	public function test_save_submission_stores_the_form_and_returns_to_the_catalog(): void {
		$url = SkillsPage::save_submission(
			[
				'slug'           => 'Site Note',
				'title'          => 'Site note',
				'description'    => 'Use when noting.',
				'content'        => "# Note\n",
				'enabled'        => '1',
				'enable_agentic' => '1',
			]
		);

		self::assertStringContainsString( 'view=catalog', $url );
		self::assertStringContainsString( 'saved=1', $url );
		$row = $this->tables->skill_by_slug( 'site-note' );
		self::assertIsArray( $row );
		self::assertSame( [ 'user', 'active', '1', '1', '0' ], [ $row['source'], $row['status'], $row['enabled'], $row['enable_agentic'], $row['enable_prompt'] ] );
	}

	public function test_save_submission_reports_refusals_on_the_editor(): void {
		$this->tables->seed_skill( [ 'slug' => 'stonewright-alpha', 'title' => 'Alpha', 'content' => '# Alpha', 'source' => 'builtin' ] );

		self::assertStringContainsString( 'error=missing_fields', SkillsPage::save_submission( [ 'slug' => 'x', 'title' => '', 'content' => '' ] ) );

		$url = SkillsPage::save_submission( [ 'slug' => 'stonewright-alpha', 'title' => 'Mine', 'content' => '# Mine', 'enabled' => '1' ] );
		self::assertStringContainsString( 'view=editor', $url );
		self::assertStringContainsString( 'error=stonewright_skill_protected', $url );
		self::assertStringContainsString( 'skill=stonewright-alpha', $url );
		self::assertSame( '# Alpha', $this->tables->skill_by_slug( 'stonewright-alpha' )['content'] ?? null );

		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertStringContainsString( 'error=stonewright_skill_permission_denied', SkillsPage::save_submission( [ 'slug' => 'other', 'title' => 'Other', 'content' => '# Other' ] ) );
		self::assertNull( $this->tables->skill_by_slug( 'other' ) );
	}

	public function test_toggle_submission_changes_the_flag_only(): void {
		$this->tables->seed_skill( [ 'id' => 4, 'slug' => 'site-note', 'title' => 'Note', 'description' => 'Use when noting.', 'content' => '# Note' ] );

		self::assertStringContainsString( 'toggled=1', SkillsPage::toggle_submission( [ 'id' => '4' ] ) );
		self::assertSame( [ '0', '1' ], [ $this->tables->skills[4]['enabled'], $this->tables->skills[4]['revision'] ] );
		self::assertStringContainsString( 'error=stonewright_skill_toggle_invalid', SkillsPage::toggle_submission( [ 'id' => '40', 'enabled' => '1' ] ) );
	}

	public function test_boot_payload_carries_the_studio_root_nonce_and_mode(): void {
		$_GET = [ 'view' => 'trash' ];

		$payload = SkillsPage::boot_payload();

		self::assertStringEndsWith( 'stonewright/v1/skills-studio', $payload['restRoot'] );
		self::assertNotSame( '', $payload['nonce'] );
		self::assertSame( 'trash', $payload['view'] );
		self::assertSame( [ 'catalog', 'editor', 'import', 'trash' ], $payload['views'] );
		self::assertSame( 'production-safe', $payload['mode'] );
		self::assertTrue( $payload['can']['manageOptions'] );
	}

	private function render(): string {
		ob_start();
		SkillsPage::render();
		return (string) ob_get_clean();
	}
}
