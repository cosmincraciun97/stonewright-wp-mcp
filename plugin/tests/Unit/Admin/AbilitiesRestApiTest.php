<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AbilitiesPage;
use Stonewright\WpMcp\Admin\AbilitiesRestApi;
use Stonewright\WpMcp\Admin\AbilityHubCatalog;

/**
 * The page's own REST routes. They change the same option, with the same capability and the same nonces, as the
 * admin-post handlers behind the no-script form, so a switch does not need a page reload.
 *
 * @covers \Stonewright\WpMcp\Admin\AbilitiesRestApi
 */
final class AbilitiesRestApiTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_enabled' => true, 'stonewright_disabled_abilities' => [] ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 2;
		$GLOBALS['stonewright_test_rest_routes']     = [];
		unset( $GLOBALS['stonewright_test_nonce_invalid'] );
		AbilitiesRestApi::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_rest_routes']     = [];
		unset( $GLOBALS['stonewright_test_nonce_invalid'] );
		AbilitiesRestApi::reset_for_tests();
	}

	/** @return list<string> */
	private static function disabled(): array {
		return array_values( (array) ( $GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] ?? [] ) );
	}

	/** @param array<string, mixed> $params */
	private function request( string $method, string $route, array $params = [], bool $signed = true ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, '/stonewright/v1/admin/abilities/' . $route, $params );
		if ( $signed ) {
			$request->set_header( 'X-WP-Nonce', 'rest-nonce' );
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

	public function test_registers_the_three_routes_once(): void {
		AbilitiesRestApi::register();
		AbilitiesRestApi::register();

		$routes = array_map(
			static fn ( array $route ): string => $route['args']['methods'] . ' ' . $route['route'],
			array_filter( $GLOBALS['stonewright_test_rest_routes'], static fn ( array $route ): bool => 'stonewright/v1' === $route['namespace'] )
		);

		self::assertSame(
			[ 'POST /admin/abilities/toggle', 'POST /admin/abilities/bulk', 'GET /admin/abilities/parameters' ],
			array_values( $routes )
		);
	}

	public function test_the_page_registers_the_routes_on_rest_api_init(): void {
		$GLOBALS['stonewright_test_actions'] = [];
		AbilitiesPage::register();

		$hooks = array_map( static fn ( array $action ): mixed => $action['callback'], $GLOBALS['stonewright_test_actions']['rest_api_init'] ?? [] );
		self::assertContains( [ AbilitiesRestApi::class, 'register' ], $hooks );
	}

	public function test_every_route_needs_manage_options_the_same_capability_as_the_form_handlers(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		foreach ( [
			[ 'abilities.toggle', $this->request( 'POST', 'toggle', [ 'name' => 'site-a/x', 'enabled' => true, 'action_nonce' => 'n' ] ) ],
			[ 'abilities.bulk', $this->request( 'POST', 'bulk', [ 'action' => 'enable_selected', 'action_nonce' => 'n' ] ) ],
			[ 'abilities.parameters', $this->request( 'GET', 'parameters', [ 'name' => 'site-a/x' ] ) ],
		] as [ $route, $request ] ) {
			$refused = AbilitiesRestApi::check_permission( $route, $request );
			self::assertInstanceOf( \WP_Error::class, $refused, $route );
			self::assertSame( 'rest_forbidden', $refused->get_error_code(), $route );
			self::assertSame( 403, $refused->get_error_data()['status'], $route );
		}
	}

	public function test_a_write_needs_the_rest_nonce_and_the_nonce_of_the_form_it_stands_in_for(): void {
		$toggle = [ 'name' => 'site-a/x', 'enabled' => true, 'action_nonce' => 'n' ];

		self::assertTrue( AbilitiesRestApi::check_permission( 'abilities.toggle', $this->request( 'POST', 'toggle', $toggle ) ) );

		$no_rest_nonce = AbilitiesRestApi::check_permission( 'abilities.toggle', $this->request( 'POST', 'toggle', $toggle, false ) );
		self::assertInstanceOf( \WP_Error::class, $no_rest_nonce );
		self::assertSame( 'stonewright_abilities_invalid_nonce', $no_rest_nonce->get_error_code() );

		unset( $toggle['action_nonce'] );
		$no_form_nonce = AbilitiesRestApi::check_permission( 'abilities.toggle', $this->request( 'POST', 'toggle', $toggle ) );
		self::assertInstanceOf( \WP_Error::class, $no_form_nonce );
		self::assertSame( 'stonewright_abilities_invalid_nonce', $no_form_nonce->get_error_code() );

		$GLOBALS['stonewright_test_nonce_invalid'] = true;
		$stale                                     = AbilitiesRestApi::check_permission( 'abilities.bulk', $this->request( 'POST', 'bulk', [ 'action' => 'enable_selected', 'action_nonce' => 'n' ] ) );
		self::assertInstanceOf( \WP_Error::class, $stale );
		self::assertSame( 403, $stale->get_error_data()['status'] );
	}

	public function test_reading_the_parameters_needs_no_nonce(): void {
		self::assertTrue( AbilitiesRestApi::check_permission( 'abilities.parameters', $this->request( 'GET', 'parameters', [ 'name' => 'site-a/x' ], false ) ) );
	}

	public function test_toggle_changes_the_option_like_the_form_handler_and_returns_the_new_counts(): void {
		$name = (string) AbilityHubCatalog::collect()[0]['name'];

		$off = $this->data( AbilitiesRestApi::toggle( $this->request( 'POST', 'toggle', [ 'name' => $name, 'enabled' => false ] ) ) );

		self::assertSame( [ true, 'disabled', $name, false ], [ $off['ok'], $off['code'], $off['name'], $off['enabled'] ] );
		self::assertSame( [ $name ], self::disabled() );
		foreach ( [ 'enabled', 'write', 'read', 'total' ] as $key ) {
			self::assertIsInt( $off['stats'][ $key ] );
		}

		$on = $this->data( AbilitiesRestApi::toggle( $this->request( 'POST', 'toggle', [ 'name' => $name, 'enabled' => true ] ) ) );

		self::assertSame( [ true, 'enabled' ], [ $on['ok'], $on['code'] ] );
		self::assertSame( [], self::disabled() );
		self::assertSame( $off['stats']['enabled'] + 1, $on['stats']['enabled'] );
	}

	public function test_toggle_without_a_name_is_a_bad_request_and_writes_nothing(): void {
		$result = AbilitiesRestApi::toggle( $this->request( 'POST', 'toggle', [ 'name' => '', 'enabled' => false ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_name_missing', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
		self::assertSame( [], self::disabled() );
	}

	public function test_bulk_runs_the_form_handlers_action_and_says_how_many_changed(): void {
		$names = array_slice( AbilityHubCatalog::names(), 0, 2 );

		$data = $this->data( AbilitiesRestApi::bulk( $this->request( 'POST', 'bulk', [ 'action' => 'disable_selected', 'abilities' => $names ] ) ) );

		self::assertSame( [ true, 'bulk-disabled', 2, false ], [ $data['ok'], $data['code'], $data['changed'], $data['enabled'] ] );
		self::assertEqualsCanonicalizing( $names, $data['names'] );
		self::assertEqualsCanonicalizing( $names, self::disabled() );
		self::assertArrayHasKey( 'stats', $data );
		self::assertStringContainsString( '2', $data['message'] );
	}

	public function test_bulk_refusals_are_bad_requests_with_the_same_words_the_page_shows(): void {
		$cases = [
			[ [ 'action' => '' ], 'stonewright_bulk_no_action', 'Choose a bulk action' ],
			[ [ 'action' => 'enable_selected', 'abilities' => [] ], 'stonewright_bulk_no_selection', 'Select at least one ability' ],
			[ [ 'action' => 'enable_category', 'category' => '' ], 'stonewright_bulk_no_category', 'Choose a category' ],
		];

		foreach ( $cases as [ $params, $code, $words ] ) {
			$result = AbilitiesRestApi::bulk( $this->request( 'POST', 'bulk', $params ) );
			self::assertInstanceOf( \WP_Error::class, $result, $code );
			self::assertSame( $code, $result->get_error_code() );
			self::assertSame( 400, $result->get_error_data()['status'] );
			self::assertStringContainsString( $words, $result->get_error_message() );
		}
		self::assertSame( [], self::disabled() );
	}

	public function test_parameters_lists_name_type_required_and_description_of_one_ability(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_abilities_hub_external'] = static function ( array $abilities ): array {
			$abilities[] = [
				'name'          => 'site-a/get-head',
				'label'         => 'Get head',
				'description'   => 'Read head data.',
				'category'      => 'seo',
				'mcp_tool_name' => 'site-a-get-head',
				'input_schema'  => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer', 'description' => 'The post to read.' ],
						'fields'  => [ 'type' => [ 'array', 'null' ] ],
					],
					'required'   => [ 'post_id' ],
				],
			];

			return $abilities;
		};

		$data = $this->data( AbilitiesRestApi::parameters( $this->request( 'GET', 'parameters', [ 'name' => 'site-a/get-head' ] ) ) );
		unset( $GLOBALS['stonewright_test_filters']['stonewright_abilities_hub_external'] );

		self::assertSame( 'site-a/get-head', $data['name'] );
		self::assertSame(
			[
				[ 'name' => 'post_id', 'type' => 'integer', 'required' => true, 'description' => 'The post to read.' ],
				[ 'name' => 'fields', 'type' => 'array|null', 'required' => false, 'description' => '' ],
			],
			$data['parameters']
		);
	}

	public function test_parameters_of_an_unknown_ability_is_not_found(): void {
		$result = AbilitiesRestApi::parameters( $this->request( 'GET', 'parameters', [ 'name' => 'site-a/not-an-ability' ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}
}
