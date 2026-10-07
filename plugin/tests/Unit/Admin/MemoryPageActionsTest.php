<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * Every Memory action ends in a message, and an "Add new" with a key that is already in use is refused instead of
 * replacing the entry.
 *
 * @covers \Stonewright\WpMcp\Admin\MemoryInstructionsPage
 * @covers \Stonewright\WpMcp\Memory\Memory::find_id
 */
final class MemoryPageActionsTest extends TestCase {

	private mixed $original_wpdb;

	private MemoryTableDouble $table;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->table                                 = new MemoryTableDouble();
		$GLOBALS['wpdb']                             = $this->table;
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_options']         = [];
		$_GET                                        = [];
		$_POST                                       = [];
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$_GET                                        = [];
		$_POST                                       = [];
		IncidentStore::reset_for_tests();
	}

	// --- Add new ---------------------------------------------------------

	public function test_a_new_key_is_stored_and_the_redirect_says_created(): void {
		$url = MemoryInstructionsPage::create_submission( $this->form( [ 'memory_key' => 'brand-voice', 'name' => 'Brand voice', 'value' => 'Plain.' ] ) );

		self::assertCount( 1, $this->table->rows );
		self::assertSame( 'Brand voice', $this->table->rows[1]['name'] );
		self::assertStringContainsString( 'memory_notice=created', $url );
		self::assertStringContainsString( 'entry=1', $url );
		self::assertStringContainsString( 'page=stonewright-memory', $url );
	}

	public function test_a_key_that_is_already_in_use_is_refused_and_the_entry_is_left_as_it_was(): void {
		$id = $this->table->seed( [ 'scope' => 'default', 'memory_key' => 'brand-voice', 'name' => 'Brand voice', 'value_json' => '"Plain."' ] );

		$url = MemoryInstructionsPage::create_submission( $this->form( [ 'memory_key' => 'brand-voice', 'name' => 'A different name', 'value' => 'Replaced.' ] ) );

		self::assertCount( 1, $this->table->rows );
		self::assertSame( 'Brand voice', $this->table->rows[ $id ]['name'], 'The existing name survives.' );
		self::assertSame( '"Plain."', $this->table->rows[ $id ]['value_json'], 'The existing value survives.' );
		self::assertStringContainsString( 'memory_notice=exists', $url );
		self::assertStringContainsString( 'entry=' . $id, $url );
		self::assertStringNotContainsString( 'created', $url );
	}

	public function test_the_same_key_in_another_scope_is_a_different_entry(): void {
		$this->table->seed( [ 'scope' => 'default', 'memory_key' => 'brand-voice' ] );

		$url = MemoryInstructionsPage::create_submission( $this->form( [ 'scope' => 'site-a', 'memory_key' => 'brand-voice', 'name' => 'Site A voice' ] ) );

		self::assertCount( 2, $this->table->rows );
		self::assertStringContainsString( 'memory_notice=created', $url );
	}

	public function test_an_entry_without_a_name_or_key_is_not_stored(): void {
		self::assertStringContainsString( 'memory_notice=invalid', MemoryInstructionsPage::create_submission( $this->form( [ 'memory_key' => '', 'name' => 'x' ] ) ) );
		self::assertStringContainsString( 'memory_notice=invalid', MemoryInstructionsPage::create_submission( $this->form( [ 'memory_key' => 'k', 'name' => '' ] ) ) );
		self::assertSame( [], $this->table->rows );
	}

	public function test_content_that_looks_like_a_credential_is_refused_with_its_own_message(): void {
		$url = MemoryInstructionsPage::create_submission( $this->form( [ 'memory_key' => 'login', 'name' => 'Login', 'value' => 'password: Tr0ub4dor-and-more-1' ] ) );

		self::assertSame( [], $this->table->rows );
		self::assertStringContainsString( 'memory_notice=blocked', $url );
	}

	public function test_find_id_returns_the_row_of_a_scope_and_key_or_zero(): void {
		$id = $this->table->seed( [ 'scope' => 'default', 'memory_key' => 'a' ] );

		self::assertSame( $id, Memory::find_id( 'default', 'a' ) );
		self::assertSame( 0, Memory::find_id( 'default', 'missing' ) );
	}

	// --- Edit and delete --------------------------------------------------

	public function test_saving_an_entry_says_updated(): void {
		$id = $this->table->seed( [ 'memory_key' => 'a', 'name' => 'Old' ] );

		$url = MemoryInstructionsPage::update_submission( [ 'id' => (string) $id, 'name' => 'New', 'scope' => 'default', 'memory_key' => 'a', 'type' => 'user', 'value' => 'v' ] );

		self::assertSame( 'New', $this->table->rows[ $id ]['name'] );
		self::assertStringContainsString( 'memory_notice=updated', $url );
		self::assertStringContainsString( 'entry=' . $id, $url );
	}

	public function test_moving_an_entry_onto_another_entrys_key_is_refused(): void {
		$one = $this->table->seed( [ 'scope' => 'default', 'memory_key' => 'one', 'name' => 'One' ] );
		$two = $this->table->seed( [ 'scope' => 'default', 'memory_key' => 'two', 'name' => 'Two' ] );

		$url = MemoryInstructionsPage::update_submission( [ 'id' => (string) $two, 'name' => 'Two', 'scope' => 'default', 'memory_key' => 'one', 'type' => 'user', 'value' => 'v' ] );

		self::assertSame( 'two', $this->table->rows[ $two ]['memory_key'] );
		self::assertStringContainsString( 'memory_notice=exists', $url );
		self::assertStringContainsString( 'entry=' . $one, $url );
	}

	public function test_saving_an_entry_that_is_gone_says_so(): void {
		self::assertStringContainsString( 'memory_notice=not-found', MemoryInstructionsPage::update_submission( [ 'id' => '99', 'name' => 'x' ] ) );
		self::assertStringContainsString( 'memory_notice=not-found', MemoryInstructionsPage::update_submission( [ 'id' => '0' ] ) );
	}

	public function test_deleting_an_entry_says_deleted_and_a_missing_one_says_so(): void {
		$id = $this->table->seed( [ 'memory_key' => 'a' ] );

		self::assertStringContainsString( 'memory_notice=deleted', MemoryInstructionsPage::delete_submission( [ 'id' => (string) $id ] ) );
		self::assertSame( [], $this->table->rows );
		self::assertStringContainsString( 'memory_notice=not-found', MemoryInstructionsPage::delete_submission( [ 'id' => (string) $id ] ) );
	}

	// --- Draft lessons and learned rules ----------------------------------

	public function test_approving_a_draft_says_approved_and_keeps_the_approval_record(): void {
		$id = $this->table->seed( [ 'type' => 'reference', 'memory_key' => 'draft-lesson-abc', 'status' => 'draft', 'value_json' => '{"proposed_remediation":"Fix it."}' ] );

		$url = MemoryInstructionsPage::draft_review_submission( $id, 'approve' );

		self::assertSame( 'active', $this->table->rows[ $id ]['status'] );
		$value = json_decode( (string) $this->table->rows[ $id ]['value_json'], true );
		self::assertSame( 7, $value['approval']['approved_by'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value['approval']['approved_at'], 'Approval times stay in UTC.' );
		self::assertStringContainsString( 'memory_notice=approved', $url );
	}

	public function test_discarding_a_draft_says_discarded(): void {
		$id = $this->table->seed( [ 'type' => 'reference', 'memory_key' => 'draft-lesson-abc', 'status' => 'draft' ] );

		self::assertStringContainsString( 'memory_notice=discarded', MemoryInstructionsPage::draft_review_submission( $id, 'discard' ) );
		self::assertSame( 'rejected', $this->table->rows[ $id ]['status'] );
	}

	public function test_reviewing_something_that_is_not_a_draft_changes_nothing_and_says_so(): void {
		$id = $this->table->seed( [ 'memory_key' => 'a', 'status' => 'active' ] );

		self::assertStringContainsString( 'memory_notice=not-draft', MemoryInstructionsPage::draft_review_submission( $id, 'approve' ) );
		self::assertSame( 'active', $this->table->rows[ $id ]['status'] );
	}

	public function test_disabling_a_learned_rule_says_disabled(): void {
		$id = $this->table->seed( [ 'type' => 'feedback', 'memory_key' => 'learning-a', 'status' => 'active' ] );

		self::assertStringContainsString( 'memory_notice=disabled', MemoryInstructionsPage::learning_disable_submission( $id ) );
		self::assertSame( 'stale', $this->table->rows[ $id ]['status'] );
		self::assertStringContainsString( 'memory_notice=not-found', MemoryInstructionsPage::learning_disable_submission( 99 ) );
	}

	// --- What the page says ------------------------------------------------

	/**
	 * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
	 */
	public static function messages(): array {
		return [
			'created'    => [ [ 'memory_notice' => 'created', 'entry' => '1' ], 'Memory entry created.', 'sw-ui-notice--ok' ],
			'updated'    => [ [ 'memory_notice' => 'updated', 'entry' => '1' ], 'Memory entry saved.', 'sw-ui-notice--ok' ],
			'deleted'    => [ [ 'memory_notice' => 'deleted' ], 'Memory entry deleted.', 'sw-ui-notice--ok' ],
			'approved'   => [ [ 'memory_notice' => 'approved', 'entry' => '1' ], 'Lesson approved.', 'sw-ui-notice--ok' ],
			'discarded'  => [ [ 'memory_notice' => 'discarded', 'entry' => '1' ], 'Draft lesson discarded.', 'sw-ui-notice--ok' ],
			'disabled'   => [ [ 'memory_notice' => 'disabled', 'entry' => '1' ], 'Learned rule disabled.', 'sw-ui-notice--ok' ],
			'exists'     => [ [ 'memory_notice' => 'exists', 'entry' => '1' ], 'No entry was created: that scope and key are already in use.', 'sw-ui-notice--warn' ],
			'blocked'    => [ [ 'memory_notice' => 'blocked' ], 'The entry was not saved.', 'sw-ui-notice--danger' ],
			'invalid'    => [ [ 'memory_notice' => 'invalid' ], 'The entry needs a name and a key.', 'sw-ui-notice--danger' ],
			'not found'  => [ [ 'memory_notice' => 'not-found' ], 'That memory entry no longer exists.', 'sw-ui-notice--danger' ],
			'not a draft' => [ [ 'memory_notice' => 'not-draft' ], 'That entry is not a draft, so nothing changed.', 'sw-ui-notice--warn' ],
			'settings'   => [ [ 'settings-updated' => 'true' ], 'Settings saved.', 'sw-ui-notice--ok' ],
			'migrated'   => [ [ 'migrated' => '3' ], '3 legacy feedback entries were classified.', 'sw-ui-notice--ok' ],
			'none migrated' => [ [ 'migrated' => '0' ], 'No legacy feedback needed classifying.', 'sw-ui-notice--ok' ],
		];
	}

	/**
	 * @dataProvider messages
	 * @param array<string, string> $query
	 */
	public function test_each_outcome_is_announced_on_the_page(array $query, string $text, string $variant): void {
		$this->table->seed( [ 'name' => 'Brand voice', 'scope' => 'default', 'memory_key' => 'brand-voice' ] );
		$_GET = $query;

		$html = $this->render();

		self::assertStringContainsString( $text, $html );
		self::assertStringContainsString( $variant, $html );
	}

	public function test_the_refusal_names_the_entry_that_holds_the_key_and_points_at_its_edit_view(): void {
		$id      = $this->table->seed( [ 'name' => 'Brand voice', 'scope' => 'default', 'memory_key' => 'brand-voice' ] );
		$_GET    = [ 'memory_notice' => 'exists', 'entry' => (string) $id ];

		$html = $this->render();

		self::assertStringContainsString( 'Brand voice', $html );
		self::assertStringContainsString( 'Nothing was changed.', $html );
		self::assertStringContainsString( 'edit=' . $id, $html );
	}

	public function test_errors_are_alerts_and_confirmations_are_status_messages(): void {
		$_GET = [ 'memory_notice' => 'blocked' ];
		self::assertMatchesRegularExpression( '/role="alert"[^>]*>.*The entry was not saved\./s', $this->render() );

		$_GET = [ 'memory_notice' => 'created' ];
		self::assertMatchesRegularExpression( '/role="status"[^>]*>.*Memory entry created\./s', $this->render() );
	}

	public function test_an_unknown_notice_code_prints_nothing_and_is_never_reflected(): void {
		$_GET = [ 'memory_notice' => '<script>alert(1)</script>' ];

		$html = $this->render();

		self::assertStringNotContainsString( 'alert(1)', $html );
		self::assertStringNotContainsString( 'sw-ui-notice--', $html );
	}

	private function render(): string {
		ob_start();
		MemoryInstructionsPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, string> $override
	 * @return array<string, string>
	 */
	private function form( array $override ): array {
		return array_merge( [ 'type' => 'user', 'scope' => 'default', 'memory_key' => 'k', 'name' => 'Name', 'value' => 'v' ], $override );
	}
}
