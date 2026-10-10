<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\FSE\TemplateStore;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockMarkup;

/**
 * Abstract base for FSE template write abilities.
 *
 * Subclasses provide the post type and ability slug; this class owns
 * the shared write logic: slug validation, confirmation-token gate,
 * backup-before-update, wp_insert_post / wp_update_post, and
 * existing-post lookup.
 *
 * The `write()` method is final — the safety envelope (backup, token,
 * permission) cannot be bypassed by subclasses. Subclasses customise
 * behavior through the two abstract hooks below.
 *
 * @stonewright-status stable
 */
abstract class AbstractTemplateWriter extends AbilityKernel {

	use ConfirmationGuard;

	// -------------------------------------------------------------------------
	// Abstract hooks — subclasses must implement these two
	// -------------------------------------------------------------------------

	/**
	 * WordPress post type this writer targets.
	 * E.g. 'wp_template' or 'wp_template_part'.
	 */
	abstract protected function post_type(): string;

	/**
	 * The ability slug used for audit log entries.
	 * Must match the value returned by name().
	 */
	abstract protected function ability_slug(): string;

	// -------------------------------------------------------------------------
	// Shared write logic (final — cannot be overridden)
	// -------------------------------------------------------------------------

	/**
	 * Execute the template write, applying the full safety envelope.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	final protected function write( array $args ): array|\WP_Error {
		$slug    = (string) ( $args['template_slug'] ?? '' );
		$theme   = (string) ( $args['theme'] ?? '' );
		$content = BlockMarkup::sanitize( (string) ( $args['content'] ?? '' ) );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		$desc = (string) ( $args['description'] ?? '' );

		if ( '' === $slug || ! preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
			return $this->error( 'invalid_slug', __( 'template_slug must match ^[a-z0-9_-]+$.', 'stonewright' ) );
		}
		if ( '' === $theme || ! preg_match( '/^[a-z0-9_-]+$/', $theme ) ) {
			return new \WP_Error(
				'stonewright_fse_invalid_theme',
				__( 'Theme slug must match [a-z0-9_-]+.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		// ── Confirmation token ───────────────────────────────────────────────
		$verify_args = array_filter(
			$args,
			static fn( string $k ) => 'confirmation_token' !== $k,
			ARRAY_FILTER_USE_KEY
		);
		$token_error = $this->confirmation_token_error( $args, $verify_args );
		if ( null !== $token_error ) {
			return $token_error;
		}

		// ── Find existing post ───────────────────────────────────────────────
		$post_type = $this->post_type();
		$area      = $this->area_term( $args );
		$existing  = $this->find_existing( $slug, $theme, $post_type );

		if ( null !== $existing ) {
			// Backup BEFORE write (AGENTS.md rule 3).
			$snapshot_id = Backup::snapshot_post( (int) $existing->ID );

			$result = wp_update_post(
				wp_slash(
					[
						'ID'           => (int) $existing->ID,
						'post_content' => $content,
						'post_excerpt' => $desc,
					]
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			TemplateStore::normalize( $existing, $slug, $theme, $area );
			return [
				'post_id'     => (int) $existing->ID,
				'snapshot_id' => $snapshot_id,
				'action'      => 'updated',
			];
		}

		// ── Insert new post ──────────────────────────────────────────────────
		// post_name is the slug and the wp_theme term names the theme: the shape
		// get_block_template() resolves.
		$post_id = TemplateStore::insert(
			$post_type,
			$slug,
			$theme,
			[
				'post_content' => $content,
				'post_excerpt' => $desc,
			],
			$area
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return [
			'post_id'     => $post_id,
			'snapshot_id' => null,
			'action'      => 'created',
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Template part area stored as the `wp_template_part_area` term. Empty for
	 * post types without areas.
	 *
	 * @param array<string, mixed> $args
	 */
	protected function area_term( array $args ): string {
		return '';
	}

	/**
	 * @return object|\WP_Post|null
	 */
	protected function find_existing( string $slug, string $theme, string $post_type ): ?object {
		return TemplateStore::find( $slug, $theme, $post_type );
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_fse();
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}

	public function category(): string {
		return 'fse';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => [ 'string', 'null' ] ],
				'action'      => [ 'type' => 'string', 'enum' => [ 'created', 'updated' ] ],
			],
			'required' => [ 'post_id', 'snapshot_id', 'action' ],
		];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'template_slug'      => [
					'type'        => 'string',
					'pattern'     => '^[a-z0-9_-]+$',
					'description' => 'Template slug (lowercase alphanumeric, hyphens, underscores).',
				],
				'theme'              => [
					'type'        => 'string',
					'description' => 'Theme stylesheet slug.',
				],
				'content'            => [
					'type'        => 'string',
					'description' => 'Block markup content.',
				],
				'description'        => [
					'type'        => 'string',
					'description' => 'Optional template description.',
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode.',
				],
			],
			'required' => [ 'template_slug', 'theme', 'content' ],
		];
	}
}
