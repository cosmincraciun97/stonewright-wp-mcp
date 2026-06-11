<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AgentInstructions;

/**
 * @covers \Stonewright\WpMcp\Core\AgentInstructions
 */
final class AgentInstructionsTest extends TestCase {

	public function test_default_instructions_force_context_skills_memory_and_elementor_widget_discipline(): void {
		$instructions = AgentInstructions::default();

		$this->assertStringContainsString( 'stonewright/context-bootstrap', $instructions );
		$this->assertStringContainsString( 'stonewright-context-bootstrap', $instructions );
		$this->assertStringContainsString( 'replace `/` with `-`', $instructions );
		$this->assertStringContainsString( 'stonewright/skills-get', $instructions );
		$this->assertStringContainsString( 'stonewright/memory-get', $instructions );
		$this->assertStringContainsString( 'stonewright/learning-record', $instructions );
		$this->assertStringContainsString( 'stonewright/elementor-knowledge-search', $instructions );
		$this->assertStringContainsString( 'stonewright/elementor-describe-widget', $instructions );
		$this->assertStringContainsString( 'stonewright/widget-intent-resolve', $instructions );
		$this->assertStringContainsString( 'stonewright/elementor-widget-implementation-guide', $instructions );
		$this->assertStringContainsString( 'stonewright/wp-cli-status', $instructions );
		$this->assertStringContainsString( 'stonewright/wp-cli-discover', $instructions );
		$this->assertStringContainsString( 'stonewright/wp-cli-run', $instructions );
		$this->assertStringContainsString( 'do not require the WordPress-side HTTP bridge', $instructions );
		$this->assertStringContainsString( 'stonewright-wp-cli-install', $instructions );
		$this->assertStringContainsString( 'Elementor, Gutenberg, ACF, CPT UI', $instructions );
		$this->assertStringContainsString( 'ACF, ACPT, Meta Box, ASE, Pods, WooCommerce', $instructions );
		$this->assertStringContainsString( 'stonewright-content-model-integrations', $instructions );
		$this->assertStringContainsString( 'stonewright-woocommerce-catalog', $instructions );
		$this->assertStringContainsString( 'Use plugin-specific official REST or WP-CLI surfaces when present', $instructions );
		$this->assertStringContainsString( 'wp eval', $instructions );
		$this->assertStringContainsString( 'real Elementor widgets', $instructions );
		$this->assertStringContainsString( 'Do not use Elementor HTML widgets', $instructions );
		$this->assertStringContainsString( 'allow_html_widget=true', $instructions );
		$this->assertStringContainsString( 'Content, Style, and Advanced', $instructions );
		$this->assertStringContainsString( 'official Elementor documentation', $instructions );
		$this->assertStringContainsString( 'stonewright/elementor-v3-get-widget-schema for every widget', $instructions );
		$this->assertStringContainsString( 'position absolute', $instructions );
		$this->assertStringContainsString( 'CSS ID', $instructions );
		$this->assertStringContainsString( 'CSS classes', $instructions );
		$this->assertStringContainsString( 'Name only major parent containers semantically', $instructions );
		$this->assertStringContainsString( 'Do not name every inner utility container', $instructions );
		$this->assertStringContainsString( 'For Gutenberg and block-theme work', $instructions );
		$this->assertStringContainsString( 'theme.json', $instructions );
		$this->assertStringContainsString( 'template parts', $instructions );
		$this->assertStringContainsString( 'block supports', $instructions );
		$this->assertStringContainsString( 'external Playwright MCP', $instructions );
		$this->assertStringContainsString( '@playwright/mcp@latest', $instructions );
		$this->assertStringContainsString( 'restart the AI client', $instructions );
		$this->assertStringContainsString( 'stop before the first visual write', $instructions );
		$this->assertStringContainsString( 'Horizontal scroll is a hard failure', $instructions );
		$this->assertStringContainsString( 'document.documentElement.scrollWidth', $instructions );
		$this->assertStringContainsString( 'preload lazy-loaded media', $instructions );
		$this->assertStringContainsString( 'Do not use the design canvas width as a fixed live page width', $instructions );
		$this->assertStringContainsString( 'Subagents must call stonewright-context-bootstrap themselves', $instructions );
		$this->assertStringContainsString( 'Do not use a full-page screenshot as a section background', $instructions );
		$this->assertStringContainsString( 'asset selection plan', $instructions );
		$this->assertStringContainsString( 'Custom CSS requires explicit user approval', $instructions );
		$this->assertStringContainsString( 'active theme style.css', $instructions );
		$this->assertStringContainsString( 'responsive desktop, tablet, and mobile layouts', $instructions );
		$this->assertStringContainsString( 'sticky', $instructions );
		$this->assertStringContainsString( 'hamburger', $instructions );
		$this->assertStringContainsString( 'glow', $instructions );
		$this->assertStringContainsString( 'native form widgets', $instructions );
		$this->assertStringContainsString( 'native gallery widgets', $instructions );
		$this->assertStringContainsString( 'If SVG upload is blocked', $instructions );
		$this->assertStringContainsString( 'do not create sandbox', $instructions );
		$this->assertStringNotContainsString( 'stonewright/qa-', $instructions );
		$this->assertStringNotContainsString( 'Figma', $instructions );
	}
}
