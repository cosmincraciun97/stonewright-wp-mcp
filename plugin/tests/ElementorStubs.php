<?php
declare( strict_types=1 );

namespace Elementor;

/**
 * Minimal Widget_Base stub for tests.
 * Loader test spies extend this class.
 */
class Widget_Base {

	public function get_name(): string {
		return '';
	}

	public function get_title(): string {
		return '';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [];
	}

	public function get_icon(): string {
		return 'eicon-code';
	}

	/** @return array<string, mixed> */
	public function get_settings_for_display(): array {
		return [];
	}

	public function start_controls_section( string $id, array $args = [] ): void {
	}

	public function add_control( string $id, array $args = [] ): void {
	}

	public function end_controls_section(): void {
	}
}

/**
 * Minimal Controls_Manager stub.
 */
class Controls_Manager {
	public const TEXT     = 'text';
	public const TEXTAREA = 'textarea';
	public const NUMBER   = 'number';
	public const COLOR    = 'color';
	public const SELECT   = 'select';
	public const URL      = 'url';
	public const MEDIA    = 'media';
	public const SWITCHER = 'switcher';

	public const TAB_CONTENT = 'content';
}

/**
 * Minimal Widgets_Manager stub.
 * Loader calls register() on it; tests subclass to spy.
 */
class Widgets_Manager {

	/**
	 * @return array<string, object>|object|null
	 */
	public function get_widget_types( ?string $name = null ): array|object|null {
		return null === $name ? [] : null;
	}

	public function register( Widget_Base $widget ): void {
	}
}

final class Plugin {

	public static object $instance;
}

Plugin::$instance = (object) [
	'widgets_manager' => new class() {
		/**
		 * @return array<string, object>|object|null
		 */
		public function get_widget_types( ?string $name = null ): array|object|null {
			$widgets = [
				'Contract' => new class() {
					public function get_title(): string {
						return 'Contract Widget';
					}

					public function get_icon(): string {
						return 'eicon-code';
					}

					/**
					 * @return list<string>
					 */
					public function get_categories(): array {
						return [ 'basic' ];
					}

					/**
					 * @return list<string>
					 */
					public function get_keywords(): array {
						return [ 'contract' ];
					}

					/**
					 * @return array<string, array<string, mixed>>
					 */
					public function get_controls(): array {
						return [
							'title' => [
								'type'    => 'text',
								'label'   => 'Title',
								'default' => 'Contract',
								'tab'     => 'content',
								'section' => 'content',
							],
							];
						}
					},
				'third-party-card' => new class() {
					public function get_title(): string {
						return 'Third Party Card';
					}

					/** @return list<string> */
					public function get_categories(): array {
						return [ 'third-party' ];
					}

					/** @return list<string> */
					public function get_keywords(): array {
						return [ 'card' ];
					}

					/** @return array<string, array<string, mixed>> */
					public function get_controls(): array {
						return [
							'title' => [ 'type' => 'text', 'label' => 'Title', 'tab' => 'content', 'section' => 'content' ],
						];
					}
				},
			];

			if ( null !== $name ) {
				if ( isset( $widgets[ $name ] ) ) {
					return $widgets[ $name ];
				}

				$catalog = \Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog::class;
				if ( ! $catalog::has( $name ) ) {
					return null;
				}
				$entry = $catalog::entry( $name );

				return new class( $name, $entry ) {
					/** @param array<string, mixed> $entry */
					public function __construct( private string $name, private array $entry ) {
					}

					public function get_title(): string {
						return (string) ( $this->entry['title'] ?? $this->name );
					}

					/** @return list<string> */
					public function get_categories(): array {
						return array_values( (array) ( $this->entry['categories'] ?? [] ) );
					}

					/** @return array<string, array<string, mixed>> */
					public function get_controls(): array {
						$controls = [];
						foreach ( (array) ( $this->entry['settings_index'] ?? [] ) as $key => $control ) {
							$controls[ (string) $key ] = is_array( $control ) ? $control : [];
						}
						foreach ( (array) ( $this->entry['sections'] ?? [] ) as $section ) {
							foreach ( (array) ( $section['group_controls'] ?? [] ) as $group ) {
								$prefix = is_string( $group['name'] ?? null ) ? $group['name'] : '';
								if ( 'typography' === ( $group['group'] ?? '' ) && '' !== $prefix ) {
									$controls[ $prefix . '_font_size' ] = [
										'type'       => 'slider',
										'tab'        => (string) ( $section['tab'] ?? 'style' ),
										'section'    => (string) ( $section['id'] ?? '' ),
										'responsive' => true,
									];
								}
							}
						}
						return $controls;
					}
				};
			}

			return $widgets;
		}
	},
	'kits_manager'    => new class() {
		public function get_active_kit(): object {
			return new class() {
				public function get_id(): int {
					return 4;
				}
			};
		}
	},
];
