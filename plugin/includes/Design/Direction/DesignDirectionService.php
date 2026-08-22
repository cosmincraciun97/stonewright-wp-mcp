<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use WP_Error;

/**
 * Lifecycle rules for stored design directions.
 *
 * The service owns everything the storage layer must not decide: contract
 * validation, contract hashing, when a revision is created, which record the
 * active pointer names, and the audit payload a caller records. All SQL stays
 * behind DesignDirectionRepository.
 *
 * Invariants enforced here:
 *
 * - A first save creates revision 1 and its version snapshot.
 * - A contract change creates the next revision; a byte-identical contract
 *   creates none, so history never fills with no-op rows.
 * - Only a contract whose readiness reports ready can be activated.
 * - Exactly one direction is active, because "active" is a single option
 *   pointing at an id rather than a status stored on every row.
 * - The active direction cannot be archived.
 * - Restoring an old revision writes a new revision; history is append-only.
 * - Every write result reports the contract hash before and after.
 */
final class DesignDirectionService {

	/** @var string Option holding the id of the active direction. */
	public const ACTIVE_OPTION = 'stonewright_active_design_direction_id';

	/**
	 * Statuses a save may set. Archiving goes through archive().
	 *
	 * @var list<string>
	 */
	public const WRITABLE_STATUSES = [ 'draft', 'ready', 'stale' ];

	/** @var string Structured error code for a record that does not exist. */
	public const NOT_FOUND_CODE = 'stonewright_direction_not_found';

	/** @var string Structured error code for a readiness precondition failure. */
	public const NOT_READY_CODE = 'stonewright_direction_not_ready';

	/** @var string Structured error code for an operation blocked by the active pointer. */
	public const ACTIVE_CODE = 'stonewright_direction_active';

	private DesignDirectionRepository $repository;

	public function __construct( ?DesignDirectionRepository $repository = null ) {
		$this->repository = $repository ?? new DesignDirectionRepository();
	}

	/**
	 * Validates and stores a direction, creating a revision when the contract
	 * changed.
	 *
	 * @param array<string,mixed> $input    Untrusted save payload.
	 * @param int                 $actor_id User the audit payload attributes.
	 * @return array<string,mixed>|WP_Error
	 */
	public function save( array $input, int $actor_id ) {
		$contract = DirectionContractValidator::validate(
			is_array( $input['contract'] ?? null ) ? $input['contract'] : []
		);

		if ( $contract instanceof WP_Error ) {
			return $contract;
		}

		$source_type = (string) ( $input['source_type'] ?? 'manual' );
		if ( ! in_array( $source_type, DirectionContract::SOURCE_TYPES, true ) ) {
			return $this->invalid( 'Unsupported direction source type.' );
		}

		$status = (string) ( $input['status'] ?? 'draft' );
		if ( ! in_array( $status, self::WRITABLE_STATUSES, true ) ) {
			return $this->invalid( 'Unsupported direction status.' );
		}

		$issues = is_array( $contract['readiness']['issues'] ?? null ) ? array_values( $contract['readiness']['issues'] ) : [];
		$issues = array_values(
			array_filter(
				$issues,
				static fn( mixed $issue ): bool => '' !== trim( (string) $issue )
			)
		);
		if ( 'ready' === $status && [] !== $issues ) {
			return new WP_Error(
				self::NOT_READY_CODE,
				'A direction cannot be marked ready while its contract reports outstanding issues.',
				[
					'issues'      => $issues,
					'issue_count' => count( $issues ),
				]
			);
		}
		if ( 'ready' === $status ) {
			$contract['readiness']['ready']  = true;
			$contract['readiness']['issues'] = [];
		}

		$slug = $this->slug( $input, $contract );
		if ( '' === $slug ) {
			return $this->invalid( 'A design direction requires a slug or an identity name.' );
		}

		$hash_after  = self::hash( $contract );
		$existing    = $this->repository->find_by_slug( $slug );
		$hash_before = null !== $existing ? (string) $existing['contract_hash'] : '';
		$versioned   = $hash_after !== $hash_before;

		if ( null === $existing ) {
			$revision = 1;
		} else {
			$revision = $versioned ? (int) $existing['revision'] + 1 : (int) $existing['revision'];
		}

		$record = [
			'slug'          => $slug,
			'status'        => $status,
			'contract'      => $contract,
			'contract_hash' => $hash_after,
			'source_type'   => $source_type,
			'source_refs'   => $this->source_refs( $input['source_refs'] ?? [] ),
			'revision'      => $revision,
		];

		if ( null !== $existing ) {
			$record['id'] = (int) $existing['id'];
		}

		$this->repository->begin_transaction();

		$id = $this->repository->save( $record );
		if ( $id instanceof WP_Error ) {
			$this->repository->rollback_transaction();
			return $id;
		}

		if ( $versioned ) {
			$version = $this->repository->add_version(
				[
					'direction_id'  => $id,
					'revision'      => $revision,
					'status'        => $status,
					'contract'      => $contract,
					'contract_hash' => $hash_after,
					'source_type'   => $source_type,
					'source_refs'   => $record['source_refs'],
				]
			);

			if ( $version instanceof WP_Error ) {
				$this->repository->rollback_transaction();
				$this->restore_record( $existing );
				return $version;
			}
		}

		$this->repository->commit_transaction();

		return $this->result( 'save', $id, $record, $hash_before, $hash_after, $versioned, $actor_id );
	}

	/**
	 * Points the active-direction option at a ready direction.
	 *
	 * @param int $id       Direction id.
	 * @param int $actor_id User the audit payload attributes.
	 * @return array<string,mixed>|WP_Error
	 */
	public function activate( int $id, int $actor_id ) {
		$record = $this->repository->get( $id );
		if ( null === $record ) {
			return $this->not_found( $id );
		}

		$ready = $record['contract']['readiness']['ready'] ?? false;
		if ( true !== $ready ) {
			return new WP_Error(
				self::NOT_READY_CODE,
				'Only a direction whose contract reports ready can be activated.',
				[ 'direction_id' => $id ]
			);
		}

		$previous = (int) get_option( self::ACTIVE_OPTION, 0 );

		$this->repository->begin_transaction();
		update_option( self::ACTIVE_OPTION, $id );

		$saved = $this->repository->save( $record );
		if ( $saved instanceof WP_Error ) {
			$this->repository->rollback_transaction();
			$this->restore_pointer( $previous );
			return $saved;
		}

		$this->repository->commit_transaction();

		$hash = (string) $record['contract_hash'];

		$result                        = $this->result( 'activate', $id, $record, $hash, $hash, false, $actor_id );
		$result['previous_active_id']  = $previous;
		$result['audit']['previous_active_id'] = $previous;

		return $result;
	}

	/**
	 * Clears the active-direction pointer without requiring a stored record.
	 *
	 * The option is set to 0 rather than deleted so a later readback is
	 * consistently integer 0, including when nothing was active.
	 *
	 * @param int $actor_id User the audit payload attributes.
	 * @return array<string,mixed>|WP_Error
	 */
	public function deactivate( int $actor_id ) {
		$previous = (int) get_option( self::ACTIVE_OPTION, 0 );

		update_option( self::ACTIVE_OPTION, 0 );

		$active = (int) get_option( self::ACTIVE_OPTION, 0 );
		if ( 0 !== $active ) {
			$this->restore_pointer( $previous );

			return new WP_Error(
				'stonewright_direction_verification_failed',
				'The active design direction pointer was not cleared.',
				[
					'status'              => 500,
					'active_id'           => $active,
					'previous_active_id'  => $previous,
					'verification_status' => 'failed',
				]
			);
		}

		$hash_before = hash( 'sha256', (string) $previous );
		$hash_after  = hash( 'sha256', '0' );

		return [
			'id'                 => 0,
			'slug'               => '',
			'status'             => 'inactive',
			'revision'           => 0,
			'contract'           => [],
			'hash_before'        => $hash_before,
			'hash_after'         => $hash_after,
			'versioned'          => false,
			'previous_active_id' => $previous,
			'audit'              => [
				'action'             => 'design_direction.deactivate',
				'actor_id'           => $actor_id,
				'direction_id'       => 0,
				'slug'               => '',
				'status'             => 'inactive',
				'revision'           => 0,
				'hash_before'        => $hash_before,
				'hash_after'         => $hash_after,
				'versioned'          => false,
				'previous_active_id' => $previous,
			],
		];
	}

	/**
	 * Archives a direction that is not currently active.
	 *
	 * @param int $id       Direction id.
	 * @param int $actor_id User the audit payload attributes.
	 * @return array<string,mixed>|WP_Error
	 */
	public function archive( int $id, int $actor_id ) {
		$record = $this->repository->get( $id );
		if ( null === $record ) {
			return $this->not_found( $id );
		}

		if ( $id === (int) get_option( self::ACTIVE_OPTION, 0 ) ) {
			return new WP_Error(
				self::ACTIVE_CODE,
				'The active design direction cannot be archived. Activate another direction first.',
				[ 'direction_id' => $id ]
			);
		}

		$archived = $this->repository->archive( $id );
		if ( $archived instanceof WP_Error ) {
			return $archived;
		}

		$hash             = (string) $record['contract_hash'];
		$record['status'] = 'archived';

		return $this->result( 'archive', $id, $record, $hash, $hash, false, $actor_id );
	}

	/**
	 * Writes a stored revision back as a new revision.
	 *
	 * History is append-only: the restored revision stays exactly where it was
	 * and the direction moves forward to a new revision carrying its contract.
	 *
	 * @param int $id       Direction id.
	 * @param int $revision Revision to restore.
	 * @param int $actor_id User the audit payload attributes.
	 * @return array<string,mixed>|WP_Error
	 */
	public function restore( int $id, int $revision, int $actor_id ) {
		$record = $this->repository->get( $id );
		if ( null === $record ) {
			return $this->not_found( $id );
		}

		$version = $this->repository->version( $id, $revision );
		if ( null === $version ) {
			return new WP_Error(
				self::NOT_FOUND_CODE,
				'That design direction revision does not exist.',
				[
					'direction_id' => $id,
					'revision'     => $revision,
				]
			);
		}

		$contract = DirectionContractValidator::validate(
			is_array( $version['contract'] ) ? $version['contract'] : []
		);

		if ( $contract instanceof WP_Error ) {
			return $contract;
		}

		$hash_after   = self::hash( $contract );
		$hash_before  = (string) $record['contract_hash'];
		$versioned    = $hash_after !== $hash_before;
		$new_revision = $versioned ? (int) $record['revision'] + 1 : (int) $record['revision'];

		$restored = [
			'id'            => $id,
			'slug'          => (string) $record['slug'],
			'status'        => (string) $record['status'],
			'contract'      => $contract,
			'contract_hash' => $hash_after,
			'source_type'   => (string) $version['source_type'],
			'source_refs'   => $this->source_refs( $version['source_refs'] ),
			'revision'      => $new_revision,
		];

		$this->repository->begin_transaction();

		$saved = $this->repository->save( $restored );
		if ( $saved instanceof WP_Error ) {
			$this->repository->rollback_transaction();
			return $saved;
		}

		if ( $versioned ) {
			$written = $this->repository->add_version(
				[
					'direction_id'  => $id,
					'revision'      => $new_revision,
					'status'        => $restored['status'],
					'contract'      => $contract,
					'contract_hash' => $hash_after,
					'source_type'   => $restored['source_type'],
					'source_refs'   => $restored['source_refs'],
				]
			);

			if ( $written instanceof WP_Error ) {
				$this->repository->rollback_transaction();
				$this->restore_record( $record );
				return $written;
			}
		}

		$this->repository->commit_transaction();

		$result                            = $this->result( 'restore', $id, $restored, $hash_before, $hash_after, $versioned, $actor_id );
		$result['restored_revision']       = $revision;
		$result['audit']['restored_revision'] = $revision;

		return $result;
	}

	/**
	 * Returns the active direction, or null when none is set or the pointer is
	 * stale.
	 *
	 * @return array<string,mixed>|null
	 */
	public function active(): ?array {
		$id = (int) get_option( self::ACTIVE_OPTION, 0 );

		if ( $id < 1 ) {
			return null;
		}

		return $this->repository->get( $id );
	}

	/**
	 * Lists stored directions.
	 *
	 * @param array<string,mixed> $filters Optional `status` filter.
	 * @return list<array<string,mixed>>
	 */
	public function list( array $filters = [] ): array {
		return $this->repository->list( $filters );
	}

	/**
	 * Returns one stored direction, or null when the id is unknown.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		if ( $id < 1 ) {
			return null;
		}

		return $this->repository->get( $id );
	}

	/**
	 * Returns one stored direction by slug, or null when the slug is unknown.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return null;
		}

		return $this->repository->find_by_slug( $slug );
	}

	/**
	 * Returns a direction's version history, newest revision first.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function versions( int $id ): array {
		return $this->repository->versions( $id );
	}

	/**
	 * The canonical contract hash.
	 *
	 * The validator returns keys in canonical order, so the encoded contract -
	 * and therefore this hash - depends only on content, never on the order the
	 * caller supplied.
	 *
	 * @param array<string,mixed> $contract Validated contract.
	 */
	public static function hash( array $contract ): string {
		$encoded = wp_json_encode( $contract );

		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * Composes the shared write result and its audit payload.
	 *
	 * @param string              $action      Lifecycle action name.
	 * @param int                 $id          Direction id.
	 * @param array<string,mixed> $record      Record as stored.
	 * @param string              $hash_before Contract hash before the write.
	 * @param string              $hash_after  Contract hash after the write.
	 * @param bool                $versioned   Whether a revision was created.
	 * @param int                 $actor_id    User the audit payload attributes.
	 * @return array<string,mixed>
	 */
	private function result( string $action, int $id, array $record, string $hash_before, string $hash_after, bool $versioned, int $actor_id ): array {
		return [
			'id'          => $id,
			'slug'        => (string) $record['slug'],
			'status'      => (string) $record['status'],
			'revision'    => (int) $record['revision'],
			'contract'    => $record['contract'],
			'hash_before' => $hash_before,
			'hash_after'  => $hash_after,
			'versioned'   => $versioned,
			'audit'       => [
				'action'       => 'design_direction.' . $action,
				'actor_id'     => $actor_id,
				'direction_id' => $id,
				'slug'         => (string) $record['slug'],
				'status'       => (string) $record['status'],
				'revision'     => (int) $record['revision'],
				'hash_before'  => $hash_before,
				'hash_after'   => $hash_after,
				'versioned'    => $versioned,
			],
		];
	}

	/**
	 * Derives the storage slug from the payload, falling back to the contract
	 * identity name.
	 *
	 * @param array<string,mixed> $input    Untrusted save payload.
	 * @param array<string,mixed> $contract Validated contract.
	 */
	private function slug( array $input, array $contract ): string {
		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );

		if ( '' !== $slug ) {
			return $slug;
		}

		return sanitize_title( (string) ( $contract['identity']['name'] ?? '' ) );
	}

	/**
	 * Reduces source references to a bounded map of plain strings.
	 *
	 * @param mixed $refs Untrusted reference map.
	 * @return array<string,string>
	 */
	private function source_refs( mixed $refs ): array {
		if ( ! is_array( $refs ) ) {
			return [];
		}

		$clean = [];

		foreach ( $refs as $key => $value ) {
			if ( count( $clean ) >= DirectionContract::MAX_LIST_ITEMS ) {
				break;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$name = sanitize_key( (string) $key );
			if ( '' === $name ) {
				continue;
			}

			$clean[ $name ] = substr( (string) $value, 0, DirectionContract::MAX_STRING_LENGTH );
		}

		ksort( $clean );

		return $clean;
	}

	/**
	 * Best-effort repair after a partial write, so a failed version insert does
	 * not leave the record ahead of its history.
	 *
	 * @param array<string,mixed>|null $record Record state before the write.
	 */
	private function restore_record( ?array $record ): void {
		if ( null === $record || ! isset( $record['id'] ) ) {
			return;
		}

		$this->repository->save( $record );
	}

	private function restore_pointer( int $previous ): void {
		if ( $previous > 0 ) {
			update_option( self::ACTIVE_OPTION, $previous );
			return;
		}

		delete_option( self::ACTIVE_OPTION );
	}

	private function invalid( string $message ): WP_Error {
		return new WP_Error( DirectionContract::ERROR_CODE, $message );
	}

	private function not_found( int $id ): WP_Error {
		return new WP_Error(
			self::NOT_FOUND_CODE,
			'That design direction does not exist.',
			[ 'direction_id' => $id ]
		);
	}
}
