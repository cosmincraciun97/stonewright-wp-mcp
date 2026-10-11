<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\PageCachePurger;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\ThemeBuilder\TemplateGuard;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * Set display conditions on an Elementor Theme Builder template.
 *
 * A condition decides where the template renders (e.g. "include / general /
 * site" = everywhere; "exclude / archive / category" = everywhere except
 * category archives). The full rule array replaces whatever was on the
 * template before — callers that want to add/remove a single rule should
 * fetch the template first via GetTemplate.
 *
 * Needs `edit_theme_options` and `edit_post` on the template. Takes a snapshot first and refuses when it
 * cannot be stored, reports the rules it overwrote, and reads the stored rules back.
 *
 * @stonewright-status stable
 */
final class SetConditions extends AbilityKernel {

	public function name(): string {
		return 'stonewright/theme-builder-set-conditions';
	}

	public function label(): string {
		return __( 'Theme Builder: Set conditions', 'stonewright' );
	}

	public function description(): string {
		return __( 'Replaces the display rules on an elementor_library template. Each rule is { type: include|exclude, name: <where>, sub_name?: <which>, sub_id?: <id> }.', 'stonewright' );
	}

	public function category(): string {
		return 'theme-builder';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'conditions'  => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'type'     => [ 'type' => 'string', 'enum' => [ 'include', 'exclude' ] ],
							'name'     => [ 'type' => 'string' ],
							'sub_name' => [ 'type' => 'string' ],
							'sub_id'   => [ 'type' => 'integer' ],
						],
						'required' => [ 'type', 'name' ],
					],
				],
			],
			'required' => [ 'template_id', 'conditions' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'template_id'         => [ 'type' => 'integer' ],
				'updated'             => [ 'type' => 'boolean' ],
				'snapshot_id'         => [ 'type' => 'string' ],
				'previous_conditions' => [ 'type' => 'array', 'description' => 'The rules this call replaced, in the stored form (e.g. include/general).' ],
				'conditions'          => [ 'type' => 'array', 'description' => 'The rules stored now, read back.' ],
				'next_step'           => [ 'type' => 'object' ],
				'cache_purge'         => [ 'type' => 'object' ],
			],
			'required'   => [ 'template_id', 'updated' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options() ? ( TemplateGuard::object_error( 'edit_post', (int) ( $args['template_id'] ?? 0 ) ) ?? true ) : false;
	}

	protected function write_precondition_error( array $args ): ?\WP_Error {
		$id     = (int) ( $args['template_id'] ?? 0 );
		$denied = TemplateGuard::execute_error( 'edit_post', $id );
		if ( null !== $denied ) {
			return $denied;
		}
		$post = get_post( $id );
		if ( ! $post || 'elementor_library' !== $post->post_type ) {
			return $this->error(
				'not_a_template',
				__( 'Post is not an elementor_library template.', 'stonewright' )
			);
		}
		return null;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$id          = (int) $args['template_id'];
				// The snapshot keeps the template as it was. Rescue is not armed: it restores the post, and the rules live in
				// an option and a meta key that a restore does not bring back, so previous_conditions is the way back.
				$snapshot_id = Backup::snapshot_post( $id, [], false );
				if ( '' === $snapshot_id ) {
					return $this->backup_failed_error();
				}
				$previous = TemplateStore::get_conditions( $id );
				if ( ! TemplateStore::set_conditions( $id, (array) $args['conditions'] ) ) {
					return $this->error(
						'write_failed',
						__( 'The display conditions were not stored as requested.', 'stonewright' ),
						[ 'status' => 500, 'previous_conditions' => $previous ]
					);
				}
				return [
					'template_id'         => $id,
					'updated'             => true,
					'snapshot_id'         => $snapshot_id,
					'previous_conditions' => $previous,
					'conditions'          => TemplateStore::get_conditions( $id ),
					'next_step'           => [
						'tool'    => 'stonewright/elementor-css-regenerate',
						'post_id' => $id,
						'why'     => 'The display rules are stored; regenerate the template CSS so the front end uses it.',
					],
					'cache_purge'         => PageCachePurger::purge_quietly( $id ),
				];
			}
		);
	}
}
