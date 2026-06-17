<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ContentModel;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * One-call content model + Elementor Loop Grid setup for editable sections.
 *
 * @stonewright-status experimental
 */
final class CptAcfLoopGridFlow extends AbilityKernel {

	public function name(): string {
		return 'stonewright/content-model-loop-grid-flow';
	}

	public function label(): string {
		return __( 'Content Model: CPT, ACF, Loop Grid flow', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a CPT UI-style post type config, ACF field contract, repeated CPT rows, optional Elementor loop-item template, and Loop Grid widget settings in one flow.', 'stonewright' );
	}

	public function category(): string {
		return 'content-model';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_type'     => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => [
						'slug'     => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ],
						'singular' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ],
						'plural'   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ],
						'public'   => [ 'type' => 'boolean', 'default' => true ],
						'supports' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
					'required'             => [ 'slug', 'singular', 'plural' ],
				],
				'fields'        => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'name'     => [ 'type' => 'string', 'minLength' => 1 ],
							'label'    => [ 'type' => 'string', 'minLength' => 1 ],
							'type'     => [ 'type' => 'string', 'default' => 'text' ],
							'required' => [ 'type' => 'boolean', 'default' => false ],
						],
						'required'             => [ 'name', 'label' ],
					],
				],
				'items'         => [
					'type'  => 'array',
					'items' => [ 'type' => 'object', 'additionalProperties' => true ],
				],
				'loop_template' => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => [
						'title'        => [ 'type' => 'string' ],
						'spec'         => [ 'type' => 'object' ],
						'link_to_post' => [ 'type' => 'boolean', 'default' => true ],
					],
				],
				'grid'          => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => [
						'columns'        => [ 'type' => 'integer', 'minimum' => 1 ],
						'posts_per_page' => [ 'type' => 'integer', 'minimum' => 1 ],
					],
				],
				'dry_run'       => [ 'type' => 'boolean', 'default' => false ],
			],
			'required'             => [ 'post_type', 'fields', 'items' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                 => [ 'type' => 'boolean' ],
				'dry_run'            => [ 'type' => 'boolean' ],
				'post_type'          => [ 'type' => 'object' ],
				'acf'                => [ 'type' => 'object' ],
				'content'            => [ 'type' => 'object' ],
				'loop_template'      => [ 'type' => 'object' ],
				'loop_grid_widget'   => [ 'type' => 'object' ],
				'repair_hints'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'next_required_call' => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'dry_run', 'post_type', 'acf', 'content', 'loop_grid_widget', 'repair_hints', 'next_required_call' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_type = (array) ( $args['post_type'] ?? [] );
				$slug      = sanitize_key( (string) ( $post_type['slug'] ?? '' ) );
				if ( '' === $slug ) {
					return $this->error( 'invalid_post_type_slug', __( 'post_type.slug is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$singular = sanitize_text_field( (string) ( $post_type['singular'] ?? $slug ) );
				$plural   = sanitize_text_field( (string) ( $post_type['plural'] ?? $singular ) );
				$fields   = self::normalise_fields( (array) ( $args['fields'] ?? [] ), $slug );
				$items    = self::normalise_items( (array) ( $args['items'] ?? [] ) );
				$grid     = (array) ( $args['grid'] ?? [] );
				$loop_template_args = (array) ( $args['loop_template'] ?? [] );
				$dry_run  = ! empty( $args['dry_run'] );

				$post_type_payload = self::post_type_payload( $slug, $singular, $plural, $post_type );
				$acf_payload       = self::acf_payload( $slug, $singular, $fields );
				$loop_grid_widget  = self::loop_grid_widget( 0, $slug, $grid );

				if ( $dry_run ) {
					return self::response(
						false,
						true,
						array_merge( $post_type_payload, [ 'registered_runtime' => false ] ),
						$acf_payload,
						[ 'ok' => false, 'created' => 0, 'updated' => 0, 'failed' => 0, 'items' => [] ],
						[ 'template_id' => 0, 'created' => false, 'link_to_post' => ! empty( $loop_template_args['link_to_post'] ) ],
						$loop_grid_widget,
						self::repair_hints(),
						'stonewright/content-model-loop-grid-flow'
					);
				}

				self::persist_cptui_post_type( $slug, $post_type_payload );
				self::register_runtime_post_type( $slug, $singular, $plural, $post_type_payload );
				self::persist_acf_contract( $slug, $acf_payload );

				$content = ( new BulkUpsertPosts() )->execute(
					[
						'post_type' => $slug,
						'items'     => $items,
					]
				);
				if ( is_wp_error( $content ) ) {
					return $content;
				}

				$loop_template = self::create_loop_template( $loop_template_args, $slug );
				if ( is_wp_error( $loop_template ) ) {
					return $loop_template;
				}

				$loop_grid_widget = self::loop_grid_widget( (int) $loop_template['template_id'], $slug, $grid );

				return self::response(
					true,
					false,
					array_merge( $post_type_payload, [ 'registered_runtime' => true ] ),
					$acf_payload,
					$content,
					$loop_template,
					$loop_grid_widget,
					self::repair_hints(),
					'stonewright/elementor-v3-batch-mutate'
				);
			}
		);
	}

	/**
	 * @param array<int, mixed> $fields
	 * @return list<array<string, mixed>>
	 */
	private static function normalise_fields( array $fields, string $post_type ): array {
		$out = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name = sanitize_key( (string) ( $field['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$out[] = [
				'key'      => 'field_stonewright_' . $post_type . '_' . $name,
				'name'     => $name,
				'label'    => sanitize_text_field( (string) ( $field['label'] ?? $name ) ),
				'type'     => sanitize_key( (string) ( $field['type'] ?? 'text' ) ),
				'required' => ! empty( $field['required'] ),
			];
		}
		return $out;
	}

	/**
	 * @param array<int, mixed> $items
	 * @return list<array<string, mixed>>
	 */
	private static function normalise_items( array $items ): array {
		$out = [];
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $post_type
	 * @return array<string, mixed>
	 */
	private static function post_type_payload( string $slug, string $singular, string $plural, array $post_type ): array {
		$supports = array_values( array_filter( array_map( 'strval', (array) ( $post_type['supports'] ?? [ 'title', 'editor', 'thumbnail', 'excerpt' ] ) ) ) );
		return [
			'slug'               => $slug,
			'name'               => $slug,
			'label'              => $plural,
			'singular_label'     => $singular,
			'public'             => array_key_exists( 'public', $post_type ) ? (bool) $post_type['public'] : true,
			'show_in_rest'       => true,
			'has_archive'        => (bool) ( $post_type['has_archive'] ?? true ),
			'supports'           => $supports,
			'registered_runtime' => false,
		];
	}

	/**
	 * @param list<array<string, mixed>> $fields
	 * @return array<string, mixed>
	 */
	private static function acf_payload( string $post_type, string $singular, array $fields ): array {
		return [
			'field_group_key' => 'group_stonewright_' . $post_type,
			'title'           => $singular . ' Fields',
			'location'        => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => $post_type,
					],
				],
			],
			'field_count'     => count( $fields ),
			'fields'          => $fields,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function persist_cptui_post_type( string $slug, array $payload ): void {
		$config = get_option( 'cptui_post_types', [] );
		if ( ! is_array( $config ) ) {
			$config = [];
		}
		$config[ $slug ] = $payload;
		update_option( 'cptui_post_types', $config, false );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function register_runtime_post_type( string $slug, string $singular, string $plural, array $payload ): void {
		$args = [
			'labels'       => [
				'name'          => $plural,
				'singular_name' => $singular,
			],
			'public'       => (bool) $payload['public'],
			'show_in_rest' => true,
			'has_archive'  => (bool) $payload['has_archive'],
			'supports'     => (array) $payload['supports'],
		];

		if ( function_exists( 'register_post_type' ) ) {
			register_post_type( $slug, $args );
		}

		if ( isset( $GLOBALS['stonewright_test_post_types'] ) && is_array( $GLOBALS['stonewright_test_post_types'] ) ) {
			$GLOBALS['stonewright_test_post_types'][ $slug ] = (object) [
				'name' => $slug,
				'cap'  => (object) [
					'create_posts'  => 'edit_posts',
					'publish_posts' => 'publish_posts',
				],
			];
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function persist_acf_contract( string $slug, array $payload ): void {
		if ( function_exists( 'acf_update_field_group' ) ) {
			acf_update_field_group(
				[
					'key'      => $payload['field_group_key'],
					'title'    => $payload['title'],
					'fields'   => $payload['fields'],
					'location' => $payload['location'],
				]
			);
		}

		$groups          = get_option( 'stonewright_acf_field_groups', [] );
		$groups          = is_array( $groups ) ? $groups : [];
		$groups[ $slug ] = $payload;
		update_option( 'stonewright_acf_field_groups', $groups, false );
	}

	/**
	 * @param array<string, mixed> $loop_template
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function create_loop_template( array $loop_template, string $post_type ): array|\WP_Error {
		if ( [] === $loop_template || ! isset( $loop_template['spec'] ) ) {
			return [
				'template_id'  => 0,
				'created'      => false,
				'link_to_post' => false,
				'note'         => 'No loop_template.spec provided; loop_grid_widget returned without template_id.',
			];
		}

		$normalized = Validator::validate( (array) $loop_template['spec'] );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$title = sanitize_text_field( (string) ( $loop_template['title'] ?? ( $post_type . ' Loop Item' ) ) );
		$id    = TemplateStore::create( $title, 'loop-item' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$diagnostics = [];
		$tree        = Renderer::render( $normalized, $diagnostics );
		$snapshot_id = Backup::snapshot_post( (int) $id );
		if ( ! ElementorData::write( (int) $id, $tree ) ) {
			return new \WP_Error( 'stonewright_loop_template_write_failed', __( 'Could not write Elementor loop-item template data.', 'stonewright' ), [ 'status' => 500 ] );
		}

		$link_to_post = ! array_key_exists( 'link_to_post', $loop_template ) || (bool) $loop_template['link_to_post'];
		update_post_meta( (int) $id, '_stonewright_loop_card_link_to_post', $link_to_post ? '1' : '0' );

		return [
			'template_id'   => (int) $id,
			'created'       => true,
			'snapshot_id'   => $snapshot_id,
			'element_count' => count( ElementorData::flatten( $tree ) ),
			'link_to_post'  => $link_to_post,
			'diagnostics'   => $diagnostics,
		];
	}

	/**
	 * @param array<string, mixed> $grid
	 * @return array<string, mixed>
	 */
	private static function loop_grid_widget( int $template_id, string $post_type, array $grid ): array {
		return [
			'widgetType' => 'loop-grid',
			'settings'   => [
				'template_id'    => $template_id,
				'post_type'      => $post_type,
				'posts_per_page' => max( 1, (int) ( $grid['posts_per_page'] ?? 6 ) ),
				'columns'        => max( 1, (int) ( $grid['columns'] ?? 3 ) ),
				'pagination'     => (string) ( $grid['pagination'] ?? 'load_more' ),
			],
		];
	}

	/**
	 * @return list<string>
	 */
	private static function repair_hints(): array {
		return [
			'Use loop_grid_widget as the widget settings payload for the target Elementor archive/listing section.',
			'After writing the section, verify the Loop Grid front end with a real post URL and logged-out browser viewport.',
			'If ACF is inactive, Stonewright still stores the field contract; activate ACF before expecting wp-admin field UI.',
		];
	}

	/**
	 * @param array<string, mixed> $post_type
	 * @param array<string, mixed> $acf
	 * @param array<string, mixed> $content
	 * @param array<string, mixed> $loop_template
	 * @param array<string, mixed> $loop_grid_widget
	 * @param list<string> $repair_hints
	 * @return array<string, mixed>
	 */
	private static function response( bool $ok, bool $dry_run, array $post_type, array $acf, array $content, array $loop_template, array $loop_grid_widget, array $repair_hints, string $next_ability ): array {
		return [
			'ok'                 => $ok,
			'dry_run'            => $dry_run,
			'post_type'          => $post_type,
			'acf'                => $acf,
			'content'            => $content,
			'loop_template'      => $loop_template,
			'loop_grid_widget'   => $loop_grid_widget,
			'repair_hints'       => $repair_hints,
			'next_required_call' => [
				'ability'  => $next_ability,
				'mcp_tool' => str_replace( '/', '-', $next_ability ),
				'why'      => $dry_run
					? 'Run the same flow with dry_run=false after checking the plan.'
					: 'Insert the returned loop_grid_widget into the Elementor listing/archive page.',
			],
		];
	}
}
