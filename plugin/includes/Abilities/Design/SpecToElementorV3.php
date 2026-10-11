<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignSpec\AssetReferences;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\ElementorTransactionRunner;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SpecToElementorV3 extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/design-spec-to-elementor-v3';
	}

	public function label(): string {
		return __( 'Render spec to Elementor V3', 'stonewright' );
	}

	public function description(): string {
		return __( 'Renders a Stonewright Design Spec into Elementor V3 element JSON and writes it to a post as one transaction: snapshot, write, read back, restore on a mismatch. Remote assets are copied to the media library only for a user who may upload files (and, in production-safe mode, with a confirmation token). The page shell (canvas template, hidden title) is set up on an empty page by default; see page_shell.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec'    => [ 'type' => 'object' ],
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'replace' => [ 'type' => 'boolean', 'default' => true ],
				'page_shell' => [
					'type'        => 'boolean',
					'description' => 'Set the full-width Elementor canvas template (elementor_header_footer) and hide the theme title on the page. When left out, the shell is set up only on a page that has no Elementor content yet; a page that already has some keeps its template and title settings. true forces it, false skips it.',
				],
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'tree'        => [ 'type' => 'array' ],
				'count'       => [ 'type' => 'integer' ],
				'diagnostics' => [ 'type' => 'array' ],
				'sideloaded_assets' => [ 'type' => 'array' ],
				'page_shell'  => [
					'type'       => 'object',
					'properties' => [
						'applied' => [ 'type' => 'boolean' ],
						'warning' => [ 'type' => 'string' ],
					],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! empty( $args['post_id'] ) ) {
			return Permissions::edit_post( (int) $args['post_id'] );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$normalized = Validator::validate( (array) $args['spec'] );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}

				if ( empty( $args['post_id'] ) || ! empty( $args['dry_run'] ) ) {
					$resolved    = AssetReferences::resolve( $normalized, false );
					$normalized  = $resolved['spec'];
					$diagnostics = [];
					$tree        = Renderer::render( $normalized, $diagnostics );

					return [
						'post_id'           => isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
						'snapshot_id'       => '',
						'tree'              => $tree,
						'count'             => count( ElementorData::flatten( $tree ) ),
						'diagnostics'       => $diagnostics,
						'sideloaded_assets' => [],
					];
				}

				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Target post not found.', 'stonewright' ) );
				}

				$replace     = ! isset( $args['replace'] ) || (bool) $args['replace'];
				$verify_args = array_filter(
					$args,
					static fn( string $key ): bool => 'confirmation_token' !== $key,
					ARRAY_FILTER_USE_KEY
				);
				if ( $replace ) {
					$token_error = $this->confirmation_token_error( $args, $verify_args );
					if ( null !== $token_error ) {
						return $token_error;
					}
				}

				// Fetching a remote file into the media library needs the right to upload files and, in
				// production-safe mode, a token. Without both, the assets are left where they are.
				$sideload = false;
				$notes    = [];
				if ( AssetReferences::has_remote( $normalized ) ) {
					if ( ! Permissions::upload_files() ) {
						$notes[] = $this->asset_note( __( 'Remote assets were not copied to the media library because you may not upload files.', 'stonewright' ) );
					} elseif ( Permissions::is_production_safe() && '' === (string) ( $args['confirmation_token'] ?? '' ) ) {
						$notes[] = $this->asset_note( __( 'Remote assets were not copied to the media library because the call carries no confirmation token.', 'stonewright' ) );
					} else {
						$token_error = $this->confirmation_token_error( $args, $verify_args );
						if ( null !== $token_error ) {
							return $token_error;
						}
						$sideload = true;
					}
				}

				$resolved          = AssetReferences::resolve( $normalized, $sideload );
				$normalized        = $resolved['spec'];
				$sideloaded_assets = $resolved['sideloaded_assets'];
				$validated         = Validator::validate( $normalized );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				$diagnostics = [];
				$tree        = Renderer::render( $validated, $diagnostics );
				$diagnostics = array_merge( $diagnostics, $notes );

				// The write is one transaction: snapshot, write, read back, restore on a mismatch. An append
				// builds its tree from what the page holds now, so it states what it read and the write is
				// refused when the page changed in between.
				$before    = ElementorData::read( $post_id );
				$next_tree = $replace ? $tree : array_merge( $before, $tree );
				$shell     = $this->wants_page_shell( $args, $before );

				$txn = ElementorTransactionRunner::run_full_tree(
					$post_id,
					$next_tree,
					[ 'min_elements' => max( 1, (int) ceil( count( ElementorData::flatten( $next_tree ) ) * 0.5 ) ) ],
					true,
					[
						'allow_html_widget' => $args['allow_html_widget'] ?? false,
						'custom_code_grant' => $args['custom_code_grant'] ?? '',
					],
					$replace ? '' : TreeHasher::hash( $before )
				);
				if ( is_wp_error( $txn ) ) {
					return 'stonewright_transaction_snapshot_failed' === $txn->get_error_code() ? $this->backup_failed_error() : $txn;
				}

				return [
					'post_id'           => $post_id,
					'snapshot_id'       => (string) ( $txn['snapshot_id'] ?? '' ),
					'tree'              => $next_tree,
					'count'             => count( ElementorData::flatten( $next_tree ) ),
					'diagnostics'       => $diagnostics,
					'sideloaded_assets' => $sideloaded_assets,
					'page_shell'        => $this->apply_page_shell_settings( $post_id, $shell ),
				];
			}
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function asset_note( string $message ): array {
		return [
			'code'    => 'assets_not_sideloaded',
			'message' => $message,
		];
	}

	/**
	 * Whether the page shell (full-width Elementor canvas template and a hidden theme title) is applied.
	 * An explicit page_shell decides. Without it the shell is set up only for a page that has no Elementor
	 * content yet, so a page someone already built keeps the template and title settings it has.
	 *
	 * @param array<string, mixed>             $args
	 * @param array<int, array<string, mixed>> $existing The tree the page held before this write.
	 */
	private function wants_page_shell( array $args, array $existing ): bool {
		if ( isset( $args['page_shell'] ) ) {
			return (bool) $args['page_shell'];
		}
		return [] === $existing;
	}

	/**
	 * Sets the page shell and reads it back.
	 *
	 * @return array{applied: bool, warning: string}
	 */
	private function apply_page_shell_settings( int $post_id, bool $wanted ): array {
		if ( ! $wanted ) {
			return [
				'applied' => false,
				'warning' => '',
			];
		}
		$post = get_post( $post_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return [
				'applied' => false,
				'warning' => __( 'The page shell applies to pages only.', 'stonewright' ),
			];
		}

		$page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( ! is_array( $page_settings ) ) {
			$page_settings = [];
		}
		$page_settings['hide_title'] = 'yes';

		update_post_meta( $post_id, '_elementor_page_settings', $page_settings );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_header_footer' );

		// update_post_meta() also returns false for a value that did not change, so the result is read back.
		$stored = get_post_meta( $post_id, '_elementor_page_settings', true );
		$landed = is_array( $stored ) && 'yes' === ( $stored['hide_title'] ?? '' )
			&& 'elementor_header_footer' === (string) get_post_meta( $post_id, '_wp_page_template', true );

		return [
			'applied' => $landed,
			'warning' => $landed ? '' : __( 'The page template and the hidden title could not be stored; the design itself was written.', 'stonewright' ),
		];
	}
}
