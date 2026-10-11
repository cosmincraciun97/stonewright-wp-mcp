<?php
/**
 * Shared fixture for the tests of the change history abilities: the rollback engine fixture, a few changes to
 * posts and one change to a theme file.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Security;

use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;
use Stonewright\WpMcp\Tests\Unit\Security\Rollback\OtherRollbackTestCase;

/**
 * The current user (7) is an administrator, as in the engine tests.
 */
abstract class ChangeHistoryTestCase extends OtherRollbackTestCase {

	protected const V1 = "<?php\n// version one\nadd_action( 'init', '__return_true' );\n";

	protected const V2 = "<?php\n// version two\nadd_action( 'init', '__return_true' );\n";

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['stonewright_test_current_user_id'] = 7;
	}

	/**
	 * A theme file change from V1 to V2, recorded without a journal entry.
	 *
	 * @return string The change id.
	 */
	protected function theme_change(): string {
		$path = $this->theme . '/functions.php';
		file_put_contents( $path, self::V1 );
		$result = ThemeWriteTransaction::apply(
			[
				'absolute' => $path,
				'relative' => 'functions.php',
				'before'   => self::V1,
				'after'    => self::V2,
				'language' => 'php',
			]
		);
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$id = (string) $this->only_row_of( 'theme_file' )['change_id'];
		$this->forget_journal();
		return $id;
	}

	/**
	 * Change the content of a page that the test made, and record the change.
	 *
	 * @return string The change id.
	 */
	protected function page_change( int $post_id, string $before, string $after, string $title = 'Fixture page' ): string {
		$this->make_post( $post_id, [ 'post_title' => $title, 'post_content' => $before ] );
		return $this->post_change( fn () => $this->edit_post( 'post_content', $after, $post_id ), $post_id );
	}

	/** @return list<array<string, mixed>> Every row of the ledger, newest first. */
	protected function all_rows(): array {
		return ChangeLedger::list( [], ChangeLedger::MAX_PER_PAGE )['items'];
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>
	 */
	protected function ok_result( array|\WP_Error $result ): array {
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		self::assertTrue( $result['ok'] );
		return $result;
	}
}
