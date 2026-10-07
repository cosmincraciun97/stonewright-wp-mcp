<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AbilityHubCatalog;
use Stonewright\WpMcp\Admin\AbilityKind;
use Stonewright\WpMcp\Admin\AbilityToggles;

/**
 * The one place the AI Abilities page changes which abilities are switched off. The admin-post handlers and the
 * REST routes both call it, so the two ways in cannot disagree.
 *
 * @covers \Stonewright\WpMcp\Admin\AbilityToggles
 * @covers \Stonewright\WpMcp\Admin\AbilityKind
 */
final class AbilityTogglesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_enabled' => true, 'stonewright_disabled_abilities' => [] ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	/** @return list<string> */
	private static function disabled(): array {
		return array_values( (array) ( $GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] ?? [] ) );
	}

	/** @return array{0: string, 1: string} A known ability name and its category. */
	private static function known(): array {
		$row = AbilityHubCatalog::collect()[0];

		return [ (string) $row['name'], (string) $row['category'] ];
	}

	public function test_switching_an_ability_off_lists_it_once_and_switching_it_on_removes_it(): void {
		[ $name ] = self::known();

		$off = AbilityToggles::set_enabled( $name, false );
		self::assertSame( [ true, 'disabled' ], [ $off['ok'], $off['code'] ] );
		self::assertSame( [ $name ], self::disabled() );

		AbilityToggles::set_enabled( $name, false );
		self::assertSame( [ $name ], self::disabled(), 'Switching off twice lists it once.' );

		$on = AbilityToggles::set_enabled( $name, true );
		self::assertSame( [ true, 'enabled' ], [ $on['ok'], $on['code'] ] );
		self::assertSame( [], self::disabled() );
	}

	public function test_switching_one_ability_leaves_the_others_as_they_were(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ 'site-a/keep', 'site-a/other' ];

		AbilityToggles::set_enabled( 'site-a/other', true );

		self::assertSame( [ 'site-a/keep' ], self::disabled(), 'The single toggle never prunes names it does not know.' );
	}

	public function test_an_empty_name_changes_nothing_and_says_so(): void {
		$result = AbilityToggles::set_enabled( '', false );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'missing-name', $result['code'] );
		self::assertSame( [], self::disabled() );
	}

	public function test_bulk_selected_changes_only_known_abilities(): void {
		$names = array_slice( AbilityHubCatalog::names(), 0, 3 );

		$off = AbilityToggles::bulk( 'disable_selected', '', array_merge( $names, [ 'site-a/not-an-ability' ] ) );

		self::assertSame( [ true, 'bulk-disabled', 3 ], [ $off['ok'], $off['code'], $off['changed'] ] );
		self::assertEqualsCanonicalizing( $names, self::disabled() );

		$on = AbilityToggles::bulk( 'enable_selected', '', [ $names[0] ] );

		self::assertSame( [ true, 'bulk-enabled', 1 ], [ $on['ok'], $on['code'], $on['changed'] ] );
		self::assertEqualsCanonicalizing( [ $names[1], $names[2] ], self::disabled() );
	}

	public function test_bulk_category_changes_every_ability_of_that_category(): void {
		[ , $category ] = self::known();
		$in_category    = array_values( array_map( static fn ( array $row ): string => (string) $row['name'], array_filter( AbilityHubCatalog::collect(), static fn ( array $row ): bool => (string) $row['category'] === $category ) ) );

		$result = AbilityToggles::bulk( 'disable_category', $category, [] );

		self::assertSame( [ true, 'bulk-disabled', count( $in_category ) ], [ $result['ok'], $result['code'], $result['changed'] ] );
		self::assertEqualsCanonicalizing( $in_category, self::disabled() );
	}

	public function test_bulk_drops_stored_names_that_are_no_longer_abilities(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ 'site-a/gone' ];
		[ $name ] = self::known();

		AbilityToggles::bulk( 'disable_selected', '', [ $name ] );

		self::assertSame( [ $name ], self::disabled(), 'The stored list is cut to known abilities, as the form handler always did.' );
	}

	public function test_bulk_refuses_what_it_cannot_do_and_changes_nothing(): void {
		[ $name ] = self::known();
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ $name ];

		$none = AbilityToggles::bulk( '', '', [ $name ] );
		self::assertSame( [ false, 'bulk-no-action' ], [ $none['ok'], $none['code'] ] );

		$unknown = AbilityToggles::bulk( 'burn_everything', '', [ $name ] );
		self::assertSame( [ false, 'bulk-no-action' ], [ $unknown['ok'], $unknown['code'] ] );

		$empty = AbilityToggles::bulk( 'enable_selected', '', [] );
		self::assertSame( [ false, 'bulk-no-selection' ], [ $empty['ok'], $empty['code'] ] );

		$foreign = AbilityToggles::bulk( 'enable_selected', '', [ 'site-a/not-an-ability' ] );
		self::assertSame( [ false, 'bulk-no-selection' ], [ $foreign['ok'], $foreign['code'] ], 'Only unknown names selected is the same as nothing selected.' );

		$no_category = AbilityToggles::bulk( 'enable_category', '', [] );
		self::assertSame( [ false, 'bulk-no-category' ], [ $no_category['ok'], $no_category['code'] ] );

		$bad_category = AbilityToggles::bulk( 'enable_category', 'no-such-category', [] );
		self::assertSame( [ false, 'bulk-no-category' ], [ $bad_category['ok'], $bad_category['code'] ] );

		self::assertSame( [ $name ], self::disabled(), 'A refused bulk action leaves the option alone.' );
	}

	public function test_stats_count_enabled_write_and_read_abilities(): void {
		$stats = AbilityToggles::stats();

		self::assertSame( count( AbilityHubCatalog::collect() ), $stats['total'] );
		self::assertSame( $stats['total'], $stats['write'] + $stats['read'] );
		self::assertLessThanOrEqual( $stats['total'], $stats['enabled'] );

		AbilityToggles::bulk( 'disable_selected', '', AbilityHubCatalog::names() );
		self::assertSame( 0, AbilityToggles::stats()['enabled'] );
	}

	public function test_every_outcome_has_a_plain_sentence_with_real_plurals(): void {
		self::assertSame( 'Ability turned on.', AbilityToggles::message( 'enabled', 1 ) );
		self::assertSame( 'Ability turned off.', AbilityToggles::message( 'disabled', 1 ) );
		self::assertSame( '1 ability turned on.', AbilityToggles::message( 'bulk-enabled', 1 ) );
		self::assertSame( '5 abilities turned off.', AbilityToggles::message( 'bulk-disabled', 5 ) );
		self::assertSame( 'Nothing changed: those abilities were already on.', AbilityToggles::message( 'bulk-enabled', 0 ) );
		self::assertSame( 'Nothing changed: those abilities were already off.', AbilityToggles::message( 'bulk-disabled', 0 ) );
		self::assertSame( 'Choose a bulk action, then press Apply.', AbilityToggles::message( 'bulk-no-action' ) );
		self::assertSame( 'Select at least one ability, then press Apply.', AbilityToggles::message( 'bulk-no-selection' ) );
		self::assertSame( 'Choose a category for that action, then press Apply.', AbilityToggles::message( 'bulk-no-category' ) );
		self::assertSame( 'No ability was named.', AbilityToggles::message( 'missing-name' ) );
		self::assertSame( '', AbilityToggles::message( 'something-else' ) );
	}

	public function test_kinds_are_read_write_or_destructive_by_the_verb_in_the_name(): void {
		self::assertSame( 'read', AbilityKind::of( 'stonewright/post-list' ) );
		self::assertSame( 'write', AbilityKind::of( 'stonewright/post-update' ) );
		self::assertSame( 'destructive', AbilityKind::of( 'stonewright/post-delete' ) );
		self::assertSame( 'Read', AbilityKind::label( 'read' ) );
		self::assertSame( 'Destructive', AbilityKind::label( 'destructive' ) );
	}
}
