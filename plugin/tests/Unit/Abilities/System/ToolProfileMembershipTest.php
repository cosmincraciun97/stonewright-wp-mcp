<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\FullProfileOnlyAbilities;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;

/**
 * An agent that works in a profile must find the abilities that do the work of that profile there:
 * the block edits and patterns on the Gutenberg profile, the content, media and SEO writes on the
 * content profile, and the menu and theme chrome tools on the site profile.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class ToolProfileMembershipTest extends TestCase {

	/** Default max_tools of a tool-profile call. */
	private const DEFAULT_MAX_TOOLS = 50;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_extra_abilities' => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	/**
	 * @return array<string, array{string, list<string>}>
	 */
	public static function memberships(): array {
		return [
			'gutenberg block edits, finalizer and patterns' => [
				'gutenberg',
				[
					'stonewright/blocks-insert',
					'stonewright/blocks-update',
					'stonewright/blocks-remove',
					'stonewright/blocks-finalize-batch',
					'stonewright/blocks-pending-batch',
					'stonewright/blocks-finalizer-runtime',
					'stonewright/patterns-list',
					'stonewright/patterns-create',
					'stonewright/patterns-update',
					'stonewright/patterns-delete',
					'stonewright/patterns-categories',
				],
			],
			'content model SEO' => [
				'content-model',
				[
					'stonewright/seo-meta-get',
					'stonewright/seo-meta-update',
				],
			],
			'content writes and media' => [
				'content-model',
				[
					'stonewright/content-create-post',
					'stonewright/content-update-post',
					'stonewright/content-bulk-create',
					'stonewright/content-duplicate-page',
					'stonewright/media-get',
					'stonewright/media-set-alt',
					'stonewright/media-optimize',
				],
			],
			'site structure' => [
				'site-admin',
				[
					'stonewright/menu-create',
					'stonewright/menu-add-item',
					'stonewright/menu-delete',
					'stonewright/menu-assign-location',
					'stonewright/theme-chrome-get',
					'stonewright/theme-chrome-update',
				],
			],
		];
	}

	/**
	 * @dataProvider memberships
	 * @param list<string> $expected
	 */
	public function test_the_profile_lists_the_abilities_of_its_work( string $profile, array $expected ): void {
		$names = ToolProfile::profile_tools( $profile );

		foreach ( $expected as $name ) {
			self::assertContains( $name, $names, $name . ' on the ' . $profile . ' profile' );
		}
		self::assertSame( $names, array_values( array_unique( $names ) ), 'No tool is listed twice.' );
	}

	/**
	 * @dataProvider memberships
	 * @param list<string> $expected
	 */
	public function test_every_added_ability_is_registered_and_never_a_full_profile_only_one( string $profile, array $expected ): void {
		$registered = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$registered[ ( new $class() )->name() ] = true;
		}

		foreach ( $expected as $name ) {
			self::assertArrayHasKey( $name, $registered, $name . ' is not a registered ability.' );
			self::assertFalse( FullProfileOnlyAbilities::is_restricted( $name ), $name . ' runs or writes code and stays on the full profile.' );
		}
	}

	public function test_the_content_writes_survive_the_default_tool_cap(): void {
		$first = array_slice( ToolProfile::profile_tools( 'content-model' ), 0, self::DEFAULT_MAX_TOOLS );

		foreach ( [ 'stonewright/content-create-post', 'stonewright/content-update-post', 'stonewright/content-bulk-create', 'stonewright/content-duplicate-page', 'stonewright/media-get', 'stonewright/media-set-alt', 'stonewright/media-optimize', 'stonewright/seo-meta-get', 'stonewright/seo-meta-update' ] as $name ) {
			self::assertContains( $name, $first, $name . ' must survive max_tools=' . self::DEFAULT_MAX_TOOLS . '.' );
		}
		foreach ( [ 'stonewright/wp-cli-status', 'stonewright/acf-value-update', 'stonewright/cpt-register', 'stonewright/taxonomy-register', 'stonewright/wc-product-list', 'stonewright/wc-product-save', 'stonewright/wc-order-list', 'stonewright/wc-sales-report' ] as $name ) {
			self::assertContains( $name, $first, $name . ' was on the profile before and must still survive max_tools=' . self::DEFAULT_MAX_TOOLS . '.' );
		}
	}

	public function test_the_gutenberg_profile_stays_within_the_default_tool_cap(): void {
		self::assertLessThanOrEqual( self::DEFAULT_MAX_TOOLS, count( ToolProfile::profile_tools( 'gutenberg' ) ) );
	}

	public function test_the_inspect_profile_stays_on_its_essential_budget_without_the_theme_chrome(): void {
		// Inspect already lists the most tools the essential budget allows, so the theme chrome read is not added.
		$names = ToolProfile::profile_tools( 'inspect' );

		self::assertLessThanOrEqual( TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS, count( $names ) );
		self::assertNotContains( 'stonewright/theme-chrome-get', $names );
	}

	public function test_new_tools_are_grouped_with_their_work(): void {
		$result = ( new ToolProfile() )->execute( [ 'action' => 'resolve', 'profile' => 'gutenberg', 'max_tools' => 200 ] );

		self::assertIsArray( $result );
		self::assertContains( 'stonewright/patterns-create', $result['tool_groups']['gutenberg_fse']['abilities'] ?? [] );
		self::assertContains( 'stonewright/blocks-insert', $result['tool_groups']['gutenberg_fse']['abilities'] ?? [] );

		$content = ( new ToolProfile() )->execute( [ 'action' => 'resolve', 'profile' => 'content-model', 'max_tools' => 200 ] );
		self::assertIsArray( $content );
		self::assertContains( 'stonewright/seo-meta-update', $content['tool_groups']['content_media']['abilities'] ?? [] );
	}
}
