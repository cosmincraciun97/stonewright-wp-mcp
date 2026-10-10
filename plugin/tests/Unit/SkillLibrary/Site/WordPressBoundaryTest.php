<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\SkillLibrary\Site\ImportReceipt;
use Stonewright\WpMcp\SkillLibrary\Site\WordPressBoundary;

/**
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\WordPressBoundary
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\ImportReceipt
 */
final class WordPressBoundaryTest extends TestCase {

	private const REVIEW_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 4;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		unset( $GLOBALS['stonewright_test_nonce_invalid'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		unset( $GLOBALS['stonewright_test_nonce_invalid'] );
	}

	public function test_admin_and_rest_channels_require_the_manage_options_capability(): void {
		foreach ( [ WordPressBoundary::ADMIN, WordPressBoundary::REST ] as $channel ) {
			$refused = ( new WordPressBoundary( $channel ) )->authorize( 'save', $this->summary(), '' );
			self::assertInstanceOf( \WP_Error::class, $refused, $channel );
			self::assertSame( 'stonewright_skill_permission_denied', $refused->get_error_code() );
			self::assertSame( 403, $refused->get_error_data()['status'] );
		}

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertTrue( ( new WordPressBoundary( WordPressBoundary::ADMIN ) )->authorize( 'save', $this->summary(), '' ) );
		self::assertTrue( ( new WordPressBoundary( WordPressBoundary::REST ) )->authorize( 'toggle', $this->summary(), '' ) );
	}

	public function test_ability_channel_relies_on_the_ability_permission_check(): void {
		self::assertTrue( ( new WordPressBoundary( WordPressBoundary::ABILITY ) )->authorize( 'save', $this->summary(), '' ) );
		self::assertTrue( ( new WordPressBoundary( WordPressBoundary::ABILITY ) )->authorize( 'rollback', $this->summary(), '' ) );
	}

	public function test_studio_channel_also_requires_a_current_rest_nonce(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];

		$missing = ( new WordPressBoundary( WordPressBoundary::STUDIO ) )->authorize( 'trash', $this->summary(), '' );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_skills_invalid_nonce', $missing->get_error_code() );

		$request = new \WP_REST_Request( 'POST', '/stonewright/v1/skills-studio/skills/7/trash' );
		$request->set_header( 'X-WP-Nonce', 'nonce-value' );
		self::assertTrue( WordPressBoundary::for_request( WordPressBoundary::STUDIO, $request )->authorize( 'trash', $this->summary(), '' ) );

		$GLOBALS['stonewright_test_nonce_invalid'] = true;
		$stale = WordPressBoundary::for_request( WordPressBoundary::STUDIO, $request )->authorize( 'trash', $this->summary(), '' );
		self::assertInstanceOf( \WP_Error::class, $stale );
		self::assertSame( 'stonewright_skills_invalid_nonce', $stale->get_error_code() );
	}

	public function test_plugin_maintenance_actions_belong_to_the_system_channel_only(): void {
		$system = new WordPressBoundary( WordPressBoundary::SYSTEM );
		self::assertTrue( $system->authorize( 'seed', $this->summary(), '' ) );
		self::assertTrue( $system->authorize( 'evidence', $this->summary(), '' ) );
		self::assertInstanceOf( \WP_Error::class, $system->authorize( 'save', $this->summary(), '' ) );
		self::assertInstanceOf( \WP_Error::class, $system->authorize( 'destroy', $this->summary(), '' ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		foreach ( [ WordPressBoundary::ABILITY, WordPressBoundary::ADMIN, WordPressBoundary::REST ] as $channel ) {
			self::assertInstanceOf( \WP_Error::class, ( new WordPressBoundary( $channel ) )->authorize( 'seed', $this->summary(), '' ), $channel );
			self::assertInstanceOf( \WP_Error::class, ( new WordPressBoundary( $channel ) )->authorize( 'evidence', $this->summary(), '' ), $channel );
		}
		self::assertInstanceOf( \WP_Error::class, ( new WordPressBoundary( 'unknown' ) )->authorize( 'save', $this->summary(), '' ) );
	}

	public function test_permanent_deletion_needs_a_bound_confirmation_token_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$boundary                              = new WordPressBoundary( WordPressBoundary::ADMIN );

		self::assertTrue( $boundary->authorize( 'destroy', $this->summary(), '' ), 'Development mode deletes without a token.' );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$missing = $boundary->authorize( 'destroy', $this->summary(), '' );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_confirmation_required', $missing->get_error_code() );
		self::assertSame( 403, $missing->get_error_data()['status'] );
		self::assertSame( 'stonewright/skills-destroy', $missing->get_error_data()['ability'] );
		self::assertSame( [ 'id' => 7 ], $missing->get_error_data()['args'] );

		$other = ConfirmationToken::issue( 'stonewright/skills-destroy', [ 'id' => 8 ] );
		self::assertInstanceOf( \WP_Error::class, $boundary->authorize( 'destroy', $this->summary(), $other ) );

		$token = ConfirmationToken::issue( 'stonewright/skills-destroy', [ 'id' => 7 ] );
		self::assertTrue( $boundary->authorize( 'destroy', $this->summary(), $token ) );
		self::assertInstanceOf( \WP_Error::class, $boundary->authorize( 'destroy', $this->summary(), $token ), 'A token is used once.' );
		self::assertTrue( $boundary->authorize( 'trash', $this->summary(), '' ), 'Trash stays reversible and needs no token.' );
	}

	public function test_import_needs_a_receipt_bound_to_the_review_the_user_and_time(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$boundary                              = new WordPressBoundary( WordPressBoundary::ADMIN );
		$summary                               = $this->summary() + [ 'review_hash' => self::REVIEW_HASH ];

		$missing = $boundary->authorize( 'import', $summary, '' );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_skill_import_review_required', $missing->get_error_code() );

		$receipt = ImportReceipt::issue( self::REVIEW_HASH, 4 );
		self::assertTrue( $boundary->authorize( 'import', $summary, $receipt ) );

		$other_review = ImportReceipt::issue( str_repeat( 'b', 64 ), 4 );
		self::assertInstanceOf( \WP_Error::class, $boundary->authorize( 'import', $summary, $other_review ) );

		$other_user = ImportReceipt::issue( self::REVIEW_HASH, 5 );
		self::assertInstanceOf( \WP_Error::class, $boundary->authorize( 'import', $summary, $other_user ) );

		$expired = ImportReceipt::issue( self::REVIEW_HASH, 4, time() - ImportReceipt::LIFETIME - 10 );
		$result  = $boundary->authorize( 'import', $summary, $expired );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_import_review_expired', $result->get_error_code() );

		[ $expires ] = explode( '.', $receipt );
		$forged      = ( (int) $expires + 3600 ) . '.' . substr( $receipt, strlen( $expires ) + 1 );
		self::assertInstanceOf( \WP_Error::class, $boundary->authorize( 'import', $summary, $forged ) );
		self::assertInstanceOf( \WP_Error::class, $boundary->authorize( 'import', $summary, 'not-a-receipt' ) );
	}

	public function test_audit_events_carry_bounded_metadata_without_bodies_or_tokens(): void {
		$boundary = new WordPressBoundary( WordPressBoundary::ADMIN );

		$boundary->record( 'destroy', $this->summary() + [ 'content' => 'SECRET BODY TEXT' ], 'ok' );

		$audit = array_values( array_filter( $GLOBALS['stonewright_test_wpdb_inserts'], static fn( array $insert ): bool => str_contains( (string) $insert['table'], 'stonewright_audit_log' ) ) );
		self::assertCount( 1, $audit );
		self::assertSame( 'stonewright/skills-destroy', $audit[0]['data']['ability_name'] );
		self::assertSame( 'ok', $audit[0]['data']['result_status'] );
		$payload = (string) $audit[0]['data']['sanitized_args'];
		self::assertStringContainsString( '"skill_id":7', $payload );
		self::assertStringContainsString( 'site-note', $payload );
		self::assertStringNotContainsString( 'SECRET BODY TEXT', $payload );
	}

	public function test_bundled_pack_maintenance_writes_no_audit_history(): void {
		( new WordPressBoundary( WordPressBoundary::SYSTEM ) )->record( 'seed', $this->summary(), 'ok' );

		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'] );
	}

	/** @return array<string, mixed> */
	private function summary(): array {
		return [
			'skill_id'           => 7,
			'slug'               => 'site-note',
			'revision'           => 2,
			'source'             => 'user',
			'status'             => 'active',
			'verification_count' => 0,
			'content_hash'       => hash( 'sha256', '# Body' ),
		];
	}
}
