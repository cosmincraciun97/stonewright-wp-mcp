<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\BuildFromFigmaReference;

/**
 * Unit tests for the BuildFromFigmaReference orchestrator ability.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\BuildFromFigmaReference
 */
final class BuildFromFigmaReferenceTest extends TestCase {

	public function test_ability_has_correct_name(): void {
		$ability = new BuildFromFigmaReference();
		$this->assertSame( 'stonewright/design-build-from-figma-reference', $ability->name() );
	}

	public function test_ability_category_is_design(): void {
		$ability = new BuildFromFigmaReference();
		$this->assertSame( 'design', $ability->category() );
	}

	public function test_input_schema_requires_correct_fields(): void {
		$ability = new BuildFromFigmaReference();
		$schema  = $ability->input_schema();
		$this->assertSame( [ 'figma_file_key', 'figma_node_id', 'post_id' ], $schema['required'] );
	}

	public function test_input_schema_renderer_enum_includes_auto(): void {
		$ability  = new BuildFromFigmaReference();
		$schema   = $ability->input_schema();
		$renderer = $schema['properties']['renderer'];
		$this->assertContains( 'auto', $renderer['enum'] );
		$this->assertContains( 'elementor_v3', $renderer['enum'] );
		$this->assertContains( 'gutenberg', $renderer['enum'] );
	}

	public function test_input_schema_has_dry_run_and_skip_qa_flags(): void {
		$ability = new BuildFromFigmaReference();
		$schema  = $ability->input_schema();
		$this->assertArrayHasKey( 'dry_run', $schema['properties'] );
		$this->assertArrayHasKey( 'skip_qa', $schema['properties'] );
		$this->assertSame( 'boolean', $schema['properties']['dry_run']['type'] );
		$this->assertSame( 'boolean', $schema['properties']['skip_qa']['type'] );
	}

	public function test_output_schema_requires_success_and_steps(): void {
		$ability = new BuildFromFigmaReference();
		$schema  = $ability->output_schema();
		$this->assertContains( 'success', $schema['required'] );
		$this->assertContains( 'steps', $schema['required'] );
	}

	public function test_execute_returns_error_on_missing_file_key(): void {
		$ability = new BuildFromFigmaReference();
		$result  = $ability->execute( [
			'figma_file_key' => '',
			'figma_node_id'  => '97:8306',
			'post_id'        => 1,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_missing_figma_file_key', $result->get_error_code() );
	}

	public function test_execute_returns_error_on_missing_node_id(): void {
		$ability = new BuildFromFigmaReference();
		$result  = $ability->execute( [
			'figma_file_key' => 'zfoLm0i7YDmVCowIsHlBDH',
			'figma_node_id'  => '',
			'post_id'        => 1,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_missing_figma_node_id', $result->get_error_code() );
	}

	public function test_execute_returns_error_on_invalid_post_id(): void {
		$ability = new BuildFromFigmaReference();
		$result  = $ability->execute( [
			'figma_file_key' => 'zfoLm0i7YDmVCowIsHlBDH',
			'figma_node_id'  => '97:8306',
			'post_id'        => 0,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_invalid_post_id', $result->get_error_code() );
	}

	public function test_label_is_non_empty_string(): void {
		$ability = new BuildFromFigmaReference();
		$this->assertNotEmpty( $ability->label() );
		$this->assertIsString( $ability->label() );
	}

	public function test_description_is_non_empty_string(): void {
		$ability = new BuildFromFigmaReference();
		$this->assertNotEmpty( $ability->description() );
		$this->assertIsString( $ability->description() );
	}
}
