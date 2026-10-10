<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\SpecToElementorV3;
use Stonewright\WpMcp\Abilities\ElementorV3\AddContainer;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec;
use Stonewright\WpMcp\Abilities\ElementorV3\MoveElement;
use Stonewright\WpMcp\Abilities\ElementorV3\RemoveElement;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitTypography;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings;
use Stonewright\WpMcp\Abilities\ElementorV4\Migrate;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddHeading;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;

/**
 * An Elementor write whose snapshot could not be stored is refused before anything is written.
 *
 * Each case makes the snapshot fail the way a real site does: the history meta cannot be stored and
 * read back, so Backup::snapshot_post() returns an empty id. The write then must not run, no lock may
 * stay held, and the same call must work once the snapshot can be stored (so the refusal is about the
 * snapshot and not about the arguments).
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddContainer
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddWidget
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\MoveElement
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\RemoveElement
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitTypography
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidgets\WidgetAbilityBase
 * @covers \Stonewright\WpMcp\Abilities\Design\SpecToElementorV3
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\Migrate
 */
final class SnapshotFailureStopsWriteTest extends TestCase {

	private const PAGE = 321;
	private const KIT  = 900;

	/** @var list<array<string, mixed>> */
	private array $tree;

	protected function setUp(): void {
		$this->tree = [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[
						'id'         => 'w1',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Existing' ],
						'elements'   => [],
					],
				],
			],
			[
				'id'       => 'other',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [],
			],
		];
		$version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0';

		$GLOBALS['stonewright_test_posts']           = [
			self::PAGE => (object) [
				'ID'           => self::PAGE,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'target',
				'meta'         => [
					'_elementor_data'          => (string) wp_json_encode( $this->tree ),
					'_elementor_edit_mode'     => 'builder',
					'_elementor_version'       => $version,
					'_elementor_page_settings' => [ 'hide_title' => 'yes' ],
				],
			],
			self::KIT  => (object) [
				'ID'           => self::KIT,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Default Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'default-kit',
				'meta'         => [
					'_elementor_page_settings' => [ 'custom_colors' => [] ],
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                     => 'development',
			'elementor_active_kit'                 => self::KIT,
			DesignDirectionService::ACTIVE_OPTION  => 9101,
			'stonewright_design_checkpoint_secret' => str_repeat( 'c', 64 ),
		];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	/**
	 * @return iterable<string, array{0:callable():AbilityKernel, 1:array<string, mixed>, 2:int}>
	 */
	public static function writers(): iterable {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Fast Path' ],
			'sections' => [ [ 'id' => 'hero', 'blocks' => [ [ 'type' => 'heading', 'text' => 'Hello', 'level' => 1 ] ] ] ],
		];
		yield 'elementor-v3-add-container' => [ static fn () => new AddContainer(), [ 'post_id' => self::PAGE ], self::PAGE ];
		yield 'elementor-v3-add-widget' => [ static fn () => new AddWidget(), [ 'post_id' => self::PAGE, 'parent_id' => 'other', 'widget_type' => 'heading', 'allow_raw_known_widget' => true, 'settings' => [ 'title' => 'New' ] ], self::PAGE ];
		yield 'elementor-v3-move-element' => [ static fn () => new MoveElement(), [ 'post_id' => self::PAGE, 'element_id' => 'w1', 'new_parent_id' => 'other' ], self::PAGE ];
		yield 'elementor-v3-remove-element' => [ static fn () => new RemoveElement(), [ 'post_id' => self::PAGE, 'element_id' => 'w1' ], self::PAGE ];
		yield 'elementor-v3-update-element' => [ static fn () => new UpdateElement(), [ 'post_id' => self::PAGE, 'element_id' => 'w1', 'settings' => [ 'title' => 'Changed' ] ], self::PAGE ];
		yield 'elementor-v3-build-page-from-spec' => [ static fn () => new BuildPageFromSpec(), [ 'post_id' => self::PAGE, 'spec' => $spec ], self::PAGE ];
		yield 'elementor-v3-update-page-settings' => [ static fn () => new UpdatePageSettings(), [ 'post_id' => self::PAGE, 'settings' => [ 'hide_title' => 'no' ], 'mode' => 'merge' ], self::PAGE ];
		yield 'elementor-v3-update-kit-colors' => [ static fn () => new UpdateKitColors(), [ 'colors' => [ [ 'id' => 'brand', 'title' => 'Brand', 'color' => '#112233' ] ] ], self::KIT ];
		yield 'elementor-v3-update-kit-typography' => [ static fn () => new UpdateKitTypography(), [ 'fonts' => [ [ 'id' => 'body', 'title' => 'Body', 'font_family' => 'Georgia', 'font_weight' => '400' ] ] ], self::KIT ];
		yield 'a per-widget ability (WidgetAbilityBase)' => [ static fn () => new AddHeading(), [ 'post_id' => self::PAGE, 'parent_id' => 'other', 'settings' => [ 'title' => 'New heading' ] ], self::PAGE ];
		yield 'design-spec-to-elementor-v3' => [ static fn () => new SpecToElementorV3(), [ 'post_id' => self::PAGE, 'replace' => false, 'spec' => $spec ], self::PAGE ];
		yield 'elementor-v4-migrate' => [ static fn () => new Migrate(), [ 'post_id' => self::PAGE, 'apply' => true, 'confirm_migration' => true ], self::PAGE ];
	}

	/**
	 * @param callable():AbilityKernel $make
	 * @param array<string, mixed>     $args
	 * @dataProvider writers
	 */
	public function test_a_failed_snapshot_stops_the_write( callable $make, array $args, int $target ): void {
		$before = (array) $GLOBALS['stonewright_test_posts'][ $target ]->meta;
		$content = $GLOBALS['stonewright_test_posts'][ $target ]->post_content;
		// The history meta cannot be stored, so the snapshot cannot be read back and comes out empty.
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = $make()->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result, 'The write must be refused.' );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'Nothing was written.' );
		self::assertSame( $before, (array) $GLOBALS['stonewright_test_posts'][ $target ]->meta );
		self::assertSame( $content, $GLOBALS['stonewright_test_posts'][ $target ]->post_content );
		foreach ( [ self::PAGE, self::KIT ] as $post_id ) {
			self::assertSame( [], get_option( 'stonewright_elementor_lock_' . $post_id, [] ), 'No write lock is left held on post ' . $post_id . '.' );
		}

		// Once the snapshot can be stored, the same call goes through: the refusal was about the snapshot.
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$retry = $make()->execute( $args );
		self::assertNotInstanceOf( \WP_Error::class, $retry, $retry instanceof \WP_Error ? $retry->get_error_code() . ': ' . $retry->get_error_message() : '' );
		self::assertNotSame( '', (string) ( $retry['snapshot_id'] ?? 'n/a' ), 'A result that names a snapshot names a real one.' );
	}
}
