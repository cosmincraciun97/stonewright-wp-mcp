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
		$this->assertArrayHasKey( 'confirmation_token', $schema['properties'] );
		$this->assertSame( 'boolean', $schema['properties']['dry_run']['type'] );
		$this->assertSame( 'boolean', $schema['properties']['skip_qa']['type'] );
	}

	public function test_non_dry_run_gutenberg_renderer_updates_post_content(): void {
		$GLOBALS['stonewright_test_posts'][1] = (object) [
			'ID'           => 1,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Target',
			'post_content' => 'Before',
			'post_excerpt' => '',
		];
		update_option( 'stonewright_mode', 'development' );

		$ability = new BuildFromFigmaReference();
		$result  = $ability->execute( [
			'figma_file_key' => 'zfoLm0i7YDmVCowIsHlBDH',
			'figma_node_id'  => '97:8306',
			'post_id'        => 1,
			'renderer'       => 'gutenberg',
			'skip_qa'        => true,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'gutenberg', $result['renderer'] );
		$this->assertStringContainsString( '<!-- wp:core/group', (string) $GLOBALS['stonewright_test_posts'][1]->post_content );
		$this->assertStringContainsString( 'Hello', (string) $GLOBALS['stonewright_test_posts'][1]->post_content );
		$this->assertSame( 'stonewright/design-spec-to-gutenberg', $this->step_value( $result['steps'], 'apply', 'ability' ) );
	}

	public function test_execute_surfaces_non_fatal_figma_ingest_warnings(): void {
		$GLOBALS['stonewright_test_companion_responses']['/figma-ingest'] = [
			'spec'        => [
				'version'  => '1.0.0',
				'page'     => [ 'title' => 'Contract Figma Page' ],
				'sections' => [
					[
						'id'     => 'hero',
						'blocks' => [
							[ 'type' => 'heading', 'text' => 'Hello', 'level' => 1 ],
						],
					],
				],
			],
			'warnings'    => [ 'Skipped decorative empty Figma container "Blur" (id: 1:2).' ],
			'asset_count' => 0,
		];

		$ability = new BuildFromFigmaReference();
		$result  = $ability->execute( [
			'figma_file_key' => 'zfoLm0i7YDmVCowIsHlBDH',
			'figma_node_id'  => '97:8306',
			'post_id'        => 1,
			'renderer'       => 'gutenberg',
			'dry_run'        => true,
			'skip_qa'        => true,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'Skipped decorative empty Figma container "Blur" (id: 1:2).' ], $result['warnings'] );
		$this->assertSame( 'ok', $this->step_value( $result['steps'], 'quality_gate', 'status' ) );
	}

	public function test_quality_gate_rejects_placeholder_figma_specs_before_apply(): void {
		$GLOBALS['stonewright_test_posts'][1] = (object) [
			'ID'           => 1,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Target',
			'post_content' => 'Before',
			'post_excerpt' => '',
		];
		update_option( 'stonewright_mode', 'development' );

		$GLOBALS['stonewright_test_companion_responses']['/figma-ingest'] = [
			'spec'        => [
				'version'  => '1.0.0',
				'page'     => [ 'title' => 'Empty Figma Page' ],
				'sections' => [
					[
						'id'     => 'section_placeholder',
						'blocks' => [
							[ 'type' => 'paragraph', 'text' => 'Empty design - no sections found.' ],
						],
					],
				],
			],
			'warnings'    => [ 'Figma node produced no mappable sections - generating a placeholder section.' ],
			'asset_count' => 0,
		];

		$ability = new BuildFromFigmaReference();
		$result  = $ability->execute( [
			'figma_file_key' => 'zfoLm0i7YDmVCowIsHlBDH',
			'figma_node_id'  => '97:8306',
			'post_id'        => 1,
			'renderer'       => 'gutenberg',
			'skip_qa'        => true,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_figma_quality_gate_failed', $result->get_error_code() );
		$this->assertSame( 'Before', (string) $GLOBALS['stonewright_test_posts'][1]->post_content );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'error', $this->step_value( $data['steps'], 'quality_gate', 'status' ) );
	}

	public function test_quality_gate_rejects_excessively_lossy_figma_ingest_before_apply(): void {
		$GLOBALS['stonewright_test_posts'][1] = (object) [
			'ID'           => 1,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Target',
			'post_content' => 'Before',
			'post_excerpt' => '',
		];
		update_option( 'stonewright_mode', 'development' );

		$GLOBALS['stonewright_test_companion_responses']['/figma-ingest'] = [
			'spec'        => [
				'version'  => '1.0.0',
				'page'     => [ 'title' => 'Lossy Figma Page' ],
				'sections' => [
					[
						'id'     => 'hero',
						'blocks' => [
							[ 'type' => 'heading', 'text' => 'Hello', 'level' => 1 ],
						],
					],
				],
			],
			'warnings'    => array_fill( 0, 51, 'Unsupported Figma node type for "Vector" (id: 1:1) - skipped.' ),
			'asset_count' => 0,
		];

		$ability = new BuildFromFigmaReference();
		$result  = $ability->execute( [
			'figma_file_key' => 'zfoLm0i7YDmVCowIsHlBDH',
			'figma_node_id'  => '97:8306',
			'post_id'        => 1,
			'renderer'       => 'gutenberg',
			'skip_qa'        => true,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_figma_quality_gate_failed', $result->get_error_code() );
		$this->assertSame( 'Before', (string) $GLOBALS['stonewright_test_posts'][1]->post_content );
	}

	public function test_production_safe_non_dry_run_requires_orchestrator_confirmation_token(): void {
		$GLOBALS['stonewright_test_posts'][1] = (object) [
			'ID'           => 1,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Target',
			'post_content' => 'Before',
			'post_excerpt' => '',
		];
		update_option( 'stonewright_mode', 'production-safe' );

		$ability = new BuildFromFigmaReference();
		$result  = $ability->execute( [
			'figma_file_key' => 'zfoLm0i7YDmVCowIsHlBDH',
			'figma_node_id'  => '97:8306',
			'post_id'        => 1,
			'renderer'       => 'gutenberg',
			'skip_qa'        => true,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
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

	/**
	 * @param array<int, array<string, mixed>> $steps
	 */
	private function step_value( array $steps, string $step, string $key ): mixed {
		foreach ( $steps as $item ) {
			if ( ( $item['step'] ?? '' ) === $step ) {
				return $item[ $key ] ?? null;
			}
		}

		return null;
	}
}
