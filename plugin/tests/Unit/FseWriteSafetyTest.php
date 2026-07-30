<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\WriteTemplate;
use Stonewright\WpMcp\Abilities\FSE\WriteTemplatePart;
use Stonewright\WpMcp\Security\Backup;

/**
 * Unit-level FSE write safety invariants.
 *
 * Focuses on:
 *   1. Backup-before-write ordering.
 *   2. Validator-before-write (theme.json validation gate).
 *   3. Confirmation-token gate in production-safe mode.
 *   4. Permission denial when capability is missing.
 *
 * End-to-end write → read-back coverage lives in Integration/FSEWriteTest.php.
 *
 * @covers \Stonewright\WpMcp\Abilities\FSE\WriteTemplate
 * @covers \Stonewright\WpMcp\Abilities\FSE\WriteTemplatePart
 * @covers \Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles
 * @covers \Stonewright\WpMcp\Security\Backup
 * @covers \Stonewright\WpMcp\ThemeJson\Validator
 */
final class FseWriteSafetyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']            = [];
		$GLOBALS['stonewright_test_posts']              = [];
		$GLOBALS['stonewright_test_next_post_id']       = 3001;
		$GLOBALS['stonewright_test_inserted_posts']     = [];
		$GLOBALS['stonewright_test_post_meta_calls']    = [];
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		$GLOBALS['stonewright_test_wp_insert_post_return'] = null;

		// Full caps by default — individual tests restrict as needed.
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options'     => true,
			'edit_theme_options' => true,
			'edit_pages'         => true,
		];
	}

	// =========================================================================
	// 1. Backup-before-write ordering
	// =========================================================================

	public function test_backup_snapshot_post_writes_meta_before_any_update(): void {
		$post_id = 3050;
		$GLOBALS['stonewright_test_posts'][ $post_id ] = (object) [
			'ID'           => $post_id,
			'post_type'    => 'wp_template',
			'post_content' => '<!-- original -->',
			'post_status'  => 'publish',
			'post_title'   => 'original',
			'post_name'    => 'test-theme//header',
			'post_excerpt' => '',
			'meta'         => [],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$snapshot_id = Backup::snapshot_post( $post_id );

		$this->assertNotEmpty( $snapshot_id, 'Backup must return a non-empty snapshot ID.' );

		$backup_writes = array_filter(
			$GLOBALS['stonewright_test_post_meta_calls'],
			static fn( array $c ) => '_stonewright_backups' === $c['meta_key'] && $post_id === $c['post_id']
		);
		$this->assertNotEmpty( $backup_writes, 'Backup::snapshot_post must write _stonewright_backups meta.' );
	}

	public function test_backup_snapshot_targets_revision_meta_without_parent_redirect(): void {
		$revision_id = 3052;
		$GLOBALS['stonewright_test_posts'][ $revision_id ] = (object) [
			'ID'           => $revision_id,
			'post_type'    => 'revision',
			'post_parent'  => 3051,
			'post_content' => '',
			'post_status'  => 'inherit',
			'post_title'   => 'revision',
			'post_excerpt' => '',
			'meta'         => [ '_elementor_data' => '[{"id":"revision-node"}]' ],
		];

		$snapshot_id = Backup::snapshot_post( $revision_id );

		self::assertNotEmpty( $snapshot_id );
		$backup_writes = array_values(
			array_filter(
				$GLOBALS['stonewright_test_post_meta_calls'],
				static fn( array $call ): bool => 'update_metadata' === $call['action']
					&& $revision_id === $call['post_id']
					&& '_stonewright_backups' === $call['meta_key']
			)
		);
		self::assertCount( 1, $backup_writes );
	}

	public function test_restore_removes_tracked_meta_that_was_absent_at_snapshot_time(): void {
		$post_id = 3051;
		$GLOBALS['stonewright_test_posts'][ $post_id ] = (object) [
			'ID'           => $post_id,
			'post_type'    => 'page',
			'post_content' => '',
			'post_status'  => 'draft',
			'post_title'   => 'restore target',
			'post_excerpt' => '',
			'meta'         => [ '_elementor_edit_mode' => 'builder' ],
		];

		$snapshot_id = Backup::snapshot_post( $post_id );
		update_post_meta( $post_id, '_elementor_data', '[{"id":"new"}]' );
		update_post_meta( $post_id, '_elementor_edit_mode', 'changed' );

		$this->assertTrue( Backup::restore( $post_id, $snapshot_id ) );
		$this->assertSame( '', get_post_meta( $post_id, '_elementor_data', true ) );
		$this->assertSame( 'builder', get_post_meta( $post_id, '_elementor_edit_mode', true ) );
	}

	public function test_write_global_styles_backups_before_write(): void {
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$ability = new WriteGlobalStyles();
		$result  = $ability->execute( [ 'theme_json' => [ 'version' => 3 ] ] );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['snapshot_id'], 'WriteGlobalStyles must take a backup before writing.' );

		$backup_meta = array_filter(
			$GLOBALS['stonewright_test_post_meta_calls'],
			static fn( array $c ) => '_stonewright_backups' === $c['meta_key']
		);
		$this->assertNotEmpty( $backup_meta, 'Backup meta must be written during WriteGlobalStyles::execute().' );
	}

	// =========================================================================
	// 2. Validator-before-write: invalid input must be rejected
	// =========================================================================

	public function test_write_global_styles_rejects_invalid_theme_json(): void {
		$ability = new WriteGlobalStyles();
		$result  = $ability->execute( [
			'theme_json' => [ 'version' => 'not-an-int' ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_theme_json_invalid', $result->get_error_code() );
	}

	public function test_write_template_rejects_invalid_slug(): void {
		$ability = new WriteTemplate();
		$result  = $ability->execute( [
			'template_slug' => 'INVALID SLUG!',
			'theme'         => 'twentytwentyfour',
			'content'       => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'stonewright_', $result->get_error_code() );
	}

	public function test_write_template_rejects_invalid_theme_slug(): void {
		$ability = new WriteTemplate();
		$result  = $ability->execute( [
			'template_slug' => 'index',
			'theme'         => 'Invalid Theme!',
			'content'       => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_fse_invalid_theme', $result->get_error_code() );
	}

	public function test_write_template_part_rejects_invalid_theme_slug(): void {
		$ability = new WriteTemplatePart();
		$result  = $ability->execute( [
			'template_slug' => 'footer',
			'theme'         => 'Bad Theme Name',
			'content'       => '<!-- wp:group --></div><!-- /wp:group -->',
			'area'          => 'footer',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_fse_invalid_theme', $result->get_error_code() );
	}

	public function test_write_template_part_rejects_invalid_slug(): void {
		$ability = new WriteTemplatePart();
		$result  = $ability->execute( [
			'template_slug' => 'INVALID!!',
			'theme'         => 'twentytwentyfour',
			'content'       => '<!-- wp:group --></div><!-- /wp:group -->',
			'area'          => 'header',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// =========================================================================
	// 3. Confirmation-token gate in production-safe mode
	// =========================================================================

	public function test_write_template_requires_token_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$ability = new WriteTemplate();
		$result  = $ability->execute( [
			'template_slug' => 'index',
			'theme'         => 'twentytwentyfour',
			'content'       => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			// No confirmation_token.
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_write_template_part_requires_token_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$ability = new WriteTemplatePart();
		$result  = $ability->execute( [
			'template_slug' => 'footer',
			'theme'         => 'twentytwentyfour',
			'content'       => '<!-- wp:group --></div><!-- /wp:group -->',
			'area'          => 'footer',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_write_global_styles_requires_token_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$ability = new WriteGlobalStyles();
		$result  = $ability->execute( [ 'theme_json' => [ 'version' => 3 ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	// =========================================================================
	// 4. Permission denial when capability is missing
	// =========================================================================

	public function test_write_template_permission_denied_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'edit_theme_options' => true,
			// manage_options deliberately omitted.
		];
		$ability = new WriteTemplate();
		$allowed = $ability->permission_callback( [] );
		$this->assertFalse( $allowed );
	}

	public function test_write_template_part_permission_denied_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'edit_theme_options' => true,
			// manage_options deliberately omitted.
		];
		$ability = new WriteTemplatePart();
		$allowed = $ability->permission_callback( [] );
		$this->assertFalse( $allowed );
	}

	public function test_write_global_styles_permission_denied_without_edit_theme_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			// edit_theme_options deliberately omitted.
		];
		$ability = new WriteGlobalStyles();
		$allowed = $ability->permission_callback( [] );
		$this->assertFalse( $allowed );
	}

	public function test_write_template_granted_with_both_caps(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options'     => true,
			'edit_theme_options' => true,
		];
		$ability = new WriteTemplate();
		$allowed = $ability->permission_callback( [] );
		$this->assertTrue( $allowed );
	}

	public function test_write_global_styles_granted_with_both_caps(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options'     => true,
			'edit_theme_options' => true,
		];
		$ability = new WriteGlobalStyles();
		$allowed = $ability->permission_callback( [] );
		$this->assertTrue( $allowed );
	}
}
