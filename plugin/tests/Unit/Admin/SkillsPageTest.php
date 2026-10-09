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
		self::assertStringContainsString( '>Built-in<', $html );
		self::assertStringContainsString( '>Draft<', $html );
		self::assertStringNotContainsString( '>Binned<', $html );
		self::assertStringContainsString( 'name="content"', $html );
	}

	public function test_the_page_is_built_from_the_layer_and_none_of_the_older_skills_classes_remain(): void {
		$this->tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Site note', 'description' => 'Use when noting.', 'content' => '# Note' ] );

		$html = $this->render();

		self::assertStringContainsString( 'sw-ui sw-ui-page sw-skills', $html );
		foreach ( [ 'sw-skills-button', 'class="sw-skills-tab', 'sw-skills-input', 'sw-skills-toolbar', 'class="sw-skills-panel', 'class="sw-badge', 'sw-empty-state', 'class="sw-callout', 'class="sw-card', 'class="sw-field', 'class="sw-actions', 'notice notice-', ' style=' ] as $legacy ) {
			self::assertStringNotContainsString( $legacy, $html, $legacy );
		}
		self::assertSame( 1, substr_count( $html, '<h1' ) );
	}

	public function test_the_view_tabs_use_the_layer_and_keep_the_hooks_the_script_boots_from(): void {
		$html = $this->render();

		self::assertStringContainsString( '<div class="sw-ui-tabs" role="tablist" aria-label="Skill views">', $html );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-tabs__tab" role="tab" id="sw-skills-tab-catalog" href="[^"]+" data-sw-view="catalog" aria-selected="true" aria-controls="sw-skills-panel-catalog" tabindex="0">Catalog<\/a>/', $html );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-tabs__tab" role="tab" id="sw-skills-tab-trash"[^>]*aria-selected="false"[^>]*tabindex="-1">Trash<\/a>/', $html );
		self::assertMatchesRegularExpression( '/<section class="sw-ui-tabs__panel" role="tabpanel" id="sw-skills-panel-trash" aria-labelledby="sw-skills-tab-trash" data-sw-panel="trash" hidden>/', $html );
		self::assertStringContainsString( 'data-sw-skills', $html );
		self::assertStringContainsString( 'data-sw-current-view="catalog"', $html );
		self::assertStringNotContainsString( 'data-sw-ui-tabs', $html, 'The script that boots the page owns the tabs; the layer must not bind them twice.' );
	}

	public function test_a_skill_shows_one_status_badge_and_at_most_two_tags(): void {
		$this->tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Site note', 'description' => 'Use when noting.', 'content' => '# Note', 'enabled' => 1, 'enable_agentic' => 1, 'enable_prompt' => 1 ] );

		$html = $this->render();

		self::assertSame( 1, preg_match( '/<li class="sw-skill-row[^"]*"[^>]*>(.*?)<\/li>/s', $html, $row ) );
		self::assertSame( 1, preg_match_all( '/class="sw-ui-badge[ "]/', $row[1] ), 'One status badge.' );
		self::assertLessThanOrEqual( 2, preg_match_all( '/class="sw-ui-tag"/', $row[1] ), 'At most two tags.' );
		self::assertStringContainsString( '>Active<', $row[1] );
		self::assertStringNotContainsString( '>auto<', $row[1] );
	}

	public function test_the_catalog_toolbar_has_a_labelled_search_and_the_one_primary_new_skill_action(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '/<label class="sw-ui-field__label" for="sw-skills-search">Search skills<\/label>/', $html );
		self::assertMatchesRegularExpression( '/<input[^>]*type="search"[^>]*id="sw-skills-search"[^>]*data-sw-skills-search[^>]*disabled/', $html );
		self::assertSame( 1, preg_match_all( '/sw-ui-btn--primary"[^>]*>(?:<svg.*?<\/svg>)?New skill/', $html ), 'New skill is the one primary button of the catalog.' );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-btn sw-ui-btn--primary"[^>]*>(?:<svg.*?<\/svg>)?New skill<\/a>/', $html );
	}

	public function test_results_are_notices_that_stay_and_errors_are_alerts(): void {
		$_GET = [ 'saved' => '1' ];
		self::assertMatchesRegularExpression( '/role="status"[^>]*>.*Skill saved\./s', $this->render() );

		$_GET = [ 'toggled' => '1' ];
		self::assertMatchesRegularExpression( '/role="status"[^>]*>.*Skill updated\./s', $this->render() );

		$_GET = [ 'error' => 'missing_fields' ];
		self::assertMatchesRegularExpression( '/role="alert"[^>]*>.*Please fill in all required fields/s', $this->render() );
	}

	public function test_the_editor_labels_every_field_and_names_the_availability_group(): void {
		$_GET = [ 'view' => 'editor' ];

		$html = $this->render();

		foreach ( [ 'Title', 'Slug', 'Description', 'Content \(Markdown\)' ] as $label ) {
			self::assertMatchesRegularExpression( '/<label class="sw-ui-field__label" for="[^"]+">' . $label . '<\/label>/', $html, $label );
		}
		self::assertMatchesRegularExpression( '/<fieldset class="sw-ui-fieldset sw-ui-stack">\s*<legend>Availability<\/legend>/', $html );
		self::assertMatchesRegularExpression( '/<label class="sw-ui-checkbox"[^>]*><input type="checkbox"[^>]*name="enabled"[^>]*><span>Skill is active<\/span>/', $html );
		self::assertStringContainsString( 'value="stonewright_skill_save"', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $html );
		self::assertMatchesRegularExpression( '/<button type="submit" class="sw-ui-btn sw-ui-btn--primary">Save skill<\/button>/', $html );
	}

	public function test_no_id_is_used_twice(): void {
		$_GET = [ 'view' => 'editor' ];
		preg_match_all( '/\bid="([^"]+)"/', $this->render(), $found );

		self::assertSame( [], array_keys( array_filter( array_count_values( $found[1] ), static fn ( int $count ): bool => $count > 1 ) ) );
	}

	public function test_the_script_boots_without_a_second_status_line_and_the_no_script_note_uses_the_layer(): void {
		$html = $this->render();

		self::assertStringContainsString( 'data-sw-skills-status', $html );
		self::assertMatchesRegularExpression( '/<noscript>.*sw-ui-callout.*The catalog, import review, and trash need JavaScript/s', $html );
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

	public function test_editor_carries_the_revision_it_loaded_and_a_new_skill_form_carries_none(): void {
		$this->tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Site note', 'description' => 'Use when noting.', 'content' => '# Note', 'revision' => 4 ] );
		$_GET = [
			'view'  => 'editor',
			'skill' => 'site-note',
		];

		self::assertStringContainsString( '<input type="hidden" name="revision" value="4">', $this->render() );

		$_GET = [ 'view' => 'editor' ];
		self::assertStringNotContainsString( 'name="revision"', $this->render() );
	}

	public function test_refused_saves_are_explained_without_reflecting_the_request(): void {
		$_GET = [ 'error' => 'stonewright_skill_lint_failed' ];
		self::assertStringContainsString( 'Save it disabled to keep a draft.', $this->render() );

		$_GET = [ 'error' => 'stonewright_skill_write_conflict' ];
		self::assertStringContainsString( 'changed after you opened it, so nothing was saved', $this->render() );

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

	public function test_save_submission_refuses_a_form_loaded_from_an_older_revision(): void {
		$this->tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Site note', 'description' => 'Use when noting.', 'content' => '# Newer', 'revision' => 3 ] );
		$form = [
			'slug'           => 'site-note',
			'title'          => 'Site note',
			'description'    => 'Use when noting.',
			'content'        => '# From the form',
			'enabled'        => '1',
			'enable_agentic' => '1',
			'enable_prompt'  => '1',
		];

		$url = SkillsPage::save_submission( $form + [ 'revision' => '2' ] );

		self::assertSame( [ '# Newer', '3' ], [ $this->tables->skills[1]['content'], $this->tables->skills[1]['revision'] ] );
		self::assertStringContainsString( 'view=editor', $url );
		self::assertStringContainsString( 'error=stonewright_skill_write_conflict', $url );
		self::assertStringContainsString( 'skill=site-note', $url );

		self::assertStringContainsString( 'saved=1', SkillsPage::save_submission( $form + [ 'revision' => '3' ] ) );
		self::assertSame( [ '# From the form', '4' ], [ $this->tables->skills[1]['content'], $this->tables->skills[1]['revision'] ] );

		self::assertStringContainsString( 'saved=1', SkillsPage::save_submission( array_replace( $form, [ 'content' => '# Without a revision' ] ) ), 'A form that carries no revision saves as it always did.' );
		self::assertSame( [ '# Without a revision', '5' ], [ $this->tables->skills[1]['content'], $this->tables->skills[1]['revision'] ] );
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
