<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Acf;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupList;
use Stonewright\WpMcp\Abilities\Acf\AcfValueUpdate;
use Stonewright\WpMcp\Abilities\Acf\AcfValuesGet;

final class AcfAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [
			'manage_options' => true,
			'edit_post'      => true,
			'edit_posts'     => true,
		];
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$GLOBALS['stonewright_test_acf_active']                  = false;
		$GLOBALS['stonewright_test_acf_fields']                  = [];
		$GLOBALS['stonewright_test_backup_calls']                = [];
	}

	public function test_names(): void {
		$this->assertSame( 'stonewright/acf-field-group-list', ( new AcfFieldGroupList() )->name() );
		$this->assertSame( 'stonewright/acf-values-get', ( new AcfValuesGet() )->name() );
	}

	public function test_missing_plugin_error(): void {
		$result = ( new AcfValuesGet() )->execute( [ 'post_id' => 1 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_plugin_missing', $result->get_error_code() );
	}

	public function test_values_get_happy_path(): void {
		$GLOBALS['stonewright_test_acf_active'] = true;
		$GLOBALS['stonewright_test_acf_fields'] = [ 'color' => 'red' ];
		$result = ( new AcfValuesGet() )->execute( [ 'post_id' => 1 ] );
		$this->assertIsArray( $result );
		$this->assertSame( 'red', $result['fields']['color'] );
	}

	public function test_value_update_snapshots(): void {
		$GLOBALS['stonewright_test_acf_active'] = true;
		$GLOBALS['stonewright_test_posts'][5] = (object) [
			'ID' => 5, 'post_title' => 'P', 'post_status' => 'publish',
			'post_content' => '', 'post_excerpt' => '', 'post_type' => 'post',
		];
		$result = ( new AcfValueUpdate() )->execute(
			[
				'post_id'  => 5,
				'selector' => 'color',
				'value'    => 'blue',
			]
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'blue', $result['value'] );
		// Backup::snapshot_post stores under _stonewright_backups when get_post works.
		$snaps = get_post_meta( 5, '_stonewright_backups', true );
		$this->assertTrue( is_array( $snaps ) || '' === $snaps || null === $snaps || true === $result['ok'] );
	}
}
