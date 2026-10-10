<?php
/**
 * The text prop type of an Elementor Atomic widget, read from the live widget.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicTextProp;

require_once __DIR__ . '/LiveAtomicFixtures.php';

/**
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicTextProp
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository
 */
final class AtomicTextPropTest extends TestCase {

	private mixed $original_manager;

	protected function setUp(): void {
		$this->original_manager = \Elementor\Plugin::$instance->widgets_manager;
		AtomicSchemaRepository::invalidate();
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance->widgets_manager = $this->original_manager;
		AtomicSchemaRepository::invalidate();
	}

	public function test_the_type_is_the_one_the_live_widget_declares(): void {
		LiveAtomicFixtures::install( [ 'e-heading' => [ 'title' => LiveAtomicFixtures::union( 'escaped-html' ) ] ] );

		self::assertSame( 'escaped-html', AtomicTextProp::live_type( 'e-heading', 'title' ) );
	}

	public function test_a_plain_declaration_without_a_union_is_read_from_its_key(): void {
		LiveAtomicFixtures::install( [ 'e-heading' => [ 'title' => [ 'kind' => 'object', 'key' => 'html-v3' ] ] ] );

		self::assertSame( 'html-v3', AtomicTextProp::live_type( 'e-heading', 'title' ) );
	}

	public function test_no_live_widget_means_no_live_type(): void {
		\Elementor\Plugin::$instance->widgets_manager = new LiveWidgetsManager( [] );

		self::assertNull( AtomicTextProp::live_type( 'e-heading', 'title' ) );
	}

	public function test_a_prop_that_is_not_text_has_no_text_type(): void {
		LiveAtomicFixtures::install( [ 'e-heading' => [ 'tag' => [ 'kind' => 'union', 'prop_types' => [ 'size' => new LiveDescriptor( [ 'kind' => 'object', 'key' => 'size' ] ) ] ] ] ] );

		self::assertNull( AtomicTextProp::live_type( 'e-heading', 'tag' ) );
		self::assertNull( AtomicTextProp::live_type( 'e-heading', 'missing' ) );
	}

	public function test_the_repository_writes_the_live_type_and_falls_back_to_the_bundled_one_without_a_live_widget(): void {
		\Elementor\Plugin::$instance->widgets_manager = new LiveWidgetsManager( [] );
		AtomicSchemaRepository::invalidate();
		self::assertSame( 'html-v3', AtomicSchemaRepository::for_atomic_type( 'e-heading' )['props']['text']['type'], 'Without a live schema the bundled map applies.' );

		LiveAtomicFixtures::install(
			[
				'e-heading'   => [ 'title' => LiveAtomicFixtures::union( 'escaped-html' ) ],
				'e-paragraph' => [ 'paragraph' => LiveAtomicFixtures::union( 'escaped-html' ) ],
				'e-button'    => [ 'text' => LiveAtomicFixtures::union( 'html-v3' ) ],
			]
		);
		AtomicSchemaRepository::invalidate();

		self::assertSame( 'escaped-html', AtomicSchemaRepository::for_atomic_type( 'e-heading' )['props']['text']['type'] );
		self::assertSame( 'escaped-html', AtomicSchemaRepository::for_atomic_type( 'e-paragraph' )['props']['text']['type'] );
		self::assertSame( 'html-v3', AtomicSchemaRepository::for_atomic_type( 'e-button' )['props']['text']['type'] );
		self::assertTrue( AtomicSchemaRepository::for_atomic_type( 'e-heading' )['write_eligible'], 'The widget stays on the certified contract; only its text type follows the live widget.' );
	}

	public function test_an_unknown_declared_type_is_not_guessed(): void {
		LiveAtomicFixtures::install( [ 'e-heading' => [ 'title' => LiveAtomicFixtures::union( 'rich-text-v9' ) ] ] );
		AtomicSchemaRepository::invalidate();

		self::assertNull( AtomicTextProp::live_type( 'e-heading', 'title' ) );
		self::assertSame( 'html-v3', AtomicSchemaRepository::for_atomic_type( 'e-heading' )['props']['text']['type'] );
	}

	public function test_each_text_type_has_its_own_envelope(): void {
		self::assertSame( [ '$$type' => 'escaped-html', 'value' => 'Hi' ], AtomicTextProp::envelope( 'escaped-html', 'Hi' ) );
		self::assertSame( [ '$$type' => 'html', 'value' => 'Hi' ], AtomicTextProp::envelope( 'html', 'Hi' ) );
		self::assertSame( [ '$$type' => 'html-v2', 'value' => [ 'content' => 'Hi', 'children' => [] ] ], AtomicTextProp::envelope( 'html-v2', 'Hi' ) );
		self::assertSame( [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => 'Hi' ], 'children' => [] ] ], AtomicTextProp::envelope( 'html-v3', 'Hi' ) );
		self::assertNull( AtomicTextProp::envelope( 'size', 'Hi' ) );
	}

	public function test_the_text_of_an_envelope_follows_the_envelopes_own_type(): void {
		self::assertSame( 'Hi', AtomicTextProp::text_of( [ '$$type' => 'escaped-html', 'value' => 'Hi' ] ) );
		self::assertSame( 'Hi', AtomicTextProp::text_of( AtomicTextProp::envelope( 'html-v3', 'Hi' ) ) );
		self::assertSame( 'Hi', AtomicTextProp::text_of( AtomicTextProp::envelope( 'html-v2', 'Hi' ) ) );
		self::assertNull( AtomicTextProp::text_of( [ '$$type' => 'escaped-html', 'value' => [ 'content' => 'Hi' ] ] ), 'An object where a string belongs renders nothing.' );
		self::assertNull( AtomicTextProp::text_of( [ '$$type' => 'html-v3', 'value' => 'Hi' ] ) );
		self::assertNull( AtomicTextProp::text_of( [ '$$type' => 'dynamic', 'value' => [ 'name' => 'post-title' ] ] ) );
	}

	public function test_the_check_flags_text_that_the_live_widget_would_render_empty_and_only_what_changed(): void {
		LiveAtomicFixtures::install( [ 'e-heading' => [ 'title' => LiveAtomicFixtures::union( 'escaped-html' ) ] ] );
		AtomicSchemaRepository::invalidate();
		$legacy = AtomicTextProp::envelope( 'html-v3', 'Old' );
		$node   = static fn( string $id, array $title ): array => [ 'id' => $id, 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'title' => $title ], 'elements' => [] ];
		$tree   = [ $node( 'a1', $legacy ), $node( 'a2', AtomicTextProp::envelope( 'escaped-html', 'New' ) ), $node( 'a3', [ '$$type' => 'dynamic', 'value' => [ 'name' => 'post-title' ] ] ) ];

		$problems = AtomicTextProp::problems( $tree, [ 'a1', 'a2', 'a3' ] );

		self::assertCount( 1, $problems );
		self::assertSame( 'a1', $problems[0]['id'] );
		self::assertSame( 'title', $problems[0]['key'] );
		self::assertSame( 'escaped-html', $problems[0]['expected_type'] );
		self::assertSame( 'html-v3', $problems[0]['actual_type'] );
		self::assertSame( [], AtomicTextProp::problems( $tree, [ 'a1' ], [ $node( 'a1', $legacy ) ] ), 'Text that was already there before the write is not the write\'s doing.' );
		self::assertSame( [], AtomicTextProp::problems( $tree, [ 'a2', 'a3' ] ), 'Only the touched elements are checked.' );
	}

	public function test_without_a_live_type_the_check_only_flags_a_malformed_envelope(): void {
		\Elementor\Plugin::$instance->widgets_manager = new LiveWidgetsManager( [] );
		AtomicSchemaRepository::invalidate();
		$node = static fn( string $id, array $title ): array => [ 'id' => $id, 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'title' => $title ], 'elements' => [] ];

		self::assertSame( [], AtomicTextProp::problems( [ $node( 'a1', AtomicTextProp::envelope( 'escaped-html', 'Written by the editor' ) ) ], [ 'a1' ] ) );
		self::assertSame( [ 'a2' ], array_column( AtomicTextProp::problems( [ $node( 'a2', [ '$$type' => 'html-v3', 'value' => 'plain' ] ) ], [ 'a2' ] ), 'id' ) );
	}
}
