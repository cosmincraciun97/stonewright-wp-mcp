<?php
/**
 * An ACF field group is stored as posts. Saving one records those posts in the change ledger.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupSave;

/**
 * @covers \Stonewright\WpMcp\Abilities\Acf\AcfRuntime::import_field_group
 * @covers \Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupSave
 */
final class AcfFieldGroupLedgerTest extends FamilyLedgerTestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_acf_import_callback'], $GLOBALS['stonewright_test_acf_field_defs'] );
		parent::tearDown();
	}

	public function test_the_group_and_field_posts_are_recorded_as_overwritten_or_created(): void {
		// The group exists, with one field. The import keeps the field and adds another.
		$this->make_post( 300, [ 'post_type' => 'acf-field-group', 'post_title' => 'Books', 'post_name' => 'group_books' ] );
		$this->make_post( 301, [ 'post_type' => 'acf-field', 'post_title' => 'ISBN', 'post_name' => 'field_isbn', 'post_parent' => 300 ] );
		$GLOBALS['stonewright_test_acf_groups']     = [ [ 'key' => 'group_books', 'ID' => 300, 'title' => 'Books' ] ];
		$GLOBALS['stonewright_test_acf_field_defs'] = [ 'group_books' => [ [ 'key' => 'field_isbn', 'ID' => 301 ] ] ];
		$GLOBALS['stonewright_test_acf_import_callback'] = function ( array $group ): array {
			$this->make_post( 302, [ 'post_type' => 'acf-field', 'post_title' => 'Author' ] );
			$GLOBALS['stonewright_test_acf_field_defs']['group_books'][] = [ 'key' => 'field_author', 'ID' => 302 ];
			return $GLOBALS['stonewright_test_acf_groups'][0];
		};

		$result = ( new AcfFieldGroupSave() )->execute(
			[
				'group' => [
					'key'    => 'group_books',
					'title'  => 'Books',
					'fields' => [
						[ 'key' => 'field_isbn', 'label' => 'ISBN', 'name' => 'isbn', 'type' => 'text' ],
						[ 'key' => 'field_author', 'label' => 'Author', 'name' => 'author', 'type' => 'text' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'group_books', $result['key'] );
		self::assertSame( 300, $result['post_id'] );
		self::assertSame( [ 302 ], $result['created_post_ids'] );
		$posts = [];
		foreach ( $this->ledger_rows() as $row ) {
			if ( 'post' === $row['resource_type'] ) {
				$posts[ $row['resource_id'] ] = $row;
			}
		}
		ksort( $posts );
		self::assertSame( [ '300', '301', '302' ], array_map( 'strval', array_keys( $posts ) ) );
		foreach ( $posts as $row ) {
			self::assertSame( 'stonewright/acf-field-group-save', $row['ability'] );
		}
		// The two posts that existed have a before image; the new one is a create.
		self::assertNotNull( \Stonewright\WpMcp\Security\ChangeLedger::read_image( $posts['300']['change_id'], 'before' ) );
		self::assertNotNull( \Stonewright\WpMcp\Security\ChangeLedger::read_image( $posts['301']['change_id'], 'before' ) );
		self::assertTrue( \Stonewright\WpMcp\Security\Adapters\PostAdapter::is_created_row( $posts['302'] ) );
	}

	public function test_a_post_the_import_inserts_is_recorded_from_the_insert_action_even_when_nothing_else_shows_it(): void {
		$this->make_post( 300, [ 'post_type' => 'acf-field-group', 'post_title' => 'Books', 'post_name' => 'group_books' ] );
		$GLOBALS['stonewright_test_acf_groups']     = [ [ 'key' => 'group_books', 'ID' => 300, 'title' => 'Books' ] ];
		$GLOBALS['stonewright_test_acf_field_defs'] = [ 'group_books' => [] ];
		// The importer inserts a field post that neither ACF's readback nor the parent walk lists afterwards:
		// only the wp_insert_post action saw it.
		$GLOBALS['stonewright_test_acf_import_callback'] = static function ( array $group ): array {
			wp_insert_post( [ 'post_type' => 'acf-field', 'post_status' => 'publish', 'post_title' => 'Author', 'post_name' => 'field_author', 'post_parent' => 0 ] );
			return $GLOBALS['stonewright_test_acf_groups'][0];
		};

		$result = ( new AcfFieldGroupSave() )->execute(
			[ 'group' => [ 'key' => 'group_books', 'title' => 'Books', 'fields' => [ [ 'key' => 'field_author', 'label' => 'Author', 'name' => 'author', 'type' => 'text' ] ] ] ]
		);

		self::assertIsArray( $result );
		self::assertSame( [ 1001 ], $result['created_post_ids'] );
		$rows = [];
		foreach ( $this->ledger_rows() as $row ) {
			if ( 'post' === $row['resource_type'] ) {
				$rows[ (int) $row['resource_id'] ] = \Stonewright\WpMcp\Security\Adapters\PostAdapter::is_created_row( $row );
			}
		}
		ksort( $rows );
		self::assertSame( [ 300 => false, 1001 => true ], $rows );
		self::assertFalse( has_action( 'wp_insert_post' ), 'The listener is removed once the import is over.' );
	}

	public function test_the_insert_listener_is_removed_when_the_import_throws(): void {
		$this->make_post( 300, [ 'post_type' => 'acf-field-group', 'post_title' => 'Books', 'post_name' => 'group_books' ] );
		$GLOBALS['stonewright_test_acf_groups']          = [ [ 'key' => 'group_books', 'ID' => 300, 'title' => 'Books' ] ];
		$GLOBALS['stonewright_test_acf_import_callback'] = static function (): array {
			throw new \RuntimeException( 'importer failed' );
		};

		$result = ( new AcfFieldGroupSave() )->execute( [ 'group' => [ 'key' => 'group_books', 'title' => 'Books', 'fields' => [] ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertFalse( has_action( 'wp_insert_post' ) );
	}
}
