<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Skills\SkillsGet;
use Stonewright\WpMcp\Abilities\Skills\SkillsList;
use Stonewright\WpMcp\Abilities\Skills\SkillsSave;
use Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site\SkillTablesDouble;

/**
 * Public input and output contracts of the three skill abilities on the site tables.
 *
 * @covers \Stonewright\WpMcp\Abilities\Skills\SkillsGet
 * @covers \Stonewright\WpMcp\Abilities\Skills\SkillsList
 * @covers \Stonewright\WpMcp\Abilities\Skills\SkillsSave
 */
final class SkillAbilityContractsTest extends TestCase {

	private mixed $original_wpdb;

	private SkillTablesDouble $tables;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->tables                                = new SkillTablesDouble();
		$GLOBALS['wpdb']                             = $this->tables;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                             = $this->original_wpdb;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	public function test_schemas_keep_their_public_shape(): void {
		self::assertSame( [ 'slug' ], ( new SkillsGet() )->input_schema()['required'] );
		self::assertSame( [ 'skill', 'found' ], ( new SkillsGet() )->output_schema()['required'] );
		self::assertSame( [ 'all', 'agentic', 'prompt', 'discover' ], ( new SkillsList() )->input_schema()['properties']['mode']['enum'] );
		self::assertSame( [ 'skills', 'count', 'mode' ], ( new SkillsList() )->output_schema()['required'] );
		self::assertSame( [ 'slug', 'title', 'content' ], ( new SkillsSave() )->input_schema()['required'] );
		self::assertSame( [ 'id', 'slug', 'updated' ], ( new SkillsSave() )->output_schema()['required'] );
		self::assertArrayHasKey( 'confirmation_token', ( new SkillsSave() )->input_schema()['properties'] );
		self::assertSame( 'integer', ( new SkillsSave() )->input_schema()['properties']['revision']['type'] ?? null, 'The optional revision lets a caller refuse to overwrite a newer change.' );
		self::assertNotContains( 'revision', ( new SkillsSave() )->input_schema()['required'] );
	}

	public function test_get_returns_the_full_row_or_reports_it_missing(): void {
		$this->tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Note', 'description' => 'Use when noting.', 'content' => '# Note body' ] );
		$this->tables->seed_skill( [ 'slug' => 'binned', 'title' => 'Binned', 'content' => '# Binned', 'status' => 'trashed', 'enabled' => 0 ] );

		$found = ( new SkillsGet() )->execute( [ 'slug' => 'site-note' ] );
		self::assertIsArray( $found );
		self::assertTrue( $found['found'] );
		self::assertSame( '# Note body', $found['skill']['content'] );
		self::assertSame( [], $found['skill']['version_constraints'] );

		self::assertSame( [ 'skill' => null, 'found' => false ], ( new SkillsGet() )->execute( [ 'slug' => 'missing' ] ) );
		self::assertSame( [ 'skill' => null, 'found' => false ], ( new SkillsGet() )->execute( [ 'slug' => 'binned' ] ) );
	}

	public function test_get_refuses_a_skill_whose_required_component_is_missing(): void {
		$this->tables->seed_skill( [ 'slug' => 'needs-plugin', 'title' => 'Needs plugin', 'content' => '# Needs', 'version_constraints_json' => '{"absent-test-plugin":">=2.0"}' ] );

		$result = ( new SkillsGet() )->execute( [ 'slug' => 'needs-plugin' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_unavailable', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( 'absent-test-plugin', $result->get_error_data()['missing_component'] );
		self::assertSame( '>=2.0', $result->get_error_data()['version_constraint'] );
	}

	public function test_list_projects_modes_and_omits_bodies_unless_asked(): void {
		$this->tables->seed_skill( [ 'slug' => 'auto-skill', 'title' => 'Auto', 'description' => 'Use when automating.', 'content' => '# Auto body', 'enable_prompt' => 0 ] );
		$this->tables->seed_skill( [ 'slug' => 'prompt-skill', 'title' => 'Prompt', 'description' => 'Use on request.', 'content' => '# Prompt', 'enable_agentic' => 0 ] );
		$this->tables->seed_skill( [ 'slug' => 'needs-plugin', 'title' => 'Needs', 'description' => 'Use with the plugin.', 'content' => '# Needs', 'version_constraints_json' => '{"absent-test-plugin":"required"}' ] );

		$all = ( new SkillsList() )->execute( [] );
		self::assertIsArray( $all );
		self::assertSame( [ 'all', 3 ], [ $all['mode'], $all['count'] ] );
		self::assertArrayNotHasKey( 'content', $all['skills'][0] );
		self::assertSame( strlen( '# Auto body' ), $all['skills'][0]['content_length'] );

		$with_bodies = ( new SkillsList() )->execute( [ 'include_content' => true ] );
		self::assertIsArray( $with_bodies );
		self::assertSame( '# Auto body', $with_bodies['skills'][0]['content'] );

		$agentic = ( new SkillsList() )->execute( [ 'mode' => 'agentic' ] );
		self::assertIsArray( $agentic );
		self::assertSame( [ 'auto-skill' ], array_column( $agentic['skills'], 'slug' ) );

		$discover = ( new SkillsList() )->execute( [ 'mode' => 'discover' ] );
		self::assertIsArray( $discover );
		self::assertSame(
			[
				[
					'slug'        => 'auto-skill',
					'description' => 'Use when automating.',
				],
				[
					'slug'        => 'prompt-skill',
					'description' => 'Use on request.',
				],
			],
			$discover['skills']
		);
	}

	public function test_save_upserts_and_reports_whether_it_updated(): void {
		$args = [
			'slug'        => 'site-note',
			'title'       => 'Note',
			'description' => 'Use when noting.',
			'content'     => '# One',
		];

		$created = ( new SkillsSave() )->execute( $args );
		self::assertSame(
			[
				'id'      => 1,
				'slug'    => 'site-note',
				'updated' => false,
			],
			$created
		);

		$updated = ( new SkillsSave() )->execute( array_replace( $args, [ 'content' => '# Two' ] ) );
		self::assertSame(
			[
				'id'      => 1,
				'slug'    => 'site-note',
				'updated' => true,
			],
			$updated
		);
		self::assertSame( '2', $this->tables->skills[1]['revision'] );
	}

	public function test_save_with_a_revision_refuses_a_stale_base_and_without_one_saves_as_before(): void {
		$args = [
			'slug'        => 'site-note',
			'title'       => 'Note',
			'description' => 'Use when noting.',
			'content'     => '# One',
		];
		( new SkillsSave() )->execute( $args );
		$read = ( new SkillsGet() )->execute( [ 'slug' => 'site-note' ] );
		self::assertIsArray( $read );
		self::assertSame( '1', $read['skill']['revision'] );

		$current = ( new SkillsSave() )->execute( array_replace( $args, [ 'content' => '# Two', 'revision' => 1 ] ) );
		self::assertSame( [ 'id' => 1, 'slug' => 'site-note', 'updated' => true ], $current );
		self::assertSame( [ '# Two', '2' ], [ $this->tables->skills[1]['content'], $this->tables->skills[1]['revision'] ] );

		$stale = ( new SkillsSave() )->execute( array_replace( $args, [ 'content' => '# From the old read', 'revision' => 1 ] ) );
		self::assertInstanceOf( \WP_Error::class, $stale );
		self::assertSame( 'stonewright_skill_write_conflict', $stale->get_error_code() );
		self::assertSame( 409, $stale->get_error_data()['status'] );
		self::assertSame( [ '# Two', '2' ], [ $this->tables->skills[1]['content'], $this->tables->skills[1]['revision'] ] );

		$unconditional = ( new SkillsSave() )->execute( array_replace( $args, [ 'content' => '# Three' ] ) );
		self::assertSame( [ 'id' => 1, 'slug' => 'site-note', 'updated' => true ], $unconditional );
		self::assertSame( [ '# Three', '3' ], [ $this->tables->skills[1]['content'], $this->tables->skills[1]['revision'] ] );
	}

	public function test_save_refuses_shipped_skills_with_a_status(): void {
		$this->tables->seed_skill( [ 'slug' => 'stonewright-alpha', 'title' => 'Alpha', 'content' => '# Alpha', 'source' => 'builtin' ] );

		$result = ( new SkillsSave() )->execute(
			[
				'slug'    => 'stonewright-alpha',
				'title'   => 'Mine',
				'content' => '# Mine',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
		self::assertSame( '# Alpha', $this->tables->skill_by_slug( 'stonewright-alpha' )['content'] ?? null );
	}
}
