<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\PromotionGrant;

/**
 * @covers \Stonewright\WpMcp\Security\PromotionGrant
 */
final class PromotionGrantTest extends TestCase {

	private const KIND    = PromotionGrant::KIND_KNOWLEDGE;
	private const HASH    = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
	private const OTHER   = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps']    = [ 'manage_options' => true ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		unset( $GLOBALS['stonewright_test_user_caps'], $GLOBALS['stonewright_test_current_user_id'] );
	}

	public function test_a_request_is_staged_pending_and_grants_nothing(): void {
		$request = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate', [ 'title' => 'Button URL control' ] );

		self::assertIsArray( $request );
		self::assertSame( 'pending', $request['status'] );
		self::assertStringContainsString( 'stonewright-skills', (string) $request['approval_url'] );

		$consumed = PromotionGrant::consume( self::KIND, '12', self::HASH, 'activate' );
		self::assertInstanceOf( \WP_Error::class, $consumed );
		self::assertSame( 'stonewright_promotion_approval_required', $consumed->get_error_code() );
	}

	public function test_requesting_the_same_promotion_twice_keeps_one_request(): void {
		$first  = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate' );
		$second = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate' );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( $first['request_id'], $second['request_id'] );
		self::assertCount( 1, PromotionGrant::pending() );
	}

	public function test_only_an_administrator_can_approve(): void {
		$request = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate' );
		self::assertIsArray( $request );

		$GLOBALS['stonewright_test_user_caps'] = [];
		$denied = PromotionGrant::approve( (string) $request['request_id'] );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'stonewright_promotion_forbidden', $denied->get_error_code() );

		$consumed = PromotionGrant::consume( self::KIND, '12', self::HASH, 'activate' );
		self::assertInstanceOf( \WP_Error::class, $consumed );
	}

	public function test_a_valid_grant_is_consumed_once(): void {
		$request = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate' );
		self::assertIsArray( $request );
		self::assertIsArray( PromotionGrant::approve( (string) $request['request_id'], 'Reviewed.' ) );

		$first = PromotionGrant::consume( self::KIND, '12', self::HASH, 'activate' );
		self::assertIsArray( $first );
		self::assertSame( 'Reviewed.', $first['note'] );

		$second = PromotionGrant::consume( self::KIND, '12', self::HASH, 'activate' );
		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_promotion_grant_used', $second->get_error_code() );
	}

	public function test_a_grant_is_bound_to_its_subject_hash_and_target(): void {
		$request = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate' );
		self::assertIsArray( $request );
		PromotionGrant::approve( (string) $request['request_id'] );

		foreach (
			[
				[ self::KIND, '13', self::HASH, 'activate' ],
				[ self::KIND, '12', self::OTHER, 'activate' ],
				[ self::KIND, '12', self::HASH, 'activate_replace' ],
				[ PromotionGrant::KIND_EXPERTISE, '12', self::HASH, 'activate' ],
			] as [ $kind, $subject, $hash, $target ]
		) {
			$result = PromotionGrant::consume( $kind, $subject, $hash, $target );
			self::assertInstanceOf( \WP_Error::class, $result, $kind . '/' . $subject . '/' . $target );
		}

		self::assertIsArray( PromotionGrant::consume( self::KIND, '12', self::HASH, 'activate' ) );
	}

	public function test_a_grant_expires(): void {
		$request = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate' );
		self::assertIsArray( $request );
		PromotionGrant::approve( (string) $request['request_id'] );

		$rows = (array) get_option( PromotionGrant::OPTION, [] );
		foreach ( $rows as $id => $row ) {
			$rows[ $id ]['grant_expires_at'] = time() - 1;
		}
		update_option( PromotionGrant::OPTION, $rows, false );

		$result = PromotionGrant::consume( self::KIND, '12', self::HASH, 'activate' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_promotion_grant_expired', $result->get_error_code() );
	}

	public function test_a_rejected_request_stays_rejected_for_a_new_request(): void {
		$request = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate' );
		self::assertIsArray( $request );
		self::assertIsArray( PromotionGrant::reject( (string) $request['request_id'] ) );

		$result = PromotionGrant::consume( self::KIND, '12', self::HASH, 'activate' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_promotion_rejected', $result->get_error_code() );
		self::assertSame( [], PromotionGrant::pending() );
	}

	public function test_a_request_with_thin_evidence_needs_a_note_from_the_approver(): void {
		$request = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate', [], true );
		self::assertIsArray( $request );

		$blank = PromotionGrant::approve( (string) $request['request_id'], '   ' );
		self::assertInstanceOf( \WP_Error::class, $blank );
		self::assertSame( 'stonewright_promotion_note_required', $blank->get_error_code() );

		self::assertIsArray( PromotionGrant::approve( (string) $request['request_id'], 'Checked against the live site.' ) );
	}

	public function test_a_decided_request_cannot_be_decided_again(): void {
		$request = PromotionGrant::request( self::KIND, '12', self::HASH, 'activate' );
		self::assertIsArray( $request );
		PromotionGrant::approve( (string) $request['request_id'] );

		$again = PromotionGrant::reject( (string) $request['request_id'] );
		self::assertInstanceOf( \WP_Error::class, $again );
		self::assertSame( 'stonewright_promotion_not_pending', $again->get_error_code() );
	}

	public function test_summary_text_is_bounded_and_stripped_of_markup(): void {
		$request = PromotionGrant::request(
			self::KIND,
			'12',
			self::HASH,
			'activate',
			[
				'title'   => '<b>Title</b>' . str_repeat( 'x', 400 ),
				'details' => [ 'Verified' => '<script>alert(1)</script>2' ],
			]
		);

		self::assertIsArray( $request );
		self::assertLessThanOrEqual( 200, mb_strlen( (string) $request['summary']['title'] ) );
		self::assertStringNotContainsString( '<', (string) $request['summary']['title'] );
		self::assertStringNotContainsString( '<', (string) $request['summary']['details']['Verified'] );
	}

	public function test_malformed_bindings_are_refused(): void {
		self::assertInstanceOf( \WP_Error::class, PromotionGrant::request( 'unknown', '12', self::HASH, 'activate' ) );
		self::assertInstanceOf( \WP_Error::class, PromotionGrant::request( self::KIND, '', self::HASH, 'activate' ) );
		self::assertInstanceOf( \WP_Error::class, PromotionGrant::request( self::KIND, '12', 'not-a-hash', 'activate' ) );
	}
}
