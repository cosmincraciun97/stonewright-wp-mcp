<?php
/**
 * Every V4 text write uses the text prop type the live Elementor declares.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode;
use Stonewright\WpMcp\Elementor\V4\AtomicRenderer;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicTextProp;
use Stonewright\WpMcp\Elementor\V4\AtomicWriteReadback;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Tests\Unit\SectionReuse\SectionFixtures;

require_once __DIR__ . '/LiveAtomicFixtures.php';
require_once dirname( __DIR__, 2 ) . '/SectionReuse/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicRenderer
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicWriteReadback
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicTextProp
 */
final class V4TextTypeWritesTest extends TestCase {

	private const POST = 9301;

	private mixed $original_manager;

	protected function setUp(): void {
		$this->original_manager = \Elementor\Plugin::$instance->widgets_manager;
		AtomicSchemaRepository::invalidate();
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_elementor_v4_atomic' => true ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		self::seed( AtomicTextProp::envelope( 'escaped-html', 'Hello' ) );
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance->widgets_manager = $this->original_manager;
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		ReferenceCatalog::set_provider( null );
		AtomicSchemaRepository::invalidate();
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/** @param array<string, mixed> $title */
	private static function seed( array $title ): void {
		$tree = [
			[
				'id'              => 'a000001',
				'version'         => '0.0',
				'elType'          => 'e-div-block',
				'isInner'         => false,
				'settings'        => [],
				'editor_settings' => [],
				'interactions'    => [],
				'styles'          => [],
				'elements'        => [
					[
						'id'              => 'heading1',
						'version'         => '0.0',
						'elType'          => 'widget',
						'widgetType'      => 'e-heading',
						'isInner'         => false,
						'settings'        => [ 'title' => $title, 'tag' => [ '$$type' => 'string', 'value' => 'h2' ] ],
						'editor_settings' => [],
						'interactions'    => [],
						'styles'          => [],
						'elements'        => [],
					],
				],
			],
		];
		$GLOBALS['stonewright_test_posts'] = [ self::POST => SectionFixtures::post( self::POST, 'page', 'draft', 'V4 page', '', SectionFixtures::elementor_meta( $tree, '4.3.4' ) ) ];
	}

	/** @param array<string, mixed> $title @return array<string, mixed>|\WP_Error */
	private static function patch_title( array $title, bool $dry_run = false ): array|\WP_Error {
		return ( new UpdateNode() )->execute( [ 'post_id' => self::POST, 'element_id' => 'heading1', 'settings' => [ 'title' => $title ], 'dry_run' => $dry_run ] );
	}

	public function test_update_node_writes_the_type_the_live_heading_declares(): void {
		LiveAtomicFixtures::current_elementor();

		$result = self::patch_title( AtomicTextProp::envelope( 'escaped-html', 'Built to last' ) );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ' ' . $result->get_error_message() : '' );
		self::assertTrue( $result['readback']['verified'] );
		self::assertSame( [ '$$type' => 'escaped-html', 'value' => 'Built to last' ], ElementorData::flatten( ElementorData::read( self::POST ) )['heading1']['settings']['title'] );
	}

	public function test_update_node_refuses_the_old_type_when_the_live_heading_declares_another(): void {
		LiveAtomicFixtures::current_elementor();

		$result = self::patch_title( AtomicTextProp::envelope( 'html-v3', 'Built to last' ), true );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_settings_envelope_type', $result->get_error_code() );
		self::assertSame( 'escaped-html', $result->get_error_data()['expected_type'] );
		self::assertSame( 'html-v3', $result->get_error_data()['actual_type'] );
	}

	public function test_update_node_refuses_an_envelope_of_the_right_type_that_renders_nothing(): void {
		LiveAtomicFixtures::current_elementor();

		$result = self::patch_title( [ '$$type' => 'escaped-html', 'value' => [ 'content' => 'Built to last' ] ], true );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_settings_envelope_value', $result->get_error_code() );
		self::assertSame( 'title', $result->get_error_data()['settings_key'] );
	}

	public function test_without_a_live_schema_the_bundled_type_still_applies(): void {
		\Elementor\Plugin::$instance->widgets_manager = new LiveWidgetsManager( [] );
		AtomicSchemaRepository::invalidate();

		$accepted = self::patch_title( AtomicTextProp::envelope( 'html-v3', 'Built to last' ), true );
		$refused  = self::patch_title( AtomicTextProp::envelope( 'escaped-html', 'Built to last' ), true );

		self::assertIsArray( $accepted );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'html-v3', $refused->get_error_data()['expected_type'] );
	}

	public function test_a_batch_adapts_copied_text_in_the_live_type(): void {
		LiveAtomicFixtures::current_elementor();
		$section = self::copied_section();

		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST,
				'operations' => [
					[ 'action' => 'insert_section', 'op_id' => 'feat', 'parent_id' => 'a000001', 'section' => $section ],
					[ 'action' => 'update_node', 'element_ref' => 'feat.ph-2', 'settings' => [ 'title' => AtomicTextProp::envelope( 'escaped-html', 'Built to last' ) ] ],
					[ 'action' => 'update_node', 'element_ref' => 'feat.ph-5', 'settings' => [ 'title' => AtomicTextProp::envelope( 'escaped-html', 'Hand made' ) ] ],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'verified', $result['verification_status'] );
		$heading = ElementorData::flatten( ElementorData::read( self::POST ) )[ $result['refs']['feat.ph-2'] ];
		self::assertSame( [ '$$type' => 'escaped-html', 'value' => 'Built to last' ], $heading['settings']['title'] );
	}

	public function test_a_copy_that_would_render_its_text_empty_is_refused_in_the_dry_run(): void {
		LiveAtomicFixtures::current_elementor();
		$section = self::copied_section();
		$before  = ElementorData::read( self::POST );

		foreach ( [ true, false ] as $dry_run ) {
			$result = ( new UpdateNode() )->execute( [ 'post_id' => self::POST, 'dry_run' => $dry_run, 'operations' => [ [ 'action' => 'insert_section', 'op_id' => 'feat', 'parent_id' => 'a000001', 'section' => $section ] ] ] );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_atomic_text_not_renderable', $result->get_error_code() );
			$problem = $result->get_error_data()['problems'][0];
			self::assertSame( 'title', $problem['key'] );
			self::assertSame( 'escaped-html', $problem['expected_type'] );
			self::assertSame( 'html-v3', $problem['actual_type'] );
		}
		self::assertSame( $before, ElementorData::read( self::POST ), 'Nothing was written.' );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_the_readback_never_reports_verified_for_text_the_live_widget_renders_empty(): void {
		LiveAtomicFixtures::current_elementor();
		$before   = ElementorData::read( self::POST );
		$snapshot = Backup::snapshot_post( self::POST );
		$written  = $before;
		$written[0]['elements'][0]['settings']['title'] = AtomicTextProp::envelope( 'html-v3', 'Stored but never rendered' );
		ElementorData::write( self::POST, $written );

		$result = AtomicWriteReadback::verify_tree( self::POST, $written, $snapshot, 'test', [ 'ids' => [ 'heading1' ], 'before' => $before ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_atomic_text_not_renderable', $result->get_error_code() );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
		self::assertSame( $before, ElementorData::read( self::POST ), 'The snapshot was restored.' );
	}

	public function test_the_renderer_writes_the_live_type(): void {
		LiveAtomicFixtures::current_elementor();
		AtomicSchemaRepository::invalidate();

		$heading   = AtomicRenderer::render_node( [ 'type' => 'Heading', 'props' => [ 'text' => 'Hello', 'level' => 2 ] ] );
		$paragraph = AtomicRenderer::render_node( [ 'type' => 'TextEditor', 'props' => [ 'text' => 'Body copy.' ] ] );
		$button    = AtomicRenderer::render_node( [ 'type' => 'Button', 'props' => [ 'text' => 'Sign up', 'link' => 'https://example.com/join' ] ] );

		self::assertSame( [ '$$type' => 'escaped-html', 'value' => 'Hello' ], $heading['settings']['title'] );
		self::assertSame( [ '$$type' => 'escaped-html', 'value' => 'Body copy.' ], $paragraph['settings']['paragraph'] );
		self::assertSame( [ '$$type' => 'escaped-html', 'value' => 'Sign up' ], $button['settings']['text'] );
		self::assertSame( [], AtomicTextProp::problems( [ $heading, $paragraph, $button ], [ $heading['id'], $paragraph['id'], $button['id'] ] ), 'What the renderer writes passes the readback check.' );
	}

	/** @return array<string, mixed> A V4 section copied from a page written by an older Elementor: its text is html-v3. */
	private static function copied_section(): array {
		$section = SectionFixtures::v4_features( 'v', 1, 'Why choose us' );
		$count   = 0;
		$walk    = static function ( array $element ) use ( &$walk, &$count ): array {
			$element['id'] = 'ph-' . ( ++$count );
			if ( isset( $element['styles'] ) && [] !== $element['styles'] ) {
				$map    = [];
				$styles = [];
				foreach ( $element['styles'] as $old => $style ) {
					$map[ $old ]                 = 'ls-' . $count;
					$style['id']                 = $map[ $old ];
					$styles[ $map[ $old ] ]      = $style;
				}
				$element['styles']   = $styles;
				$element['settings'] = \Stonewright\WpMcp\SectionReuse\PortableSection::remap_classes( $element['settings'], $map );
			}
			$element['elements'] = array_map( $walk, $element['elements'] );

			return $element;
		};

		return [ 'schema' => 'SectionPortableV1', 'builder' => 'elementor-v4', 'element' => $walk( $section ) ];
	}
}
