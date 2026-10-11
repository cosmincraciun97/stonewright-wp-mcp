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
 * Controls a booted Elementor lists for its group controls, which the bundled catalog records only as group
 * entries: a group's sub-fields appear once the group's toggle is set.
 */
final class Stub_Group_Controls {

	/**
	 * A data control Elementor registers without an explicit default holds an empty string.
	 *
	 * @param array<string, mixed> $control
	 * @return array<string, mixed>
	 */
	public static function with_default( array $control ): array {
		if ( null === ( $control['default'] ?? null ) && in_array( $control['type'] ?? '', [ 'switcher', 'select', 'choose', 'text', 'textarea', 'hidden', 'group_activator' ], true ) ) {
			$control['default'] = '';
		}
		return $control;
	}

	/** @return array<string, array<string, mixed>> */
	public static function fields( string $group, string $prefix, string $tab, string $section ): array {
		$where  = [ 'tab' => $tab, 'section' => $section ];
		$choice = static fn( array $values ): array => array_combine( $values, $values );
		$when   = static fn( string $activator, string $operator, mixed $value ): array => [ 'condition' => [ $activator . $operator => $value ] ];
		$fields = [];
		switch ( $group ) {
			case 'typography':
				$on = $when( $prefix . '_typography', '!', '' );
				$defs = [
					'font_family'     => [ 'type' => 'font' ],
					'font_size'       => [ 'type' => 'slider', 'responsive' => true ],
					'font_weight'     => [ 'type' => 'select', 'options' => $choice( [ '', '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ] ) ],
					'text_transform'  => [ 'type' => 'select', 'options' => $choice( [ '', 'none', 'capitalize', 'uppercase', 'lowercase' ] ) ],
					'font_style'      => [ 'type' => 'select', 'options' => $choice( [ '', 'normal', 'italic', 'oblique' ] ) ],
					'text_decoration' => [ 'type' => 'select', 'options' => $choice( [ '', 'none', 'underline', 'overline', 'line-through' ] ) ],
					'line_height'     => [ 'type' => 'slider', 'responsive' => true ],
					'letter_spacing'  => [ 'type' => 'slider', 'responsive' => true ],
					'word_spacing'    => [ 'type' => 'slider', 'responsive' => true ],
				];
				foreach ( $defs as $field => $definition ) {
					$fields[ $prefix . '_' . $field ] = $definition + $on + $where;
				}
				break;
			case 'border':
				$on = $when( $prefix . '_border', '!', '' );
				$fields[ $prefix . '_width' ] = [ 'type' => 'dimensions', 'responsive' => true ] + $on + $where;
				$fields[ $prefix . '_color' ] = [ 'type' => 'color' ] + $on + $where;
				break;
			case 'background':
				$classic = $when( $prefix . '_background', '', 'classic' );
				$fields[ $prefix . '_color' ] = [ 'type' => 'color', 'condition' => [ $prefix . '_background' => [ 'classic', 'gradient' ] ] ] + $where;
				foreach ( [ 'image' => 'media', 'position' => 'select', 'xpos' => 'slider', 'ypos' => 'slider', 'attachment' => 'select', 'repeat' => 'select', 'size' => 'select', 'bg_width' => 'slider' ] as $field => $type ) {
					$fields[ $prefix . '_' . $field ] = [ 'type' => $type, 'responsive' => 'image' !== $field ] + $classic + $where;
				}
				break;
			case 'image-size':
				$fields[ $prefix . '_size' ]             = [ 'type' => 'select', 'default' => 'full', 'options' => $choice( [ 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048', 'full', 'custom' ] ) ] + $where;
				$fields[ $prefix . '_custom_dimension' ] = [ 'type' => 'image_dimensions' ] + $when( $prefix . '_size', '', 'custom' ) + $where;
				break;
			case 'box-shadow':
				$fields[ $prefix . '_box_shadow_type' ] = [ 'type' => 'switcher', 'default' => '' ] + $where;
				$fields[ $prefix . '_box_shadow' ]      = [ 'type' => 'box_shadow' ] + $when( $prefix . '_box_shadow_type', '', 'yes' ) + $where;
				break;
			case 'text-shadow':
				$fields[ $prefix . '_text_shadow_type' ] = [ 'type' => 'switcher', 'default' => '' ] + $where;
				$fields[ $prefix . '_text_shadow' ]      = [ 'type' => 'text_shadow' ] + $when( $prefix . '_text_shadow_type', '', 'yes' ) + $where;
				break;
		}
		return $fields;
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
	'frontend' => new class() {
		public function get_builder_content_for_display( int $post_id, bool $with_css = false ): string {
			return '<div class="elementor-element-contract">Contract render</div>';
		}
	},
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
				'loop-grid' => new class() {
					public function get_title(): string {
						return 'Loop Grid';
					}

					/** @return list<string> */
					public function get_categories(): array {
						return [ 'pro-elements' ];
					}

					/** @return array<string, array<string, mixed>> */
					public function get_controls(): array {
						$options = [
							'by_id'         => 'Manual Selection',
							'current_query' => 'Current Query',
							'post'          => 'Posts',
							'page'          => 'Pages',
							'product'       => 'Products',
						];
						foreach ( (array) ( $GLOBALS['stonewright_test_registered_post_types'] ?? [] ) as $slug => $args ) {
							$slug = sanitize_key( (string) $slug );
							if ( '' === $slug || isset( $options[ $slug ] ) ) {
								continue;
							}
							$label = $slug;
							if ( is_array( $args ) ) {
								$labels = is_array( $args['labels'] ?? null ) ? $args['labels'] : [];
								$label  = (string) ( $args['label'] ?? $labels['name'] ?? $slug );
							}
							$options[ $slug ] = $label;
						}

						return [
							'template_id'     => [ 'type' => 'query', 'label' => 'Choose template' ],
							'query_post_type' => [
								'type'    => 'select',
								'label'   => 'Source',
								'options' => $options,
							],
							'posts_per_page' => [ 'type' => 'number', 'min' => 1, 'max' => 100 ],
							'columns'        => [ 'type' => 'number', 'responsive' => true ],
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
							$controls[ (string) $key ] = \Elementor\Stub_Group_Controls::with_default( is_array( $control ) ? $control : [] );
						}
						foreach ( (array) ( $this->entry['sections'] ?? [] ) as $section ) {
							foreach ( (array) ( $section['group_controls'] ?? [] ) as $group ) {
								$prefix = is_string( $group['name'] ?? null ) ? $group['name'] : '';
								if ( '' !== $prefix ) {
									foreach ( \Elementor\Stub_Group_Controls::fields( (string) ( $group['group'] ?? '' ), $prefix, (string) ( $section['tab'] ?? 'style' ), (string) ( $section['id'] ?? '' ) ) as $key => $definition ) {
										$controls[ $key ] = $definition;
									}
								}
							}
						}
						if ( 'button' === $this->name && isset( $controls['background_background'] ) ) {
							// The button's background group is classic until changed.
							$controls['background_background']['default'] = 'classic';
						}
						$controls['_element_id'] = [ 'type' => 'text', 'label' => 'CSS ID', 'tab' => 'advanced', 'section' => '_section_style' ];
						$controls['_animation'] = [
							'type'    => 'select',
							'label'   => 'Entrance Animation',
							'tab'     => 'advanced',
							'section' => '_section_effects',
							'options' => [ '' => 'None', 'fadeIn' => 'Fade In', 'fadeInUp' => 'Fade In Up' ],
						];
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

namespace Elementor\Core\Files\CSS;

final class Post {
	/** @var callable|null */
	public static $factory = null;

	public static function create( int $post_id ): object {
		if ( is_callable( self::$factory ) ) {
			return ( self::$factory )( $post_id );
		}

		return new class() {
			public function update(): void {
			}

			public function update_file(): void {
			}

			public function get_path(): string {
				return '';
			}

			public function get_url(): string {
				return '';
			}
		};
	}
}

namespace Elementor\Modules\GlobalClasses;

/**
 * The global classes repository as Elementor 4.3 exposes it. The unit stub keeps the classes of each context
 * (frontend and preview) in memory; Elementor keeps them in e_global_class posts.
 */
final class Global_Classes_Repository {
	public const CONTEXT_FRONTEND = 'frontend';
	public const CONTEXT_PREVIEW  = 'preview';

	/** @var array<string, array{items: array<string, array<string, mixed>>, order: list<string>}> */
	private static array $store = [];

	private string $context = self::CONTEXT_FRONTEND;

	/** @var array{items: array<string, array<string, mixed>>, order: list<string>}|null */
	private ?array $cache = null;

	private static function defaults(): array {
		return [ 'items' => [ 'cls_contract' => [ 'id' => 'cls_contract', 'label' => 'Contract', 'type' => 'class', 'variants' => [ [ 'meta' => [ 'breakpoint' => 'desktop', 'state' => null ], 'props' => [] ] ] ] ], 'order' => [ 'cls_contract' ] ];
	}

	/** @internal Tests only: back to the single contract class in both contexts. */
	public static function reset(): void {
		self::$store = [ self::CONTEXT_FRONTEND => self::defaults(), self::CONTEXT_PREVIEW => self::defaults() ];
	}

	public static function make(): self { return new self(); }

	public function set_preview( bool $is_preview = true ): self {
		$this->context = $is_preview ? self::CONTEXT_PREVIEW : self::CONTEXT_FRONTEND;
		$this->cache   = null;
		return $this;
	}

	/** @return array{items: array<string, array<string, mixed>>, order: list<string>} */
	private function state(): array {
		if ( [] === self::$store ) {
			self::reset();
		}
		return self::$store[ $this->context ];
	}

	/** The answer is cached on the instance like Elementor's; $force reads the storage again. */
	public function all( bool $force = false ): object {
		if ( $force || null === $this->cache ) {
			$this->cache = $this->state();
		}
		return new class( $this->cache['items'], $this->cache['order'] ) {
			public function __construct( private array $items, private array $order ) {}
			public function get_items(): array { return $this->items; }
			public function get_order(): array { return $this->order; }
			public function get_ordered_items(): array { return array_values( array_intersect_key( $this->items, array_flip( $this->order ) ) ); }
		};
	}

	/** @return array<string, string> */
	public function all_labels(): array { return array_map( static fn( array $item ): string => (string) $item['label'], $this->state()['items'] ); }
	/** @return array<string, array<string, mixed>> */
	public function get_by_ids( array $ids ): array { return array_intersect_key( $this->state()['items'], array_flip( $ids ) ); }
	/** @return list<string> */
	public function get_order(): array { return $this->state()['order']; }
	/** @return array<string, mixed>|null */
	public function get( string $id ): ?array { return $this->state()['items'][ $id ] ?? null; }

	/** @param array<string, array<string, mixed>> $items @param list<string> $order */
	public function put( array $items, array $order ): void {
		$this->state();
		self::$store[ $this->context ] = [ 'items' => $items, 'order' => array_values( $order ) ];
		// The cache of this instance is deliberately kept, as Elementor's is.
	}

	public function apply_changes( array $items, array $changes, array $order ): void {
		$state = $this->state();
		$all   = array_replace( $state['items'], $items );
		foreach ( (array) ( $changes['deleted'] ?? [] ) as $id ) {
			unset( $all[ $id ] );
		}
		self::$store[ $this->context ] = [ 'items' => $all, 'order' => array_values( $order ) ];
	}
}
namespace Elementor\Modules\Variables\Storage;

final class Variables_Repository {
	public function __construct( public object $kit ) {}
}

namespace Elementor\Modules\Variables\Services\Batch_Operations;

final class Batch_Processor {}

namespace Elementor\Modules\Variables\Services;

final class Variables_Service {
	/** @var array<string, array<string, mixed>> */
	private static array $items = [
		'var_contract' => [ 'label' => 'Contract', 'type' => 'global-color-variable', 'value' => '#111111' ],
	];
	/** @var list<string> Fields the stored variable loses, to simulate a runtime that does not keep what was written. */
	public static array $drop_fields = [];
	public function __construct( object $repository, object $batch ) {}
	/** @return array<string, array<string, mixed>> */
	public function get_variables_list(): array { return self::$items; }
	/** @param array<string, mixed> $data @return array<string, mixed> */
	public function create( array $data ): array { $id = 'var_' . count( self::$items ); self::$items[ $id ] = array_diff_key( $data, array_flip( self::$drop_fields ) ); self::persist(); return [ 'variable' => array_merge( [ 'id' => $id ], $data ) ]; }
	/** @param array<string, mixed> $data @return array<string, mixed> */
	public function update( string $id, array $data ): array { if ( ! isset( self::$items[ $id ] ) ) { throw new \RuntimeException( 'Variable not found.' ); } self::$items[ $id ] = array_replace( self::$items[ $id ], array_diff_key( $data, array_flip( self::$drop_fields ) ) ); self::persist(); return [ 'variable' => array_merge( [ 'id' => $id ], self::$items[ $id ] ) ]; }
	/** @return array<string, mixed> */
	public function delete( string $id ): array { if ( ! isset( self::$items[ $id ] ) ) { throw new \RuntimeException( 'Variable not found.' ); } $gone = self::$items[ $id ]; unset( self::$items[ $id ] ); self::persist(); return [ 'variable' => array_merge( [ 'id' => $id ], $gone ) ]; }
	/** @internal Tests only: back to the single contract variable, optionally stored in the kit meta. */
	public static function reset( bool $persist = false ): void { self::$drop_fields = []; self::$items = [ 'var_contract' => [ 'label' => 'Contract', 'type' => 'global-color-variable', 'value' => '#111111' ] ]; if ( $persist ) { self::persist(); } }
	/** Elementor keeps the variables in the kit meta; the unit bootstrap keeps that meta in its fake posts. */
	private static function persist(): void { if ( function_exists( 'update_post_meta' ) ) { update_post_meta( 4, '_elementor_global_variables', [ 'data' => self::$items, 'version' => 1 ] ); } }
}

namespace Elementor\Modules\Promotions\Widgets;

/** The placeholder Elementor registers in place of a Pro widget when Pro is not active. */
class Pro_Widget_Promotion extends \Elementor\Widget_Base {
	/** @param array<string, mixed>|null $args */
	public function __construct( private array $data = [], private ?array $args = null ) {}

	public function get_name(): string {
		return (string) ( $this->args['widget_name'] ?? '' );
	}

	public function get_title(): string {
		return (string) ( $this->args['widget_title'] ?? '' );
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'general', 'pro-elements' ];
	}

	public function show_in_panel(): bool {
		return false;
	}

	public function hide_on_search(): bool {
		return true;
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [];
	}
}
