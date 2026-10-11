<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\FSE\TemplateStore;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockMarkup;

/**
 * Compatibility create wrapper around the FSE template-part write envelope.
 *
 * Content is sanitized via BlockMarkup. Permission matches write-*:
 * can_manage_fse(). Confirmation is required in production-safe mode.
 * Prefer {@see WriteTemplatePart} for the canonical slug/theme contract.
 *
 * @stonewright-status stable
 */
final class CreateTemplatePart extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/fse-create-template-part';
	}

	public function label(): string {
		return __( 'Create template part', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a wp_template_part (header, footer, sidebar, uncategorized) in the active theme. Refuses a slug that already exists there (the error names the existing part). Compatibility wrapper around fse-write-template-part sanitization and confirmation.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'slug'               => [ 'type' => 'string', 'maxLength' => 200 ],
				'title'              => [ 'type' => 'string', 'maxLength' => 255 ],
				'content'            => [ 'type' => 'string' ],
				'area'               => [ 'type' => 'string', 'enum' => [ 'header', 'footer', 'sidebar', 'uncategorized' ], 'default' => 'uncategorized' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'slug', 'title', 'content' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'string' ],
				'post_id' => [ 'type' => 'integer' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_fse();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$content = BlockMarkup::sanitize( (string) $args['content'] );
				if ( is_wp_error( $content ) ) {
					return $content;
				}

				$slug  = sanitize_title( (string) $args['slug'] );
				$area  = isset( $args['area'] ) ? (string) $args['area'] : 'uncategorized';
				$theme = get_stylesheet();

				// Core finds a part by theme and slug, so a second part with the same slug would be a different
				// post that is never returned. A create does not overwrite: say where the part is.
				$existing = TemplateStore::find( $slug, $theme, TemplateStore::TEMPLATE_PART );
				if ( null !== $existing ) {
					return $this->error(
						'template_part_exists',
						__( 'A template part with this slug already exists in the active theme. Use stonewright/fse-write-template-part or stonewright/fse-update-template to change it.', 'stonewright' ),
						[
							'status'  => 409,
							'id'      => $theme . '//' . $slug,
							'post_id' => (int) $existing->ID,
						]
					);
				}

				$post_id = TemplateStore::insert(
					TemplateStore::TEMPLATE_PART,
					$slug,
					$theme,
					[
						'post_title'   => sanitize_text_field( (string) $args['title'] ),
						'post_content' => $content,
					],
					$area
				);
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				return [
					'id'      => $theme . '//' . $slug,
					'post_id' => $post_id,
				];
			}
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}
