<?php
/**
 * The change ledger API: record, settle, read, list and follow a chain.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\BlobStore;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\LedgerWpdb;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeLedger
 * @covers \Stonewright\WpMcp\Security\ChangeImage
 */
final class ChangeLedgerTest extends TestCase {

	private const MARKER = 'SYNTHETIC-SECRET-7f3a91';

	private const START = 1789000000;

	private mixed $original_wpdb;

	private LedgerWpdb $db;

	private string $uploads = '';

	private int $now = self::START;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new LedgerWpdb();
		$this->db->unique[ $this->db->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$GLOBALS['wpdb']     = $this->db;
		$this->uploads       = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sw-ledger-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir']  = [
			'basedir' => $this->uploads,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = fn(): int => $this->now;
		$_SERVER['HTTP_USER_AGENT'] = 'claude-code/1.4.2 (synthetic)';
		ChangeLedger::reset_schema_health_cache_for_tests();
		ChangeLedger::use_blob_store_for_tests( null );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset( $GLOBALS['stonewright_test_upload_dir'], $_SERVER['HTTP_USER_AGENT'] );
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		ChangeLedger::use_blob_store_for_tests( null );
		self::remove_tree( $this->uploads );
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: [] as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				self::remove_tree( $path . '/' . $item );
			}
		}
		@rmdir( $path );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function record( array $overrides = [] ): array {
		$row = ChangeLedger::record(
			array_merge(
				[
					'ability'       => 'stonewright/content-update-page',
					'family'        => 'post',
					'resource_type' => 'post',
					'resource_id'   => '42',
					'before'        => [ 'post_title' => 'Old title', 'post_content' => '<p>Old</p>' ],
					'summary'       => 'Updated the page content.',
				],
				$overrides
			)
		);
		self::assertIsArray( $row, is_wp_error( $row ) ? $row->get_error_message() : '' );
		return $row;
	}

	/** @return list<string> */
	private function blob_files(): array {
		return array_map( 'basename', glob( $this->uploads . '/stonewright-state/blobs/*.gz' ) ?: [] );
	}

	/** @return list<array<string, mixed>> */
	private function rows(): array {
		return $this->db->tables[ $this->db->prefix . 'stonewright_changes' ] ?? [];
	}

	// ---- record and settle ------------------------------------------------------------------------

	public function test_record_writes_an_open_row_with_the_before_image_kept_in_a_blob(): void {
		$row = $this->record();

		self::assertMatchesRegularExpression( '/^cs-[a-f0-9]{24}$/D', $row['change_id'] );
		self::assertSame( 'armed', $row['status'] );
		self::assertSame( 'change', $row['kind'] );
		self::assertSame( 'stonewright/content-update-page', $row['ability'] );
		self::assertSame( 'post', $row['family'] );
		self::assertSame( 'post', $row['resource_type'] );
		self::assertSame( '42', $row['resource_id'] );
		self::assertSame( 7, $row['actor'] );
		self::assertSame( 'claude-code/1.4.2', $row['client'] );
		self::assertSame( 'Updated the page content.', $row['summary'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', self::START ), $row['created_at'] );
		self::assertNull( $row['settled_at'] );
		self::assertSame( '', $row['parent_id'] );
		self::assertSame( '', $row['change_set_id'] );
		self::assertTrue( $row['restorable'] );
		self::assertSame( '', $row['restorable_reason'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $row['before_ref'] );
		self::assertSame( $row['before_ref'], $row['before_sha256'] );
		self::assertGreaterThan( 0, $row['before_bytes'] );
		self::assertSame( '', $row['after_ref'] );
		self::assertSame( '', $row['after_sha256'] );
		self::assertSame( 0, $row['after_bytes'] );
		self::assertSame( [ $row['before_ref'] . '.gz' ], $this->blob_files() );
	}

	public function test_record_and_settle_round_trip_with_both_images(): void {
		$row = $this->record();
		$this->now += 5;

		$settled = ChangeLedger::settle(
			$row['change_id'],
			[
				'status' => 'verified',
				'after'  => [ 'post_title' => 'New title', 'post_content' => '<p>New</p>' ],
			]
		);

		self::assertIsArray( $settled );
		self::assertSame( 'verified', $settled['status'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', self::START + 5 ), $settled['settled_at'] );
		self::assertNotSame( '', $settled['after_ref'] );
		self::assertNotSame( $settled['before_ref'], $settled['after_ref'] );
		self::assertGreaterThan( 0, $settled['after_bytes'] );
		self::assertSame( $row['before_ref'], $settled['before_ref'], 'settle leaves the before image alone' );
		self::assertCount( 2, $this->blob_files() );
		self::assertSame( $settled, ChangeLedger::get( $row['change_id'] ) );

		self::assertSame(
			[ 'post_content' => '<p>Old</p>', 'post_title' => 'Old title' ],
			ChangeLedger::read_image( $row['change_id'], 'before' )
		);
		self::assertSame(
			[ 'post_content' => '<p>New</p>', 'post_title' => 'New title' ],
			ChangeLedger::read_image( $row['change_id'], 'after' )
		);
	}

	public function test_an_after_image_equal_to_the_before_image_shares_the_blob(): void {
		$row = $this->record();

		$settled = ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'post_title' => 'Old title', 'post_content' => '<p>Old</p>' ] ] );

		self::assertIsArray( $settled );
		self::assertSame( $settled['before_ref'], $settled['after_ref'] );
		self::assertCount( 1, $this->blob_files() );
	}

	public function test_two_changes_that_hold_the_same_image_share_one_blob(): void {
		$one = $this->record();
		$two = $this->record();

		self::assertNotSame( $one['change_id'], $two['change_id'] );
		self::assertSame( $one['before_ref'], $two['before_ref'] );
		self::assertCount( 1, $this->blob_files() );
	}

	public function test_settle_can_move_a_settled_row_to_another_status_without_a_new_image(): void {
		$row = $this->record();
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'post_title' => 'New' ] ] );

		$later = ChangeLedger::settle( $row['change_id'], [ 'status' => 'rolled_back_by' ] );

		self::assertIsArray( $later );
		self::assertSame( 'rolled_back_by', $later['status'] );
	}

	public function test_an_after_image_is_written_once(): void {
		$row = $this->record();
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'post_title' => 'New' ] ] );

		$again = ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'post_title' => 'Other' ] ] );

		self::assertInstanceOf( \WP_Error::class, $again );
		self::assertSame( 'stonewright_change_already_settled', $again->get_error_code() );
		self::assertSame( [ 'post_title' => 'New' ], ChangeLedger::read_image( $row['change_id'], 'after' ) );
	}

	public function test_settle_with_a_failed_write_keeps_the_row_and_the_status(): void {
		$row = $this->record();

		$settled = ChangeLedger::settle( $row['change_id'], [ 'status' => 'failed', 'summary' => 'The write was refused.' ] );

		self::assertIsArray( $settled );
		self::assertSame( 'failed', $settled['status'] );
		self::assertSame( 'The write was refused.', $settled['summary'] );
		self::assertSame( '', $settled['after_ref'] );
	}

	public function test_settle_rejects_an_unknown_row_an_invalid_id_and_an_unknown_status(): void {
		$row = $this->record();

		$missing = ChangeLedger::settle( 'cs-' . str_repeat( '0', 24 ), [ 'status' => 'verified' ] );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_change_not_found', $missing->get_error_code() );

		$invalid = ChangeLedger::settle( "x' OR 1=1 --", [ 'status' => 'verified' ] );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'stonewright_change_invalid_id', $invalid->get_error_code() );

		$bad = ChangeLedger::settle( $row['change_id'], [ 'status' => 'exploded' ] );
		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( 'stonewright_change_invalid_status', $bad->get_error_code() );
		self::assertSame( 'armed', ChangeLedger::get( $row['change_id'] )['status'] ?? '' );
	}

	public function test_record_rejects_a_spec_that_is_not_usable(): void {
		$base = [ 'ability' => 'stonewright/content-update-page', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '42' ];
		foreach (
			[
				'ability missing'  => [ 'ability' => '' ],
				'ability shape'    => [ 'ability' => 'Not An Ability' ],
				'resource type'    => [ 'resource_type' => '' ],
				'resource id'      => [ 'resource_id' => '' ],
				'kind'             => [ 'kind' => 'sideways' ],
				'change id'        => [ 'change_id' => 'cs-not-hex' ],
				'parent shape'     => [ 'parent_id' => "cs-1' OR '1'='1" ],
				'parent not found' => [ 'parent_id' => 'cs-' . str_repeat( 'f', 24 ) ],
			] as $label => $override
		) {
			$result = ChangeLedger::record( array_merge( $base, $override ) );

			self::assertInstanceOf( \WP_Error::class, $result, $label );
			self::assertStringStartsWith( 'stonewright_change_', $result->get_error_code(), $label );
		}
		self::assertSame( [], $this->rows(), 'nothing was written' );
		self::assertSame( [], $this->blob_files() );
	}

	public function test_an_id_given_by_the_caller_is_used_once(): void {
		$id  = 'cs-' . str_repeat( 'ab', 12 );
		$row = $this->record( [ 'change_id' => $id ] );

		self::assertSame( $id, $row['change_id'] );
		$dup = ChangeLedger::record( [ 'change_id' => $id, 'ability' => 'stonewright/content-update-page', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '42' ] );
		self::assertInstanceOf( \WP_Error::class, $dup );
		self::assertSame( 'stonewright_change_exists', $dup->get_error_code() );
		self::assertCount( 1, $this->rows() );
	}

	public function test_an_unknown_family_is_filed_under_other(): void {
		self::assertSame( 'other', $this->record( [ 'family' => 'something-new' ] )['family'] );
		foreach ( [ 'post', 'elementor', 'theme_file', 'custom_code', 'sandbox', 'option', 'menu', 'widget', 'user', 'media', 'woocommerce' ] as $family ) {
			self::assertContains( $family, ChangeLedger::FAMILIES );
			self::assertSame( $family, $this->record( [ 'family' => $family ] )['family'] );
		}
	}

	public function test_the_actor_and_client_come_from_the_request_unless_given(): void {
		$GLOBALS['stonewright_test_current_user_id'] = 9;
		unset( $_SERVER['HTTP_USER_AGENT'] );
		$cli = $this->record();
		self::assertSame( 9, $cli['actor'] );
		self::assertSame( '', $cli['client'] );

		$given = $this->record( [ 'actor' => 3, 'client' => "Codex <script>alert(1)</script> client with a very long label that goes on and on and on and on and on and on and on and on and on" ] );
		self::assertSame( 3, $given['actor'] );
		self::assertStringNotContainsString( '<', $given['client'] );
		self::assertLessThanOrEqual( 96, strlen( $given['client'] ) );
	}

	public function test_the_summary_is_short_plain_and_free_of_credentials(): void {
		self::assertSame( '[redacted]', $this->record( [ 'summary' => 'Set pass' . 'word: ' . self::MARKER . '-pw for the user' ] )['summary'] );
		self::assertSame( 'bold', $this->record( [ 'summary' => '<b>bold</b>' ] )['summary'] );
		self::assertSame( 191, mb_strlen( $this->record( [ 'summary' => str_repeat( 'é', 400 ) ] )['summary'] ) );
		self::assertSame( 'two lines', $this->record( [ 'summary' => "two\n\tlines" ] )['summary'] );
	}

	public function test_a_long_resource_id_is_shortened_the_same_way_for_filters(): void {
		$long = 'wp-content/themes/site-a/' . str_repeat( 'nested/', 40 ) . 'file.php';
		$row  = $this->record( [ 'resource_type' => 'theme_file', 'family' => 'theme_file', 'resource_id' => $long ] );

		self::assertLessThanOrEqual( 150, strlen( $row['resource_id'] ) );
		self::assertSame( $row['resource_id'], ChangeLedger::normalize_resource_id( $long ) );
		$found = ChangeLedger::list( [ 'resource_type' => 'theme_file', 'resource_id' => $long ] );
		self::assertSame( [ $row['change_id'] ], array_column( $found['items'], 'change_id' ) );
		self::assertNotSame( ChangeLedger::normalize_resource_id( $long ), ChangeLedger::normalize_resource_id( $long . 'x' ) );
	}

	// ---- restorable and never stored --------------------------------------------------------------

	public function test_a_change_without_a_before_image_is_not_restorable_unless_the_caller_says_so(): void {
		$none = $this->record( [ 'before' => null ] );
		self::assertFalse( $none['restorable'] );
		self::assertSame( 'no_before_image', $none['restorable_reason'] );
		self::assertSame( '', $none['before_ref'] );

		$created = $this->record( [ 'before' => null, 'restorable' => true ] );
		self::assertTrue( $created['restorable'], 'a created resource is undone by removing it, with no before image' );
	}

	public function test_the_caller_can_mark_a_change_as_not_restorable_with_a_reason(): void {
		$row = $this->record( [ 'restorable' => false, 'restorable_reason' => 'plugin_deleted' ] );

		self::assertFalse( $row['restorable'] );
		self::assertSame( 'plugin_deleted', $row['restorable_reason'] );
		self::assertNotSame( '', $row['before_ref'], 'the image is still kept' );
	}

	public function test_a_masked_image_is_kept_masked_and_is_not_restorable(): void {
		$row = $this->record(
			[
				'family'        => 'user',
				'resource_type' => 'user',
				'resource_id'   => '12',
				'before'        => [ 'user_login' => 'editor-a', 'user_pass' => '$P$B' . self::MARKER ],
			]
		);

		self::assertFalse( $row['restorable'] );
		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		self::assertNotSame( '', $row['before_ref'] );
		self::assertStringNotContainsString( self::MARKER, json_encode( $this->read_all_blobs() ) );
		self::assertIsArray( ChangeLedger::read_image( $row['change_id'], 'before' ) );
	}

	public function test_an_after_image_that_was_masked_makes_the_row_not_restorable(): void {
		$row = $this->record( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'blogname', 'before' => [ 'value' => 'Old name' ] ] );
		self::assertTrue( $row['restorable'] );

		$settled = ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'value' => 'Authorization: Bearer ' . self::MARKER . '1234567890' ] ] );

		self::assertIsArray( $settled );
		self::assertFalse( $settled['restorable'] );
		self::assertSame( 'masked_secret', $settled['restorable_reason'] );
		self::assertNotSame( '', $settled['after_ref'], 'The masked image is still kept.' );
		self::assertStringNotContainsString( self::MARKER, json_encode( $this->read_all_blobs() ) );
	}

	public function test_a_masked_after_image_keeps_the_reason_a_row_already_had(): void {
		$row = $this->record( [ 'restorable' => false, 'restorable_reason' => 'too_large' ] );

		$settled = ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'post_title' => 'Authorization: Bearer ' . self::MARKER . '1234567890' ] ] );

		self::assertIsArray( $settled );
		self::assertSame( 'too_large', $settled['restorable_reason'] );
	}

	/** @return list<string> */
	private function read_all_blobs(): array {
		$out = [];
		foreach ( glob( $this->uploads . '/stonewright-state/blobs/*.gz' ) ?: [] as $file ) {
			$out[] = (string) gzdecode( (string) file_get_contents( $file ) );
		}
		return $out;
	}

	/** @return array<string, array{0: array<string, mixed>, 1: string}> */
	public static function neverStored(): array {
		$marker = self::MARKER;
		return [
			'wp-config'            => [ [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'wp-config.php', 'before' => "define( 'DB_PASSWORD', '$marker' );" ], 'secret_file' ],
			'keys and salts option' => [ [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'auth_salt', 'before' => [ 'value' => $marker ] ], 'secret_option' ],
			'secret-list option'   => [ [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'stonewright_confirmation_secret', 'before' => [ 'value' => $marker ] ], 'secret_option' ],
			'oauth key option'     => [ [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'stonewright_oauth_private_key', 'before' => $marker ], 'secret_option' ],
			'application password' => [ [ 'family' => 'user', 'resource_type' => 'application_password', 'resource_id' => '12:agent-a', 'before' => [ 'password' => $marker ] ], 'secret_resource' ],
			'user password'        => [ [ 'family' => 'user', 'resource_type' => 'user_password', 'resource_id' => '12', 'before' => [ 'user_pass' => $marker ] ], 'secret_resource' ],
			'oauth token'          => [ [ 'family' => 'other', 'resource_type' => 'oauth_token', 'resource_id' => 'client-a', 'before' => [ 'access_token' => $marker ] ], 'secret_resource' ],
		];
	}

	/**
	 * @dataProvider neverStored
	 * @param array<string, mixed> $spec
	 */
	public function test_a_secret_is_never_stored_in_a_blob_a_row_or_an_audit_row( array $spec, string $reason ): void {
		$row = $this->record( array_merge( $spec, [ 'restorable' => true ] ) );

		self::assertFalse( $row['restorable'], 'the caller cannot make a refused image restorable' );
		self::assertSame( $reason, $row['restorable_reason'] );
		self::assertSame( '', $row['before_ref'] );
		self::assertSame( '', $row['before_sha256'], 'not even a hash of a credential is kept' );
		self::assertSame( 0, $row['before_bytes'] );
		self::assertSame( [], $this->blob_files() );

		$settled = ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => $spec['before'] ] );
		self::assertIsArray( $settled );
		self::assertSame( '', $settled['after_ref'] );
		self::assertSame( '', $settled['after_sha256'] );
		self::assertSame( [], $this->blob_files() );

		$dump = json_encode( [ $this->db->tables, $row, $settled ] );
		self::assertStringNotContainsString( self::MARKER, (string) $dump );
		$inserted = json_encode( $GLOBALS['stonewright_test_wpdb_inserts'] ?? [] );
		self::assertStringNotContainsString( self::MARKER, (string) $inserted );
	}

	public function test_an_image_that_is_too_large_keeps_its_hash_and_is_not_restorable(): void {
		ChangeLedger::use_blob_store_for_tests( new BlobStore( $this->uploads . '/stonewright-state/blobs', BlobStore::DEFAULT_MAX_TOTAL_BYTES, BlobStore::MAX_BLOB_BYTES, 64 ) );

		$row = $this->record( [ 'before' => [ 'post_content' => str_repeat( 'x', 500 ) ] ] );

		self::assertFalse( $row['restorable'] );
		self::assertSame( 'too_large', $row['restorable_reason'] );
		self::assertSame( '', $row['before_ref'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $row['before_sha256'] );
		self::assertGreaterThan( 64, $row['before_bytes'] );
	}

	public function test_a_full_store_records_the_row_without_the_image(): void {
		ChangeLedger::use_blob_store_for_tests( new BlobStore( $this->uploads . '/stonewright-state/blobs', 10 ) );

		$row = $this->record();

		self::assertFalse( $row['restorable'] );
		self::assertSame( 'store_full', $row['restorable_reason'] );
		self::assertSame( '', $row['before_ref'] );
		self::assertCount( 1, $this->rows(), 'the change is still on record' );
	}

	public function test_without_a_usable_store_the_row_is_recorded_and_not_restorable(): void {
		$GLOBALS['stonewright_test_upload_dir'] = [ 'basedir' => '', 'baseurl' => '', 'error' => 'no uploads' ];

		$row = $this->record();

		self::assertFalse( $row['restorable'] );
		self::assertSame( 'store_unavailable', $row['restorable_reason'] );
		self::assertCount( 1, $this->rows() );
	}

	// ---- read ---------------------------------------------------------------------------------------

	public function test_get_returns_typed_fields_or_null(): void {
		$row = $this->record();

		$read = ChangeLedger::get( $row['change_id'] );
		self::assertIsArray( $read );
		self::assertIsInt( $read['id'] );
		self::assertIsInt( $read['actor'] );
		self::assertIsInt( $read['before_bytes'] );
		self::assertIsBool( $read['restorable'] );
		self::assertSame( ChangeLedger::COLUMNS, array_keys( $read ) );
		self::assertNull( ChangeLedger::get( 'cs-' . str_repeat( '0', 24 ) ) );
		self::assertNull( ChangeLedger::get( 'not-an-id' ) );
		self::assertNull( ChangeLedger::get( '' ) );
	}

	public function test_an_image_is_read_back_through_the_integrity_check(): void {
		$row = $this->record();
		$file = $this->uploads . '/stonewright-state/blobs/' . $row['before_ref'] . '.gz';
		file_put_contents( $file, (string) gzencode( 'tampered' ) );

		$read = ChangeLedger::read_image( $row['change_id'], 'before' );

		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'stonewright_blob_corrupt', $read->get_error_code() );
	}

	public function test_reading_an_image_that_was_not_stored_is_an_error(): void {
		$row = $this->record( [ 'before' => null ] );

		$read = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'stonewright_change_no_image', $read->get_error_code() );

		$unknown = ChangeLedger::read_image( 'cs-' . str_repeat( '0', 24 ), 'before' );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( 'stonewright_change_not_found', $unknown->get_error_code() );

		$which = ChangeLedger::read_image( $row['change_id'], 'sideways' );
		self::assertInstanceOf( \WP_Error::class, $which );
		self::assertSame( 'stonewright_change_invalid_image', $which->get_error_code() );
	}

	// ---- list ---------------------------------------------------------------------------------------

	/** @return list<array<string, mixed>> Ten rows, oldest first, spread over families, users and days. */
	private function seed(): array {
		$rows  = [];
		$plan  = [
			[ 'post', 'post', '1', 'stonewright/content-update-page', 7, 'verified' ],
			[ 'post', 'post', '2', 'stonewright/content-update-post', 7, 'verified' ],
			[ 'elementor', 'post', '2', 'stonewright/elementor-v3-batch-mutate', 8, 'verified' ],
			[ 'theme_file', 'theme_file', 'site-a/style.css', 'stonewright/theme-file-patch', 8, 'rolled_back' ],
			[ 'option', 'option', 'blogname', 'stonewright/settings-update', 7, 'verified' ],
			[ 'menu', 'menu', '5', 'stonewright/menu-add-item', 9, 'incident' ],
			[ 'post', 'post', '1', 'stonewright/content-update-page', 9, 'armed' ],
			[ 'sandbox', 'sandbox', 'site-a/widget.php', 'stonewright/sandbox-write', 7, 'verified' ],
			[ 'user', 'user', '12', 'stonewright/user-update', 8, 'failed' ],
			[ 'post', 'post', '3', 'stonewright/content-update-page', 7, 'verified' ],
		];
		foreach ( $plan as $index => [ $family, $type, $id, $ability, $actor, $status ] ) {
			$this->now = self::START + $index * 86400;
			$row       = $this->record(
				[
					'family'        => $family,
					'resource_type' => $type,
					'resource_id'   => $id,
					'ability'       => $ability,
					'actor'         => $actor,
					'before'        => [ 'n' => $index ],
				]
			);
			if ( 'armed' !== $status ) {
				ChangeLedger::settle( $row['change_id'], [ 'status' => $status ] );
			}
			$rows[] = ChangeLedger::get( $row['change_id'] ) ?? [];
		}
		return $rows;
	}

	/** @param array<string, mixed> $result @return list<string> */
	private function ids( array $result ): array {
		return array_column( $result['items'], 'change_id' );
	}

	public function test_list_returns_the_newest_change_first_with_page_numbers(): void {
		$rows   = $this->seed();
		$result = ChangeLedger::list();

		self::assertSame( 10, $result['total'] );
		self::assertSame( 1, $result['page'] );
		self::assertSame( 25, $result['per_page'] );
		self::assertSame( 1, $result['pages'] );
		self::assertSame( array_reverse( array_column( $rows, 'change_id' ) ), $this->ids( $result ) );
	}

	public function test_list_filters_by_family_resource_ability_actor_and_status(): void {
		$rows = $this->seed();
		$id   = static fn ( int ...$n ): array => array_map( static fn ( int $i ): string => (string) $rows[ $i ]['change_id'], array_reverse( $n ) );

		self::assertSame( $id( 0, 1, 6, 9 ), $this->ids( ChangeLedger::list( [ 'family' => 'post' ] ) ) );
		self::assertSame( $id( 1, 2 ), $this->ids( ChangeLedger::list( [ 'resource_type' => 'post', 'resource_id' => '2' ] ) ) );
		self::assertSame( $id( 0, 6 ), $this->ids( ChangeLedger::list( [ 'resource_id' => '1' ] ) ) );
		self::assertSame( $id( 0, 6, 9 ), $this->ids( ChangeLedger::list( [ 'ability' => 'stonewright/content-update-page' ] ) ) );
		self::assertSame( $id( 2, 3, 8 ), $this->ids( ChangeLedger::list( [ 'actor' => 8 ] ) ) );
		self::assertSame( $id( 3 ), $this->ids( ChangeLedger::list( [ 'status' => 'rolled_back' ] ) ) );
		self::assertSame( $id( 3, 8 ), $this->ids( ChangeLedger::list( [ 'status' => [ 'rolled_back', 'failed' ] ] ) ) );
		self::assertSame( $id( 5, 6 ), $this->ids( ChangeLedger::list( [ 'open' => true ] ) ) );
		self::assertSame( $id( 0, 1 ), $this->ids( ChangeLedger::list( [ 'family' => 'post', 'status' => 'verified', 'actor' => 7, 'resource_id' => [ '1', '2' ] ] ) ) );
	}

	public function test_list_filters_by_date(): void {
		$rows  = $this->seed();
		$since = self::START + 7 * 86400;
		$ids   = array_column( $rows, 'change_id' );

		self::assertSame( [ $ids[9], $ids[8], $ids[7] ], $this->ids( ChangeLedger::list( [ 'since' => $since ] ) ) );
		self::assertSame( [ $ids[9], $ids[8], $ids[7] ], $this->ids( ChangeLedger::list( [ 'since' => gmdate( 'Y-m-d', $since ) ] ) ) );
		self::assertSame( [ $ids[1], $ids[0] ], $this->ids( ChangeLedger::list( [ 'until' => self::START + 86400 ] ) ) );
		self::assertSame( [ $ids[3], $ids[2] ], $this->ids( ChangeLedger::list( [ 'since' => self::START + 2 * 86400, 'until' => self::START + 3 * 86400 ] ) ) );
	}

	public function test_list_filters_by_kind_parent_change_set_and_restorable(): void {
		$rows   = $this->seed();
		$parent = (string) $rows[0]['change_id'];
		$child  = $this->record( [ 'kind' => 'rollback', 'parent_id' => $parent, 'change_set_id' => 'cs-set-0001', 'before' => null, 'restorable' => false, 'restorable_reason' => 'rollback_row' ] );

		self::assertSame( [ $child['change_id'] ], $this->ids( ChangeLedger::list( [ 'kind' => 'rollback' ] ) ) );
		self::assertSame( [ $child['change_id'] ], $this->ids( ChangeLedger::list( [ 'parent_id' => $parent ] ) ) );
		self::assertSame( [ $child['change_id'] ], $this->ids( ChangeLedger::list( [ 'change_set_id' => 'cs-set-0001' ] ) ) );
		self::assertSame( [ $child['change_id'] ], $this->ids( ChangeLedger::list( [ 'restorable' => false ] ) ) );
		self::assertSame( 10, ChangeLedger::list( [ 'restorable' => true ] )['total'] );
	}

	public function test_list_pages_through_the_rows_and_clamps_the_page_size(): void {
		$rows = $this->seed();
		$all  = array_reverse( array_column( $rows, 'change_id' ) );

		$first = ChangeLedger::list( [], 4, 1 );
		self::assertSame( array_slice( $all, 0, 4 ), $this->ids( $first ) );
		self::assertSame( [ 10, 3, 4 ], [ $first['total'], $first['pages'], $first['per_page'] ] );

		$last = ChangeLedger::list( [], 4, 3 );
		self::assertSame( array_slice( $all, 8, 2 ), $this->ids( $last ) );
		self::assertSame( 3, $last['page'] );

		self::assertSame( [], $this->ids( ChangeLedger::list( [], 4, 9 ) ), 'a page past the end is empty' );
		self::assertSame( 1, ChangeLedger::list( [], 0, 1 )['per_page'] );
		self::assertSame( 100, ChangeLedger::list( [], 5000, 1 )['per_page'] );
		self::assertSame( 1, ChangeLedger::list( [], 4, -3 )['page'] );
	}

	public function test_a_filter_with_an_impossible_value_matches_nothing(): void {
		$this->seed();

		foreach ( [ [ 'status' => 'exploded' ], [ 'kind' => 'sideways' ], [ 'status' => [ 'verified', 'exploded' ] ] ] as $filter ) {
			$result = ChangeLedger::list( $filter );
			self::assertSame( 0, $result['total'], json_encode( $filter ) );
			self::assertSame( [], $result['items'] );
		}
		self::assertSame( 10, ChangeLedger::list( [ 'family' => '', 'actor' => 0, 'unknown_filter' => 'x' ] )['total'], 'empty and unknown filters are ignored' );
	}

	public function test_filter_values_are_prepared_and_not_pasted_into_the_query(): void {
		$this->seed();
		$this->db->statements = [];

		ChangeLedger::list( [ 'ability' => "x' OR '1'='1", 'resource_id' => "1'; DROP TABLE wptests_options; --" ] );

		self::assertSame( [], $this->db->tables['wptests_options'] ?? [] );
		foreach ( $this->db->statements as $sql ) {
			self::assertStringStartsWith( 'SELECT', $sql );
		}
		self::assertSame( 10, count( $this->rows() ) );
	}

	public function test_count_matches_the_total_of_a_listing(): void {
		$this->seed();

		self::assertSame( 4, ChangeLedger::count( [ 'family' => 'post' ] ) );
		self::assertSame( 10, ChangeLedger::count() );
	}

	// ---- chains -------------------------------------------------------------------------------------

	public function test_the_parent_chain_runs_from_the_first_change_to_the_row(): void {
		$change   = $this->record();
		$rollback = $this->record( [ 'kind' => 'rollback', 'parent_id' => $change['change_id'], 'before' => [ 'post_title' => 'New title' ] ] );
		$redo     = $this->record( [ 'kind' => 'redo', 'parent_id' => $rollback['change_id'], 'before' => [ 'post_title' => 'Old title' ] ] );

		self::assertSame(
			[ $change['change_id'], $rollback['change_id'], $redo['change_id'] ],
			array_column( ChangeLedger::chain( $redo['change_id'] ), 'change_id' )
		);
		self::assertSame( [ $change['change_id'] ], array_column( ChangeLedger::chain( $change['change_id'] ), 'change_id' ) );
		self::assertSame( [ $rollback['change_id'] ], array_column( ChangeLedger::children( $change['change_id'] ), 'change_id' ) );
		self::assertSame( [], ChangeLedger::children( $redo['change_id'] ) );
		self::assertSame( [], ChangeLedger::chain( 'cs-' . str_repeat( '0', 24 ) ) );
		self::assertSame( [], ChangeLedger::chain( 'not-an-id' ) );
	}

	public function test_a_chain_that_loops_back_on_itself_ends(): void {
		$one = $this->record();
		$two = $this->record( [ 'kind' => 'rollback', 'parent_id' => $one['change_id'] ] );
		foreach ( $this->db->tables[ $this->db->prefix . 'stonewright_changes' ] as $index => $row ) {
			if ( $row['change_id'] === $one['change_id'] ) {
				$this->db->tables[ $this->db->prefix . 'stonewright_changes' ][ $index ]['parent_id'] = $two['change_id'];
			}
		}

		$chain = ChangeLedger::chain( $two['change_id'] );

		self::assertCount( 2, $chain );
	}

	public function test_a_long_chain_is_cut_at_the_limit(): void {
		$parent = '';
		$last   = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$row    = $this->record( [ 'kind' => '' === $parent ? 'change' : 'rollback', 'parent_id' => $parent ] );
			$parent = $row['change_id'];
			$last   = $row['change_id'];
		}

		self::assertCount( 8, ChangeLedger::chain( $last ) );
		self::assertCount( 3, ChangeLedger::chain( $last, 3 ) );
		self::assertSame( $last, ChangeLedger::chain( $last, 3 )[2]['change_id'], 'the cut keeps the row that was asked for' );
	}

	// ---- audit --------------------------------------------------------------------------------------

	public function test_recording_writes_one_short_audit_row_without_any_image(): void {
		$row = $this->record( [ 'before' => [ 'post_content' => 'PRIVATE-BODY-TEXT' ], 'summary' => 'Updated the page.' ] );

		$audit = $this->db->tables[ $this->db->prefix . 'stonewright_audit_log' ] ?? [];
		self::assertCount( 1, $audit );
		self::assertSame( 'stonewright/change-ledger-record', $audit[0]['ability_name'] );
		self::assertSame( $row['change_id'], $audit[0]['change_set_id'] );
		self::assertSame( 'ok', $audit[0]['result_status'] );
		self::assertSame( $row['before_sha256'], $audit[0]['before_sha256'] );
		self::assertStringNotContainsString( 'PRIVATE-BODY-TEXT', json_encode( $audit ) );
		self::assertLessThan( 2500, strlen( (string) $audit[0]['sanitized_args'] ), 'the row is short' );
	}

	public function test_a_failed_audit_write_does_not_fail_the_record(): void {
		$db = new class() extends LedgerWpdb {
			public function insert( $table, $data, $format = null ) {
				if ( str_contains( (string) $table, 'audit_log' ) ) {
					throw new \RuntimeException( 'audit down' );
				}
				return parent::insert( $table, $data, $format );
			}
		};
		$GLOBALS['wpdb'] = $db;

		$row = ChangeLedger::record( [ 'ability' => 'stonewright/content-update-page', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '42', 'before' => [ 'a' => 1 ] ] );

		self::assertIsArray( $row );
		self::assertCount( 1, $db->tables[ $db->prefix . 'stonewright_changes' ] );
	}

	// ---- purge --------------------------------------------------------------------------------------

	public function test_purge_all_removes_every_row_and_blob(): void {
		$this->seed();
		self::assertNotSame( [], $this->blob_files() );

		$removed = ChangeLedger::purge_all();

		self::assertSame( 10, $removed );
		self::assertSame( [], $this->rows() );
		self::assertSame( [], $this->blob_files() );
	}
}
