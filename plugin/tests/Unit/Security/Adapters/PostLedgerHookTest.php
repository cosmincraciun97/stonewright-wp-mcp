<?php
/**
 * Post writes are recorded in the change ledger where the rescue guard arms and settles them.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\RescueGuard;

/**
 * @covers \Stonewright\WpMcp\Security\RescueGuard
 * @covers \Stonewright\WpMcp\Security\Adapters\PostAdapter
 */
final class PostLedgerHookTest extends PostLedgerTestCase {

	private const POST = 31;

	/**
	 * What the ability does between its snapshot and the end of its call.
	 *
	 * @param callable():void $write
	 * @return mixed What RescueGuard::leave returns.
	 */
	private function call( string $ability, int $post_id, callable $write, mixed $result = [ 'ok' => true ] ): mixed {
		RescueGuard::enter( $ability );
		Backup::snapshot_post( $post_id );
		$write();
		return RescueGuard::leave( $result );
	}

	private function change_content( int $post_id = self::POST ): void {
		$GLOBALS['stonewright_test_posts'][ $post_id ]->post_content = 'changed body';
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function writers(): array {
		return [
			'elementor v3 document writer' => [ 'stonewright/elementor-v3-update-element', 'page', 'elementor' ],
			'elementor v3 batch'           => [ 'stonewright/elementor-v3-batch-mutate', 'page', 'elementor' ],
			'elementor v4 node'            => [ 'stonewright/elementor-v4-update-node', 'page', 'elementor' ],
			'page settings'                => [ 'stonewright/elementor-v3-update-page-settings', 'page', 'elementor' ],
			'kit'                          => [ 'stonewright/elementor-v3-update-kit-colors', 'elementor_library', 'elementor' ],
			'gutenberg apply'              => [ 'stonewright/gutenberg-apply-to-post', 'page', 'gutenberg' ],
			'gutenberg batch'              => [ 'stonewright/blocks-batch-mutate', 'post', 'gutenberg' ],
			'fse template'                 => [ 'stonewright/fse-update-template', 'wp_template', 'fse' ],
			'fse part'                     => [ 'stonewright/fse-write-template-part', 'wp_template_part', 'fse' ],
			'navigation'                   => [ 'stonewright/fse-navigation', 'wp_navigation', 'fse' ],
			'global styles'                => [ 'stonewright/fse-update-global-styles', 'wp_global_styles', 'global_styles' ],
			'pattern'                      => [ 'stonewright/patterns-update', 'wp_block', 'gutenberg' ],
			'theme builder template'       => [ 'stonewright/theme-builder-apply-template', 'elementor_library', 'elementor' ],
			'content update page'          => [ 'stonewright/content-update-page', 'page', 'post' ],
			'content update post'          => [ 'stonewright/content-update-post', 'post', 'post' ],
			'seo meta'                     => [ 'stonewright/seo-meta-update', 'page', 'post' ],
			'acf value'                    => [ 'stonewright/acf-value-update', 'page', 'post' ],
			'section reuse insert'         => [ 'stonewright/elementor-v3-batch-mutate', 'page', 'elementor' ],
		];
	}

	/**
	 * @dataProvider writers
	 */
	public function test_each_post_family_is_recorded_before_the_write_and_settled_after_it( string $ability, string $post_type, string $family ): void {
		$this->make_post( self::POST, [ 'post_type' => $post_type, 'post_content' => 'original body' ] );
		$this->set_meta( self::POST, '_yoast_wpseo_title', 'Before' );

		$result = $this->call(
			$ability,
			self::POST,
			function (): void {
				$this->change_content();
				$this->set_meta( self::POST, '_yoast_wpseo_title', 'After' );
			}
		);

		self::assertSame( [ 'ok' => true ], $result, 'The ability result is untouched.' );
		$row = $this->only_row();
		self::assertSame( $family, $row['family'] );
		self::assertSame( $ability, $row['ability'] );
		self::assertSame( 'post', $row['resource_type'] );
		self::assertSame( (string) self::POST, $row['resource_id'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertSame( 7, $row['actor'] );
		self::assertTrue( $row['restorable'] );
		self::assertNotNull( $row['settled_at'] );
		self::assertSame( ChangeJournal::recent()[0]['id'], $row['change_id'], 'The journal and the ledger share one id.' );

		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $row['change_id'], 'after' );
		self::assertSame( 'original body', $before['post']['post_content'] );
		self::assertSame( 'Before', $before['meta']['_yoast_wpseo_title'] );
		self::assertSame( 'changed body', $after['post']['post_content'] );
		self::assertSame( 'After', $after['meta']['_yoast_wpseo_title'] );
		self::assertNotSame( $row['before_sha256'], $row['after_sha256'] );
	}

	public function test_the_ledger_row_is_written_before_the_post_changes(): void {
		$this->make_post( self::POST, [ 'post_content' => 'original body' ] );
		RescueGuard::enter( 'stonewright/content-update-page' );

		Backup::snapshot_post( self::POST );

		$row = $this->only_row();
		self::assertSame( 'armed', $row['status'] );
		self::assertSame( 'original body', ChangeLedger::read_image( $row['change_id'], 'before' )['post']['post_content'] );
		self::assertSame( '', $row['after_ref'] );
		RescueGuard::leave( [ 'ok' => true ] );
	}

	public function test_a_post_written_twice_in_one_call_has_one_row_with_the_first_state_as_its_before_image(): void {
		$this->make_post( self::POST, [ 'post_content' => 'original body' ] );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		Backup::snapshot_post( self::POST );
		$this->change_content();
		Backup::snapshot_post( self::POST );
		$GLOBALS['stonewright_test_posts'][ self::POST ]->post_content = 'changed again';

		RescueGuard::leave( [ 'ok' => true ] );

		$row = $this->only_row();
		self::assertSame( 'original body', ChangeLedger::read_image( $row['change_id'], 'before' )['post']['post_content'] );
		self::assertSame( 'changed again', ChangeLedger::read_image( $row['change_id'], 'after' )['post']['post_content'] );
	}

	public function test_meta_keys_named_before_the_snapshot_join_both_images(): void {
		$this->make_post( self::POST );
		$this->set_meta( self::POST, 'section_slug', 'hero' );
		RescueGuard::enter( 'stonewright/content-update-page' );
		RescueGuard::note_post_meta_keys( self::POST, [ 'section_slug', 'section_order' ] );
		Backup::snapshot_post( self::POST );
		$this->set_meta( self::POST, 'section_slug', 'proof' );
		$this->set_meta( self::POST, 'section_order', 3 );

		RescueGuard::leave( [ 'ok' => true ] );

		$row    = $this->only_row();
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $row['change_id'], 'after' );
		self::assertSame( 'hero', $before['meta']['section_slug'] );
		self::assertContains( 'section_order', $before['meta_absent'] );
		self::assertSame( 'proof', $after['meta']['section_slug'] );
		self::assertSame( 3, $after['meta']['section_order'] );
	}

	public function test_a_write_the_site_cannot_survive_is_settled_as_rolled_back_with_the_image_it_wrote(): void {
		$this->make_post( self::POST, [ 'post_content' => 'original body' ] );

		$result = $this->call(
			'stonewright/elementor-v3-batch-mutate',
			self::POST,
			function (): void {
				$this->change_content();
				$this->site = 'broken';
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$row = $this->only_row();
		self::assertSame( 'rolled_back', $row['status'] );
		self::assertSame( 'rolled_back', ChangeJournal::get( $row['change_id'] )['state'] );
		self::assertSame( 'changed body', ChangeLedger::read_image( $row['change_id'], 'after' )['post']['post_content'], 'The after image is what the write produced, taken before the rollback.' );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][ self::POST ]->post_content );
	}

	public function test_a_failed_call_is_settled_as_failed(): void {
		$this->make_post( self::POST, [ 'post_content' => 'original body' ] );

		$error  = new \WP_Error( 'stonewright_invalid', 'nope' );
		$result = $this->call( 'stonewright/content-update-page', self::POST, fn () => $this->change_content(), $error );

		self::assertSame( $error, $result );
		self::assertSame( 'failed', $this->only_row()['status'] );
	}

	public function test_a_call_that_changed_nothing_is_settled_verified_with_equal_images(): void {
		$this->make_post( self::POST );

		$this->call( 'stonewright/content-update-page', self::POST, static function (): void {} );

		$row = $this->only_row();
		self::assertSame( 'verified', $row['status'] );
		self::assertSame( $row['before_sha256'], $row['after_sha256'] );
	}

	public function test_a_write_that_could_not_be_probed_is_settled_as_probe_unavailable(): void {
		$this->make_post( self::POST, [ 'post_content' => 'original body' ] );
		\Stonewright\WpMcp\Security\HealthProbe::set_transport( static fn () => new \WP_Error( 'http_request_failed', 'cURL error 7' ) );

		$this->call( 'stonewright/content-update-page', self::POST, fn () => $this->change_content() );

		self::assertSame( 'probe_unavailable', $this->only_row()['status'] );
		self::assertSame( 'changed body', $GLOBALS['stonewright_test_posts'][ self::POST ]->post_content, 'The write stays.' );
	}

	public function test_a_post_created_in_the_call_keeps_its_create_row_when_a_snapshot_follows(): void {
		$this->make_post( self::POST, [ 'post_status' => 'draft' ] );
		RescueGuard::enter( 'stonewright/fse-create-template-part' );

		RescueGuard::note_post_created( self::POST );
		Backup::snapshot_post( self::POST );
		RescueGuard::leave( [ 'ok' => true ] );

		$row = $this->only_row();
		self::assertTrue( \Stonewright\WpMcp\Security\Adapters\PostAdapter::is_created_row( $row ), 'The snapshot of a fresh post is not an undo.' );
		self::assertSame( 'verified', $row['status'] );
		self::assertSame( 'post_snapshot', ChangeJournal::recent()[0]['recipe']['type'], 'The journal arms as it always did.' );
	}

	public function test_a_post_written_outside_an_ability_call_is_not_recorded(): void {
		$this->make_post( self::POST );

		$snapshot = Backup::snapshot_post( self::POST );

		self::assertNotSame( '', $snapshot );
		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_nothing_is_recorded_while_the_ledger_table_is_missing(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wptests_';
		};
		$this->make_post( self::POST, [ 'post_content' => 'original body' ] );

		$result = $this->call( 'stonewright/content-update-page', self::POST, fn () => $this->change_content() );

		self::assertSame( [ 'ok' => true ], $result );
		self::assertSame( 'verified', ChangeJournal::recent()[0]['state'], 'The journal is not affected.' );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function ledger_failures(): array {
		return [
			'insert is refused' => [ 'refuse', 'change_ledger_record_failed' ],
			'database throws'   => [ 'throw', 'change_ledger_record_failed' ],
			'settle is refused' => [ 'refuse_update', 'change_ledger_settle_failed' ],
			'settle throws'     => [ 'throw_update', 'change_ledger_settle_failed' ],
		];
	}

	/**
	 * @dataProvider ledger_failures
	 */
	public function test_a_ledger_failure_is_logged_and_never_changes_the_write_or_its_result( string $failure, string $event ): void {
		$log   = $this->uploads . '/php-errors.log';
		$saved = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $log );
		$db         = new class() extends PostLedgerWpdb {
			public string $mode = '';

			public function insert( $table, $data, $format = null ) {
				if ( str_contains( (string) $table, 'stonewright_changes' ) ) {
					if ( 'throw' === $this->mode ) {
						throw new \RuntimeException( 'database gone' );
					}
					if ( 'refuse' === $this->mode ) {
						return false;
					}
				}
				return parent::insert( $table, $data, $format );
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				if ( 'throw_update' === $this->mode ) {
					throw new \RuntimeException( 'database gone' );
				}
				if ( 'refuse_update' === $this->mode ) {
					return false;
				}
				return parent::update( $table, $data, $where, $format, $where_format );
			}
		};
		$db->unique = $this->db->unique;
		$db->mode   = $failure;
		$GLOBALS['wpdb'] = $db;
		$this->make_post( self::POST, [ 'post_content' => 'original body' ] );

		try {
			$result = $this->call( 'stonewright/content-update-page', self::POST, fn () => $this->change_content(), [ 'ok' => true, 'applied' => 1 ] );
		} finally {
			ini_set( 'error_log', $saved );
		}

		self::assertStringContainsString( 'stonewright.' . $event, (string) file_get_contents( $log ), 'The failure is logged.' );
		self::assertSame( [ 'ok' => true, 'applied' => 1 ], $result );
		self::assertSame( 'changed body', $GLOBALS['stonewright_test_posts'][ self::POST ]->post_content );
		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'verified', $entry['state'], 'The journal settled as it always does.' );
		self::assertSame( 'post_snapshot', $entry['recipe']['type'] );
	}

	public function test_a_ledger_failure_does_not_stop_a_rollback(): void {
		$db         = new class() extends PostLedgerWpdb {
			public function insert( $table, $data, $format = null ) {
				if ( str_contains( (string) $table, 'stonewright_changes' ) ) {
					throw new \RuntimeException( 'database gone' );
				}
				return parent::insert( $table, $data, $format );
			}
		};
		$db->unique      = $this->db->unique;
		$GLOBALS['wpdb'] = $db;
		$this->make_post( self::POST, [ 'post_content' => 'original body' ] );

		$result = $this->call(
			'stonewright/elementor-v3-batch-mutate',
			self::POST,
			function (): void {
				$this->change_content();
				$this->site = 'broken';
			}
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][ self::POST ]->post_content );
	}

	public function test_a_row_and_a_write_of_two_posts_in_one_call_stay_apart(): void {
		$this->make_post( 31, [ 'post_content' => 'one' ] );
		$this->make_post( 32, [ 'post_content' => 'two' ] );
		RescueGuard::enter( 'stonewright/design-apply-to-post' );
		Backup::snapshot_post( 31 );
		Backup::snapshot_post( 32 );
		$GLOBALS['stonewright_test_posts'][31]->post_content = 'one changed';

		RescueGuard::leave( [ 'ok' => true ] );

		$rows = array_column( $this->ledger_rows(), null, 'resource_id' );
		self::assertCount( 2, $rows );
		self::assertNotSame( $rows['31']['after_sha256'], $rows['31']['before_sha256'] );
		self::assertSame( $rows['32']['after_sha256'], $rows['32']['before_sha256'] );
	}
}
