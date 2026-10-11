<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV4;

use Elementor\Modules\GlobalClasses\Global_Classes_Repository;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\CreateClass;
use Stonewright\WpMcp\Abilities\ElementorV4\CreateVariable;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateClass;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateVariable;
use Stonewright\WpMcp\Elementor\V4\GlobalClassesState;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Security\Backup;

/**
 * The V4 class and variable writers return a snapshot id that really restores what they wrote: the classes through
 * Elementor's repository (e_global_class posts on 4.3), the variables through the kit meta that holds them.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\CreateClass
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateClass
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\CreateVariable
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateVariable
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicVariableRepositoryAdapter
 */
final class V4KitWritersBackupTest extends TestCase {

	private const KIT = 4;

	/** @var list<array<string, mixed>> */
	private const VARIANTS = [ [ 'meta' => [ 'breakpoint' => 'desktop', 'state' => null ], 'props' => [] ] ];

	private const CLASSES_BEFORE = [ 'items' => [ 'cls_contract' => [ 'id' => 'cls_contract', 'label' => 'Contract' ] ], 'order' => [ 'cls_contract' ] ];

	protected function setUp(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		GlobalClassesState::use_factory_for_tests( null );
		Global_Classes_Repository::reset();
		\Elementor\Modules\Variables\Services\Variables_Service::reset();
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_elementor_v4_atomic' => true ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [
			self::KIT => (object) [
				'ID'           => self::KIT,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Default Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'default-kit',
				'meta'         => [
					'_elementor_page_settings'  => [ 'custom_colors' => [] ],
					'_elementor_global_classes' => self::CLASSES_BEFORE,
				],
			],
		];
	}

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		GlobalClassesState::use_factory_for_tests( null );
		Global_Classes_Repository::reset();
		\Elementor\Modules\Variables\Services\Variables_Service::reset();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	private function kit_meta( string $key ): mixed {
		return ( (array) $GLOBALS['stonewright_test_posts'][ self::KIT ]->meta )[ $key ] ?? null;
	}

	public function test_create_class_returns_a_snapshot_that_restores_the_class_list(): void {
		$before = (array) GlobalClassesState::capture();

		$result = ( new CreateClass() )->execute( [ 'label' => 'Backup create', 'variants' => self::VARIANTS ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertFalse( GlobalClassesState::same( $before, (array) GlobalClassesState::capture() ), 'the write reached the repository' );

		self::assertTrue( Backup::restore( self::KIT, $result['snapshot_id'] ) );
		self::assertTrue( GlobalClassesState::same( $before, (array) GlobalClassesState::capture() ) );
		self::assertNull( Global_Classes_Repository::make()->get( $result['item']['id'] ) );
	}

	public function test_update_class_returns_a_snapshot_that_restores_the_class_list(): void {
		$before = (array) GlobalClassesState::capture();

		$result = ( new UpdateClass() )->execute( [ 'id' => 'cls_contract', 'label' => 'Backup update', 'variants' => self::VARIANTS ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'Backup update', Global_Classes_Repository::make()->get( 'cls_contract' )['label'] );

		self::assertTrue( Backup::restore( self::KIT, $result['snapshot_id'] ) );
		self::assertSame( 'Contract', Global_Classes_Repository::make()->get( 'cls_contract' )['label'] );
		self::assertTrue( GlobalClassesState::same( $before, (array) GlobalClassesState::capture() ) );
	}

	public function test_without_the_repository_a_class_snapshot_falls_back_to_the_legacy_kit_meta(): void {
		GlobalClassesState::use_factory_for_tests( static fn(): ?object => null );
		$snapshot = Backup::snapshot_post( self::KIT, [], true, Backup::KIT_V4_META_KEYS, true );
		self::assertNotSame( '', $snapshot );
		$GLOBALS['stonewright_test_posts'][ self::KIT ]->meta['_elementor_global_classes'] = [ 'items' => [], 'order' => [] ];

		self::assertTrue( Backup::restore( self::KIT, $snapshot ) );
		self::assertSame( self::CLASSES_BEFORE, $this->kit_meta( '_elementor_global_classes' ) );
	}

	public function test_create_variable_returns_a_snapshot_that_removes_the_variable_store_again(): void {
		$result = ( new CreateVariable() )->execute( [ 'label' => 'Backup var', 'type' => 'global-color-variable', 'value' => '#abcdef' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertNotNull( $this->kit_meta( '_elementor_global_variables' ), 'the write reached the kit meta' );

		self::assertTrue( Backup::restore( self::KIT, $result['snapshot_id'] ) );
		self::assertNull( $this->kit_meta( '_elementor_global_variables' ), 'the store did not exist before the write' );
	}

	public function test_update_variable_returns_a_snapshot_that_restores_the_stored_value(): void {
		\Elementor\Modules\Variables\Services\Variables_Service::reset( true );
		$before = $this->kit_meta( '_elementor_global_variables' );
		self::assertNotNull( $before );

		$result = ( new UpdateVariable() )->execute( [ 'id' => 'var_contract', 'value' => '#222222' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertNotSame( $before, $this->kit_meta( '_elementor_global_variables' ), 'the write reached the kit meta' );

		self::assertTrue( Backup::restore( self::KIT, $result['snapshot_id'] ) );
		self::assertSame( $before, $this->kit_meta( '_elementor_global_variables' ) );
	}

	public function test_a_created_variable_that_does_not_read_back_is_removed_again(): void {
		\Elementor\Modules\Variables\Services\Variables_Service::$drop_fields = [ 'label' ];
		$before = ( new \Elementor\Modules\Variables\Services\Variables_Service( new \stdClass(), new \stdClass() ) )->get_variables_list();

		$result = ( new CreateVariable() )->execute( [ 'label' => 'Lost label', 'type' => 'global-color-variable', 'value' => '#123456' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
		self::assertSame( $before, ( new \Elementor\Modules\Variables\Services\Variables_Service( new \stdClass(), new \stdClass() ) )->get_variables_list() );
	}

	public function test_an_updated_variable_that_does_not_read_back_is_put_back(): void {
		\Elementor\Modules\Variables\Services\Variables_Service::$drop_fields = [ 'label' ];
		$service = new \Elementor\Modules\Variables\Services\Variables_Service( new \stdClass(), new \stdClass() );
		$before  = $service->get_variables_list()['var_contract'];

		$result = ( new UpdateVariable() )->execute( [ 'id' => 'var_contract', 'label' => 'Renamed', 'value' => '#999999' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
		self::assertSame( $before, $service->get_variables_list()['var_contract'], 'the value of the failed write is not left behind' );
	}

	/** @return array<string, array{0: class-string, 1: array<string, mixed>}> */
	public static function kit_writer_calls(): array {
		return [
			'create class'    => [ CreateClass::class, [ 'label' => 'Busy', 'variants' => self::VARIANTS ] ],
			'update class'    => [ UpdateClass::class, [ 'id' => 'cls_contract', 'label' => 'Busy', 'variants' => self::VARIANTS ] ],
			'create variable' => [ CreateVariable::class, [ 'label' => 'Busy', 'type' => 'global-color-variable', 'value' => '#abcdef' ] ],
			'update variable' => [ UpdateVariable::class, [ 'id' => 'var_contract', 'value' => '#222222' ] ],
		];
	}

	/**
	 * @dataProvider kit_writer_calls
	 * @param class-string         $ability
	 * @param array<string, mixed> $args
	 */
	public function test_the_writer_is_refused_as_busy_while_another_writer_holds_the_kit_lease( string $ability, array $args ): void {
		\Elementor\Modules\Variables\Services\Variables_Service::reset( true );
		$classes_before   = (array) GlobalClassesState::capture();
		$variables_before = $this->kit_meta( '_elementor_global_variables' );
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		PostWriteLock::acquire( self::KIT, 'other-writer', 30 );

		$result = ( new $ability() )->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'Nothing was snapshotted or written.' );
		self::assertTrue( GlobalClassesState::same( $classes_before, (array) GlobalClassesState::capture() ) );
		self::assertSame( $variables_before, $this->kit_meta( '_elementor_global_variables' ) );
		self::assertTrue( PostWriteLock::owned_by( self::KIT, 'other-writer' ), 'The other writer keeps its lease.' );
	}

	/**
	 * @dataProvider kit_writer_calls
	 * @param class-string         $ability
	 * @param array<string, mixed> $args
	 */
	public function test_the_writer_releases_the_kit_lease( string $ability, array $args ): void {
		\Elementor\Modules\Variables\Services\Variables_Service::reset( true );

		$result = ( new $ability() )->execute( $args );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertIsArray( PostWriteLock::acquire( self::KIT, 'next-writer', 30 ), 'The lease is free afterwards.' );
	}
}
