<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockMarkup;

/**
 * Create, read, and update wp_navigation posts and emit a core/navigation ref spec.
 *
 * Classic Menu/* abilities stay for nav_menu terms. This ability owns the FSE
 * wp_navigation CPT used by core/navigation {ref}.
 *
 * @stonewright-status stable
 */
final class Navigation extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/fse-navigation';
	}

	public function label(): string {
		return __( 'FSE navigation', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates, reads, or updates a wp_navigation post and returns a core/navigation ref block for wiring. Classic menus stay on stonewright/menu-*.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'action'             => [
					'type'    => 'string',
					'enum'    => [ 'create', 'read', 'update' ],
					'default' => 'read',
				],
				'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
				'title'              => [ 'type' => 'string', 'maxLength' => 255 ],
				'content'            => [ 'type' => 'string' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'action' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'integer' ],
				'title'       => [ 'type' => 'string' ],
				'content'     => [ 'type' => 'string' ],
				'snapshot_id' => [ 'type' => [ 'string', 'null' ] ],
				'block'       => [ 'type' => 'object' ],
			],
			'required'   => [ 'id', 'block' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_fse();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$action = (string) ( $args['action'] ?? 'read' );
				if ( in_array( $action, [ 'create', 'update' ], true ) ) {
					$verify = $args;
					unset( $verify['confirmation_token'] );
					$token_error = $this->confirmation_token_error( $args, $verify );
					if ( null !== $token_error ) {
						return $token_error;
					}
				}

				return match ( $action ) {
					'create' => $this->create( $args ),
					'update' => $this->update( $args ),
					default  => $this->read( $args ),
				};
			}
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create( array $args ) {
		$title   = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
		$content = BlockMarkup::sanitize( (string) ( $args['content'] ?? '' ) );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( '' === $title ) {
			return $this->error( 'missing_title', __( 'Navigation title is required.', 'stonewright' ) );
		}

		$id = wp_insert_post(
			[
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$snapshot_id = Backup::snapshot_post( (int) $id );
		return $this->payload( (int) $id, $snapshot_id );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update( array $args ) {
		$post = $this->require_navigation( (int) ( $args['id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$payload = [ 'ID' => (int) $post->ID ];
		if ( isset( $args['title'] ) ) {
			$payload['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$content = BlockMarkup::sanitize( (string) $args['content'] );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$payload['post_content'] = $content;
		}

		$snapshot_id = Backup::snapshot_post( (int) $post->ID );
		$result      = wp_update_post( $payload, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->payload( (int) $post->ID, $snapshot_id );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function read( array $args ) {
		$post = $this->require_navigation( (int) ( $args['id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return $this->payload( (int) $post->ID, null );
	}

	/**
	 * @return object|\WP_Error
	 */
	private function require_navigation( int $id ) {
		if ( $id <= 0 ) {
			return $this->error( 'not_found', __( 'Navigation not found.', 'stonewright' ) );
		}
		$post = get_post( $id );
		if ( ! $post || 'wp_navigation' !== (string) ( $post->post_type ?? '' ) ) {
			return $this->error( 'not_found', __( 'Navigation not found.', 'stonewright' ) );
		}
		return $post;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload( int $id, ?string $snapshot_id ): array {
		$post = get_post( $id );
		return [
			'id'          => $id,
			'title'       => $post ? (string) $post->post_title : '',
			'content'     => $post ? (string) $post->post_content : '',
			'snapshot_id' => $snapshot_id,
			'block'       => [
				'name'        => 'core/navigation',
				'attrs'       => [ 'ref' => $id ],
				'innerBlocks' => [],
			],
		];
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}
