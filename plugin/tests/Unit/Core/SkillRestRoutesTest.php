<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site\SkillTablesDouble;

/**
 * The public `stonewright/v1/skills` routes.
 *
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 */
final class SkillRestRoutesTest extends TestCase {

	private mixed $original_wpdb;

	private SkillTablesDouble $tables;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->tables                                = new SkillTablesDouble();
		$GLOBALS['wpdb']                             = $this->tables;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_rest_routes']     = [];
		RestRoutes::register();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                             = $this->original_wpdb;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_rest_routes']     = [];
	}

	public function test_routes_keep_their_declared_arguments(): void {
		$collection = $this->route( '/skills' );
		self::assertSame( [ 'GET', 'POST' ], array_column( $collection, 'methods' ) );
		self::assertSame( [ 'enabled_only', 'mode' ], array_keys( $collection[0]['args'] ) );
		self::assertSame( [ 'all', 'agentic', 'prompt' ], $collection[0]['args']['mode']['enum'] );
		self::assertSame( [ 'slug', 'title', 'description', 'content', 'enabled', 'enable_agentic', 'enable_prompt' ], array_keys( $collection[1]['args'] ) );
		self::assertSame( [ 'id', 'enabled' ], array_keys( $this->route( '/skills/(?P<id>\d+)/toggle' )['args'] ) );
		self::assertSame( 'DELETE', $this->route( '/skills/(?P<id>\d+)' )['methods'] );
	}

	public function test_create_returns_the_id_of_a_new_local_active_skill_and_upserts_by_slug(): void {
		$create = $this->route( '/skills' )[1]['callback'];

		$first = $this->data( $create( $this->request( [ 'slug' => 'site-note', 'title' => 'Site note', 'description' => 'Use when noting.', 'content' => '# One' ] ) ) );
		self::assertSame( [ 'id' => 1 ], $first );
		$row = $this->tables->skills[1];
		self::assertSame( [ 'user', 'active', '1', '1', '1', '1' ], [ $row['source'], $row['status'], $row['revision'], $row['enabled'], $row['enable_agentic'], $row['enable_prompt'] ] );
		self::assertSame( [], $this->tables->versions );

		$second = $this->data( $create( $this->request( [ 'slug' => 'site-note', 'title' => 'Site note', 'description' => 'Use when noting.', 'content' => '# Two', 'enable_prompt' => false ] ) ) );
		self::assertSame( [ 'id' => 1 ], $second );
		self::assertSame( [ '2', '# Two', '0' ], [ $this->tables->skills[1]['revision'], $this->tables->skills[1]['content'], $this->tables->skills[1]['enable_prompt'] ] );
		self::assertCount( 1, $this->tables->versions );
	}

	public function test_create_refuses_shipped_slugs_and_unauthorized_callers(): void {
		$this->tables->seed_skill( [ 'slug' => 'stonewright-alpha', 'title' => 'Alpha', 'content' => '# Alpha', 'source' => 'builtin' ] );
		$create = $this->route( '/skills' )[1]['callback'];

		$shipped = $create( $this->request( [ 'slug' => 'stonewright-alpha', 'title' => 'Mine', 'content' => '# Mine' ] ) );
		self::assertInstanceOf( \WP_Error::class, $shipped );
		self::assertSame( 403, $shipped->get_error_data()['status'] );

		$GLOBALS['stonewright_test_user_caps'] = [];
		$denied                                = $create( $this->request( [ 'slug' => 'site-note', 'title' => 'Note', 'content' => '# Note' ] ) );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'stonewright_skill_permission_denied', $denied->get_error_code() );
		self::assertCount( 1, $this->tables->skills );
	}

	public function test_list_filters_by_mode_and_enabled_state(): void {
		$this->tables->seed_skill( [ 'slug' => 'auto-skill', 'title' => 'Auto', 'content' => '# Auto', 'enable_prompt' => 0 ] );
		$this->tables->seed_skill( [ 'slug' => 'prompt-skill', 'title' => 'Prompt', 'content' => '# Prompt', 'enable_agentic' => 0 ] );
		$this->tables->seed_skill( [ 'slug' => 'off-skill', 'title' => 'Off', 'content' => '# Off', 'enabled' => 0 ] );
		$this->tables->seed_skill( [ 'slug' => 'binned', 'title' => 'Binned', 'content' => '# Binned', 'status' => 'trashed', 'enabled' => 0 ] );
		$list = $this->route( '/skills' )[0]['callback'];

		$all = $this->data( $list( $this->request( [ 'mode' => 'all' ] ) ) );
		self::assertSame( [ 'skills', 'count', 'mode' ], array_keys( $all ) );
		self::assertSame( [ 'auto-skill', 'prompt-skill', 'off-skill' ], array_column( $all['skills'], 'slug' ) );
		self::assertSame( 3, $all['count'] );
		self::assertSame( 'all', $all['mode'] );
		self::assertSame( [ 'auto-skill', 'prompt-skill' ], array_column( $this->data( $list( $this->request( [ 'enabled_only' => true ] ) ) )['skills'], 'slug' ) );
		self::assertSame( [ 'auto-skill' ], array_column( $this->data( $list( $this->request( [ 'mode' => 'agentic' ] ) ) )['skills'], 'slug' ) );
		$prompt = $this->data( $list( $this->request( [ 'mode' => 'prompt' ] ) ) );
		self::assertSame( [ 'prompt-skill' ], array_column( $prompt['skills'], 'slug' ) );
		self::assertSame( 'prompt', $prompt['mode'] );
		self::assertSame( '# Prompt', $prompt['skills'][0]['content'] );
	}

	public function test_toggle_flips_the_flag_without_a_revision(): void {
		$this->tables->seed_skill( [ 'id' => 6, 'slug' => 'site-note', 'title' => 'Note', 'description' => 'Use when noting.', 'content' => '# Note' ] );
		$toggle = $this->route( '/skills/(?P<id>\d+)/toggle' )['callback'];

		self::assertSame( [ 'id' => 6, 'enabled' => false ], $this->data( $toggle( $this->request( [ 'id' => 6, 'enabled' => false ] ) ) ) );
		self::assertSame( [ '0', '1' ], [ $this->tables->skills[6]['enabled'], $this->tables->skills[6]['revision'] ] );
		self::assertSame( [ 'id' => 6, 'enabled' => true ], $this->data( $toggle( $this->request( [ 'id' => 6, 'enabled' => true ] ) ) ) );
		self::assertSame( [], $this->tables->versions );

		$missing = $toggle( $this->request( [ 'id' => 60, 'enabled' => true ] ) );
		self::assertInstanceOf( \WP_Error::class, $missing );
	}

	public function test_delete_moves_a_local_skill_to_the_trash_and_refuses_shipped_ones(): void {
		$this->tables->seed_skill( [ 'id' => 7, 'slug' => 'site-note', 'title' => 'Note', 'content' => '# Note' ] );
		$this->tables->seed_skill( [ 'id' => 8, 'slug' => 'playbook-alpha', 'title' => 'Alpha', 'content' => '# Alpha', 'source' => 'playbook' ] );
		$delete = $this->route( '/skills/(?P<id>\d+)' )['callback'];

		self::assertSame( [ 'deleted' => true, 'id' => 7, 'status' => 'trashed' ], $this->data( $delete( $this->request( [ 'id' => 7 ] ) ) ) );
		self::assertSame( 'trashed', $this->tables->skills[7]['status'] );

		$shipped = $delete( $this->request( [ 'id' => 8 ] ) );
		self::assertInstanceOf( \WP_Error::class, $shipped );
		self::assertSame( 'stonewright_skill_builtin', $shipped->get_error_code() );

		$missing = $delete( $this->request( [ 'id' => 7 ] ) );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 404, $missing->get_error_data()['status'] );
	}

	/** @return array<string, mixed>|array<int, array<string, mixed>> */
	private function route( string $path ): array {
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			if ( 'stonewright/v1' === $route['namespace'] && $path === $route['route'] ) {
				return $route['args'];
			}
		}
		self::fail( 'Route not registered: ' . $path );
	}

	/** @param array<string, mixed> $params */
	private function request( array $params ): \WP_REST_Request {
		return new \WP_REST_Request( 'POST', '/stonewright/v1/skills', $params );
	}

	/** @return array<string, mixed> */
	private function data( mixed $response ): array {
		self::assertInstanceOf( \WP_REST_Response::class, $response, $response instanceof \WP_Error ? $response->get_error_code() . ': ' . $response->get_error_message() : '' );
		$data = $response->get_data();
		self::assertIsArray( $data );
		return $data;
	}
}
