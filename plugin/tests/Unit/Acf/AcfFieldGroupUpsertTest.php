<?php
/**
 * A field group save is an upsert by key: it updates the existing posts instead of inserting a second group.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Acf;

use Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupGet;
use Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupSave;
use Stonewright\WpMcp\Abilities\Acf\AcfRuntime;
use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Tests\Unit\Acf\Fixtures\AcfImportStandIn;
use Stonewright\WpMcp\Tests\Unit\Security\Adapters\FamilyLedgerTestCase;

/**
 * The importer is the ACF stand-in of the fixtures folder: a group or field whose ID is 0 is inserted as a
 * new post, one with an ID is updated in place, and the fields of a group with an ID that the payload does
 * not name are deleted.
 *
 * @covers \Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupSave
 * @covers \Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupGet
 * @covers \Stonewright\WpMcp\Abilities\Acf\AcfRuntime::import_field_group
 * @covers \Stonewright\WpMcp\Abilities\Acf\AcfRuntime::group_posts_by_key
 * @covers \Stonewright\WpMcp\Abilities\Acf\AcfRuntime::field_posts_of_group
 */
final class AcfFieldGroupUpsertTest extends FamilyLedgerTestCase {

	private const GROUP_POST = 300;
	private const FIELD_POST = 301;

	protected function setUp(): void {
		parent::setUp();
		// The group exists in the database, with one text field.
		$this->make_post( self::GROUP_POST, [ 'post_type' => 'acf-field-group', 'post_title' => 'Books', 'post_name' => 'group_books' ] );
		$this->make_field( self::FIELD_POST, 'field_summary', 'summary', 'Summary', self::GROUP_POST );
		AcfImportStandIn::install();
	}

	protected function tearDown(): void {
		AcfImportStandIn::uninstall();
		parent::tearDown();
	}

	/** The payload the field report describes: the group key and the field keys, no post IDs, one field type changed. */
	private function payload_without_ids(): array {
		return [
			'group' => [
				'key'    => 'group_books',
				'title'  => 'Books',
				'fields' => [ [ 'key' => 'field_summary', 'label' => 'Summary', 'name' => 'summary', 'type' => 'wysiwyg' ] ],
			],
		];
	}

	public function test_a_save_by_key_without_ids_updates_the_existing_group_instead_of_inserting_a_second_one(): void {
		$result = ( new AcfFieldGroupSave() )->execute( $this->payload_without_ids() );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'group_books', $result['key'] );
		self::assertSame( self::GROUP_POST, $result['post_id'] );
		self::assertFalse( $result['created'] );
		self::assertSame( [], $result['created_post_ids'] );
		self::assertSame( [], $result['fields_created'] );
		self::assertSame( [], $result['fields_removed'] );
		self::assertSame( [], $result['duplicate_post_ids'] );
		self::assertSame( '', $result['local'] );
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'], 'No post was inserted.' );
		self::assertSame( [ self::GROUP_POST ], $this->group_posts_with_key( 'group_books' ), 'One field group post holds the key after the save.' );
		self::assertSame( [ self::FIELD_POST ], $this->field_posts_with_key( 'field_summary' ), 'One field post holds the field key after the save.' );
		self::assertSame( 'wysiwyg', $this->field_type( self::FIELD_POST ), 'The existing field was changed in place.' );
		self::assertSame( self::GROUP_POST, AcfImportStandIn::$received[0]['ID'], 'ACF received the post id of the group.' );
	}

	public function test_the_existing_posts_are_recorded_as_overwritten_and_a_field_the_save_adds_as_created(): void {
		$payload                       = $this->payload_without_ids();
		$payload['group']['fields'][]  = [ 'key' => 'field_isbn', 'label' => 'ISBN', 'name' => 'isbn', 'type' => 'text' ];

		$result = ( new AcfFieldGroupSave() )->execute( $payload );

		self::assertIsArray( $result );
		self::assertSame( [ 'field_isbn' ], $result['fields_created'] );
		self::assertSame( [ 1001 ], $result['created_post_ids'] );
		self::assertSame( [ 1001 ], $this->field_posts_with_key( 'field_isbn' ) );
		self::assertSame( [ self::GROUP_POST => false, self::FIELD_POST => false, 1001 => true ], $this->post_rows() );
		self::assertNotNull( ChangeLedger::read_image( $this->row_of( self::FIELD_POST )['change_id'], 'before' ), 'The field that existed has a before image.' );
	}

	public function test_a_group_no_post_holds_is_created_with_its_fields_and_every_post_is_recorded(): void {
		$result = ( new AcfFieldGroupSave() )->execute(
			[
				'group' => [
					'key'    => 'group_authors',
					'title'  => 'Authors',
					'fields' => [ [ 'key' => 'field_author_name', 'label' => 'Name', 'name' => 'name', 'type' => 'text' ] ],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['created'] );
		self::assertSame( 1001, $result['post_id'], 'The temporary post the importer inserted first became the group.' );
		self::assertSame( [ 1001, 1002 ], $result['created_post_ids'] );
		self::assertSame( [ 'field_author_name' ], $result['fields_created'] );
		self::assertSame( [ 1001 ], $this->group_posts_with_key( 'group_authors' ) );
		self::assertSame( [ 1002 ], $this->field_posts_with_key( 'field_author_name' ) );
		self::assertSame( [ 1001 => true, 1002 => true ], $this->post_rows(), 'Both posts have a created row, the group one although it was inserted as a plain post.' );
	}

	public function test_a_group_that_exists_only_in_local_json_is_created_and_reported_as_local(): void {
		unset( $GLOBALS['stonewright_test_posts'][ self::GROUP_POST ], $GLOBALS['stonewright_test_posts'][ self::FIELD_POST ] );
		AcfImportStandIn::sync_from_posts();
		$GLOBALS['stonewright_test_acf_local_groups']['group_books'] = [ 'key' => 'group_books', 'title' => 'Books', 'local' => 'json', 'ID' => 0 ];

		$result = ( new AcfFieldGroupSave() )->execute( $this->payload_without_ids() );

		self::assertIsArray( $result );
		self::assertTrue( $result['created'] );
		self::assertSame( 'json', $result['local'] );
		self::assertSame( 1001, $result['post_id'] );
		self::assertSame( [ 1001 ], $this->group_posts_with_key( 'group_books' ) );
		self::assertSame( [ 1001 => true, 1002 => true ], $this->post_rows() );
	}

	public function test_a_group_registered_in_php_is_refused(): void {
		unset( $GLOBALS['stonewright_test_posts'][ self::GROUP_POST ], $GLOBALS['stonewright_test_posts'][ self::FIELD_POST ] );
		AcfImportStandIn::sync_from_posts();
		$GLOBALS['stonewright_test_acf_local_groups']['group_books'] = [ 'key' => 'group_books', 'title' => 'Books', 'local' => 'php', 'ID' => 0 ];

		$result = ( new AcfFieldGroupSave() )->execute( $this->payload_without_ids() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_acf_group_registered_in_code', $result->get_error_code() );
		$this->assertNothingWritten();
	}

	public function test_a_save_whose_id_belongs_to_another_key_is_refused(): void {
		$this->make_post( 400, [ 'post_type' => 'acf-field-group', 'post_title' => 'Authors', 'post_name' => 'group_authors' ] );
		AcfImportStandIn::sync_from_posts();
		$payload                = $this->payload_without_ids();
		$payload['group']['ID'] = 400;

		$result = ( new AcfFieldGroupSave() )->execute( $payload );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_acf_group_id_mismatch', $result->get_error_code() );
		self::assertSame( [ self::GROUP_POST ], (array) ( $result->get_error_data()['post_ids'] ?? [] ) );
		self::assertSame( 'group_authors', $GLOBALS['stonewright_test_posts'][400]->post_name, 'The other group was not renamed.' );
		self::assertSame( [ self::GROUP_POST ], $this->group_posts_with_key( 'group_books' ) );
		$this->assertNothingWritten();
	}

	public function test_an_id_for_a_key_no_post_holds_is_refused_because_a_save_never_renames_a_group(): void {
		$payload                 = $this->payload_without_ids();
		$payload['group']['key'] = 'group_authors';
		$payload['group']['ID']  = self::GROUP_POST;

		$result = ( new AcfFieldGroupSave() )->execute( $payload );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_acf_group_id_mismatch', $result->get_error_code() );
		self::assertSame( 'group_books', $GLOBALS['stonewright_test_posts'][ self::GROUP_POST ]->post_name );
		$this->assertNothingWritten();
	}

	public function test_a_key_that_already_exists_twice_is_refused_until_the_duplicate_is_resolved(): void {
		$this->make_post( 500, [ 'post_type' => 'acf-field-group', 'post_title' => 'Books', 'post_name' => 'group_books' ] );
		AcfImportStandIn::sync_from_posts();

		$result = ( new AcfFieldGroupSave() )->execute( $this->payload_without_ids() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_acf_group_key_ambiguous', $result->get_error_code() );
		self::assertSame( [ self::GROUP_POST, 500 ], (array) ( $result->get_error_data()['post_ids'] ?? [] ) );
		self::assertSame( [ self::GROUP_POST, 500 ], $this->group_posts_with_key( 'group_books' ), 'Nothing was written.' );
		$this->assertNothingWritten();
	}

	public function test_an_ambiguous_key_is_saved_on_the_post_the_call_names_and_the_other_one_is_reported(): void {
		$this->make_post( 500, [ 'post_type' => 'acf-field-group', 'post_title' => 'Books (copy)', 'post_name' => 'group_books' ] );
		AcfImportStandIn::sync_from_posts();
		$payload                = $this->payload_without_ids();
		$payload['group']['ID'] = self::GROUP_POST;

		$result = ( new AcfFieldGroupSave() )->execute( $payload );

		self::assertIsArray( $result );
		self::assertSame( self::GROUP_POST, $result['post_id'] );
		self::assertSame( [ 500 ], $result['duplicate_post_ids'] );
		self::assertSame( 'wysiwyg', $this->field_type( self::FIELD_POST ) );
		self::assertSame( 'Books (copy)', $GLOBALS['stonewright_test_posts'][500]->post_title, 'The duplicate was left alone.' );
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'] );
	}

	public function test_a_field_id_that_does_not_belong_to_that_field_of_the_group_is_refused(): void {
		$this->make_field( 302, 'field_isbn', 'isbn', 'ISBN', self::GROUP_POST );
		AcfImportStandIn::sync_from_posts();

		foreach ( [ 999, 302 ] as $wrong_id ) {
			$payload                              = $this->payload_without_ids();
			$payload['group']['fields'][]         = [ 'key' => 'field_isbn', 'label' => 'ISBN', 'name' => 'isbn', 'type' => 'text' ];
			$payload['group']['fields'][0]['ID']  = $wrong_id;

			$result = ( new AcfFieldGroupSave() )->execute( $payload );

			self::assertInstanceOf( \WP_Error::class, $result, (string) $wrong_id );
			self::assertSame( 'stonewright_acf_field_id_mismatch', $result->get_error_code() );
			self::assertSame( 'field_summary', $result->get_error_data()['field_key'] );
		}
		self::assertSame( 'text', $this->field_type( self::FIELD_POST ) );
		self::assertSame( 'ISBN', $GLOBALS['stonewright_test_posts'][302]->post_title );
		$this->assertNothingWritten();
	}

	public function test_a_field_left_out_of_the_payload_is_refused_until_the_removal_is_allowed(): void {
		$this->make_field( 302, 'field_isbn', 'isbn', 'ISBN', self::GROUP_POST );
		AcfImportStandIn::sync_from_posts();

		$refused = ( new AcfFieldGroupSave() )->execute( $this->payload_without_ids() );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_acf_fields_would_be_removed', $refused->get_error_code() );
		self::assertSame( [ 'field_isbn' ], $refused->get_error_data()['field_keys'] );
		self::assertSame( [ 302 ], $this->field_posts_with_key( 'field_isbn' ), 'The field is still there.' );
		self::assertSame( 'text', $this->field_type( self::FIELD_POST ), 'The other field was not changed either.' );
		$this->assertNothingWritten();

		$result = ( new AcfFieldGroupSave() )->execute( $this->payload_without_ids() + [ 'allow_field_removal' => true ] );

		self::assertIsArray( $result );
		self::assertSame( [ 'field_isbn' ], $result['fields_removed'], 'The result names the field the save deleted.' );
		self::assertSame( [], $this->field_posts_with_key( 'field_isbn' ) );
		self::assertSame( [ self::GROUP_POST => false, self::FIELD_POST => false, 302 => false ], $this->post_rows(), 'The deleted field has a row with its before image.' );
		self::assertNotNull( ChangeLedger::read_image( $this->row_of( 302 )['change_id'], 'before' ) );
	}

	public function test_nested_field_keys_are_left_to_acf_and_their_posts_are_updated_in_place(): void {
		$this->make_field( 310, 'field_rows', 'rows', 'Rows', self::GROUP_POST, [ 'type' => 'repeater' ] );
		$this->make_field( 311, 'field_rows_title', 'title', 'Title', 310 );
		AcfImportStandIn::sync_from_posts();
		$payload                      = $this->payload_without_ids();
		$payload['group']['fields'][] = [
			'key'        => 'field_rows',
			'label'      => 'Rows',
			'name'       => 'rows',
			'type'       => 'repeater',
			'sub_fields' => [ [ 'key' => 'field_rows_title', 'label' => 'Heading', 'name' => 'title', 'type' => 'text' ] ],
		];

		$result = ( new AcfFieldGroupSave() )->execute( $payload );

		self::assertIsArray( $result );
		self::assertSame( [], $result['fields_created'] );
		self::assertSame( [], $result['created_post_ids'] );
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'] );
		self::assertSame( 'Heading', $GLOBALS['stonewright_test_posts'][311]->post_title, 'The sub field post was updated in place.' );
		self::assertSame( 310, (int) $GLOBALS['stonewright_test_posts'][311]->post_parent );
		self::assertArrayNotHasKey( 'ID', AcfImportStandIn::$received[0]['fields'][1]['sub_fields'][0], 'The sub field went to ACF without an id: ACF maps nested keys itself.' );
		self::assertSame( [ self::GROUP_POST => false, self::FIELD_POST => false, 310 => false, 311 => false ], $this->post_rows() );
	}

	public function test_a_group_whose_fields_nest_deeper_than_the_save_can_check_is_refused(): void {
		// A chain of nested group fields one level deeper than the walk follows.
		$parent = self::GROUP_POST;
		for ( $level = 1; $level <= AcfRuntime::MAX_FIELD_LEVELS + 1; $level++ ) {
			$this->make_field( 400 + $level, 'field_level_' . $level, 'level_' . $level, 'Level ' . $level, $parent, [ 'type' => 'group' ] );
			$parent = 400 + $level;
		}
		AcfImportStandIn::sync_from_posts();
		// The payload names every level but the deepest, which ACF would delete.
		$payload                      = $this->payload_without_ids();
		$payload['group']['fields'][] = self::nested_chain( 'level', AcfRuntime::MAX_FIELD_LEVELS );

		$result = ( new AcfFieldGroupSave() )->execute( $payload );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_acf_group_too_deep', $result->get_error_code() );
		$deepest = AcfRuntime::MAX_FIELD_LEVELS + 1;
		self::assertSame( [ 400 + $deepest ], $this->field_posts_with_key( 'field_level_' . $deepest ), 'The deepest field is still there.' );
		$this->assertNothingWritten();

		// Allowing removal does not lift it: the save still cannot see what ACF would change past that depth.
		$allowed = ( new AcfFieldGroupSave() )->execute( $payload + [ 'allow_field_removal' => true ] );
		self::assertInstanceOf( \WP_Error::class, $allowed );
		self::assertSame( 'stonewright_acf_group_too_deep', $allowed->get_error_code() );
		$this->assertNothingWritten();
	}

	public function test_a_payload_whose_fields_nest_deeper_than_the_save_can_check_is_refused(): void {
		$payload                      = $this->payload_without_ids();
		$payload['group']['fields'][] = self::nested_chain( 'new', AcfRuntime::MAX_FIELD_LEVELS + 1 );

		$result = ( new AcfFieldGroupSave() )->execute( $payload );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_acf_group_too_deep', $result->get_error_code() );
		$this->assertNothingWritten();
	}

	public function test_the_mirror_holds_the_resolved_post_ids(): void {
		$this->make_field( 310, 'field_rows', 'rows', 'Rows', self::GROUP_POST, [ 'type' => 'repeater' ] );
		$this->make_field( 311, 'field_rows_title', 'title', 'Title', 310 );
		AcfImportStandIn::sync_from_posts();
		$payload                      = $this->payload_without_ids();
		$payload['group']['fields'][] = [
			'key'        => 'field_rows',
			'label'      => 'Rows',
			'name'       => 'rows',
			'type'       => 'repeater',
			'sub_fields' => [ [ 'key' => 'field_rows_title', 'label' => 'Title', 'name' => 'title', 'type' => 'text' ] ],
		];
		$payload['group']['fields'][] = [ 'key' => 'field_isbn', 'label' => 'ISBN', 'name' => 'isbn', 'type' => 'text' ];

		( new AcfFieldGroupSave() )->execute( $payload );

		$mirror = get_option( 'stonewright_acf_field_groups' )['group_books'];
		self::assertSame( self::GROUP_POST, $mirror['ID'] );
		self::assertSame( self::FIELD_POST, $mirror['fields'][0]['ID'] );
		self::assertSame( 310, $mirror['fields'][1]['ID'] );
		self::assertSame( 311, $mirror['fields'][1]['sub_fields'][0]['ID'] );
		self::assertSame( 1001, $mirror['fields'][2]['ID'], 'The field the save created has the id ACF gave it.' );
	}

	public function test_get_returns_the_post_ids_a_round_trip_needs(): void {
		$result = ( new AcfFieldGroupGet() )->execute( [ 'key' => 'group_books' ] );

		self::assertIsArray( $result );
		self::assertSame( self::GROUP_POST, $result['group']['ID'] ?? null, 'The group post id is part of the read.' );
		self::assertSame( '', $result['group']['local'] ?? null );
		self::assertSame( self::FIELD_POST, $result['fields'][0]['ID'] ?? null, 'The field post id is part of the read.' );
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $settings
	 */
	private function make_field( int $id, string $key, string $name, string $label, int $parent, array $settings = [ 'type' => 'text' ] ): void {
		$this->make_post( $id, [ 'post_type' => 'acf-field', 'post_title' => $label, 'post_name' => $key, 'post_excerpt' => $name, 'post_parent' => $parent, 'post_content' => serialize( $settings ) ] );
	}

	/**
	 * A group field holding a group field, $levels deep, with keys field_<prefix>_1 (outermost) to field_<prefix>_<levels>.
	 *
	 * @return array<string, mixed>
	 */
	private static function nested_chain( string $prefix, int $levels ): array {
		$nested = [];
		for ( $level = $levels; $level >= 1; $level-- ) {
			$field = [ 'key' => 'field_' . $prefix . '_' . $level, 'label' => ucfirst( $prefix ) . ' ' . $level, 'name' => $prefix . '_' . $level, 'type' => 'group' ];
			if ( [] !== $nested ) {
				$field['sub_fields'] = [ $nested ];
			}
			$nested = $field;
		}
		return $nested;
	}

	private function field_type( int $post_id ): string {
		return (string) ( unserialize( (string) $GLOBALS['stonewright_test_posts'][ $post_id ]->post_content )['type'] ?? '' );
	}

	private function assertNothingWritten(): void {
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'], 'No post was inserted.' );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'], 'No post was updated.' );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'], 'No post was deleted.' );
		self::assertFalse( get_option( 'stonewright_acf_field_groups', false ), 'The mirror was not written.' );
		self::assertSame( [], $this->ledger_rows(), 'Nothing was recorded.' );
	}

	/**
	 * The post rows of the ledger: post id to whether the row is a creation.
	 *
	 * @return array<int, bool>
	 */
	private function post_rows(): array {
		$rows = [];
		foreach ( $this->ledger_rows() as $row ) {
			if ( 'post' === $row['resource_type'] ) {
				$rows[ (int) $row['resource_id'] ] = PostAdapter::is_created_row( $row );
			}
		}
		ksort( $rows );
		return $rows;
	}

	/** @return array<string, mixed> */
	private function row_of( int $post_id ): array {
		foreach ( $this->ledger_rows() as $row ) {
			if ( 'post' === $row['resource_type'] && (int) $row['resource_id'] === $post_id ) {
				return $row;
			}
		}
		self::fail( 'No ledger row for post ' . $post_id );
	}

	/** @return list<int> */
	private function group_posts_with_key( string $key ): array {
		return $this->posts_with( 'acf-field-group', $key );
	}

	/** @return list<int> */
	private function field_posts_with_key( string $key ): array {
		return $this->posts_with( 'acf-field', $key );
	}

	/** @return list<int> */
	private function posts_with( string $type, string $name ): array {
		$ids = [];
		foreach ( $GLOBALS['stonewright_test_posts'] as $post ) {
			if ( $type === $post->post_type && $name === $post->post_name ) {
				$ids[] = (int) $post->ID;
			}
		}
		sort( $ids );
		return $ids;
	}
}
