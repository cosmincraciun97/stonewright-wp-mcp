<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * Create/update a real Elementor Theme Builder template in one fast path.
 *
 * @stonewright-status experimental
 */
final class ApplyTemplate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/theme-builder-apply-template';
	}

	public function label(): string {
		return __( 'Theme Builder: Apply template', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates or updates an Elementor Theme Builder template, validates and renders a Stonewright spec, sets display conditions, clears Elementor cache, and returns live verification hints in one call.', 'stonewright' );
	}

	public function category(): string {
		return 'theme-builder';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'template_id'        => [ 'type' => 'integer', 'minimum' => 1 ],
				'title'              => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
				'template_type'      => [ 'type' => 'string', 'enum' => TemplateStore::ALLOWED_TYPES ],
				'spec'               => [ 'type' => 'object' ],
				'conditions'         => [
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
						'required'             => [ 'type', 'name' ],
					],
				],
				'mode'               => [ 'type' => 'string', 'enum' => [ 'replace', 'append', 'replace_section' ], 'default' => 'replace' ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'verify_url'         => [ 'type' => 'string' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'template_type', 'spec', 'conditions' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'applied'            => [ 'type' => 'boolean' ],
				'created'            => [ 'type' => 'boolean' ],
				'dry_run'            => [ 'type' => 'boolean' ],
				'template_id'        => [ 'type' => 'integer' ],
				'template_type'      => [ 'type' => 'string' ],
				'snapshot_id'        => [ 'type' => 'string' ],
				'element_count'      => [ 'type' => 'integer' ],
				'conditions'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'diagnostics'        => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'verify'             => [ 'type' => 'object' ],
				'repair_hints'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'next_required_call' => [ 'type' => 'object' ],
				'preview'            => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['template_id'] ?? 0 );
		if ( $id > 0 ) {
			return Permissions::edit_post( $id );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$template_type = sanitize_key( (string) ( $args['template_type'] ?? '' ) );
				if ( ! TemplateStore::is_allowed_type( $template_type ) ) {
					return $this->error(
						'invalid_template_type',
						__( 'Unsupported template_type.', 'stonewright' ),
						[ 'status' => 400, 'allowed' => TemplateStore::ALLOWED_TYPES ]
					);
				}

				$normalized = Validator::validate( (array) $args['spec'] );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}

				$diagnostics = [];
				$rendered    = Renderer::render( $normalized, $diagnostics );
				$mode        = self::write_mode( $args );
				$template_id = (int) ( $args['template_id'] ?? 0 );
				$created     = false;
				$dry_run     = ! empty( $args['dry_run'] );
				$conditions  = (array) ( $args['conditions'] ?? [] );

				if ( $template_id > 0 ) {
					$post = get_post( $template_id );
					if ( ! $post || 'elementor_library' !== (string) $post->post_type ) {
						return $this->error( 'not_a_template', __( 'Post is not an elementor_library template.', 'stonewright' ), [ 'status' => 404 ] );
					}
				}

				$tree = $template_id > 0
					? self::merge_tree( ElementorData::read( $template_id ), $rendered, $mode )
					: $rendered;

				if ( $dry_run ) {
					return self::response(
						false,
						false,
						true,
						0,
						$template_type,
						'',
						$tree,
						self::serialise_conditions_for_response( $conditions ),
						$diagnostics,
						self::verify_payload( '', 0 ),
						self::repair_hints( $template_type, $conditions, false ),
						$tree
					);
				}

				$verify_args = array_filter(
					$args,
					static fn( string $key ): bool => 'confirmation_token' !== $key,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				if ( 0 === $template_id ) {
					$title = isset( $args['title'] ) && '' !== trim( (string) $args['title'] )
						? (string) $args['title']
						: (string) ( $normalized['page']['title'] ?? 'Stonewright Template' );
					$new_id = TemplateStore::create( $title, $template_type );
					if ( is_wp_error( $new_id ) ) {
						return $new_id;
					}
					$template_id = (int) $new_id;
					$created     = true;
				}

				$snapshot_id = Backup::snapshot_post( $template_id );
				if ( ! ElementorData::write( $template_id, $tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor template data.', 'stonewright' ) );
				}
				TemplateStore::set_conditions( $template_id, $conditions );

				return self::response(
					true,
					$created,
					false,
					$template_id,
					$template_type,
					$snapshot_id,
					$tree,
					self::serialise_conditions_for_response( $conditions ),
					$diagnostics,
					self::verify_payload( (string) ( $args['verify_url'] ?? '' ), $template_id ),
					self::repair_hints( $template_type, $conditions, true ),
					[]
				);
			}
		);
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function write_mode( array $args ): string {
		$mode = (string) ( $args['mode'] ?? 'replace' );
		return in_array( $mode, [ 'replace', 'append', 'replace_section' ], true ) ? $mode : 'replace';
	}

	/**
	 * @param array<int, array<string, mixed>> $existing
	 * @param array<int, array<string, mixed>> $rendered
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_tree( array $existing, array $rendered, string $mode ): array {
		if ( 'append' === $mode ) {
			return array_merge( $existing, $rendered );
		}
		if ( 'replace_section' !== $mode ) {
			return $rendered;
		}

		$rendered_by_id = [];
		foreach ( $rendered as $section ) {
			$id = isset( $section['id'] ) ? (string) $section['id'] : '';
			if ( '' !== $id ) {
				$rendered_by_id[ $id ] = $section;
			}
		}
		if ( [] === $rendered_by_id ) {
			return $existing;
		}

		foreach ( $existing as $index => $section ) {
			$id = isset( $section['id'] ) ? (string) $section['id'] : '';
			if ( '' !== $id && isset( $rendered_by_id[ $id ] ) ) {
				$existing[ $index ] = $rendered_by_id[ $id ];
				unset( $rendered_by_id[ $id ] );
			}
		}
		return array_merge( $existing, array_values( $rendered_by_id ) );
	}

	/**
	 * @param array<int, array<string, mixed>|string> $conditions
	 * @return list<string>
	 */
	private static function serialise_conditions_for_response( array $conditions ): array {
		$out = [];
		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) ) {
				$out[] = trim( $condition, '/' );
				continue;
			}
			if ( ! is_array( $condition ) ) {
				continue;
			}
			$parts = [];
			foreach ( [ 'type', 'name', 'sub_name', 'sub_id' ] as $key ) {
				if ( isset( $condition[ $key ] ) && '' !== (string) $condition[ $key ] ) {
					$parts[] = (string) $condition[ $key ];
				}
			}
			if ( [] !== $parts ) {
				$out[] = implode( '/', $parts );
			}
		}
		return $out;
	}

	/**
	 * @param array<int, mixed> $conditions
	 * @return list<string>
	 */
	private static function repair_hints( string $template_type, array $conditions, bool $applied ): array {
		$hints = [];
		if ( [] === $conditions ) {
			$hints[] = 'Add at least one include condition so Elementor Theme Builder can apply the template on the front end.';
		}
		if ( ! $applied ) {
			$hints[] = 'Run again with dry_run=false and the same spec after diagnostics look correct.';
		}
		if ( ! $applied && in_array( $template_type, [ 'single', 'single-post', 'single-page', 'archive' ], true ) ) {
			$hints[] = 'Verify a real matching URL with external browser MCP after this write; Theme Builder display logic depends on active post/query context.';
		}
		return $hints;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function verify_payload( string $url, int $template_id ): array {
		$url = trim( $url );
		if ( '' === $url ) {
			return [
				'requested'   => false,
				'url'         => $template_id > 0 ? get_permalink( $template_id ) : '',
				'http_status' => 0,
				'ok'          => false,
				'note'        => 'Pass verify_url or use browser MCP for logged-out front-end verification.',
			];
		}

		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
		if ( is_wp_error( $response ) ) {
			return [
				'requested'   => true,
				'url'         => $url,
				'http_status' => 0,
				'ok'          => false,
				'error'       => $response->get_error_message(),
			];
		}

		$status = wp_remote_retrieve_response_code( $response );
		return [
			'requested'   => true,
			'url'         => $url,
			'http_status' => $status,
			'ok'          => $status >= 200 && $status < 400,
			'body_bytes'  => strlen( wp_remote_retrieve_body( $response ) ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param list<string> $conditions
	 * @param array<int, mixed> $diagnostics
	 * @param array<string, mixed> $verify
	 * @param list<string> $repair_hints
	 * @param array<int, array<string, mixed>> $preview
	 * @return array<string, mixed>
	 */
	private static function response( bool $applied, bool $created, bool $dry_run, int $template_id, string $template_type, string $snapshot_id, array $tree, array $conditions, array $diagnostics, array $verify, array $repair_hints, array $preview ): array {
		return [
			'applied'            => $applied,
			'created'            => $created,
			'dry_run'            => $dry_run,
			'template_id'        => $template_id,
			'template_type'      => $template_type,
			'snapshot_id'        => $snapshot_id,
			'element_count'      => count( ElementorData::flatten( $tree ) ),
			'conditions'         => $conditions,
			'diagnostics'        => $diagnostics,
			'verify'             => $verify,
			'repair_hints'       => $repair_hints,
			'next_required_call' => [
				'ability'  => 'stonewright/theme-builder-apply-template',
				'mcp_tool' => 'stonewright-theme-builder-apply-template',
				'why'      => $dry_run ? 'Write the same validated spec after dry-run diagnostics pass.' : 'Re-run only when changing the template spec or display conditions.',
			],
			'preview'            => $preview,
		];
	}
}
