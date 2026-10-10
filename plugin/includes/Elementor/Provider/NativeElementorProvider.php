<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

use Stonewright\WpMcp\Elementor\V4\AtomicReadbackVerifier;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Elementor\Write\ElementTreeDiff;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Executes a certified Elementor ability in-process inside Stonewright's closure.
 *
 * Every write runs in this order: route (certified, contract allows a native write, document
 * architecture), feature gates, input validation, snapshot, write lock, native execute,
 * independent readback and recursive comparison, then rollback on any mismatch. Page CSS is
 * regenerated only through `stonewright/elementor-css-regenerate`; nothing here clears a cache,
 * publishes a document, or converts V3 to V4.
 *
 * Abilities whose contract refuses native writes, including every write that clears generated
 * CSS site-wide, never execute. A change to a published document lands in an autosave and is
 * reported as `staged_in_autosave`, never as applied.
 */
final class NativeElementorProvider {

	private const MAX_XML_BYTES  = 262144;
	private const MAX_CSS_BYTES  = 20000;
	private const LOCK_TTL       = 60;
	private const MAX_OPERATIONS = 20;
	private const COMPOSITION_KEYS = [ 'post_id', 'xml_structure', 'element_config', 'style', 'classes', 'interactions', 'parent_id', 'mode' ];

	public function __construct( private readonly ProviderRouter $router, private readonly NativeRuntime $runtime ) {}

	public static function live(): self {
		return new self( new ProviderRouter(), new WordPressNativeRuntime() );
	}

	/**
	 * @param array<string,mixed> $input   The native ability's own input.
	 * @param array{dry_run?: bool} $options
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run( string $ability, array $input, array $options = [] ): array|\WP_Error {
		$block = $this->router->native_elementor();
		$route = NativeRoute::for_ability( $ability, $block );
		$dry_run = ! empty( $options['dry_run'] );
		if ( NativeRoute::NATIVE !== $route['route'] ) {
			return $dry_run ? $this->refusal_plan( $ability, $input, $route ) : self::refused( $route );
		}
		$contract = NativeContracts::for_ability( $ability );
		if ( null === $contract ) {
			$unknown = NativeRoute::for_ability( $ability, [] );
			return $dry_run ? $this->refusal_plan( $ability, $input, $unknown ) : self::refused( $unknown );
		}
		$gate = V4FeatureGate::check( ! $route['read_only'] );
		if ( $gate instanceof \WP_Error ) {
			return $gate;
		}
		if ( true === ( $contract['requires']['atomic_editor'] ?? false ) && true !== ( $block['mcp_module']['atomic_editor_active'] ?? false ) ) {
			return self::error( 'stonewright_native_atomic_editor_inactive', 'Elementor\'s Atomic Editor is turned off, so this native ability cannot run.', 409, [ 'missing_feature' => 'atomic_editor', 'route' => $route ] );
		}
		return match ( $route['family'] ) {
			'kit_defaults'     => $this->default_styles( $ability, $input, $dry_run, $route, $contract ),
			'tree_composition' => $this->composition( $ability, $input, $dry_run, $route ),
			'structure_read'   => $this->structure_read( $ability, $input, $route ),
			default            => self::refused( $route ),
		};
	}

	/**
	 * The changes a request plans, read tolerantly from raw input so a failed call still reports what it meant to do.
	 *
	 * @param array<string,mixed> $input
	 * @return list<array<string,mixed>>
	 */
	public static function plan( string $ability, array $input ): array {
		$family = (string) ( NativeContracts::for_ability( $ability )['routing']['family'] ?? '' );
		if ( 'kit_defaults' === $family ) {
			$planned = [];
			foreach ( is_array( $input['operations'] ?? null ) ? array_values( $input['operations'] ) : [] as $index => $operation ) {
				if ( is_array( $operation ) && is_string( $operation['tag'] ?? null ) && in_array( $operation['action'] ?? null, [ 'update', 'delete' ], true ) ) {
					$planned[] = [ 'kind' => 'default_style', 'ref' => substr( $operation['tag'], 0, 32 ), 'action' => $operation['action'], 'index' => $index ];
				}
			}
			return array_slice( $planned, 0, 50 );
		}
		if ( 'tree_composition' === $family ) {
			$parent = is_string( $input['parent_id'] ?? null ) && '' !== $input['parent_id'] ? substr( $input['parent_id'], 0, 64 ) : 'document';
			return [ [ 'kind' => 'element', 'ref' => $parent, 'action' => 'replace_children' === ( $input['mode'] ?? '' ) ? 'replace_children' : 'insert_composition', 'index' => 0 ] ];
		}
		return [];
	}

	/**
	 * A dry run of a route that cannot execute is a plan, not an error: it says why and what to use instead.
	 *
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $route
	 * @return array<string,mixed>
	 */
	private function refusal_plan( string $ability, array $input, array $route ): array {
		return $this->envelope(
			$ability,
			$route,
			'refused',
			[
				'dry_run'    => true,
				'executable' => false,
				'planned'    => self::plan( $ability, $input ),
				'refusal'    => [
					'code'            => 'stonewright_native_route_refused',
					'reason'          => (string) $route['reason'],
					'issues'          => array_values( array_map( 'strval', (array) ( $route['issues'] ?? [] ) ) ),
					'missing_feature' => $route['missing_feature'] ?? null,
					'fallback'        => array_values( array_map( 'strval', (array) ( $route['fallback'] ?? [] ) ) ),
				],
			]
		);
	}

	// ---------------------------------------------------------------- kit-level default styles

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $route
	 * @param array<string,mixed> $contract
	 * @return array<string,mixed>|\WP_Error
	 */
	private function default_styles( string $ability, array $input, bool $dry_run, array $route, array $contract ): array|\WP_Error {
		$limit      = (int) ( $contract['runtime_contract']['runtime_operation_limit'] ?? self::MAX_OPERATIONS );
		$operations = self::style_operations( $input, $limit );
		if ( $operations instanceof \WP_Error ) {
			return $operations;
		}
		$planned = self::plan( $ability, [ 'operations' => $operations ] );
		if ( $dry_run ) {
			return $this->envelope( $ability, $route, 'planned', [ 'dry_run' => true, 'planned' => $planned, 'native_called' => false ] );
		}
		$kit_id = $this->runtime->active_kit_id();
		if ( $kit_id <= 0 ) {
			return self::error( 'stonewright_native_no_kit', 'No active Elementor kit found.', 409 );
		}
		$before = $this->runtime->default_styles( $kit_id );
		if ( $before instanceof \WP_Error ) {
			return $before;
		}
		$snapshot = Backup::snapshot_post( $kit_id );
		if ( '' === $snapshot ) {
			return self::error( 'stonewright_backup_failed', 'The active Elementor kit could not be snapshotted; refusing the default styles write.', 500 );
		}
		$owner = self::lock_owner( $kit_id );
		$lease = PostWriteLock::acquire( $kit_id, $owner, self::LOCK_TTL );
		if ( $lease instanceof \WP_Error ) {
			return $lease;
		}
		try {
			return $this->apply_default_styles( $ability, $operations, $planned, $route, $kit_id, $before, $snapshot );
		} finally {
			PostWriteLock::release( $kit_id, $owner );
		}
	}

	/**
	 * @param list<array<string,mixed>> $operations
	 * @param list<array<string,mixed>> $planned
	 * @param array<string,mixed>       $route
	 * @param array<string,mixed>       $before
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply_default_styles( string $ability, array $operations, array $planned, array $route, int $kit_id, array $before, string $snapshot ): array|\WP_Error {
		$native = $this->runtime->execute( $ability, [ 'operations' => $operations ] );
		$after  = $this->runtime->default_styles( $kit_id );
		if ( $after instanceof \WP_Error ) {
			$rollback = $this->rollback_styles( $kit_id, $before, null );
			return $this->style_failure( 'stonewright_native_readback_unavailable', 'The default styles could not be read back after the write.', 502, $before, $this->runtime->default_styles( $kit_id ), $rollback, $snapshot );
		}
		if ( $native instanceof \WP_Error ) {
			$rollback = $this->rollback_styles( $kit_id, $before, $after );
			return $this->style_failure( $native->get_error_code(), $native->get_error_message(), self::status_of( $native, 500 ), $before, $this->runtime->default_styles( $kit_id ), $rollback, $snapshot, is_array( $native->get_error_data() ) ? $native->get_error_data() : [] );
		}
		$results = is_array( $native ) && is_array( $native['results'] ?? null ) ? array_values( $native['results'] ) : null;
		if ( null === $results || 'ok' !== ( $native['status'] ?? '' ) || count( $results ) !== count( $operations ) || ! self::all_operations_ok( $results, $operations ) ) {
			$rollback = $this->rollback_styles( $kit_id, $before, $after );
			return $this->style_failure(
				'stonewright_native_operations_failed',
				'Elementor did not apply every default style operation; the whole batch was rolled back.',
				409,
				$before,
				$this->runtime->default_styles( $kit_id ),
				$rollback,
				$snapshot,
				[ 'operations' => self::bounded_results( $results ?? [] ) ]
			);
		}
		$changed = self::changed_tags( $before, $after );
		$planned_tags = array_values( array_unique( array_column( $operations, 'tag' ) ) );
		$unexpected   = array_values( array_diff( $changed, $planned_tags ) );
		if ( [] !== $unexpected ) {
			$rollback = $this->rollback_styles( $kit_id, $before, $after );
			return $this->style_failure( 'stonewright_native_unexpected_change', 'Elementor changed a default style that the request did not name; the write was rolled back.', 409, $before, $this->runtime->default_styles( $kit_id ), $rollback, $snapshot, [ 'unexpected_refs' => array_slice( $unexpected, 0, 20 ) ] );
		}
		$problems = [];
		foreach ( $operations as $operation ) {
			$present = array_key_exists( $operation['tag'], $after );
			if ( 'update' === $operation['action'] && ! $present ) {
				$problems[] = [ 'code' => 'update_not_stored', 'id' => $operation['tag'] ];
			}
			if ( 'delete' === $operation['action'] && $present ) {
				$problems[] = [ 'code' => 'delete_not_stored', 'id' => $operation['tag'] ];
			}
		}
		if ( [] !== $problems ) {
			$rollback = $this->rollback_styles( $kit_id, $before, $after );
			return $this->style_failure( 'stonewright_native_readback_mismatch', 'The stored default styles do not match what Elementor reported; the write was rolled back.', 409, $before, $this->runtime->default_styles( $kit_id ), $rollback, $snapshot, [ 'problems' => array_slice( $problems, 0, 20 ) ] );
		}
		$status = [] === $changed ? 'unchanged' : 'applied';
		return $this->envelope(
			$ability,
			$route,
			$status,
			[
				'dry_run'         => false,
				'kit_id'          => $kit_id,
				'kit_snapshot_id' => $snapshot,
				'planned'         => $planned,
				'changed_refs'    => $changed,
				'native_results'  => self::bounded_results( $results ),
				'readback'        => [ 'method' => 'default_styles_repository', 'verified' => true, 'checked' => count( $after ) ],
				'css'             => [ 'status' => 'not_applicable', 'note' => 'Default styles are served from Elementor\'s own scoped style cache, which its update hook invalidates; no page CSS is regenerated.' ],
				'write_receipt'   => [
					'post_id'             => $kit_id,
					'before_hash'         => TreeHasher::hash( $before ),
					'readback_hash'       => TreeHasher::hash( $after ),
					'verification_status' => 'unchanged' === $status ? 'unchanged' : 'verified',
					'rollback_status'     => 'not_needed',
					'architecture'        => 'v4',
					'native_ability'      => $ability,
				],
			]
		);
	}

	/**
	 * @param array<string,mixed>      $before
	 * @param array<string,mixed>|null $after
	 */
	private function rollback_styles( int $kit_id, array $before, ?array $after ): string {
		if ( null !== $after && TreeHasher::hash( $after ) === TreeHasher::hash( $before ) ) {
			return 'not_needed';
		}
		$this->runtime->restore_default_styles( $kit_id, $before );
		$restored = $this->runtime->default_styles( $kit_id );
		return is_array( $restored ) && TreeHasher::hash( $restored ) === TreeHasher::hash( $before ) ? 'succeeded' : 'failed';
	}

	/**
	 * @param array<string,mixed>            $before
	 * @param array<string,mixed>|\WP_Error  $after
	 * @param array<string,mixed>            $extra
	 */
	private function style_failure( string $code, string $message, int $status, array $before, array|\WP_Error $after, string $rollback, string $snapshot, array $extra = [] ): \WP_Error {
		$hash = $after instanceof \WP_Error ? '' : TreeHasher::hash( $after );
		return self::error(
			$code,
			$message,
			$status,
			array_merge(
				$extra,
				[
					'rollback_status' => $rollback,
					'kit_snapshot_id' => $snapshot,
					'write_receipt'   => [
						'before_hash'         => TreeHasher::hash( $before ),
						'readback_hash'       => $hash,
						'verification_status' => 'failed',
						'rollback_status'     => $rollback,
						'root_error_code'     => $code,
						'architecture'        => 'v4',
					],
				]
			)
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	private static function style_operations( array $input, int $limit ): array|\WP_Error {
		$operations = $input['operations'] ?? null;
		if ( ! is_array( $operations ) || [] === $operations || ! array_is_list( $operations ) || count( $operations ) > $limit ) {
			return self::invalid( sprintf( 'operations must be a list of 1 to %d default style operations.', $limit ) );
		}
		$out = [];
		foreach ( $operations as $index => $operation ) {
			$action = is_array( $operation ) ? ( $operation['action'] ?? null ) : null;
			$tag    = is_array( $operation ) ? ( $operation['tag'] ?? null ) : null;
			if ( ! in_array( $action, [ 'update', 'delete' ], true ) || ! is_string( $tag ) || 1 !== preg_match( '/^[a-z][a-z0-9]{0,31}$/', $tag ) ) {
				return self::invalid( sprintf( 'operation %d needs an action of update or delete and a lowercase HTML tag.', $index ) );
			}
			$row = [ 'action' => $action, 'tag' => $tag ];
			if ( 'update' === $action ) {
				$css = $operation['css'] ?? null;
				if ( ! is_string( $css ) || '' === trim( $css ) || strlen( $css ) > self::MAX_CSS_BYTES ) {
					return self::invalid( sprintf( 'operation %d: update needs a css string.', $index ) );
				}
				$row['css'] = $css;
				if ( array_key_exists( 'mode', $operation ) ) {
					if ( ! in_array( $operation['mode'], [ 'patch', 'replace' ], true ) ) {
						return self::invalid( sprintf( 'operation %d: mode must be patch or replace.', $index ) );
					}
					$row['mode'] = $operation['mode'];
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param list<mixed>               $results
	 * @param list<array<string,mixed>> $operations
	 */
	private static function all_operations_ok( array $results, array $operations ): bool {
		foreach ( $operations as $index => $operation ) {
			$row = $results[ $index ] ?? null;
			if ( ! is_array( $row ) || 'ok' !== ( $row['status'] ?? '' ) || ( $row['action'] ?? $operation['action'] ) !== $operation['action'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param list<mixed> $results
	 * @return list<array<string,mixed>>
	 */
	private static function bounded_results( array $results ): array {
		$out = [];
		foreach ( array_slice( $results, 0, self::MAX_OPERATIONS ) as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array_intersect_key( $row, array_flip( [ 'index', 'action', 'status', 'tag', 'code', 'message' ] ) );
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @return list<string>
	 */
	private static function changed_tags( array $before, array $after ): array {
		$changed = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $tag ) {
			$tag = (string) $tag;
			if ( array_key_exists( $tag, $before ) !== array_key_exists( $tag, $after ) || TreeHasher::hash( $before[ $tag ] ?? null ) !== TreeHasher::hash( $after[ $tag ] ?? null ) ) {
				$changed[] = $tag;
			}
		}
		sort( $changed );
		return $changed;
	}

	// ---------------------------------------------------------------- document composition

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $route
	 * @return array<string,mixed>|\WP_Error
	 */
	private function composition( string $ability, array $input, bool $dry_run, array $route ): array|\WP_Error {
		$request = self::composition_request( $input );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		$post_id = $request['post_id'];
		$status  = $this->runtime->post_status( $post_id );
		if ( '' === $status ) {
			return self::error( 'not_found', 'Post not found.', 404 );
		}
		$staged          = in_array( $status, [ 'publish', 'private' ], true );
		$live_before     = ElementorData::read( $post_id );
		$autosave_before = $this->runtime->autosave_tree( $post_id );
		if ( ! $staged && null !== $autosave_before ) {
			return self::error( 'stonewright_native_ambiguous_autosave', 'An unpublished document has a pending autosave, so the base of the edit is ambiguous. Resolve the autosave in the Elementor editor first.', 409, [ 'post_id' => $post_id, 'post_status' => $status ] );
		}
		$base         = $staged && null !== $autosave_before ? $autosave_before : $live_before;
		$architecture = (string) ( AtomicTreeInspector::inspect( $base )['architecture'] ?? 'unknown' );
		$parent_kind  = self::parent_kind( $base, $request['parent_id'] );
		$document     = NativeRoute::for_document( $architecture, $parent_kind );
		if ( NativeRoute::NATIVE !== $document['route'] ) {
			return self::error(
				'stonewright_native_route_refused',
				'This document or target element is not routed to Elementor\'s native composition: ' . $document['reason'] . '.',
				409,
				[ 'route' => $route, 'document_route' => $document, 'architecture' => $architecture ]
			);
		}
		$missing = $this->missing_atomic_types( $request['xml_structure'] );
		if ( [] !== $missing ) {
			$available = $this->runtime->atomic_types();
			return self::error(
				'stonewright_atomic_type_unavailable',
				'The composition uses an Atomic type this site does not have: ' . $missing[0] . '.',
				409,
				[ 'missing_types' => $missing, 'missing_feature' => 'atomic_type:' . $missing[0], 'available_types' => array_slice( $available, 0, 40 ) ]
			);
		}
		$native_input = array_intersect_key( $request['input'], array_flip( self::COMPOSITION_KEYS ) );
		$planned      = self::plan( $ability, $request['input'] );
		if ( $dry_run ) {
			$native = self::dry_run_result( $this->runtime->execute( $ability, $native_input + [ 'dry_run' => true ] ) );
			if ( $native instanceof \WP_Error ) {
				return $native;
			}
			return $this->envelope( $ability, $route, 'planned', [ 'dry_run' => true, 'planned' => $planned, 'native_called' => true, 'resolved_xml' => (string) ( $native['resolved_xml'] ?? '' ), 'warnings' => self::warnings( $native ), 'architecture' => $architecture, 'document_route' => $document ] );
		}
		$snapshot = Backup::snapshot_post( $post_id );
		if ( '' === $snapshot ) {
			return self::error( 'stonewright_backup_failed', 'Backup snapshot failed; write aborted.', 500 );
		}
		$owner = self::lock_owner( $post_id );
		$lease = PostWriteLock::acquire( $post_id, $owner, self::LOCK_TTL );
		if ( $lease instanceof \WP_Error ) {
			return $lease;
		}
		try {
			$result = $this->apply_composition( $ability, $native_input, $request, $planned, $route, $document, $architecture, $staged, $live_before, $autosave_before, $base, $snapshot );
		} finally {
			PostWriteLock::release( $post_id, $owner );
		}
		// The CSS ability takes the same per-post lock, so it runs only after the closure has released it.
		return $this->regenerate_page_css( $result, $post_id );
	}

	/**
	 * Regenerates post-scoped CSS for an applied page edit, through the CSS ability only.
	 *
	 * @param array<string,mixed>|\WP_Error $result
	 * @return array<string,mixed>|\WP_Error
	 */
	private function regenerate_page_css( array|\WP_Error $result, int $post_id ): array|\WP_Error {
		if ( $result instanceof \WP_Error || 'pending' !== ( $result['css']['status'] ?? '' ) ) {
			return $result;
		}
		$css = self::css_summary( $this->runtime->regenerate_css( $post_id ) );
		$result['css'] = $css;
		if ( 'regenerated' === $css['status'] ) {
			$result['next_step'] = 'Run stonewright-elementor-post-write-verify with the new root ids, then check desktop, tablet, and mobile.';
			return $result;
		}
		$result['warnings'][] = 'Page CSS was not regenerated; the page may render without its new styles until it is.';
		$result['next_step']  = 'Regenerate CSS with stonewright-elementor-css-regenerate for post ' . $post_id . ', then run stonewright-elementor-post-write-verify.';
		return $result;
	}

	/**
	 * @param array<string,mixed>       $native_input
	 * @param array<string,mixed>       $request
	 * @param list<array<string,mixed>> $planned
	 * @param array<string,mixed>       $route
	 * @param array<string,mixed>       $document
	 * @param array<int,mixed>          $live_before
	 * @param array<int,mixed>|null     $autosave_before
	 * @param array<int,mixed>          $base
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply_composition( string $ability, array $native_input, array $request, array $planned, array $route, array $document, string $architecture, bool $staged, array $live_before, ?array $autosave_before, array $base, string $snapshot ): array|\WP_Error {
		$post_id = (int) $request['post_id'];
		$native  = $this->runtime->execute( $ability, $native_input );
		$context = [ 'staged' => $staged, 'post_id' => $post_id, 'snapshot' => $snapshot, 'live_before' => $live_before, 'autosave_before' => $autosave_before, 'base' => $base, 'architecture' => $architecture ];

		if ( $native instanceof \WP_Error ) {
			$rollback = $this->rollback_document( $context );
			return $this->document_failure( $native->get_error_code(), $native->get_error_message(), self::status_of( $native, 500 ), $context, $rollback, is_array( $native->get_error_data() ) ? $native->get_error_data() : [] );
		}
		$roots = is_array( $native['root_element_ids'] ?? null ) ? array_values( array_map( 'strval', $native['root_element_ids'] ) ) : [];
		if ( true !== ( $native['success'] ?? null ) || [] === $roots || ! is_string( $native['resolved_xml'] ?? null ) ) {
			return $this->document_failure( 'stonewright_native_result_unusable', 'Elementor returned a composition result Stonewright cannot verify.', 502, $context, $this->rollback_document( $context ) );
		}
		$expected = AtomicReadbackVerifier::structure_from_xml( $native['resolved_xml'] );
		if ( $expected instanceof \WP_Error ) {
			return $this->document_failure( $expected->get_error_code(), $expected->get_error_message(), 502, $context, $this->rollback_document( $context ) );
		}
		if ( array_column( $expected, 'id' ) !== $roots ) {
			return $this->document_failure( 'stonewright_native_result_inconsistent', 'The root ids Elementor reported differ from the resolved structure.', 502, $context, $this->rollback_document( $context ) );
		}

		$live_after = ElementorData::read( $post_id );
		if ( $staged ) {
			if ( TreeHasher::hash( $live_after ) !== TreeHasher::hash( $live_before ) ) {
				return $this->document_failure( 'stonewright_native_live_document_changed', 'A change meant for an autosave altered the live document; it was restored.', 409, $context, $this->rollback_document( $context ) );
			}
			$after = $this->runtime->autosave_tree( $post_id );
			if ( null === $after ) {
				return $this->document_failure( 'stonewright_native_autosave_missing', 'Elementor reported success but no autosave holds the change.', 409, $context, $this->rollback_document( $context ) );
			}
		} else {
			$after = $live_after;
		}
		$removed = is_array( $native['removed_element_ids'] ?? null ) ? array_values( array_map( 'strval', $native['removed_element_ids'] ) ) : [];
		$report  = AtomicReadbackVerifier::verify( $expected, $after, [ 'root_parent' => 'document' === $request['parent_id'] ? '' : $request['parent_id'] ] );
		if ( 'replace_children' === $request['mode'] ) {
			$children = self::child_ids( $after, $request['parent_id'] );
			if ( $children !== $roots ) {
				$report['ok']        = false;
				$report['problems'][] = [ 'code' => 'replace_children_mismatch', 'id' => (string) $request['parent_id'], 'path' => '/' . $request['parent_id'] ];
				++$report['problems_count'];
			}
		}
		if ( ! $report['ok'] ) {
			$rollback = $this->rollback_document( $context );
			$error    = AtomicReadbackVerifier::error( $report, 'native_composition' );
			$data     = $error->get_error_data();
			return $this->document_failure( $error->get_error_code(), $error->get_error_message(), 409, $context, $rollback, is_array( $data ) ? $data : [] );
		}
		$unexpected = array_column( ElementTreeDiff::unexpected( $base, $after, self::ids_of( $expected ), $removed ), 'ref' );
		if ( [] !== $unexpected ) {
			return $this->document_failure( 'stonewright_native_unexpected_change', 'Elementor changed an element that the composition did not name; the write was rolled back.', 409, $context, $this->rollback_document( $context ), [ 'unexpected_refs' => array_slice( $unexpected, 0, 20 ) ] );
		}

		$css      = $staged
			? [ 'status' => 'not_applicable', 'note' => 'The change is staged in an autosave; the live page CSS is unchanged.' ]
			: [ 'status' => 'pending' ];
		$warnings = self::warnings( $native );
		$next     = $staged
			? 'The change is staged in an autosave and is not live. Review it in the Elementor editor; publish only on explicit user intent.'
			: 'Regenerate page CSS, then run stonewright-elementor-post-write-verify.';
		return $this->envelope(
			$ability,
			$route,
			$staged ? 'staged_in_autosave' : 'applied',
			[
				'dry_run'             => false,
				'post_id'             => $post_id,
				'staged'              => $staged,
				'queued'              => $staged,
				'published'           => false,
				'planned'             => $planned,
				'root_element_ids'    => $roots,
				'removed_element_ids' => $removed,
				'edit_url'            => is_string( $native['edit_url'] ?? null ) ? $native['edit_url'] : '',
				'readback'            => [ 'method' => $staged ? 'autosave_tree' : 'document_tree', 'verified' => true, 'checked' => $report['checked'] ],
				'css'                 => $css,
				'warnings'            => $warnings,
				'next_step'           => $next,
				'document_route'      => $document,
				'write_receipt'       => [
					'post_id'             => $post_id,
					'snapshot_id'         => $snapshot,
					'before_hash'         => TreeHasher::hash( $base ),
					'readback_hash'       => TreeHasher::hash( $after ),
					'verification_status' => 'verified',
					'rollback_status'     => 'not_needed',
					'architecture'        => $architecture,
					'native_ability'      => $ability,
				],
			]
		);
	}

	/**
	 * Puts the live document and any autosave back as they were before the native call.
	 *
	 * @param array<string,mixed> $context
	 */
	private function rollback_document( array $context ): string {
		$post_id     = (int) $context['post_id'];
		$live_before = TreeHasher::hash( $context['live_before'] );
		$state       = 'not_needed';
		if ( TreeHasher::hash( ElementorData::read( $post_id ) ) !== $live_before ) {
			$restored = Backup::restore( $post_id, (string) $context['snapshot'] ) && TreeHasher::hash( ElementorData::read( $post_id ) ) === $live_before;
			$state    = $restored ? 'succeeded' : 'failed';
		}
		if ( $context['staged'] ) {
			$current = $this->runtime->autosave_tree( $post_id );
			$wanted  = $context['autosave_before'];
			if ( ! self::same_tree( $current, $wanted ) ) {
				$this->runtime->restore_autosave( $post_id, $wanted );
				if ( ! self::same_tree( $this->runtime->autosave_tree( $post_id ), $wanted ) ) {
					return 'failed';
				}
				$state = 'failed' === $state ? 'failed' : 'succeeded';
			}
		}
		return $state;
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $extra
	 */
	private function document_failure( string $code, string $message, int $status, array $context, string $rollback, array $extra = [] ): \WP_Error {
		$post_id = (int) $context['post_id'];
		$current = $context['staged'] ? $this->runtime->autosave_tree( $post_id ) : ElementorData::read( $post_id );
		unset( $extra['status'] );
		return self::error(
			$code,
			$message,
			$status,
			array_merge(
				$extra,
				[
					'rollback_status' => $rollback,
					'snapshot_id'     => (string) $context['snapshot'],
					'write_receipt'   => [
						'post_id'             => $post_id,
						'snapshot_id'         => (string) $context['snapshot'],
						'before_hash'         => TreeHasher::hash( $context['base'] ),
						'readback_hash'       => null === $current ? '' : TreeHasher::hash( $current ),
						'verification_status' => 'failed',
						'rollback_status'     => $rollback,
						'root_error_code'     => $code,
						'architecture'        => (string) $context['architecture'],
					],
				]
			)
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{post_id:int,xml_structure:string,parent_id:string,mode:string,input:array<string,mixed>}|\WP_Error
	 */
	private static function composition_request( array $input ): array|\WP_Error {
		$post_id = $input['post_id'] ?? null;
		$post_id = is_int( $post_id ) ? $post_id : ( is_string( $post_id ) && ctype_digit( $post_id ) ? (int) $post_id : 0 );
		$xml     = $input['xml_structure'] ?? null;
		$parent  = $input['parent_id'] ?? 'document';
		$mode    = $input['mode'] ?? 'append';
		if ( $post_id <= 0 ) {
			return self::invalid( 'post_id must be a positive integer.' );
		}
		if ( ! is_string( $xml ) || '' === trim( $xml ) || strlen( $xml ) > self::MAX_XML_BYTES ) {
			return self::invalid( 'xml_structure must be a non-empty XML string of at most 256 KiB.' );
		}
		if ( ! is_string( $parent ) || '' === $parent || strlen( $parent ) > 64 ) {
			return self::invalid( 'parent_id must be an element id or "document".' );
		}
		if ( ! in_array( $mode, [ 'append', 'replace_children' ], true ) ) {
			return self::invalid( 'mode must be append or replace_children.' );
		}
		$input['post_id'] = $post_id;
		return [ 'post_id' => $post_id, 'xml_structure' => $xml, 'parent_id' => $parent, 'mode' => $mode, 'input' => $input ];
	}

	/** @param array<int,mixed> $tree */
	private static function parent_kind( array $tree, string $parent_id ): string {
		if ( 'document' === $parent_id ) {
			return 'document';
		}
		$path = ElementorData::find_path( $tree, $parent_id );
		if ( null === $path ) {
			return 'missing';
		}
		$node = $tree;
		$found = null;
		foreach ( $path as $index ) {
			$found = is_array( $node[ $index ] ?? null ) ? $node[ $index ] : null;
			$node  = is_array( $found['elements'] ?? null ) ? $found['elements'] : [];
		}
		if ( null === $found ) {
			return 'missing';
		}
		$type = 'widget' === ( $found['elType'] ?? '' ) ? (string) ( $found['widgetType'] ?? '' ) : (string) ( $found['elType'] ?? '' );
		return str_starts_with( $type, 'e-' ) ? 'atomic' : 'v3';
	}

	/** @return list<string> */
	private function missing_atomic_types( string $xml ): array {
		preg_match_all( '/<\s*([A-Za-z][A-Za-z0-9_:.-]*)/', $xml, $matches );
		$live    = $this->runtime->atomic_types();
		$missing = [];
		foreach ( array_values( array_unique( $matches[1] ) ) as $tag ) {
			if ( ! in_array( $tag, $live, true ) ) {
				$missing[] = $tag;
			}
		}
		return $missing;
	}

	/**
	 * @param array<int,mixed> $tree
	 * @return list<string>
	 */
	private static function child_ids( array $tree, string $parent_id ): array {
		if ( 'document' === $parent_id ) {
			return array_values( array_filter( array_map( static fn( mixed $node ): string => is_array( $node ) ? (string) ( $node['id'] ?? '' ) : '', $tree ) ) );
		}
		$path = ElementorData::find_path( $tree, $parent_id );
		if ( null === $path ) {
			return [];
		}
		$node = null;
		$list = $tree;
		foreach ( $path as $index ) {
			$node = is_array( $list[ $index ] ?? null ) ? $list[ $index ] : null;
			$list = is_array( $node['elements'] ?? null ) ? $node['elements'] : [];
		}
		return self::child_ids( $list, 'document' );
	}

	/**
	 * @param array<int,mixed> $nodes
	 * @return list<string>
	 */
	private static function ids_of( array $nodes ): array {
		$ids = [];
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) && '' !== (string) $node['id'] ) {
				$ids[] = (string) $node['id'];
			}
			$ids = array_merge( $ids, self::ids_of( is_array( $node['elements'] ?? null ) ? $node['elements'] : [] ) );
		}
		return $ids;
	}

	/**
	 * @param array<int,mixed>|null $left
	 * @param array<int,mixed>|null $right
	 */
	private static function same_tree( ?array $left, ?array $right ): bool {
		if ( null === $left || null === $right ) {
			return $left === $right;
		}
		return TreeHasher::hash( $left ) === TreeHasher::hash( $right );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function dry_run_result( mixed $native ): array|\WP_Error {
		if ( $native instanceof \WP_Error ) {
			return $native;
		}
		if ( ! is_array( $native ) || true !== ( $native['success'] ?? null ) ) {
			return self::error( 'stonewright_native_result_unusable', 'Elementor returned a validation result Stonewright cannot read.', 502 );
		}
		return $native;
	}

	/**
	 * @param array<string,mixed>|\WP_Error $result
	 * @return array{status:string,error_code?:string,generation_status?:string}
	 */
	private static function css_summary( array|\WP_Error $result ): array {
		if ( $result instanceof \WP_Error ) {
			return [ 'status' => 'failed', 'error_code' => sanitize_key( (string) $result->get_error_code() ) ];
		}
		if ( true === ( $result['ok'] ?? false ) && true === ( $result['effect_verified'] ?? false ) ) {
			return [ 'status' => 'regenerated', 'generation_status' => (string) ( $result['generation_status'] ?? '' ) ];
		}
		return [ 'status' => 'failed', 'error_code' => sanitize_key( (string) ( $result['root_error_code'] ?? 'css_not_verified' ) ), 'generation_status' => (string) ( $result['generation_status'] ?? '' ) ];
	}

	/**
	 * @param array<string,mixed> $native
	 * @return list<string>
	 */
	private static function warnings( array $native ): array {
		$warnings = [];
		foreach ( is_array( $native['warnings'] ?? null ) ? $native['warnings'] : [] as $warning ) {
			if ( is_string( $warning ) ) {
				$warnings[] = substr( $warning, 0, 300 );
			}
		}
		return array_slice( $warnings, 0, 20 );
	}

	// ---------------------------------------------------------------- readback

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $route
	 * @return array<string,mixed>|\WP_Error
	 */
	private function structure_read( string $ability, array $input, array $route ): array|\WP_Error {
		$post_id = $input['post_id'] ?? null;
		$post_id = is_int( $post_id ) ? $post_id : ( is_string( $post_id ) && ctype_digit( $post_id ) ? (int) $post_id : 0 );
		$element = $input['element_id'] ?? null;
		$content = $input['include_content'] ?? null;
		if ( $post_id <= 0 ) {
			return self::invalid( 'post_id must be a positive integer.' );
		}
		if ( ( null !== $element && ( ! is_string( $element ) || '' === $element || strlen( $element ) > 64 ) ) || ( null !== $content && ! is_bool( $content ) ) ) {
			return self::invalid( 'element_id must be an element id and include_content a boolean.' );
		}
		if ( true === $content && null === $element ) {
			return self::invalid( 'include_content needs an element_id.' );
		}
		if ( '' === $this->runtime->post_status( $post_id ) ) {
			return self::error( 'not_found', 'Post not found.', 404 );
		}
		$native_input = [ 'post_id' => $post_id ];
		if ( null !== $element ) {
			$native_input['element_id'] = $element;
		}
		if ( null !== $content ) {
			$native_input['include_content'] = $content;
		}
		$native = $this->runtime->execute( $ability, $native_input );
		if ( $native instanceof \WP_Error ) {
			return $native;
		}
		if ( ! is_array( $native ) ) {
			return self::error( 'stonewright_native_result_unusable', 'Elementor returned a structure Stonewright cannot read.', 502 );
		}
		$pending = null !== $this->runtime->autosave_tree( $post_id );
		return $this->envelope(
			$ability,
			$route,
			'read',
			[
				'dry_run'          => false,
				'read_only'        => true,
				'post_id'          => $post_id,
				'reads'            => 'published_document',
				'autosave_pending' => $pending,
				'structure'        => $native,
				'warnings'         => $pending ? [ 'A pending autosave exists. This read shows the published document, not the staged edit.' ] : [],
			]
		);
	}

	// ---------------------------------------------------------------- shared

	/**
	 * @param array<string,mixed> $route
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private function envelope( string $ability, array $route, string $status, array $fields ): array {
		return array_merge(
			[
				'ok'      => true,
				'route'   => $route,
				'ability' => $ability,
				'status'  => $status,
			],
			$fields
		);
	}

	/** @param array<string,mixed> $route */
	private static function refused( array $route ): \WP_Error {
		$data = [ 'route' => $route ];
		if ( ! empty( $route['issues'] ) ) {
			$data['issues'] = $route['issues'];
		}
		return self::error(
			'stonewright_native_route_refused',
			sprintf( 'Elementor\'s native %s is not routed through Stonewright: %s.', (string) $route['ability'], (string) $route['reason'] ),
			409,
			$data
		);
	}

	/** @param array<string,mixed> $data */
	private static function error( string $code, string $message, int $status, array $data = [] ): \WP_Error {
		$data += [ 'execution_status' => 'failed', 'verification_status' => 'failed' ];
		$data['status'] = $status;
		if ( ! array_key_exists( 'rollback_status', $data ) ) {
			$data['rollback_status'] = 'not_needed';
		}
		return new \WP_Error( $code, $message, $data );
	}

	private static function invalid( string $message ): \WP_Error {
		return self::error( 'stonewright_native_invalid_input', $message, 400 );
	}

	private static function status_of( \WP_Error $error, int $fallback ): int {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) && is_int( $data['status'] ) ? $data['status'] : $fallback;
	}

	private static function lock_owner( int $post_id ): string {
		return 'native-' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . $post_id ), 0, 32 );
	}
}
