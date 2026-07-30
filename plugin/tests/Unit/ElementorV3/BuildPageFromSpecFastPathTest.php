<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Workflow\DesignCheckpoint;
use Stonewright\WpMcp\Elementor\Renderer\Section;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec
 */
final class BuildPageFromSpecFastPathTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			777 => (object) [
				'ID'           => 777,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Spec target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"keep","elType":"container","settings":[],"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode'                     => 'development',
			DesignDirectionService::ACTIVE_OPTION  => 9101,
			'stonewright_design_checkpoint_secret' => str_repeat( 'c', 64 ),
		];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_filters'] = [];

		global $wpdb;
		if ( property_exists( $wpdb, 'direction_rows' ) ) {
			$contract = [ 'schema_version' => '1.0.0', 'name' => 'Quarry', 'summary' => 'Stone and precision.' ];

			$wpdb->direction_rows = [
				9101 => [
					'id'               => 9101,
					'slug'             => 'quarry',
					'status'           => 'ready',
					'contract_json'    => (string) wp_json_encode( $contract ),
					'contract_hash'    => DesignDirectionService::hash( $contract ),
					'source_type'      => 'manual',
					'source_refs_json' => '[]',
					'revision'         => 1,
					'created_at'       => '2026-07-01 00:00:00',
					'updated_at'       => '2026-07-01 00:00:00',
				],
			];
		}
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_filters'] = [];

		global $wpdb;
		if ( property_exists( $wpdb, 'direction_rows' ) ) {
			$wpdb->direction_rows = [];
		}
	}

	public function test_dry_run_renders_metrics_without_snapshot_or_write(): void {
		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id' => 777,
				'dry_run' => true,
				'spec'    => self::spec( 'Dry Run' ),
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 2, $result['elements'] );
		self::assertArrayHasKey( 'preview', $result );
		self::assertGreaterThanOrEqual( 0.0, $result['metrics']['elapsed_ms'] );
		self::assertGreaterThanOrEqual( 0.0, $result['metrics']['render_ms'] );
		self::assertSame( 0.0, $result['metrics']['write_ms'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_strict_visual_spec_requires_design_evidence_before_render(): void {
		$spec = self::spec( 'Verified heading' );
		$spec['style_policy'] = 'strict';

		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id' => 777,
				'dry_run' => true,
				'spec'    => $spec,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_design_evidence_invalid', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_append_mode_keeps_existing_top_level_elements(): void {
		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id' => 777,
				'mode'    => 'append',
				'spec'    => self::spec( 'Append' ),
			]
		);

		self::assertIsArray( $result );

		$post = $GLOBALS['stonewright_test_posts'][777];
		$tree = json_decode( stripslashes( (string) $post->meta['_elementor_data'] ), true );
		self::assertSame( 'keep', $tree[0]['id'] );
		self::assertSame( Section::stable_id( 's0' ), $tree[1]['id'] );
	}

	public function test_replace_section_mode_replaces_matching_sections_only(): void {
		$section_id = Section::stable_id( 's0' );
		$GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] = wp_json_encode(
			[
				[ 'id' => 'keep', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
				[
					'id'       => $section_id,
					'elType'   => 'container',
					'settings' => [],
					'elements' => [
						[
							'id'         => 'old',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => [ 'title' => 'Old' ],
							'elements'   => [],
						],
					],
				],
			],
			JSON_UNESCAPED_SLASHES
		);

		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id' => 777,
				'mode'    => 'replace_section',
				'spec'    => self::spec( 'New' ),
			]
		);

		self::assertIsArray( $result );

		$post = $GLOBALS['stonewright_test_posts'][777];
		$tree = json_decode( stripslashes( (string) $post->meta['_elementor_data'] ), true );
		self::assertSame( 'keep', $tree[0]['id'] );
		self::assertSame( $section_id, $tree[1]['id'] );
		self::assertSame( 'New', $tree[1]['elements'][0]['settings']['title'] );
	}

	public function test_new_direction_may_write_its_first_section_without_approval(): void {
		$GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] = '[]';

		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id'      => 777,
				'design_scope' => 'new_identity',
				'spec'         => self::spec( 'First section' ),
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, count( json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] ), true ) ) );
	}

	public function test_new_direction_is_blocked_past_the_first_section_without_a_checkpoint(): void {
		$GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] = '[]';
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id'      => 777,
				'design_scope' => 'rebrand',
				'spec'         => self::multi_section_spec(),
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( DesignCheckpoint::ERROR_REQUIRED, $result->get_error_code() );

		$data = $result->get_error_data();
		self::assertSame( 409, $data['status'] );
		self::assertSame( 1, $data['first_section_limit'] );
		self::assertSame( 3, $data['sections_requested'] );
		self::assertSame( DesignCheckpoint::CONTINUATION_ABILITY, $data['next_action']['ability'] );
		self::assertNotEmpty( $data['loop'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_verified_checkpoint_unblocks_the_remaining_sections(): void {
		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id'          => 777,
				'design_scope'     => 'rebrand',
				'mode'             => 'append',
				'checkpoint_token' => $this->checkpoint_token(),
				'spec'             => self::multi_section_spec(),
			]
		);

		self::assertIsArray( $result );

		$tree = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] ), true );
		self::assertSame( 'keep', $tree[0]['id'] );
		self::assertCount( 4, $tree );
	}

	public function test_checkpoint_stops_working_once_the_approved_section_changes(): void {
		$token = $this->checkpoint_token();

		$GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] = (string) wp_json_encode(
			[ [ 'id' => 'keep', 'elType' => 'container', 'settings' => [ 'padding' => '40px' ], 'elements' => [] ] ],
			JSON_UNESCAPED_SLASHES
		);
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id'          => 777,
				'design_scope'     => 'rebrand',
				'mode'             => 'append',
				'checkpoint_token' => $token,
				'spec'             => self::multi_section_spec(),
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( DesignCheckpoint::ERROR_MISMATCH, $result->get_error_code() );
		self::assertSame( 'render_hash', $result->get_error_data()['field'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_preservation_work_gains_no_new_blocker(): void {
		foreach ( DesignCheckpoint::OPEN_SCOPES as $scope ) {
			$GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] = '[]';

			$result = ( new BuildPageFromSpec() )->execute(
				[
					'post_id'      => 777,
					'design_scope' => $scope,
					'spec'         => self::multi_section_spec(),
				]
			);

			self::assertIsArray( $result, "Scope {$scope} must not be gated." );

			$tree = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] ), true );
			self::assertCount( 3, $tree, "Scope {$scope} must write all three sections." );
		}
	}

	public function test_omitted_scope_behaves_as_preserve(): void {
		$GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] = '[]';

		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id' => 777,
				'spec'    => self::multi_section_spec(),
			]
		);

		self::assertIsArray( $result );
	}

	public function test_dry_run_is_never_blocked_by_the_checkpoint(): void {
		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id'      => 777,
				'design_scope' => 'rebrand',
				'dry_run'      => true,
				'spec'         => self::multi_section_spec(),
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
	}

	/**
	 * Approval of the `keep` section that setUp seeds, bound to live state.
	 */
	private function checkpoint_token(): string {
		$existing = json_decode( (string) $GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'], true );

		return (string) DesignCheckpoint::issue(
			777,
			'keep',
			DesignCheckpoint::active_direction_hash(),
			DesignCheckpoint::section_render_hash( is_array( $existing ) ? $existing : [], 'keep' )
		)['token'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function multi_section_spec(): array {
		return [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Whole page' ],
			'sections' => [
				[ 'id' => 'hero', 'blocks' => [ [ 'type' => 'heading', 'text' => 'Hero', 'level' => 1 ] ] ],
				[ 'id' => 'features', 'blocks' => [ [ 'type' => 'heading', 'text' => 'Features', 'level' => 2 ] ] ],
				[ 'id' => 'contact', 'blocks' => [ [ 'type' => 'heading', 'text' => 'Contact', 'level' => 2 ] ] ],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function spec( string $title ): array {
		return [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Fast Path' ],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[ 'type' => 'heading', 'text' => $title, 'level' => 1 ],
					],
				],
			],
		];
	}
}
