<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Elementor;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\Provider\NativeContracts;
use Stonewright\WpMcp\Elementor\Provider\NativeElementorProvider;
use Stonewright\WpMcp\Elementor\Provider\NativeExecuteGate;
use Stonewright\WpMcp\Elementor\Provider\NativeInputSchema;
use Stonewright\WpMcp\Elementor\Provider\NativeObserver;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\ChangeSetSources;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Runs one certified native Elementor ability inside Stonewright's closure.
 *
 * Elementor's own ability executes in-process only when its live contract matches and the contract
 * allows a native write. The request is planned first by default; a real write takes a snapshot and
 * a write lock, is read back independently and compared recursively, and is rolled back on any
 * mismatch. Abilities that clear generated CSS site-wide are refused with their exact reason.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * Availability: the tool is available when the native Elementor state is `available` and at least one
 * ability is certified; it does not use the experimental V4 writer flag. Reads and dry-run plans work in
 * every mode. A real write follows the rule of the other writers: in production-safe mode it needs a
 * confirmation token bound to its arguments, in every other mode it runs.
 *
 * @stonewright-status experimental
 */
final class NativeExecute extends AbilityKernel {
	use ConfirmationGuard;

	private NativeElementorProvider $provider;

	public function __construct( ?NativeElementorProvider $provider = null ) {
		$this->provider = $provider ?? NativeElementorProvider::live();
	}

	public function name(): string {
		return 'stonewright/elementor-native-execute';
	}

	public function label(): string {
		return __( 'Run a certified native Elementor ability', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs a certified Elementor ability (default styles, element composition, or the structure read) in-process inside Stonewright\'s snapshot, write lock, readback, rollback, and audit closure. Plans first by default. Abilities that clear generated CSS site-wide are refused. A change to a published page is reported as staged_in_autosave, never as applied. Reads and dry-run plans work in every mode; a real write needs a confirmation_token in production-safe mode. Prefer this tool over Elementor\'s own MCP tools, which skip the backup, the change history and the audit.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'ability', 'input' ],
			'properties'           => [
				'ability'            => [
					'type'        => 'string',
					'enum'        => NativeInputSchema::routable_abilities(),
					'description' => 'The certified Elementor ability to run.',
				],
				'input'              => NativeInputSchema::input(),
				'dry_run'            => [
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Plan without writing. Pass false to write.',
				],
				'confirmation_token' => [ 'type' => 'string', 'description' => 'Required for a real write in production-safe mode.' ],
				'repair_of'          => ChangeSet::input_properties()['repair_of'],
				'supersedes'         => ChangeSet::input_properties()['supersedes'],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'ability'             => [ 'type' => 'string' ],
				'status'              => [ 'type' => 'string', 'enum' => [ 'planned', 'refused', 'applied', 'unchanged', 'staged_in_autosave', 'read' ] ],
				'executable'          => [ 'type' => 'boolean' ],
				'refusal'             => [ 'type' => 'object' ],
				'dry_run'             => [ 'type' => 'boolean' ],
				'route'               => [ 'type' => 'object' ],
				'document_route'      => [ 'type' => 'object' ],
				'planned'             => [ 'type' => 'array' ],
				'post_id'             => [ 'type' => 'integer' ],
				'kit_id'              => [ 'type' => 'integer' ],
				'kit_snapshot_id'     => [ 'type' => 'string' ],
				'staged'              => [ 'type' => 'boolean' ],
				'published'           => [ 'type' => 'boolean' ],
				'root_element_ids'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'removed_element_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'changed_refs'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'readback'            => [ 'type' => 'object' ],
				'css'                 => [ 'type' => 'object' ],
				'structure'           => [ 'type' => 'object' ],
				'autosave_pending'    => [ 'type' => 'boolean' ],
				'warnings'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'next_step'           => [ 'type' => 'string' ],
				'write_receipt'       => [ 'type' => 'object' ],
				'change_set'          => ChangeSet::output_property(),
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$ability = (string) ( $args['ability'] ?? '' );
		$input   = is_array( $args['input'] ?? null ) ? $args['input'] : [];
		$family  = (string) ( NativeContracts::for_ability( $ability )['routing']['family'] ?? '' );
		$allowed = match ( $family ) {
			'kit_defaults'                       => Permissions::edit_theme_options(),
			'tree_composition', 'structure_read' => Permissions::edit_post( (int) ( $input['post_id'] ?? 0 ) ),
			default                              => Permissions::edit_posts(),
		};
		if ( true !== $allowed ) {
			return $allowed;
		}
		// Availability, not the experimental V4 flag: the tool exists when native Elementor is available and a certified ability is registered.
		// The production-safe rule for a write (a confirmation token) is applied in execute(), like every other writer.
		return NativeExecuteGate::check( $this->provider->native_report() );
	}

	public function execute( array $args ): array|\WP_Error {
		$ability = (string) ( $args['ability'] ?? '' );
		$read    = 'structure_read' === ( NativeContracts::for_ability( $ability )['routing']['family'] ?? '' );
		$dry_run = array_key_exists( 'dry_run', $args ) ? (bool) $args['dry_run'] : true;
		$run     = function ( array $args ): array|\WP_Error {
			$input   = is_array( $args['input'] ?? null ) ? $args['input'] : [];
			$dry_run = array_key_exists( 'dry_run', $args ) ? (bool) $args['dry_run'] : true;
			// The provider snapshots the page or kit, takes the write lock and reads back before and after Elementor runs.
			// The mark tells the observer of Elementor's own calls that this one records itself.
			return NativeObserver::mark_native_execute( fn(): array|\WP_Error => $this->provider->run( (string) ( $args['ability'] ?? '' ), $input, [ 'dry_run' => $dry_run ] ) );
		};
		// A read and a dry-run plan change nothing, so they work in every mode without a token. Only a real write is a write.
		return $read || $dry_run ? $this->audit_read( $args, $run ) : $this->audit_write( $args, $run );
	}

	/**
	 * ChangeSetV1 of a native write: the planned changes the request names, the hashes the closure
	 * measured, and the rollback the closure performed. A staged edit is queued, never applied; a
	 * kit-level write names its kit snapshot as the rollback recipe only when that snapshot holds the
	 * default styles (they are posts of their own that kit meta never shows), which is the same
	 * condition under which the change history marks the row restorable.
	 *
	 * @param array<string, mixed>           $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>|null
	 */
	protected function change_set_inputs( array $args, array|\WP_Error $result, string $status ): ?array {
		$ability = (string) ( $args['ability'] ?? '' );
		$input   = is_array( $args['input'] ?? null ) ? $args['input'] : [];
		if ( 'structure_read' === ( NativeContracts::for_ability( $ability )['routing']['family'] ?? '' ) ) {
			return null;
		}
		$data      = ChangeSetSources::data( $result );
		$planned   = is_array( $data['planned'] ?? null ) && [] !== $data['planned'] ? $data['planned'] : NativeElementorProvider::plan( $ability, $input );
		$changed   = is_array( $data['changed_refs'] ?? null ) ? array_map( 'strval', $data['changed_refs'] ) : null;
		$effective = null === $changed
			? $planned
			: array_values( array_filter( $planned, static fn( array $entry ): bool => in_array( (string) ( $entry['ref'] ?? '' ), $changed, true ) ) );
		$receipt   = is_array( $data['write_receipt'] ?? null ) ? $data['write_receipt'] : [];
		$unexpected = [];
		foreach ( is_array( $data['unexpected_refs'] ?? null ) ? $data['unexpected_refs'] : [] as $ref ) {
			$unexpected[] = ChangeSet::entry( 'default_style' === ( $planned[0]['kind'] ?? '' ) ? 'default_style' : 'element', (string) $ref, 'update' );
		}
		$inputs = ChangeSetSources::receipt(
			$args,
			$result,
			$status,
			$planned,
			$effective,
			[
				'unchanged' => 'unchanged' === ( $data['status'] ?? '' ),
				'seed'      => [ $ability, (string) ( $receipt['before_hash'] ?? '' ), (string) wp_json_encode( $planned ) ],
			]
		);
		if ( [] !== $unexpected ) {
			$inputs['unexpected'] = $unexpected;
		}
		$inputs['verification']['evidence']['native_ability'] = $ability;
		$inputs['verification']['evidence']['readback']       = (string) ( $data['readback']['method'] ?? '' );
		return $inputs;
	}
}
