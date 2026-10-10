<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SaveTemplate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-save-template';
	}

	public function label(): string {
		return __( 'Save Elementor template', 'stonewright' );
	}

	public function description(): string {
		return __( 'Saves the current page tree as an Elementor library template (section, page, container).', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
				'title'         => [ 'type' => 'string', 'maxLength' => 255 ],
				'template_type' => [ 'type' => 'string', 'enum' => [ 'page', 'section', 'container', 'header', 'footer' ], 'default' => 'section' ],
			],
			'required'             => [ 'post_id', 'title' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'template_id'   => [ 'type' => 'integer' ],
				'template_type' => [ 'type' => 'string' ],
				'write_receipt' => [ 'type' => 'object' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id ) && Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$post_id = (int) $args['post_id'];
				$tree    = ElementorData::read( $post_id );
				if ( empty( $tree ) ) {
					return $this->error( 'empty_page', __( 'Source page has no Elementor data.', 'stonewright' ) );
				}

				$template_type = (string) ( $args['template_type'] ?? 'section' );
				if ( ! self::is_registered_document_type( $template_type ) ) {
					return $this->error(
						'invalid_template_type',
						sprintf(
							/* translators: %s: rejected template_type value */
							__( 'Elementor has no document type "%s" on this site, so no library template was created.', 'stonewright' ),
							$template_type
						),
						[ 'status' => 400, 'template_type' => $template_type ]
					);
				}

				$id = wp_insert_post(
					wp_slash(
						[
							'post_title'  => sanitize_text_field( (string) $args['title'] ),
							'post_status' => 'publish',
							'post_type'   => 'elementor_library',
						]
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					return $id;
				}

				// Library templates are recognised through their type meta and the
				// type term; the edit mode, version and data are stored by ElementorData::write().
				update_post_meta( (int) $id, '_elementor_template_type', $template_type );
				wp_set_object_terms( (int) $id, $template_type, 'elementor_library_type', false );
				if ( ! ElementorData::write( (int) $id, $tree ) ) {
					wp_delete_post( (int) $id, true );
					return $this->error( 'write_failed', __( 'Could not save Elementor template data.', 'stonewright' ) );
				}
				if ( $template_type !== (string) get_post_meta( (int) $id, '_elementor_template_type', true ) ) {
					wp_delete_post( (int) $id, true );
					return $this->error( 'write_failed', __( 'The Elementor template type could not be read back.', 'stonewright' ) );
				}

				return [ 'template_id' => (int) $id, 'template_type' => $template_type ];
			}
		);
	}

	/**
	 * True when Elementor's document manager knows the type, or when no manager is available to ask.
	 */
	private static function is_registered_document_type( string $type ): bool {
		$plugin    = class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ? \Elementor\Plugin::$instance : null;
		$documents = is_object( $plugin ) ? ( $plugin->documents ?? null ) : null;
		if ( ! is_object( $documents ) || ! method_exists( $documents, 'get_document_type' ) ) {
			return true;
		}
		return (bool) $documents->get_document_type( $type, false );
	}
}
