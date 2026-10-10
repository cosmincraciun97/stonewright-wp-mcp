<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\RescueRollback;

/**
 * How the rollback service runs a recipe.
 *
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 */
final class RescueRollbackTest extends TestCase {

	protected function tearDown(): void {
		RescueRollback::set_selection_runner( null );
	}

	/** An entry whose recipe fails at once and touches nothing: a sandbox name that is not a plain file name. */
	private static function entry(): array {
		return [
			'id'            => 'cs-0123456789abcdef01234567',
			'recipe'        => [ 'type' => 'sandbox_file', 'ref' => '../not-a-name.php' ],
			'recipe_detail' => [],
		];
	}

	public function test_a_recipe_runs_with_the_stored_plugin_and_theme_selection_in_place(): void {
		$calls = [];
		RescueRollback::set_selection_runner(
			static function ( callable $callback ) use ( &$calls ): mixed {
				$calls[] = 'enter';
				$result  = $callback();
				$calls[] = 'leave';
				return $result;
			}
		);

		$result = RescueRollback::apply_recipe( self::entry(), 'ability' );

		self::assertSame( [ 'enter', 'leave' ], $calls, 'In safe mode the plugin and theme options are filtered; a recipe that changes them must see the stored values.' );
		self::assertSame( 'failed', $result['status'], 'The recipe itself ran.' );
		self::assertSame( 'invalid_name', $result['detail'] );
		self::assertSame( 'ability', $result['by'] );
	}

	public function test_a_recipe_the_caller_supplied_runs_as_it_is(): void {
		$calls = [];
		RescueRollback::set_selection_runner(
			static function ( callable $callback ) use ( &$calls ): mixed {
				$calls[] = 'wrapped';
				return $callback();
			}
		);

		$result = RescueRollback::apply_recipe(
			self::entry(),
			'auto',
			static fn (): array => [ 'status' => 'succeeded', 'recipe' => 'theme_backup', 'detail' => '' ]
		);

		self::assertSame( [], $calls, 'An in-process rollback already holds the bytes it needs.' );
		self::assertSame( 'succeeded', $result['status'] );
	}

	public function test_a_helper_that_fails_or_skips_the_callback_never_stops_or_repeats_the_recipe(): void {
		$runs = 0;
		$entry = self::entry();
		$entry['recipe']['type'] = 'sandbox_file';

		RescueRollback::set_selection_runner( static function ( callable $callback ): mixed {
			throw new \RuntimeException( 'helper broke before running the recipe' );
		} );
		$thrown = RescueRollback::apply_recipe( $entry, 'ability' );

		RescueRollback::set_selection_runner( static fn ( callable $callback ): mixed => 'ignored' );
		$skipped = RescueRollback::apply_recipe( $entry, 'ability' );

		RescueRollback::set_selection_runner(
			static function ( callable $callback ) use ( &$runs ): mixed {
				$callback();
				++$runs;
				throw new \RuntimeException( 'helper broke after running the recipe' );
			}
		);
		$late = RescueRollback::apply_recipe( $entry, 'ability' );

		foreach ( [ $thrown, $skipped, $late ] as $result ) {
			self::assertSame( 'failed', $result['status'] );
			self::assertSame( 'invalid_name', $result['detail'], 'The recipe ran and its own outcome is reported.' );
		}
		self::assertSame( 1, $runs );
	}

	public function test_without_the_rescue_helper_the_recipe_simply_runs(): void {
		RescueRollback::set_selection_runner( null );

		$result = RescueRollback::apply_recipe( self::entry(), 'ability' );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'invalid_name', $result['detail'] );
	}
}
