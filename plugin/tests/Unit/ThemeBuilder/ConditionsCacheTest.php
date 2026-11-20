<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * Setting display rules keeps Elementor Pro's cached list of rules (option elementor_pro_theme_builder_conditions)
 * whole: other templates' entries stay, a type Stonewright does not know never empties the option, and with Elementor
 * Pro loaded the list is rebuilt by Elementor Pro itself.
 *
 * @covers \Stonewright\WpMcp\ThemeBuilder\TemplateStore
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate
 */
final class ConditionsCacheTest extends TestCase {

	private const OPTION  = 'elementor_pro_theme_builder_conditions';
	private const LOCK    = 'stonewright_lock_theme_builder_conditions';
	private const HEADER  = 9101;
	private const FOOTER  = 9102;
	private const TARGET  = 9103;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_inserted_posts']    = [];
		$GLOBALS['stonewright_test_deleted_posts']     = [];
		$GLOBALS['stonewright_test_actions']           = [];
		$GLOBALS['stonewright_test_next_post_id']      = 9200;
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap ): bool => in_array( $cap, [ 'edit_posts', 'edit_post', 'edit_theme_options' ], true );
		TemplateStore::use_pro_conditions_cache( null );

		self::add_template( self::HEADER, 'header', [ 'include/general' ] );
		self::add_template( self::FOOTER, 'footer', [ 'include/general' ] );
		$GLOBALS['stonewright_test_options'][ self::OPTION ] = self::base_option();
	}

	protected function tearDown(): void {
		TemplateStore::use_pro_conditions_cache( null );
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_inserted_posts']  = [];
		$GLOBALS['stonewright_test_deleted_posts']   = [];
		$GLOBALS['stonewright_test_actions']         = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_user_caps']       = [];
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
	}

	/**
	 * @param array<int, string> $conditions
	 */
	private static function add_template( int $id, string $type, array $conditions = [] ): void {
		$meta = [
			'_elementor_template_type' => $type,
			'_elementor_edit_mode'     => 'builder',
			'_elementor_data'          => '[]',
		];
		if ( [] !== $conditions ) {
			$meta['_elementor_conditions'] = $conditions;
		}
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => 'elementor_library',
			'post_status'  => 'publish',
			'post_title'   => 'Template ' . $id,
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'template-' . $id,
			'meta'         => $meta,
		];
	}

	/** @return array<string, array<int, array<int, string>>> */
	private static function base_option(): array {
		return [
			'header' => [ self::HEADER => [ 'include/general' ] ],
			'footer' => [ self::FOOTER => [ 'include/general' ] ],
		];
	}

	private static function option(): mixed {
		return $GLOBALS['stonewright_test_options'][ self::OPTION ] ?? null;
	}

	/** @return array<int, array<string, string>> */
	private static function general_rule(): array {
		return [ [ 'type' => 'include', 'name' => 'general' ] ];
	}

	private static function meta_writes_for( int $id ): int {
		$writes = array_filter(
			$GLOBALS['stonewright_test_post_meta_calls'],
			static fn ( array $call ): bool => (int) $call['post_id'] === $id && '_elementor_conditions' === $call['meta_key']
		);
		return count( $writes );
	}

	private static function minimal_spec(): array {
		return [
			'page'     => [ 'title' => 'Template' ],
			'sections' => [
				[
					'id'     => 'content',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Exhibitor' ],
					],
				],
			],
		];
	}

	/**
	 * A stand-in for Elementor Pro's conditions cache that rebuilds the option from the stored rules of every
	 * template the way Conditions_Cache::regenerate() does.
	 */
	private static function fake_pro_cache( ?\Throwable $failure = null ): object {
		return new class( $failure ) {
			public int $runs = 0;

			/** @var array<int, bool> Whether the rules of the popup were in the stored meta at the time of each run. */
			public array $saw_target = [];

			public function __construct( private ?\Throwable $failure ) {}

			public function regenerate(): void {
				++$this->runs;
				if ( null !== $this->failure ) {
					throw $this->failure;
				}
				$locations = [
					'header'         => 'header',
					'footer'         => 'footer',
					'single'         => 'single',
					'single-post'    => 'single',
					'single-page'    => 'single',
					'error-404'      => 'single',
					'product'        => 'single',
					'archive'        => 'archive',
					'search-results' => 'archive',
					'product-archive' => 'archive',
					'popup'          => 'popup',
				];
				$conditions = [];
				foreach ( $GLOBALS['stonewright_test_posts'] as $id => $post ) {
					if ( 'elementor_library' !== $post->post_type || ! in_array( $post->post_status, [ 'publish', 'draft' ], true ) ) {
						continue;
					}
					$meta = (array) ( $post->meta ?? [] );
					if ( empty( $meta['_elementor_conditions'] ) ) {
						continue;
					}
					$location = $locations[ (string) ( $meta['_elementor_template_type'] ?? '' ) ] ?? null;
					if ( null === $location ) {
						continue;
					}
					$conditions[ $location ][ (int) $id ] = $meta['_elementor_conditions'];
				}
				$this->saw_target[] = isset( $GLOBALS['stonewright_test_posts'][ 9103 ]->meta['_elementor_conditions'] );
				$GLOBALS['stonewright_test_options']['elementor_pro_theme_builder_conditions'] = $conditions;
			}
		};
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function located_types(): array {
		return [
			'popup'           => [ 'popup', 'popup' ],
			'product'         => [ 'product', 'single' ],
			'product-archive' => [ 'product-archive', 'archive' ],
			'error-404'       => [ 'error-404', 'single' ],
		];
	}

	/**
	 * @dataProvider located_types
	 */
	public function test_a_type_with_a_location_is_listed_there_and_other_entries_stay( string $type, string $location ): void {
		self::add_template( self::TARGET, $type );

		$result = TemplateStore::set_conditions( self::TARGET, self::general_rule() );

		self::assertTrue( $result );
		$option = self::option();
		self::assertIsArray( $option );
		self::assertSame( [ self::HEADER => [ 'include/general' ] ], $option['header'] );
		self::assertSame( [ self::FOOTER => [ 'include/general' ] ], $option['footer'] );
		self::assertSame( [ 'include/general' ], $option[ $location ][ self::TARGET ] ?? null );
	}

	public function test_a_loop_item_is_listed_nowhere_and_a_stale_loop_entry_goes(): void {
		self::add_template( self::TARGET, 'loop-item' );
		$GLOBALS['stonewright_test_options'][ self::OPTION ]['loop'] = [ self::TARGET => [ 'include/general' ] ];

		$result = TemplateStore::set_conditions( self::TARGET, self::general_rule() );

		self::assertTrue( $result );
		$option = self::option();
		self::assertIsArray( $option );
		self::assertSame( [ self::HEADER => [ 'include/general' ] ], $option['header'] );
		self::assertSame( [ self::FOOTER => [ 'include/general' ] ], $option['footer'] );
		foreach ( $option as $entries ) {
			self::assertArrayNotHasKey( self::TARGET, (array) $entries );
		}
	}

	public function test_clearing_the_rules_of_one_template_removes_only_its_entry(): void {
		self::add_template( self::TARGET, 'popup', [ 'include/general' ] );
		$GLOBALS['stonewright_test_options'][ self::OPTION ]['popup'] = [ self::TARGET => [ 'include/general' ] ];

		$result = TemplateStore::set_conditions( self::TARGET, [] );

		self::assertTrue( $result );
		$option = self::option();
		self::assertIsArray( $option );
		self::assertSame( [ self::HEADER => [ 'include/general' ] ], $option['header'] );
		self::assertSame( [ self::FOOTER => [ 'include/general' ] ], $option['footer'] );
		self::assertArrayNotHasKey( self::TARGET, (array) ( $option['popup'] ?? [] ) );
	}

	public function test_an_unknown_type_without_elementor_pro_is_refused_before_anything_is_written(): void {
		self::add_template( self::TARGET, 'some-addon-type' );
		$before = serialize( self::option() );

		$result = TemplateStore::set_conditions( self::TARGET, self::general_rule() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_builder_location_unknown', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 409, $data['status'] );
		self::assertSame( self::TARGET, $data['template_id'] );
		self::assertSame( 'some-addon-type', $data['template_type'] );
		self::assertSame( $before, serialize( self::option() ) );
		self::assertSame( 0, self::meta_writes_for( self::TARGET ) );
		self::assertArrayNotHasKey( self::LOCK, $GLOBALS['stonewright_test_options'] );
	}

	public function test_clearing_the_rules_of_an_unknown_type_does_not_need_its_location(): void {
		self::add_template( self::TARGET, 'some-addon-type', [ 'include/general' ] );

		$result = TemplateStore::set_conditions( self::TARGET, [] );

		self::assertTrue( $result );
		self::assertSame( self::base_option(), self::option() );
	}

	public function test_another_process_holding_the_lock_refuses_the_call_without_writing(): void {
		self::add_template( self::TARGET, 'popup' );
		$other = time() . ':0123456789abcdef';
		$GLOBALS['stonewright_test_options'][ self::LOCK ] = $other;
		$before = serialize( self::option() );

		$result = TemplateStore::set_conditions( self::TARGET, self::general_rule() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_builder_conditions_busy', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 409, $data['status'] );
		self::assertTrue( $data['retryable'] );
		self::assertSame( 0, self::meta_writes_for( self::TARGET ) );
		self::assertSame( $before, serialize( self::option() ) );
		self::assertSame( $other, $GLOBALS['stonewright_test_options'][ self::LOCK ] );
	}

	public function test_no_lock_row_of_ours_remains_after_a_write_or_a_refusal(): void {
		self::add_template( self::TARGET, 'popup' );
		self::assertTrue( TemplateStore::set_conditions( self::TARGET, self::general_rule() ) );
		self::assertArrayNotHasKey( self::LOCK, $GLOBALS['stonewright_test_options'] );

		self::add_template( self::TARGET + 1, 'some-addon-type' );
		self::assertInstanceOf( \WP_Error::class, TemplateStore::set_conditions( self::TARGET + 1, self::general_rule() ) );
		self::assertArrayNotHasKey( self::LOCK, $GLOBALS['stonewright_test_options'] );
	}

	public function test_an_unreadable_option_is_left_as_it_is(): void {
		self::add_template( self::TARGET, 'popup' );
		$GLOBALS['stonewright_test_options'][ self::OPTION ] = 'corrupt';

		$result = TemplateStore::set_conditions( self::TARGET, self::general_rule() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_builder_cache_unreadable', $result->get_error_code() );
		self::assertSame( 'corrupt', self::option() );
		self::assertArrayNotHasKey( self::LOCK, $GLOBALS['stonewright_test_options'] );
	}

	public function test_the_option_keeps_the_autoload_setting_elementor_pro_uses(): void {
		self::add_template( self::TARGET, 'popup' );

		self::assertTrue( TemplateStore::set_conditions( self::TARGET, self::general_rule() ) );

		self::assertTrue( $GLOBALS['stonewright_test_option_autoload'][ self::OPTION ] );
	}

	public function test_with_elementor_pro_loaded_the_list_is_rebuilt_by_elementor_pro_once_after_the_meta_write(): void {
		$cache = self::fake_pro_cache();
		TemplateStore::use_pro_conditions_cache( $cache );
		self::add_template( self::TARGET, 'popup' );
		// A stale option: only Elementor Pro's rebuild can produce the complete list.
		$GLOBALS['stonewright_test_options'][ self::OPTION ] = [];

		$result = TemplateStore::set_conditions( self::TARGET, self::general_rule() );

		self::assertTrue( $result );
		self::assertSame( 1, $cache->runs );
		self::assertSame( [ true ], $cache->saw_target );
		$option = self::option();
		self::assertIsArray( $option );
		self::assertSame( [ self::HEADER => [ 'include/general' ] ], $option['header'] );
		self::assertSame( [ self::FOOTER => [ 'include/general' ] ], $option['footer'] );
		self::assertSame( [ 'include/general' ], $option['popup'][ self::TARGET ] ?? null );
		self::assertArrayNotHasKey( self::LOCK, $GLOBALS['stonewright_test_options'] );
	}

	public function test_with_elementor_pro_loaded_an_unknown_type_is_left_to_elementor_pro(): void {
		$cache = self::fake_pro_cache();
		TemplateStore::use_pro_conditions_cache( $cache );
		self::add_template( self::TARGET, 'some-addon-type' );

		$result = TemplateStore::set_conditions( self::TARGET, self::general_rule() );

		self::assertTrue( $result );
		self::assertSame( 1, $cache->runs );
		self::assertSame( self::base_option(), self::option() );
	}

	public function test_with_elementor_pro_loaded_a_failing_rebuild_is_reported_and_the_option_is_not_touched(): void {
		$cache = self::fake_pro_cache( new \RuntimeException( 'boom' ) );
		TemplateStore::use_pro_conditions_cache( $cache );
		self::add_template( self::TARGET, 'popup' );
		$before = serialize( self::option() );

		$result = TemplateStore::set_conditions( self::TARGET, self::general_rule() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_builder_cache_rebuild_failed', $result->get_error_code() );
		self::assertStringContainsString( 'boom', $result->get_error_message() );
		self::assertSame( 500, $result->get_error_data()['status'] );
		self::assertSame( $before, serialize( self::option() ) );
		self::assertArrayNotHasKey( self::LOCK, $GLOBALS['stonewright_test_options'] );
	}

	public function test_set_conditions_ability_on_a_popup_keeps_the_other_entries(): void {
		self::add_template( self::TARGET, 'popup' );

		$result = ( new SetConditions() )->execute( [ 'template_id' => self::TARGET, 'conditions' => self::general_rule() ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['updated'] );
		$option = self::option();
		self::assertIsArray( $option );
		self::assertSame( [ self::HEADER => [ 'include/general' ] ], $option['header'] );
		self::assertSame( [ self::FOOTER => [ 'include/general' ] ], $option['footer'] );
		self::assertSame( [ 'include/general' ], $option['popup'][ self::TARGET ] ?? null );
	}

	public function test_set_conditions_ability_on_an_unknown_type_answers_with_the_error_and_the_previous_rules(): void {
		self::add_template( self::TARGET, 'some-addon-type', [ 'exclude/general' ] );
		$before = serialize( self::option() );

		$result = ( new SetConditions() )->execute( [ 'template_id' => self::TARGET, 'conditions' => self::general_rule() ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_builder_location_unknown', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( [ 'exclude/general' ], $data['previous_conditions'] );
		self::assertSame( 409, $data['status'] );
		self::assertSame( $before, serialize( self::option() ) );
		self::assertSame( [ 'exclude/general' ], TemplateStore::get_conditions( self::TARGET ) );
	}

	public function test_apply_template_on_an_existing_popup_lists_it_under_popup_and_keeps_the_other_entries(): void {
		self::add_template( self::TARGET, 'popup' );

		$result = ( new ApplyTemplate() )->execute(
			[
				'template_id'   => self::TARGET,
				'template_type' => 'single',
				'conditions'    => self::general_rule(),
				'spec'          => self::minimal_spec(),
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['applied'] );
		$option = self::option();
		self::assertIsArray( $option );
		self::assertSame( [ self::HEADER => [ 'include/general' ] ], $option['header'] );
		self::assertSame( [ self::FOOTER => [ 'include/general' ] ], $option['footer'] );
		self::assertSame( [ 'include/general' ], $option['popup'][ self::TARGET ] ?? null );
	}

	public function test_apply_template_on_an_existing_template_of_an_unknown_type_is_refused_with_the_ids(): void {
		self::add_template( self::TARGET, 'some-addon-type' );
		$before = serialize( self::option() );

		$result = ( new ApplyTemplate() )->execute(
			[
				'template_id'   => self::TARGET,
				'template_type' => 'single',
				'conditions'    => self::general_rule(),
				'spec'          => self::minimal_spec(),
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_builder_location_unknown', $result->get_error_code() );
		self::assertStringContainsString( 'The template was saved, but its display conditions could not be applied.', $result->get_error_message() );
		$data = $result->get_error_data();
		self::assertSame( self::TARGET, $data['template_id'] );
		self::assertNotEmpty( $data['snapshot_id'] );
		self::assertSame( 409, $data['status'] );
		self::assertSame( 'stonewright/theme-builder-set-conditions', $data['next_step']['tool'] );
		self::assertSame( self::TARGET, $data['next_step']['template_id'] );
		self::assertSame( $before, serialize( self::option() ) );
	}

	public function test_apply_template_on_an_existing_template_while_the_lock_is_held_points_to_set_conditions_instead_of_a_retry(): void {
		self::add_template( self::TARGET, 'popup' );
		$GLOBALS['stonewright_test_options'][ self::LOCK ] = time() . ':0123456789abcdef';
		$before = serialize( self::option() );

		$result = ( new ApplyTemplate() )->execute(
			[
				'template_id'   => self::TARGET,
				'template_type' => 'single',
				'conditions'    => self::general_rule(),
				'spec'          => self::minimal_spec(),
				'mode'          => 'append',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_builder_conditions_busy', $result->get_error_code() );
		$data = $result->get_error_data();
		// The tree is saved: a retry of apply-template would append it twice, so no retry hint goes out.
		self::assertArrayNotHasKey( 'retryable', $data );
		self::assertArrayNotHasKey( 'retry_after', $data );
		self::assertSame( 'stonewright/theme-builder-set-conditions', $data['next_step']['tool'] );
		self::assertStringContainsString( 'Apply them with stonewright-theme-builder-set-conditions.', $result->get_error_message() );
		self::assertSame( 0, self::meta_writes_for( self::TARGET ) );
		self::assertSame( $before, serialize( self::option() ) );
	}

	public function test_apply_template_that_creates_a_template_removes_it_when_the_rules_are_not_stored(): void {
		TemplateStore::use_pro_conditions_cache( self::fake_pro_cache( new \RuntimeException( 'boom' ) ) );

		$result = ( new ApplyTemplate() )->execute(
			[
				'title'         => 'New Header',
				'template_type' => 'header',
				'conditions'    => self::general_rule(),
				'spec'          => self::minimal_spec(),
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_builder_cache_rebuild_failed', $result->get_error_code() );
		self::assertStringStartsWith( 'The template was not created', $result->get_error_message() );
		$data = $result->get_error_data();
		self::assertArrayNotHasKey( 'next_step', $data );
		$removed = array_column( $GLOBALS['stonewright_test_deleted_posts'], 'post_id' );
		self::assertContains( (int) $data['template_id'], $removed );
		self::assertSame( self::base_option(), self::option() );
	}
}
