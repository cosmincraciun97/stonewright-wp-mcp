<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\ProtectedResource;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Using the MCP servers needs more than a signed-in account: every account holds `read`, and WordPress
 * lets any account create an Application Password. The capability is edit_posts unless the site filters it.
 *
 * @covers \Stonewright\WpMcp\Security\Permissions::mcp_required_capability
 * @covers \Stonewright\WpMcp\Security\Permissions::can_use_mcp_transport
 * @covers \Stonewright\WpMcp\Security\Permissions::user_can_use_mcp
 * @covers \Stonewright\WpMcp\Authorization\WordPress\ProtectedResource::current_user_can_use_mcp
 */
final class McpAccessCapabilityTest extends TestCase {

	private const SUBSCRIBER  = 31;
	private const CONTRIBUTOR = 32;
	private const AUTHOR      = 33;

	protected function setUp(): void {
		$this->reset();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [
			self::SUBSCRIBER  => [ 'read' => true ],
			self::CONTRIBUTOR => [ 'read' => true, 'edit_posts' => true ],
			self::AUTHOR      => [ 'read' => true, 'edit_posts' => true, 'publish_posts' => true ],
		];
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		unset( $GLOBALS['stonewright_test_filters']['stonewright_mcp_required_capability'] );
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_caps_by_id'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/** Signs in one of the fixture accounts for the current-user checks. */
	private function sign_in( int $user_id ): void {
		$GLOBALS['stonewright_test_current_user_id'] = $user_id;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = $GLOBALS['stonewright_test_user_caps_by_id'][ $user_id ] ?? [];
	}

	private function filter_to( mixed $value ): void {
		$GLOBALS['stonewright_test_filters']['stonewright_mcp_required_capability'] = static fn (): mixed => $value;
	}

	public function test_the_required_capability_is_edit_posts_by_default(): void {
		self::assertSame( 'edit_posts', Permissions::mcp_required_capability() );
	}

	public function test_the_site_can_lower_or_raise_the_required_capability(): void {
		$this->filter_to( 'read' );
		self::assertSame( 'read', Permissions::mcp_required_capability() );

		$this->filter_to( 'publish_posts' );
		self::assertSame( 'publish_posts', Permissions::mcp_required_capability() );
	}

	/** @return array<string, array{0: mixed}> */
	public static function unusable_filter_values(): array {
		return [
			'empty string' => [ '' ],
			'null'         => [ null ],
			'array'        => [ [ 'read' ] ],
			'boolean'      => [ false ],
		];
	}

	/** @dataProvider unusable_filter_values */
	public function test_a_filter_that_returns_no_capability_name_keeps_the_default( mixed $value ): void {
		$this->filter_to( $value );

		self::assertSame( 'edit_posts', Permissions::mcp_required_capability() );
	}

	public function test_the_transport_refuses_a_subscriber_and_admits_a_contributor_and_an_author(): void {
		$this->sign_in( self::SUBSCRIBER );
		self::assertFalse( Permissions::can_use_mcp_transport() );

		$this->sign_in( self::CONTRIBUTOR );
		self::assertTrue( Permissions::can_use_mcp_transport() );

		$this->sign_in( self::AUTHOR );
		self::assertTrue( Permissions::can_use_mcp_transport() );
	}

	public function test_the_transport_refuses_a_request_nobody_signed_in_for(): void {
		self::assertFalse( Permissions::can_use_mcp_transport() );
	}

	public function test_the_transport_follows_the_filtered_capability(): void {
		$this->filter_to( 'read' );
		$this->sign_in( self::SUBSCRIBER );
		self::assertTrue( Permissions::can_use_mcp_transport(), 'A site may lower the capability to read.' );

		$this->filter_to( 'publish_posts' );
		$this->sign_in( self::CONTRIBUTOR );
		self::assertFalse( Permissions::can_use_mcp_transport(), 'A site may raise the capability.' );
		$this->sign_in( self::AUTHOR );
		self::assertTrue( Permissions::can_use_mcp_transport() );
	}

	public function test_a_grant_subject_needs_the_same_capability(): void {
		self::assertFalse( Permissions::user_can_use_mcp( self::SUBSCRIBER ) );
		self::assertTrue( Permissions::user_can_use_mcp( self::CONTRIBUTOR ) );
		self::assertTrue( Permissions::user_can_use_mcp( self::AUTHOR ) );
		self::assertFalse( Permissions::user_can_use_mcp( 0 ) );

		$this->filter_to( 'read' );
		self::assertTrue( Permissions::user_can_use_mcp( self::SUBSCRIBER ) );

		$this->filter_to( 'publish_posts' );
		self::assertFalse( Permissions::user_can_use_mcp( self::CONTRIBUTOR ) );
		self::assertTrue( Permissions::user_can_use_mcp( self::AUTHOR ) );
	}

	public function test_the_signed_in_user_check_of_the_oauth_route_uses_the_same_capability(): void {
		$this->sign_in( self::SUBSCRIBER );
		self::assertFalse( ProtectedResource::current_user_can_use_mcp() );

		$this->sign_in( self::CONTRIBUTOR );
		self::assertTrue( ProtectedResource::current_user_can_use_mcp() );

		$this->filter_to( 'publish_posts' );
		self::assertFalse( ProtectedResource::current_user_can_use_mcp() );
		$this->sign_in( self::AUTHOR );
		self::assertTrue( ProtectedResource::current_user_can_use_mcp() );
	}
}
