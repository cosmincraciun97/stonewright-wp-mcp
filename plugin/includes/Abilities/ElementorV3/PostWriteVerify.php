<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\RenderMarkers;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\ChangeSetSources;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Observes an Elementor frontend render. It does not regenerate CSS, invalidate
 * caches, or roll back files. CSS mutation belongs to elementor-css-regenerate.
 *
 * @stonewright-status stable
 */
final class PostWriteVerify extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-post-write-verify';
	}

	public function label(): string {
		return __( 'Verify Elementor post write', 'stonewright' );
	}

	public function description(): string {
		return __( 'Renders Elementor with CSS generation disabled and checks bounded element ids or content markers. Observation only: it does not regenerate CSS, invalidate caches, or roll back files. Call after apply and CSS regenerate, then complete Browser QA.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_ids'   => [
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ],
					'default'  => [],
				],
				'html_contains' => [
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
					'default'  => [],
				],
				'write_receipt' => [ 'type' => 'object', 'description' => 'Receipt returned by the originating batch transaction.' ],
				'change_set'    => [ 'type' => 'object', 'description' => 'ChangeSetV1 returned by the write being verified. Preferred over write_receipt: the verification is reported under the same change_set_id and keeps its lineage.' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'                  => [ 'type' => 'boolean' ],
				'post_id'             => [ 'type' => 'integer' ],
				'verification_status' => [ 'type' => 'string', 'enum' => [ 'passed', 'failed' ] ],
				'effect_verified'              => [ 'type' => 'boolean' ],
				'generation_status'            => [
					'type' => 'string',
					'enum' => [ 'verified', 'blocked', 'failed', 'not_checked' ],
				],
				'delivery_status'              => [
					'type' => 'string',
					'enum' => [ 'verified', 'blocked', 'failed', 'not_checked' ],
				],
				'frontend_verification_status' => [
					'type' => 'string',
					'enum' => [ 'verified', 'blocked', 'failed', 'not_checked' ],
				],
				'root_error_code'              => [ 'type' => 'string' ],
				'failed_check'                 => [ 'type' => 'string' ],
				'rendered_bytes'               => [ 'type' => 'integer' ],
				'render_sha256'       => [ 'type' => 'string' ],
				'element_checks'      => [ 'type' => 'array', 'description' => 'One entry per element_ids item: element_id, selector, present, markers_checked (the data-id, data-interaction-id or elementor-element-<id> class markers its kind renders), matched_marker when found, and widget_type, reason and note when missing.' ],
				'content_checks'      => [ 'type' => 'array' ],
				'missing_elements'    => [ 'type' => 'array', 'description' => 'On a failure: each missing element as element_id, widget_type and reason (not_in_document, not_rendered, render_empty, password_protected, not_built_with_elementor, no_elementor_data, cached_html_suspected or renderer_failed).' ],
				'render_context'      => [ 'type' => 'object', 'description' => 'Evidence behind the reasons: post_status, password_protected, built_with_elementor, element_cache_active, rendered_bytes, atomic_elements_active.' ],
				'message'             => [ 'type' => 'string', 'description' => 'On a failure: one plain sentence naming the missing elements, their reasons and the next step.' ],
				'notes'               => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Observations that do not fail the check, such as a post that is not published.' ],
				'browser_required'    => [ 'type' => 'boolean' ],
				'browser_recipe'      => [ 'type' => 'object' ],
				'write_receipt'       => [ 'type' => 'object' ],
				'change_set'          => ChangeSet::output_property(),
			],
			'required'             => [ 'ok', 'post_id', 'verification_status', 'effect_verified', 'generation_status', 'delivery_status', 'frontend_verification_status', 'rendered_bytes', 'render_sha256', 'element_checks', 'content_checks', 'browser_required', 'browser_recipe' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			function ( array $args ): array|\WP_Error {
				$post_id = (int) ( $args['post_id'] ?? 0 );
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
				}

				$mutating = [ 'regenerate_css', 'clear_cache', 'invalidate_cache', 'restore', 'rollback', 'files_manager' ];
				foreach ( $mutating as $key ) {
					if ( array_key_exists( $key, $args ) ) {
						return $this->error(
							'elementor_verify_mutating_arg',
							__( 'Post-write verify is observation-only and rejects leftover CSS or cache mutation arguments.', 'stonewright' ),
							[
								'status'           => 400,
								'failed_check'     => 'input',
								'root_error_code'  => 'stonewright_elementor_verify_mutating_arg',
								'generation_status'=> 'not_checked',
								'delivery_status'  => 'not_checked',
								'frontend_verification_status' => 'not_checked',
							]
						);
					}
				}

				if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
					return $this->error(
						'elementor_unavailable',
						__( 'Elementor is not loaded, so frontend verification cannot run.', 'stonewright' ),
						[ 'status' => 409, 'repair' => 'Activate Elementor, then retry this verification ability.' ]
					);
				}

				$frontend = \Elementor\Plugin::$instance->frontend ?? null;
				if ( ! is_object( $frontend ) || ! method_exists( $frontend, 'get_builder_content_for_display' ) ) {
					return $this->error(
						'elementor_frontend_unavailable',
						__( 'Elementor frontend renderer is unavailable.', 'stonewright' ),
						[ 'status' => 409, 'repair' => 'Reload WordPress with Elementor fully initialized, then retry.' ]
					);
				}

				$context = self::render_context( $post_id );
				try {
					$html = (string) $frontend->get_builder_content_for_display( $post_id, false );
				} catch ( \Throwable $error ) {
					return self::failure_response( $post_id, '', $args, 'renderer_failed', $context, get_class( $error ) );
				}
				$context['rendered_bytes'] = strlen( $html );

				$verification   = self::verification_evidence( $html, $args, $context );
				$element_checks = $verification['element_checks'];
				$content_checks = $verification['content_checks'];
				$checks         = array_merge( $element_checks, $content_checks );
				$passed         = '' !== $html && ! in_array( false, array_column( $checks, 'present' ), true );

				$write_receipt = isset( $args['write_receipt'] ) && is_array( $args['write_receipt'] ) ? self::sanitize_receipt( $args['write_receipt'] ) : [];
				if ( [] !== $write_receipt ) {
					$write_receipt['verification_status'] = $passed ? 'verified' : 'failed';
					$write_receipt['root_error_code']     = $passed ? '' : 'stonewright_elementor_frontend_verification_failed';
				}

				$result = [
					'ok'                           => $passed,
					'post_id'                      => $post_id,
					'verification_status'          => $passed ? 'passed' : 'failed',
					'effect_verified'              => $passed,
					'generation_status'            => 'not_checked',
					'delivery_status'              => 'not_checked',
					'frontend_verification_status' => $passed ? 'verified' : 'failed',
					'rendered_bytes'               => $verification['rendered_bytes'],
					'render_sha256'                => $verification['render_sha256'],
					'element_checks'               => $element_checks,
					'content_checks'               => $content_checks,
					'render_context'               => self::public_context( $context ),
					'browser_required'             => true,
					'browser_recipe'               => self::browser_recipe(),
					'write_receipt'                => $write_receipt,
				];
				$notes = self::status_notes( $context );
				if ( [] !== $notes ) {
					$result['notes'] = $notes;
				}
				if ( ! $passed ) {
					$missing                    = self::missing_elements( $element_checks );
					$result['root_error_code']  = 'stonewright_elementor_frontend_verification_failed';
					$result['failed_check']     = 'frontend';
					$result['missing_elements'] = $missing;
					$result['message']          = self::failure_message( $missing, $content_checks, $html, $context );
				}
				return $result;
			}
		);
	}

	/**
	 * ChangeSetV1 of the verification: the change set of the write being verified
	 * (given as `change_set`, or recovered from `write_receipt`) with the frontend
	 * render as its evidence. Each checked element or marker is one planned change;
	 * those the render shows are applied, the others missing. Without either input
	 * there is no write to report on, so the verification reports none.
	 *
	 * @param array<string, mixed>           $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>|null
	 */
	protected function change_set_inputs( array $args, array|\WP_Error $result, string $status ): ?array {
		$data    = ChangeSetSources::data( $result );
		$given   = is_array( $args['change_set'] ?? null ) ? $args['change_set'] : [];
		$receipt = isset( $args['write_receipt'] ) && is_array( $args['write_receipt'] ) ? self::sanitize_receipt( $args['write_receipt'] ) : [];
		$id      = trim( (string) ( $given['change_set_id'] ?? '' ) );
		if ( '' === $id ) {
			$id = trim( (string) ( $receipt['change_set_id'] ?? '' ) );
		}
		if ( '' === $id ) {
			return null;
		}

		$planned = [];
		$applied = [];
		$missing = [];
		foreach ( (array) ( $data['element_checks'] ?? [] ) as $check ) {
			$entry     = ChangeSet::entry( 'element', (string) ( $check['element_id'] ?? '' ), 'render', count( $planned ) );
			$planned[] = $entry;
			if ( ! empty( $check['present'] ) ) {
				$applied[] = $entry;
			} else {
				$missing[] = $entry;
			}
		}
		foreach ( (array) ( $data['content_checks'] ?? [] ) as $check ) {
			$entry     = ChangeSet::entry( 'content', 'marker-' . substr( (string) ( $check['sha256'] ?? '' ), 0, 12 ), 'render', count( $planned ) );
			$planned[] = $entry;
			if ( ! empty( $check['present'] ) ) {
				$applied[] = $entry;
			} else {
				$missing[] = $entry;
			}
		}

		$passed   = 'passed' === (string) ( $data['verification_status'] ?? '' ) && 'ok' === $status;
		$snapshot = (string) ( $receipt['snapshot_id'] ?? '' );
		$recipe   = is_array( $given['rollback_recipe_ref'] ?? null )
			? $given['rollback_recipe_ref']
			: ( '' !== $snapshot ? [ 'kind' => 'post_snapshot', 'ref' => $snapshot, 'target' => (string) ( $receipt['post_id'] ?? $args['post_id'] ?? '' ) ] : null );

		return [
			'change_set_id'       => $id,
			'planned'             => $planned,
			'applied'             => $applied,
			'missing'             => $missing,
			'before_hash'         => (string) ( $given['before_hash'] ?? $receipt['before_hash'] ?? '' ),
			'after_hash'          => (string) ( $given['after_hash'] ?? $receipt['readback_hash'] ?? $receipt['after_hash'] ?? '' ),
			'verification'        => [
				'status'   => $passed ? ChangeSet::STATUS_VERIFIED : ChangeSet::STATUS_FAILED,
				'evidence' => [
					'method'           => 'frontend_render',
					'render_sha256'    => (string) ( $data['render_sha256'] ?? '' ),
					'rendered_bytes'   => isset( $data['rendered_bytes'] ) ? (int) $data['rendered_bytes'] : null,
					'checked_elements' => count( (array) ( $data['element_checks'] ?? [] ) ),
					'checked_markers'  => count( (array) ( $data['content_checks'] ?? [] ) ),
					'failed_check'     => (string) ( $data['failed_check'] ?? '' ),
					'root_error_code'  => $result instanceof \WP_Error ? sanitize_key( (string) $result->get_error_code() ) : (string) ( $data['root_error_code'] ?? '' ),
				],
			],
			'rollback_available'  => true === ( $given['rollback_available'] ?? null ) || ( null === ( $given['rollback_available'] ?? null ) && null !== $recipe ),
			'rollback_recipe_ref' => $recipe,
			'repair_of'           => $given['repair_of'] ?? $receipt['repair_of'] ?? null,
			'supersedes'          => $given['supersedes'] ?? $receipt['supersedes'] ?? null,
			'approval_reason'     => $given['approval_reason'] ?? null,
		];
	}

	/**
	 * What the post is, as evidence for a missing element: its status, password, Elementor flag, document and element cache.
	 *
	 * @return array<string,mixed>
	 */
	private static function render_context( int $post_id ): array {
		$status   = get_post_status( $post_id );
		$cache    = get_post_meta( $post_id, '_elementor_element_cache', true );
		$cache    = is_string( $cache ) ? json_decode( $cache, true ) : $cache;
		$atomic   = null;
		$features  = class_exists( '\\Elementor\\Plugin' ) ? ( \Elementor\Plugin::$instance->experiments ?? null ) : null;
		if ( is_object( $features ) && method_exists( $features, 'is_feature_active' ) ) {
			$atomic = (bool) $features->is_feature_active( 'e_atomic_elements' );
		}
		return [
			'post_status'            => is_string( $status ) ? $status : '',
			'password_protected'     => function_exists( 'post_password_required' ) && post_password_required( $post_id ),
			'built_with_elementor'   => '' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
			'element_cache_active'   => is_array( $cache ) && (int) ( $cache['timeout'] ?? 0 ) > time(),
			'rendered_bytes'         => 0,
			'atomic_elements_active' => $atomic,
			'tree'                   => ElementorData::read( $post_id ),
		];
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function public_context( array $context ): array {
		unset( $context['tree'] );
		return $context;
	}

	/**
	 * The reason an empty render is empty, from evidence; null when the render is not empty.
	 *
	 * @param array<string,mixed> $context
	 */
	private static function empty_render_reason( string $html, array $context ): ?string {
		if ( '' !== $html ) {
			return null;
		}
		if ( ! empty( $context['password_protected'] ) ) {
			return 'password_protected';
		}
		if ( empty( $context['built_with_elementor'] ) ) {
			return 'not_built_with_elementor';
		}
		if ( [] === $context['tree'] ) {
			return 'no_elementor_data';
		}
		return 'render_empty';
	}

	/**
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $context
	 * @return array{rendered_bytes:int,render_sha256:string,element_checks:list<array<string,mixed>>,content_checks:list<array<string,mixed>>}
	 */
	private static function verification_evidence( string $html, array $args, array $context = [] ): array {
		$empty_reason   = [] === $context ? null : self::empty_render_reason( $html, $context );
		$tree           = is_array( $context['tree'] ?? null ) ? $context['tree'] : [];
		$element_checks = [];
		foreach ( self::bounded_strings( $args['element_ids'] ?? [], 50, 80 ) as $element_id ) {
			$path        = ElementorData::find_path( $tree, $element_id );
			$element     = null === $path ? null : ElementorData::element_at( $tree, $path );
			$widget_type = is_array( $element ) ? (string) ( $element['widgetType'] ?? '' ) : '';
			$type        = is_array( $element ) ? ( '' !== $widget_type ? $widget_type : (string) ( $element['elType'] ?? '' ) ) : '';
			$matched     = RenderMarkers::find( $html, $element_id, $type );
			$check       = [
				'element_id'      => $element_id,
				'selector'        => '.elementor-element-' . $element_id,
				'present'         => null !== $matched,
				'markers_checked' => RenderMarkers::labels( $element_id, $type ),
			];
			if ( null !== $matched ) {
				$check['matched_marker'] = $matched;
			} else {
				$check['widget_type'] = $widget_type;
				$check['reason']      = $empty_reason ?? self::missing_reason( null !== $element, $tree, $context );
				$note                 = self::atomic_note( $type, $context );
				if ( '' !== $note ) {
					$check['note'] = $note;
				}
			}
			$element_checks[] = $check;
		}
		$content_checks = [];
		foreach ( self::bounded_strings( $args['html_contains'] ?? [], 20, 200 ) as $marker ) {
			$present = str_contains( $html, $marker );
			$check   = [
				'sha256'  => hash( 'sha256', $marker ),
				'length'  => strlen( $marker ),
				'present' => $present,
			];
			if ( ! $present ) {
				$check['reason'] = $empty_reason ?? 'marker_not_in_render';
			}
			$content_checks[] = $check;
		}
		return [
			'rendered_bytes'  => strlen( $html ),
			'render_sha256'   => hash( 'sha256', $html ),
			'element_checks'  => $element_checks,
			'content_checks'  => $content_checks,
		];
	}

	/**
	 * Why a stored or unknown element is absent from a render that is not empty.
	 *
	 * @param array<int|string,mixed> $tree
	 * @param array<string,mixed>     $context
	 */
	private static function missing_reason( bool $in_document, array $tree, array $context ): string {
		if ( [] === $tree ) {
			return 'no_elementor_data';
		}
		if ( ! $in_document ) {
			return 'not_in_document';
		}
		return ! empty( $context['element_cache_active'] ) ? 'cached_html_suspected' : 'not_rendered';
	}

	/** @param array<string,mixed> $context */
	private static function atomic_note( string $type, array $context ): string {
		if ( str_starts_with( $type, 'e-' ) && false === ( $context['atomic_elements_active'] ?? null ) ) {
			return 'The Elementor V4 atomic elements experiment is off, so Elementor skips e-* elements when it renders.';
		}
		return '';
	}

	/**
	 * @param list<array<string,mixed>> $element_checks
	 * @return list<array{element_id:string,widget_type:string,reason:string,note?:string}>
	 */
	private static function missing_elements( array $element_checks ): array {
		$missing = [];
		foreach ( $element_checks as $check ) {
			if ( ! empty( $check['present'] ) ) {
				continue;
			}
			$entry = [
				'element_id'  => (string) $check['element_id'],
				'widget_type' => (string) ( $check['widget_type'] ?? '' ),
				'reason'      => (string) ( $check['reason'] ?? 'not_rendered' ),
			];
			if ( ! empty( $check['note'] ) ) {
				$entry['note'] = (string) $check['note'];
			}
			$missing[] = $entry;
		}
		return $missing;
	}

	/**
	 * Notes about the post that do not fail the check: Elementor renders a draft, a visitor gets no public page.
	 *
	 * @param array<string,mixed> $context
	 * @return list<string>
	 */
	private static function status_notes( array $context ): array {
		$status = (string) ( $context['post_status'] ?? '' );
		if ( '' === $status || 'publish' === $status ) {
			return [];
		}
		return [ sprintf( 'The post status is %s, so an anonymous visitor does not get this page. Elementor still rendered it, so this check ran; confirm the page in a browser while logged in, or publish it first.', $status ) ];
	}

	/**
	 * One plain sentence a person or an audit row can act on: the missing ids with their reasons and the next step.
	 *
	 * @param list<array{element_id:string,widget_type:string,reason:string,note?:string}> $missing
	 * @param list<array<string,mixed>>                                      $content_checks
	 * @param array<string,mixed>                                            $context
	 */
	private static function failure_message( array $missing, array $content_checks, string $html, array $context, string $exception_class = '' ): string {
		$parts   = [];
		$reasons = [];
		if ( '' !== $exception_class ) {
			$parts[]   = 'The Elementor renderer threw ' . $exception_class . ', so nothing could be checked';
			$reasons[] = 'renderer_failed';
		}
		if ( [] !== $missing && '' === $exception_class ) {
			$named = [];
			foreach ( array_slice( $missing, 0, 5 ) as $item ) {
				$named[] = $item['element_id'] . ' (' . $item['reason'] . ')';
			}
			$text = 'Not found in the render: ' . implode( ', ', $named );
			if ( count( $missing ) > 5 ) {
				$text .= ' and ' . ( count( $missing ) - 5 ) . ' more';
			}
			$parts[] = $text;
			foreach ( $missing as $item ) {
				$reasons[] = $item['reason'];
			}
		}
		$markers_missing = count( array_filter( $content_checks, static fn( array $check ): bool => empty( $check['present'] ) ) );
		if ( $markers_missing > 0 && '' === $exception_class ) {
			$parts[] = $markers_missing . ' content marker(s) not in the render';
			foreach ( $content_checks as $check ) {
				if ( empty( $check['present'] ) && isset( $check['reason'] ) ) {
					$reasons[] = (string) $check['reason'];
				}
			}
		}
		if ( [] === $parts ) {
			$empty = self::empty_render_reason( $html, $context ) ?? 'render_empty';
			$parts[]   = 'The render is empty (' . $empty . ')';
			$reasons[] = $empty;
		}
		$next = [];
		foreach ( array_unique( $reasons ) as $reason ) {
			$hint = self::next_step( $reason );
			if ( '' !== $hint ) {
				$next[] = $hint;
			}
		}
		$message = 'Frontend verification failed. ' . implode( '; ', $parts ) . '.';
		if ( [] !== $next ) {
			$message .= ' Next: ' . implode( ' ', array_slice( $next, 0, 2 ) );
		}
		return strlen( $message ) > 400 ? substr( $message, 0, 397 ) . '...' : $message;
	}

	private static function next_step( string $reason ): string {
		return match ( $reason ) {
			'cached_html_suspected'    => 'The render may come from the Elementor element cache: clear it (Elementor > Tools > Regenerate CSS & Data) or rerun after it expires.',
			'password_protected'       => 'The render of a password-protected post is empty by design; verify in a browser while logged in.',
			'not_built_with_elementor' => 'The post is not built with Elementor; open it in the Elementor editor once or check the post id.',
			'no_elementor_data'        => 'The post has no stored Elementor data; read the page structure to confirm the write landed.',
			'render_empty'             => 'The renderer returned nothing for a populated document; check for a fatal error in the page template and verify in a browser.',
			'not_in_document'          => 'The id is not in the stored document; read the page structure for the current ids.',
			'not_rendered'             => 'The element is stored but not in the HTML; check its visibility settings and verify in a browser.',
			'renderer_failed'          => 'Check the PHP error log for the renderer error and retry once.',
			'marker_not_in_render'     => 'The text is not in the render; check the stored content.',
			default                    => '',
		};
	}

	/**
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $context
	 */
	private static function failure_response( int $post_id, string $html, array $args, string $code, array $context = [], string $exception_class = '' ): array {
		$verification = self::verification_evidence( $html, $args, $context );
		$write_receipt = isset( $args['write_receipt'] ) && is_array( $args['write_receipt'] ) ? self::sanitize_receipt( $args['write_receipt'] ) : [];
		if ( [] !== $write_receipt ) {
			$write_receipt['verification_status'] = 'failed';
			$write_receipt['root_error_code']     = sanitize_key( $code );
		}
		$exception_class = (string) preg_replace( '/[^A-Za-z0-9_\\\\]/', '', $exception_class );
		$checks          = [];
		foreach ( $verification['element_checks'] as $check ) {
			$check['present'] = false;
			$check['reason']  = $code;
			unset( $check['matched_marker'] );
			$checks[] = $check;
		}
		$missing        = self::missing_elements( $checks );
		$content_checks = [];
		foreach ( $verification['content_checks'] as $check ) {
			$check['present'] = false;
			$check['reason']  = $code;
			$content_checks[] = $check;
		}
		return [
			'ok'                           => false,
			'post_id'                      => $post_id,
			'verification_status'          => 'failed',
			'effect_verified'              => false,
			'generation_status'            => 'not_checked',
			'delivery_status'              => 'not_checked',
			'frontend_verification_status' => 'failed',
			'rendered_bytes'               => $verification['rendered_bytes'],
			'render_sha256'                => $verification['render_sha256'],
			'element_checks'               => $checks,
			'content_checks'               => $content_checks,
			'render_context'               => self::public_context( $context ),
			'missing_elements'             => $missing,
			'browser_required'             => true,
			'browser_recipe'               => self::browser_recipe(),
			'write_receipt'                => $write_receipt,
			'root_error_code'              => sanitize_key( $code ),
			'failed_check'                 => 'frontend',
			'message'                      => self::failure_message( $missing, $content_checks, $html, $context, '' !== $exception_class ? $exception_class : 'an exception' ),
		];
	}

	/** @return array<string,mixed> */
	private static function browser_recipe(): array {
		return [
			'desktop_tablet_mobile' => true,
			'outer_selector'        => '.elementor-element-<element_id>',
			'boxed_inner_selector'  => '.elementor-element-<element_id> > .e-con-inner',
			'rule'                  => 'For boxed containers measure both outer and .e-con-inner. A builder render pass is not visual acceptance.',
		];
	}

	/**
	 * @return list<string>
	 */
	private static function bounded_strings( mixed $values, int $limit, int $max_length ): array {
		if ( ! is_array( $values ) ) {
			return [];
		}

		$out = [];
		foreach ( array_slice( $values, 0, $limit ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( mb_substr( (string) $value, 0, $max_length ) );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string,mixed> $receipt
	 * @return array<string,mixed>
	 */
	private static function sanitize_receipt( array $receipt ): array {
		$allowed = [
			'transaction_id',
			'change_set_id',
			'post_id',
			'architecture',
			'target_ids',
			'dry_run',
			'snapshot_id',
			'before_hash',
			'planned_hash',
			'after_hash',
			'readback_hash',
			'verification_status',
			'rollback_status',
			'root_error_code',
			'root_error_path',
			'retryable',
			'retry_after_seconds',
			'audit_id',
			'cause_fingerprint',
			'strategy_fingerprint',
		];
		$out = [];
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $receipt ) ) {
				continue;
			}
			$value = $receipt[ $key ];
			if ( in_array( $key, [ 'before_hash', 'planned_hash', 'after_hash', 'readback_hash', 'cause_fingerprint', 'strategy_fingerprint' ], true ) ) {
				$value = is_scalar( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/i', (string) $value ) ? strtolower( (string) $value ) : '';
			} elseif ( 'target_ids' === $key ) {
				$value = self::bounded_strings( $value, 100, 80 );
			} elseif ( 'post_id' === $key ) {
				$value = max( 0, (int) $value );
			} elseif ( 'dry_run' === $key || 'retryable' === $key ) {
				$value = (bool) $value;
			} elseif ( 'retry_after_seconds' === $key ) {
				$value = max( 0, min( 86400, (int) $value ) );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$value = mb_substr( sanitize_text_field( (string) $value ), 0, 255 );
			} else {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
