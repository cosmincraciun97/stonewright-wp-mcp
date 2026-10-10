<?php
/**
 * A live Elementor Atomic widget registry for the tests: widgets that answer with their props schema.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

/** Builds the live widget registry the way Elementor 4 answers it: props are JsonSerializable objects. */
final class LiveAtomicFixtures {

	/**
	 * Elementor describes a text prop as a union of the text type and the types that can stand in for it.
	 *
	 * @return array<string, mixed>
	 */
	public static function union( string $text_type ): array {
		return [
			'kind'       => 'union',
			'default'    => [ '$$type' => $text_type, 'value' => 'This is a title' ],
			'prop_types' => [
				$text_type => new LiveDescriptor( [ 'kind' => 'string', 'key' => $text_type ] ),
				'dynamic'  => new LiveDescriptor( [ 'kind' => 'plain', 'key' => 'dynamic' ] ),
			],
		];
	}

	/**
	 * Installs a registry with these widgets in place of the stub's.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $props Props schema by widget type.
	 */
	public static function install( array $props ): void {
		$widgets = [];
		foreach ( $props as $type => $schema ) {
			$widgets[ $type ] = new LiveAtomicWidget( array_map( static fn( array $descriptor ): LiveDescriptor => new LiveDescriptor( $descriptor ), $schema ) );
		}
		\Elementor\Plugin::$instance->widgets_manager = new LiveWidgetsManager( $widgets );
	}

	/** The usual widgets of a current Elementor: the text of each is escaped HTML. */
	public static function current_elementor(): void {
		self::install(
			[
				'e-heading'   => [ 'title' => self::union( 'escaped-html' ) ],
				'e-paragraph' => [ 'paragraph' => self::union( 'escaped-html' ) ],
				'e-button'    => [ 'text' => self::union( 'escaped-html' ) ],
			]
		);
	}
}

/** One prop of a live widget. */
final class LiveDescriptor implements \JsonSerializable {
	/** @param array<string, mixed> $data */
	public function __construct( private array $data ) {}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return $this->data;
	}
}

/** A live Atomic widget that answers with its props schema. */
final class LiveAtomicWidget {
	/** @param array<string, LiveDescriptor> $props */
	public function __construct( private array $props ) {}

	/** @return array<string, LiveDescriptor> */
	public function get_props_schema(): array {
		return $this->props;
	}
}

/** The widgets manager of a site that has the given live widgets. */
final class LiveWidgetsManager {
	/** @param array<string, object> $widgets */
	public function __construct( private array $widgets ) {}

	/** @return array<string, object>|object|null */
	public function get_widget_types( ?string $name = null ): array|object|null {
		return null === $name ? $this->widgets : ( $this->widgets[ $name ] ?? null );
	}
}
