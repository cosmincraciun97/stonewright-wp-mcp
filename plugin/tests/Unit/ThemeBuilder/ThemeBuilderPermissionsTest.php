<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\CreateTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\DeleteTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\GetTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ListTemplates;
use Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions;

/**
 * Object-level permissions and write safety of the Theme Builder abilities.
 *
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\CreateTemplate
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\DeleteTemplate
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\GetTemplate
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\ListTemplates
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions
 * @covers \Stonewright\WpMcp\ThemeBuilder\TemplateStore
 */
final class ThemeBuilderPermissionsTest extends TestCase {

	/** A published template owned by somebody else. */
	private const TEMPLATE = 7800;

	/** A draft template. */
	private const DRAFT = 7801;

	/** A private template. */
	private const PRIVATE_TEMPLATE = 7802;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_post_meta_calls']  = [];
		$GLOBALS['stonewright_test_deleted_posts']    = [];
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_user_caps']        = [];
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		foreach ( [
			self::TEMPLATE         => [ 'publish', 'Site Header' ],
			self::DRAFT            => [ 'draft', 'Draft Footer' ],
			self::PRIVATE_TEMPLATE => [ 'private', 'Private Archive' ],
		] as $id => [ $status, $title ] ) {
			$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
				'ID'           => $id,
				'post_type'    => 'elementor_library',
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'tpl-' . $id,
				'meta'         => [
					'_elementor_template_type' => 'header',
					'_elementor_data'          => '[]',
					'_elementor_conditions'    => [ 'include/general' ],
				],
			];
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_user_can_callback'], $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_options']         = [];
	}

	/**
	 * @param list<string> $caps   Capabilities the current user holds.
	 * @param list<string> $denied "cap:post_id" pairs refused for one object although the capability is held.
	 */
	private function user_can( array $caps, array $denied = [] ): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ) use ( $caps, $denied ): bool {
			if ( isset( $args[0] ) && in_array( $cap . ':' . (int) $args[0], $denied, true ) ) {
				return false;
			}
			return in_array( $cap, $caps, true );
		};
	}

	/** @return list<string> */
	private function editor_caps(): array {
		return [ 'edit_posts', 'edit_theme_options', 'edit_post', 'delete_post', 'read_post' ];
	}

	private function assert_refused( mixed $result ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_template_forbidden', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	// -----------------------------------------------------------------------
	// delete-template
	// -----------------------------------------------------------------------

	public function test_delete_by_a_user_who_cannot_delete_that_template_is_refused(): void {
		// Holds edit_posts and edit_theme_options, but WordPress would refuse delete_post for this template.
		$this->user_can( [ 'edit_posts', 'edit_theme_options', 'edit_post', 'delete_post' ], [ 'delete_post:' . self::TEMPLATE ] );
		$args = [ 'template_id' => self::TEMPLATE ];

		$this->assert_refused( ( new DeleteTemplate() )->permission_callback( $args ) );
		$this->assert_refused( ( new DeleteTemplate() )->execute( $args ) );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'] );
		self::assertSame( 'publish', $GLOBALS['stonewright_test_posts'][ self::TEMPLATE ]->post_status );
	}

	public function test_delete_by_a_user_without_edit_theme_options_is_refused(): void {
		$this->user_can( [ 'edit_posts', 'edit_post', 'delete_post' ] );
		$args = [ 'template_id' => self::TEMPLATE ];

		self::assertFalse( ( new DeleteTemplate() )->permission_callback( $args ) );
		$this->assert_refused( ( new DeleteTemplate() )->execute( $args ) );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'] );
	}

	public function test_delete_trashes_for_a_user_who_may_delete_the_template(): void {
		$this->user_can( $this->editor_caps() );
		$args = [ 'template_id' => self::TEMPLATE ];

		self::assertTrue( ( new DeleteTemplate() )->permission_callback( $args ) );
		$result = ( new DeleteTemplate() )->execute( $args );

		self::assertIsArray( $result );
		self::assertTrue( $result['deleted'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertSame( 'trash', $GLOBALS['stonewright_test_posts'][ self::TEMPLATE ]->post_status );
	}

	public function test_delete_is_refused_when_the_snapshot_cannot_be_stored(): void {
		$this->user_can( $this->editor_caps() );
		// The history meta cannot be stored, so the snapshot cannot be read back and comes out empty.
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = ( new DeleteTemplate() )->execute( [ 'template_id' => self::TEMPLATE ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'], 'The template was not deleted.' );
	}

	public function test_delete_refuses_a_permanent_delete_and_keeps_the_template(): void {
		$this->user_can( $this->editor_caps() );

		$result = ( new DeleteTemplate() )->execute( [ 'template_id' => self::TEMPLATE, 'force' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_force_delete_refused', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'] );
		self::assertArrayHasKey( self::TEMPLATE, $GLOBALS['stonewright_test_posts'], 'The template, and the snapshots stored in its meta, are still there.' );
	}

	// -----------------------------------------------------------------------
	// set-conditions
	// -----------------------------------------------------------------------

	public function test_set_conditions_needs_edit_post_on_that_template(): void {
		$this->user_can( [ 'edit_posts', 'edit_theme_options', 'edit_post' ], [ 'edit_post:' . self::TEMPLATE ] );
		$args = [ 'template_id' => self::TEMPLATE, 'conditions' => [ [ 'type' => 'include', 'name' => 'general' ] ] ];

		$this->assert_refused( ( new SetConditions() )->permission_callback( $args ) );
		$this->assert_refused( ( new SetConditions() )->execute( $args ) );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_set_conditions_needs_edit_theme_options(): void {
		$this->user_can( [ 'edit_posts', 'edit_post' ] );
		$args = [ 'template_id' => self::TEMPLATE, 'conditions' => [] ];

		self::assertFalse( ( new SetConditions() )->permission_callback( $args ) );
		$this->assert_refused( ( new SetConditions() )->execute( $args ) );
	}

	public function test_set_conditions_snapshots_first_and_reports_what_it_overwrote(): void {
		$this->user_can( $this->editor_caps() );

		$result = ( new SetConditions() )->execute(
			[
				'template_id' => self::TEMPLATE,
				'conditions'  => [ [ 'type' => 'exclude', 'name' => 'archive', 'sub_name' => 'category' ] ],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['updated'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertSame( [ 'include/general' ], $result['previous_conditions'] );
		self::assertSame( [ 'exclude/archive/category' ], $result['conditions'] );
		self::assertSame( [ 'exclude/archive/category' ], $GLOBALS['stonewright_test_posts'][ self::TEMPLATE ]->meta['_elementor_conditions'] );
	}

	public function test_set_conditions_is_refused_when_the_snapshot_cannot_be_stored(): void {
		$this->user_can( $this->editor_caps() );
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = ( new SetConditions() )->execute( [ 'template_id' => self::TEMPLATE, 'conditions' => [] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( [ 'include/general' ], $GLOBALS['stonewright_test_posts'][ self::TEMPLATE ]->meta['_elementor_conditions'], 'The conditions are untouched.' );
	}

	public function test_set_conditions_reports_a_failure_when_the_read_back_differs(): void {
		$this->user_can( $this->editor_caps() );
		// The conditions meta write reports success but stores nothing.
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_elementor_conditions' => true ];

		$result = ( new SetConditions() )->execute(
			[
				'template_id' => self::TEMPLATE,
				'conditions'  => [ [ 'type' => 'exclude', 'name' => 'archive' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_write_failed', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// get-template and list-templates
	// -----------------------------------------------------------------------

	public function test_get_template_needs_edit_theme_options_and_edit_post(): void {
		$this->user_can( [ 'edit_posts', 'edit_post' ] );
		self::assertFalse( ( new GetTemplate() )->permission_callback( [ 'template_id' => self::TEMPLATE ] ) );

		$this->user_can( [ 'edit_posts', 'edit_theme_options', 'edit_post' ], [ 'edit_post:' . self::DRAFT ] );
		$this->assert_refused( ( new GetTemplate() )->permission_callback( [ 'template_id' => self::DRAFT ] ) );
		$this->assert_refused( ( new GetTemplate() )->execute( [ 'template_id' => self::DRAFT ] ) );
		self::assertTrue( ( new GetTemplate() )->permission_callback( [ 'template_id' => self::TEMPLATE ] ) );
		self::assertIsArray( ( new GetTemplate() )->execute( [ 'template_id' => self::TEMPLATE ] ) );
	}

	public function test_list_templates_needs_edit_theme_options(): void {
		$this->user_can( [ 'edit_posts', 'edit_post' ] );

		self::assertFalse( ( new ListTemplates() )->permission_callback( [] ) );
	}

	public function test_list_templates_hides_drafts_and_private_templates_the_user_cannot_edit(): void {
		$this->user_can( [ 'edit_posts', 'edit_theme_options', 'edit_post' ], [ 'edit_post:' . self::DRAFT, 'edit_post:' . self::PRIVATE_TEMPLATE ] );

		$result = ( new ListTemplates() )->execute( [] );

		self::assertSame( [ self::TEMPLATE ], array_column( $result['templates'], 'template_id' ) );

		$this->user_can( [ 'edit_posts', 'edit_theme_options', 'edit_post' ] );
		$all = ( new ListTemplates() )->execute( [] );
		self::assertEqualsCanonicalizing( [ self::TEMPLATE, self::DRAFT, self::PRIVATE_TEMPLATE ], array_column( $all['templates'], 'template_id' ) );
	}

	// -----------------------------------------------------------------------
	// create-template and apply-template
	// -----------------------------------------------------------------------

	public function test_create_template_needs_edit_theme_options(): void {
		$this->user_can( [ 'edit_posts' ] );

		self::assertFalse( ( new CreateTemplate() )->permission_callback( [ 'title' => 'T', 'template_type' => 'header' ] ) );

		$this->user_can( [ 'edit_posts', 'edit_theme_options' ] );
		self::assertTrue( ( new CreateTemplate() )->permission_callback( [ 'title' => 'T', 'template_type' => 'header' ] ) );
	}

	public function test_apply_template_needs_edit_theme_options(): void {
		$this->user_can( [ 'edit_posts', 'edit_post' ] );
		self::assertFalse( ( new ApplyTemplate() )->permission_callback( [ 'template_id' => self::TEMPLATE ] ) );
		self::assertFalse( ( new ApplyTemplate() )->permission_callback( [] ) );

		$this->user_can( [ 'edit_posts', 'edit_theme_options', 'edit_post' ] );
		self::assertTrue( ( new ApplyTemplate() )->permission_callback( [ 'template_id' => self::TEMPLATE ] ) );
	}
}
