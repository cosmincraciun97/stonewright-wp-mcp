<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Acf;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupSave;

/**
 * Saving a field group says it saved only when ACF imported it.
 *
 * @covers \Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupSave
 * @covers \Stonewright\WpMcp\Abilities\Acf\AcfRuntime
 */
final class AcfFieldGroupSaveFixesTest extends TestCase {

	private const GROUP = [
		'key'    => 'group_books',
		'title'  => 'Books',
		'fields' => [ [ 'key' => 'field_isbn', 'label' => 'ISBN', 'name' => 'isbn', 'type' => 'text' ] ],
	];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_acf_active']     = true;
		$GLOBALS['stonewright_test_acf_groups']     = [];
		unset( $GLOBALS['stonewright_test_acf_import_available'], $GLOBALS['stonewright_test_acf_import_callback'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_acf_import_available'], $GLOBALS['stonewright_test_acf_import_callback'], $GLOBALS['stonewright_test_acf_active'] );
		$GLOBALS['stonewright_test_acf_groups'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_it_saves_through_acf_and_keeps_the_contract_mirror(): void {
		$result = ( new AcfFieldGroupSave() )->execute( [ 'group' => self::GROUP ] );

		self::assertSame( [ 'ok' => true, 'key' => 'group_books' ], $result );
		self::assertSame( 'group_books', $GLOBALS['stonewright_test_acf_groups'][0]['key'] );
		self::assertSame( 'Books', get_option( 'stonewright_acf_field_groups' )['group_books']['title'] );
	}

	public function test_it_is_an_error_when_acf_import_is_unavailable_and_writes_nothing(): void {
		$GLOBALS['stonewright_test_acf_import_available'] = false;

		$result = ( new AcfFieldGroupSave() )->execute( [ 'group' => self::GROUP ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_acf_import_unavailable', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_acf_groups'] );
		self::assertFalse( get_option( 'stonewright_acf_field_groups', false ) );
	}

	public function test_an_import_that_acf_refuses_is_returned_and_the_mirror_is_not_written(): void {
		$GLOBALS['stonewright_test_acf_import_callback'] = static fn (): \WP_Error => new \WP_Error( 'acf_invalid', 'Bad field type.' );

		$result = ( new AcfFieldGroupSave() )->execute( [ 'group' => self::GROUP ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'acf_invalid', $result->get_error_code() );
		self::assertFalse( get_option( 'stonewright_acf_field_groups', false ) );
	}

	public function test_an_import_that_returns_nothing_is_an_error(): void {
		$GLOBALS['stonewright_test_acf_import_callback'] = static fn (): bool => false;

		$result = ( new AcfFieldGroupSave() )->execute( [ 'group' => self::GROUP ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_acf_import_failed', $result->get_error_code() );
		self::assertFalse( get_option( 'stonewright_acf_field_groups', false ) );
	}

	public function test_a_group_that_cannot_be_read_back_after_the_import_is_an_error(): void {
		// ACF says it imported the group, but the group is not there afterwards.
		$GLOBALS['stonewright_test_acf_import_callback'] = static fn ( array $group ): array => $group;

		$result = ( new AcfFieldGroupSave() )->execute( [ 'group' => self::GROUP ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_acf_import_failed', $result->get_error_code() );
	}

	public function test_it_still_reports_acf_missing_when_acf_is_not_active(): void {
		$GLOBALS['stonewright_test_acf_active'] = false;

		$result = ( new AcfFieldGroupSave() )->execute( [ 'group' => self::GROUP ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_plugin_missing', $result->get_error_code() );
	}
}
