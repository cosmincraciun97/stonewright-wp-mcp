<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminBootstrap;
use Stonewright\WpMcp\Admin\Pages\BlueprintsPage;

/**
 * @covers \Stonewright\WpMcp\Admin\Pages\BlueprintsPage
 */
final class BlueprintsPageRenderTest extends TestCase {

	public function test_admin_bootstrap_maps_blueprints_stylesheet(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/Admin/AdminBootstrap.php'
		);
		$this->assertStringContainsString( "'stonewright-blueprints'", $source );
		$this->assertStringContainsString( 'blueprints.css', $source );
		// Class reference keeps bootstrap in the type graph for static analysis.
		$this->assertTrue( class_exists( AdminBootstrap::class ) );
	}

	public function test_render_emits_blueprint_cards(): void {
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];

		ob_start();
		BlueprintsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'sw-blueprint-card', $html );
		$this->assertStringContainsString( 'sw-blueprint-grid', $html );
		$this->assertStringContainsString( 'sw-blueprint-card__actions', $html );
		$this->assertStringContainsString( 'Copy AI Prompt', $html );
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/blueprints.css' );
		$this->assertStringContainsString( '.sw-blueprint-card__actions', $css );
		$this->assertStringContainsString( 'gap:', $css );
		// Full multi-line prompt, not the old one-liner only.
		$this->assertStringContainsString( 'stonewright/blueprint-apply', $html );
		$this->assertStringContainsString( 'stonewright-task-start', $html );
	}

	public function test_blueprint_prompt_is_full_playbook(): void {
		$prompt = BlueprintsPage::blueprint_prompt(
			[
				'id'          => 'agency',
				'name'        => 'Creative Agency',
				'industry'    => 'agency',
				'description' => 'Landing with hero and services.',
				'section_ids' => [ 'hero', 'services' ],
				'palette'     => [ 'primary' => '#312E81' ],
				'fonts'       => [ 'heading' => 'Space Grotesk' ],
				'spec_sha8'   => 'deadbeef',
			]
		);

		$this->assertStringContainsString( "\n", $prompt );
		$this->assertStringContainsString( 'id: agency', $prompt );
		$this->assertStringContainsString( '## Engine', $prompt );
		$this->assertStringContainsString( 'engine=elementor', $prompt );
		$this->assertStringContainsString( 'engine=gutenberg', $prompt );
		$this->assertStringContainsString( 'do not switch engines silently', $prompt );
		$this->assertStringContainsString( 'stonewright/blueprint-get', $prompt );
		$this->assertStringContainsString( 'stonewright/blueprint-apply', $prompt );
		$this->assertStringContainsString( '{business}', $prompt );
		$this->assertGreaterThan( 400, strlen( $prompt ) );
	}
}
