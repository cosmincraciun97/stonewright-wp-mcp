<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SkillsRestApi;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site\SkillTablesDouble;

/**
 * @covers \Stonewright\WpMcp\Admin\SkillsRestApi
 */
final class SkillsRestApiTest extends TestCase {

	private const UPLOAD = "---\nname: Studio guide\ndescription: Use when testing the studio routes.\n---\n\n# Studio guide\n\nSteps.\n";

	private mixed $original_wpdb;

	private SkillTablesDouble $tables;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->tables                                = new SkillTablesDouble();
		$GLOBALS['wpdb']                             = $this->tables;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 2;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_rest_routes']     = [];
		unset( $GLOBALS['stonewright_test_nonce_invalid'] );
		SkillsRestApi::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                             = $this->original_wpdb;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_rest_routes']     = [];
		SkillsRestApi::reset_for_tests();
	}

	public function test_registers_the_studio_routes_once(): void {
		SkillsRestApi::register();
		SkillsRestApi::register();

		$routes = array_map(
			static fn( array $route ): string => $route['args']['methods'] . ' ' . $route['route'],
			array_filter( $GLOBALS['stonewright_test_rest_routes'], static fn( array $route ): bool => 'stonewright/v1' === $route['namespace'] )
		);
		self::assertSame(
			[
				'GET /skills-studio/catalog',
				'POST /skills-studio/import/inspect',
				'POST /skills-studio/import',
				'GET /skills-studio/skills/(?P<id>\d+)/export',
				'POST /skills-studio/skills/(?P<id>\d+)/trash',
				'POST /skills-studio/skills/(?P<id>\d+)/restore',
				'DELETE /skills-studio/skills/(?P<id>\d+)',
			],
			array_values( $routes )
		);
	}

	public function test_permission_needs_manage_options_and_a_nonce_for_writes(): void {
		self::assertTrue( SkillsRestApi::check_permission( 'skills.catalog', $this->request( 'GET' ) ) );

		$unsigned = SkillsRestApi::check_permission( 'skills.trash', $this->request( 'POST', [], false ) );
		self::assertInstanceOf( \WP_Error::class, $unsigned );
		self::assertSame( 'stonewright_skills_invalid_nonce', $unsigned->get_error_code() );
		self::assertTrue( SkillsRestApi::check_permission( 'skills.trash', $this->request( 'POST' ) ) );

		$GLOBALS['stonewright_test_user_caps'] = [];
		$forbidden                             = SkillsRestApi::check_permission( 'skills.catalog', $this->request( 'GET' ) );
		self::assertInstanceOf( \WP_Error::class, $forbidden );
		self::assertSame( 403, $forbidden->get_error_data()['status'] );
	}

	public function test_catalog_returns_skills_conflicts_sources_and_trash(): void {
		$this->tables->seed_skill( [ 'slug' => 'stonewright-alpha', 'title' => 'Alpha', 'content' => '# Alpha', 'source' => 'builtin' ] );
		$this->tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Note', 'content' => '# Note' ] );
		$this->tables->seed_skill( [ 'slug' => 'binned', 'title' => 'Binned', 'content' => '# Binned', 'status' => 'trashed', 'enabled' => 0, 'trashed_at' => '2026-10-06 08:00:00' ] );

		$data = $this->data( SkillsRestApi::handle( 'skills.catalog', $this->request( 'GET' ) ) );

		self::assertTrue( $data['ok'] );
		self::assertSame( [ 'stonewright-alpha', 'site-note' ], array_column( $data['skills'], 'slug' ) );
		self::assertSame( [ 'binned' ], array_column( $data['trashed'], 'slug' ) );
		self::assertSame( '2026-10-06 08:00:00', $data['trashed'][0]['trashed_at'] );
		self::assertSame( [ 'builtin', 'local' ], array_column( $data['sources'], 'kind' ) );
		self::assertSame( [], $data['conflicts'] );
		self::assertSame( '1', $data['skills'][1]['revision'] );
	}

	public function test_inspect_then_import_lands_one_disabled_draft(): void {
		$inspected = $this->data( SkillsRestApi::handle( 'skills.inspect', $this->request( 'POST', [ 'filename' => 'studio-guide.md', 'content' => self::UPLOAD ] ) ) );

		self::assertTrue( $inspected['ok'] );
		$inspection = $inspected['inspection'];
		foreach ( [ 'slug', 'title', 'description', 'content', 'bytes', 'content_hash', 'body_hash', 'lint', 'trust', 'collision', 'ready_to_import' ] as $key ) {
			self::assertArrayHasKey( $key, $inspection );
		}
		self::assertSame( [ 'errors', 'warnings', 'word_count' ], array_keys( $inspection['lint'] ) );
		self::assertSame( [ 'findings', 'blocked' ], array_keys( $inspection['trust'] ) );
		self::assertSame( [ 'exists', 'source' ], array_keys( $inspection['collision'] ) );
		self::assertTrue( $inspection['ready_to_import'] );
		self::assertSame( [], $this->tables->skills );

		$imported = $this->data( SkillsRestApi::handle( 'skills.import', $this->request( 'POST', [ 'inspection' => $inspection ] ) ) );

		self::assertSame( [ 'ok' => true, 'skill_id' => 1 ], $imported );
		$row = $this->tables->skills[1];
		self::assertSame( [ 'studio-guide', 'uploaded', 'draft', '0', '0', '0', '1' ], [ $row['slug'], $row['source'], $row['status'], $row['enabled'], $row['enable_agentic'], $row['enable_prompt'], $row['revision'] ] );

		$collision = SkillsRestApi::handle( 'skills.import', $this->request( 'POST', [ 'inspection' => $inspection, 'content' => self::UPLOAD, 'filename' => 'studio-guide.md' ] ) );
		self::assertInstanceOf( \WP_Error::class, $collision );
		self::assertSame( 'stonewright_skill_import_collision', $collision->get_error_code() );
		self::assertCount( 1, $this->tables->skills );
	}

	public function test_inspect_and_import_refuse_unusable_requests(): void {
		$binary = SkillsRestApi::handle( 'skills.inspect', $this->request( 'POST', [ 'filename' => 'x.md', 'content' => [ 'not text' ] ] ) );
		self::assertInstanceOf( \WP_Error::class, $binary );
		self::assertSame( 'Upload the file contents as text.', $binary->get_error_message() );

		$unnamed = SkillsRestApi::handle( 'skills.inspect', $this->request( 'POST', [ 'content' => self::UPLOAD ] ) );
		self::assertInstanceOf( \WP_Error::class, $unnamed );
		self::assertSame( 'Skills import from Markdown only. Upload a .md file.', $unnamed->get_error_message() );
		self::assertSame( 400, $unnamed->get_error_data()['status'] );

		$blind = SkillsRestApi::handle( 'skills.import', $this->request( 'POST', [ 'content' => self::UPLOAD, 'filename' => 'studio-guide.md' ] ) );
		self::assertInstanceOf( \WP_Error::class, $blind );
		self::assertSame( 'Inspect the file before importing it.', $blind->get_error_message() );

		$echoed = SkillsRestApi::handle( 'skills.import', $this->request( 'POST', [ 'inspection' => [ 'slug' => 'studio-guide', 'content_hash' => hash( 'sha256', self::UPLOAD ) ], 'content' => self::UPLOAD, 'filename' => 'studio-guide.md' ] ) );
		self::assertInstanceOf( \WP_Error::class, $echoed );
		self::assertSame( 'Inspect the file before importing it.', $echoed->get_error_message() );
		self::assertSame( [], $this->tables->skills );
	}

	public function test_export_returns_the_file_name_and_markdown(): void {
		$this->tables->seed_skill( [ 'id' => 4, 'slug' => 'site-note', 'title' => 'Note', 'description' => 'Use when noting.', 'content' => "# Note\n" ] );

		$data = $this->data( SkillsRestApi::handle( 'skills.export', $this->request( 'GET', [ 'id' => '4' ] ) ) );

		self::assertSame( [ 'ok', 'filename', 'markdown' ], array_keys( $data ) );
		self::assertSame( 'site-note.md', $data['filename'] );
		self::assertStringStartsWith( "---\nname: \"Note\"\ndescription: \"Use when noting.\"\nslug: \"site-note\"\n", $data['markdown'] );

		$missing = SkillsRestApi::handle( 'skills.export', $this->request( 'GET', [ 'id' => '99' ] ) );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 404, $missing->get_error_data()['status'] );
	}

	public function test_trash_restore_and_destroy_report_their_action(): void {
		$this->tables->seed_skill( [ 'id' => 5, 'slug' => 'site-note', 'title' => 'Note', 'description' => 'Use when noting.', 'content' => '# Note' ] );

		self::assertSame( [ 'ok' => true, 'skill_id' => 5, 'action' => 'trash' ], $this->data( SkillsRestApi::handle( 'skills.trash', $this->request( 'POST', [ 'id' => '5' ] ) ) ) );
		self::assertSame( 'trashed', $this->tables->skills[5]['status'] );
		self::assertNotNull( $this->tables->skills[5]['trashed_at'] );

		self::assertSame( [ 'ok' => true, 'skill_id' => 5, 'action' => 'restore' ], $this->data( SkillsRestApi::handle( 'skills.restore', $this->request( 'POST', [ 'id' => '5' ] ) ) ) );
		self::assertSame( [ 'draft', '0', null ], [ $this->tables->skills[5]['status'], $this->tables->skills[5]['enabled'], $this->tables->skills[5]['trashed_at'] ] );

		$live = SkillsRestApi::handle( 'skills.destroy', $this->request( 'DELETE', [ 'id' => '5' ] ) );
		self::assertInstanceOf( \WP_Error::class, $live, 'Only a trashed skill can be erased.' );

		SkillsRestApi::handle( 'skills.trash', $this->request( 'POST', [ 'id' => '5' ] ) );
		self::assertSame( [ 'ok' => true, 'skill_id' => 5, 'action' => 'destroy' ], $this->data( SkillsRestApi::handle( 'skills.destroy', $this->request( 'DELETE', [ 'id' => '5' ] ) ) ) );
		self::assertSame( [], $this->tables->skills );
	}

	public function test_destroy_in_production_safe_mode_needs_a_token_for_that_skill(): void {
		$this->tables->seed_skill( [ 'id' => 8, 'slug' => 'binned', 'title' => 'Binned', 'content' => '# Binned', 'status' => 'trashed', 'enabled' => 0 ] );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$missing = SkillsRestApi::handle( 'skills.destroy', $this->request( 'DELETE', [ 'id' => '8' ] ) );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_confirmation_required', $missing->get_error_code() );
		self::assertArrayHasKey( 8, $this->tables->skills );

		$token = ConfirmationToken::issue( 'stonewright/skills-destroy', [ 'id' => 8 ] );
		$data  = $this->data( SkillsRestApi::handle( 'skills.destroy', $this->request( 'DELETE', [ 'id' => '8', 'confirmation_token' => $token ] ) ) );
		self::assertSame( 'destroy', $data['action'] );
		self::assertSame( [], $this->tables->skills );
	}

	public function test_shipped_skills_cannot_be_trashed_and_ids_must_be_positive(): void {
		$this->tables->seed_skill( [ 'id' => 3, 'slug' => 'stonewright-alpha', 'title' => 'Alpha', 'content' => '# Alpha', 'source' => 'builtin' ] );

		$shipped = SkillsRestApi::handle( 'skills.trash', $this->request( 'POST', [ 'id' => '3' ] ) );
		self::assertInstanceOf( \WP_Error::class, $shipped );
		self::assertSame( 'stonewright_skill_builtin', $shipped->get_error_code() );
		self::assertSame( 403, $shipped->get_error_data()['status'] );
		self::assertSame( 'active', $this->tables->skills[3]['status'] );

		$zero = SkillsRestApi::handle( 'skills.trash', $this->request( 'POST', [ 'id' => '0' ] ) );
		self::assertInstanceOf( \WP_Error::class, $zero );
		self::assertSame( SkillsRestApi::INVALID_ID_CODE, $zero->get_error_code() );
	}

	/** @param array<string, mixed> $params */
	private function request( string $method, array $params = [], bool $signed = true ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, '/stonewright/v1/skills-studio', $params );
		if ( $signed ) {
			$request->set_header( 'X-WP-Nonce', 'nonce-value' );
		}
		return $request;
	}

	/** @return array<string, mixed> */
	private function data( mixed $response ): array {
		self::assertInstanceOf( \WP_REST_Response::class, $response, $response instanceof \WP_Error ? $response->get_error_code() . ': ' . $response->get_error_message() : '' );
		$data = $response->get_data();
		self::assertIsArray( $data );
		return $data;
	}
}
