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
		$this->make_post( 300, [ 'post_type' => 'acf-field-group', 'post_title' => 'Books' ] );
		$this->make_post( 301, [ 'post_type' => 'acf-field', 'post_title' => 'ISBN' ] );
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

		self::assertSame( [ 'ok' => true, 'key' => 'group_books' ], $result );
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
}
