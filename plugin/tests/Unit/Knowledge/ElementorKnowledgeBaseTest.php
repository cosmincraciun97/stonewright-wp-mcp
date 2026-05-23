<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Knowledge;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Knowledge\DescribeWidget;
use Stonewright\WpMcp\Abilities\Knowledge\ExplainEditor;
use Stonewright\WpMcp\Abilities\Knowledge\KnowledgeSearch;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Knowledge\ElementorKnowledgeBase;

/**
 * @covers \Stonewright\WpMcp\Knowledge\ElementorKnowledgeBase
 * @covers \Stonewright\WpMcp\Abilities\Knowledge\KnowledgeSearch
 * @covers \Stonewright\WpMcp\Abilities\Knowledge\DescribeWidget
 * @covers \Stonewright\WpMcp\Abilities\Knowledge\ExplainEditor
 */
final class ElementorKnowledgeBaseTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
	}

	public function test_search_finds_relevant_elementor_widget_docs(): void {
		$result = ElementorKnowledgeBase::search( 'countdown due date', 'widgets', 5 );

		$this->assertSame( 'countdown due date', $result['query'] );
		$this->assertNotEmpty( $result['results'] );
		$first = $result['results'][0];
		$this->assertArrayHasKey( 'title', $first );
		$this->assertArrayHasKey( 'path', $first );
		$this->assertArrayHasKey( 'summary', $first );
		$this->assertStringContainsString( 'countdown', strtolower( $first['path'] . ' ' . $first['title'] . ' ' . $first['summary'] ) );
	}

	public function test_describe_widget_returns_manifest_and_documents(): void {
		$result = ElementorKnowledgeBase::describe_widget( 'countdown' );

		$this->assertSame( 'countdown', $result['widget'] );
		$this->assertArrayHasKey( 'manifest', $result );
		$this->assertArrayHasKey( 'documents', $result );
		$this->assertNotEmpty( $result['documents'] );
		$this->assertSame( 'stonewright/elementor-knowledge-refresh', $result['refresh_ability'] );
	}

	public function test_read_only_knowledge_abilities_are_registered(): void {
		$this->assertContains( KnowledgeSearch::class, AbilityRegistry::list() );
		$this->assertContains( DescribeWidget::class, AbilityRegistry::list() );
		$this->assertContains( ExplainEditor::class, AbilityRegistry::list() );

		$this->assertSame( 'stonewright/elementor-knowledge-search', ( new KnowledgeSearch() )->name() );
		$this->assertSame( 'stonewright/elementor-describe-widget', ( new DescribeWidget() )->name() );
		$this->assertSame( 'stonewright/elementor-explain-editor', ( new ExplainEditor() )->name() );
		$this->assertSame( 'knowledge', ( new KnowledgeSearch() )->category() );
	}

	public function test_knowledge_search_ability_returns_ranked_results(): void {
		$result = ( new KnowledgeSearch() )->execute(
			[
				'query' => 'nav menu widget',
				'area'  => 'widgets',
				'limit' => 3,
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['ok'] );
		$this->assertNotEmpty( $result['results'] );
	}
}
