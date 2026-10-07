<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\RescueRollback;
use Stonewright\WpMcp\Security\RollbackRecipes;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * A rollback that runs while the request is in safe boot changes the real plugin selection.
 *
 * The recipe here is a fake custom-code provider whose rollback does what activating or
 * deactivating a plugin does: read the stored list of active plugins, change it, save it. The path
 * from RescueRollback::apply_recipe() through RescueSafeBoot and the helper to that provider is
 * the real one.
 *
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 * @covers \Stonewright\WpMcp\Security\RescueSafeBoot
 */
final class RescueSafeBootRollbackTest extends TestCase {

	public const STORED = [ 'akismet/akismet.php', 'hello/hello.php', 'stonewright/stonewright.php' ];

	protected function setUp(): void {
		MuRuntime::begin();
	}

	protected function tearDown(): void {
		RollbackRecipes::set_provider_resolver( null );
		MuRuntime::end();
	}

	private function enter_safe_boot(): void {
		$token = MuRuntime::open_session( 7 );
		MuRuntime::request( 'wp-admin/index.php', [], [ 'stonewright_rescue_session' => $token ] );
		MuRuntime::start();
	}

	/** A provider whose rollback deactivates hello/hello.php, and what it saw and would have saved. */
	private function provider(): object {
		return new class() {
			/** @var array<string, mixed> */
			public array $seen = [];

			/** @param array<string, string> $args */
			public function rollback( array $args ): array {
				$read              = MuRuntime::filter( 'option_active_plugins', RescueSafeBootRollbackTest::STORED );
				$next              = array_values( array_diff( $read, [ 'hello/hello.php' ] ) );
				$this->seen['read']  = $read;
				$this->seen['next']  = $next;
				$this->seen['saved'] = MuRuntime::filter( 'pre_update_option_active_plugins', $next, RescueSafeBootRollbackTest::STORED );
				return [ 'effect_verified' => true ];
			}
		};
	}

	/** @return array<string, mixed> */
	private static function entry(): array {
		return [
			'id'            => 'cs-1',
			'recipe'        => [ 'type' => 'none', 'ref' => '' ],
			'recipe_detail' => [ 'provider' => 'fake-provider', 'snapshot_id' => 'snap-1' ],
		];
	}

	public function test_in_safe_boot_the_rollback_reads_and_saves_the_stored_plugin_list(): void {
		$provider = $this->provider();
		RollbackRecipes::set_provider_resolver( static fn ( string $id ): object => $provider );
		$this->enter_safe_boot();

		$outcome = RescueRollback::apply_recipe( self::entry(), 'test' );

		self::assertSame( 'succeeded', $outcome['status'] );
		self::assertSame( self::STORED, $provider->seen['read'], 'the real list, not the safe boot one' );
		self::assertSame( [ 'akismet/akismet.php', 'stonewright/stonewright.php' ], $provider->seen['saved'], 'and the change is what gets saved' );
	}

	public function test_without_the_wrapper_safe_boot_hides_the_list_and_drops_the_write(): void {
		$provider = $this->provider();
		$this->enter_safe_boot();

		$provider->rollback( [] );

		self::assertSame( [ 'stonewright/stonewright.php' ], $provider->seen['read'], 'safe boot shows only Stonewright' );
		self::assertSame( self::STORED, $provider->seen['saved'], 'and the write keeps what was stored' );
	}

	public function test_outside_safe_boot_the_rollback_reads_and_saves_the_stored_plugin_list(): void {
		$provider = $this->provider();
		RollbackRecipes::set_provider_resolver( static fn ( string $id ): object => $provider );
		MuRuntime::boot();

		$outcome = RescueRollback::apply_recipe( self::entry(), 'test' );

		self::assertSame( 'succeeded', $outcome['status'] );
		self::assertSame( self::STORED, $provider->seen['read'] );
		self::assertSame( [ 'akismet/akismet.php', 'stonewright/stonewright.php' ], $provider->seen['saved'] );
	}

	public function test_the_selection_stays_replaced_after_the_rollback_in_safe_boot(): void {
		$provider = $this->provider();
		RollbackRecipes::set_provider_resolver( static fn ( string $id ): object => $provider );
		$this->enter_safe_boot();

		RescueRollback::apply_recipe( self::entry(), 'test' );

		self::assertSame( [ 'stonewright/stonewright.php' ], MuRuntime::filter( 'option_active_plugins', self::STORED ), 'safe boot is back in place for the rest of the request' );
		self::assertSame( self::STORED, MuRuntime::filter( 'pre_update_option_active_plugins', [ 'x' ], self::STORED ), 'and writes are ignored again' );
	}
}
