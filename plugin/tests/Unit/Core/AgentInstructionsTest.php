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
		$this->assertStringContainsString( 'real Elementor widgets', $instructions );
		$this->assertStringContainsString( 'stonewright/qa-verify-against-reference', $instructions );
		$this->assertStringContainsString( 'Do not claim pixel-perfect', $instructions );
	}
}
