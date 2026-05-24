<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AgentInstructions;

/**
 * @covers \Stonewright\WpMcp\Core\AgentInstructions
 */
final class AgentInstructionsTest extends TestCase {

	public function test_default_instructions_force_real_widgets_and_qa_gate(): void {
		$instructions = AgentInstructions::default();

		$this->assertStringContainsString( 'stonewright/elementor-knowledge-search', $instructions );
		$this->assertStringContainsString( 'stonewright/elementor-describe-widget', $instructions );
		$this->assertStringContainsString( 'stonewright/widget-intent-resolve', $instructions );
		$this->assertStringContainsString( 'real Elementor widgets', $instructions );
		$this->assertStringContainsString( 'Do not use Elementor HTML widgets', $instructions );
		$this->assertStringContainsString( 'allow_html_widget=true', $instructions );
		$this->assertStringContainsString( 'Custom CSS requires explicit user approval', $instructions );
		$this->assertStringContainsString( 'active theme style.css', $instructions );
		$this->assertStringContainsString( 'responsive desktop, tablet, and mobile layouts', $instructions );
		$this->assertStringContainsString( 'sticky', $instructions );
		$this->assertStringContainsString( 'hamburger', $instructions );
		$this->assertStringContainsString( 'glow', $instructions );
		$this->assertStringContainsString( 'native form widgets', $instructions );
		$this->assertStringContainsString( 'native gallery widgets', $instructions );
		$this->assertStringContainsString( 'stonewright/qa-verify-against-reference', $instructions );
		$this->assertStringContainsString( 'Do not claim pixel-perfect', $instructions );
	}
}
