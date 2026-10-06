<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Decisions\ConsentDecision;
use Stonewright\WpMcp\Authorization\Exchange\ConsentCoordinator;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\CodeProof;
use Stonewright\WpMcp\Authorization\WordPress\PendingConsentStore;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Authorization\WordPress\StorageFailure;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticSubjectAuthority;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\PendingConsentStore
 */
final class PendingConsentStoreTest extends TestCase {

	private StorageRig $rig;
	private string $client;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->rig = new StorageRig();
		$this->client = $this->rig->register_client();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	private function open( array $overrides = [] ): string {
		return $this->rig->consents->open(
			array_replace(
				[
					'subject_key'           => '7',
					'client_key'            => $this->client,
					'redirect_uri'          => StorageRig::REDIRECT,
					'code_challenge'        => ( new CodeProof() )->challenge( StorageRig::VERIFIER ),
					'code_challenge_method' => 'S256',
					'scopes'                => [ 'mcp' ],
					'resources'             => [ StorageRig::RESOURCE ],
					'state'                 => 'opaque-state',
					'native_client'         => true,
					'registered_redirects'  => [ StorageRig::REDIRECT ],
				],
				$overrides
			)
		);
	}

	private function decide( string $pending_key, bool $approved = true, ?StorageRig $rig = null ): array {
		$rig ??= $this->rig;
		return ( new ConsentCoordinator( $rig->consents, $rig->clock, $rig->ids, new SyntheticSubjectAuthority(), new ConsentDecision( 60 ) ) )->decide( $pending_key, '7', true, $approved );
	}

	private function refused( callable $work ): void {
		try {
			$work();
			self::fail( 'The pending consent must be refused.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_request', $fault->error() );
		}
	}

	public function test_open_stores_a_ten_minute_request_bound_to_the_user(): void {
		$pending_key = $this->open();

		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/D', $pending_key );
		$row = (array) $this->rig->row( 'consents', 'consent_hash', RowKeys::consent( $pending_key ) );
		self::assertSame( '7', $row['user_id'] );
		self::assertSame( $this->client, $row['client_id'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + PendingConsentStore::LIFETIME ), $row['expires_at'] );
		self::assertStringNotContainsString( $pending_key, (string) $row['request_json'] );
		$peek = $this->rig->consents->peek( $pending_key );
		self::assertSame( $this->client, $peek['client_key'] );
		self::assertSame( 'opaque-state', $peek['state'] );
		self::assertSame( StorageRig::T + PendingConsentStore::LIFETIME, $peek['expires_at'] );
	}

	public function test_approval_consumes_the_request_and_stores_the_code(): void {
		$pending_key = $this->open();

		$outcome = $this->decide( $pending_key );

		self::assertSame( StorageRig::REDIRECT, $outcome['redirect_uri'] );
		self::assertSame( 'opaque-state', $outcome['redirect_parameters']['state'] );
		self::assertSame( [], $this->rig->rows( 'consents' ) );
		self::assertNotNull( $this->rig->row( 'auth_codes', 'identifier_hash', RowKeys::code( $outcome['code']['code_key'] ) ) );
		self::assertNull( $this->rig->consents->peek( $pending_key ) );
	}

	public function test_a_pending_request_is_single_use(): void {
		$pending_key = $this->open();
		$this->decide( $pending_key );

		$this->refused( fn () => $this->decide( $pending_key ) );
		self::assertCount( 1, $this->rig->rows( 'auth_codes' ) );
	}

	public function test_denial_is_consumed_without_a_code(): void {
		$pending_key = $this->open();

		$outcome = $this->decide( $pending_key, false );

		self::assertSame( 'access_denied', $outcome['redirect_parameters']['error'] );
		self::assertSame( [], $this->rig->rows( 'consents' ) );
		self::assertSame( [], $this->rig->rows( 'auth_codes' ) );
	}

	public function test_another_user_cannot_decide_the_request(): void {
		$pending_key = $this->open();
		$this->rig->current_subject = '8';

		$this->refused( fn () => $this->decide( $pending_key ) );
		self::assertNull( $this->rig->consents->peek( $pending_key ) );
		self::assertCount( 1, $this->rig->rows( 'consents' ) );
	}

	public function test_an_expired_request_is_refused(): void {
		$pending_key = $this->open();

		$this->rig->at( StorageRig::T + PendingConsentStore::LIFETIME );
		$this->refused( fn () => $this->decide( $pending_key ) );
		self::assertNull( $this->rig->consents->peek( $pending_key ) );
		self::assertSame( [], $this->rig->rows( 'auth_codes' ) );
	}

	public function test_unknown_keys_are_refused(): void {
		$this->refused( fn () => $this->decide( str_repeat( '0', 32 ) ) );
	}

	public function test_concurrent_approvals_create_one_code(): void {
		$pending_key = $this->open();
		$other = $this->rig->connection();
		$first = null;
		$this->rig->wpdb->before = function ( string $sql ) use ( $other, $pending_key, &$first ): void {
			if ( null === $first && str_starts_with( $sql, 'DELETE FROM wptests_stonewright_oauth_consents' ) ) {
				$first = $this->decide( $pending_key, true, $other );
			}
		};

		$this->refused( fn () => $this->decide( $pending_key ) );
		self::assertNotNull( $first['code'] );
		self::assertCount( 1, $this->rig->rows( 'auth_codes' ) );
	}

	public function test_a_failed_code_write_keeps_the_request(): void {
		$pending_key = $this->open();
		$this->rig->wpdb->fail_when = static fn ( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO wptests_stonewright_oauth_auth_codes' );

		try {
			$this->decide( $pending_key );
			self::fail( 'A failed write must surface.' );
		} catch ( StorageFailure $failure ) {
			self::assertCount( 1, $this->rig->rows( 'consents' ) );
		}
	}

	public function test_open_rejects_incomplete_requests(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->open( [ 'subject_key' => '' ] );
	}
}
