<?php
/**
 * Logical catalog coordination over explicit repository and authority ports.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

/** No default repository or authority implementation can silently activate this service. */
final class SkillCatalog {
	private Repository $repository;
	private MutationBoundary $boundary;
	/** @var array<int, string> */
	private array $tool_names;
	/** @var callable(string): bool|null */
	private $trigger_policy;
	/** @var callable(array<string, mixed>): bool|null */
	private $compatible;
	/** @var array<int, string> */
	private array $reserved;

	/**
	 * Known built-in identities stay reserved even before their pack entries are seeded.
	 *
	 * @param callable(array<string, mixed>): bool|null $compatible @param array<int, string> $tool_names @param callable(string): bool|null $trigger_policy @param array<int, string> $reserved
	 */
	public function __construct( Repository $repository, MutationBoundary $boundary, ?callable $compatible = null, array $tool_names = [], ?callable $trigger_policy = null, array $reserved = [] ) {
		$this->repository = $repository;
		$this->boundary = $boundary;
		$this->compatible = $compatible;
		$this->tool_names = $tool_names;
		$this->trigger_policy = $trigger_policy;
		$this->reserved = array_values( array_map( static fn( string $slug ): string => RecordRules::identity( $slug ), $reserved ) );
	}

	/** @return array<int, array<string, mixed>> */
	public function browse( bool $enabled_only = false, string $mode = 'all' ): array {
		return array_values( array_filter( $this->repository->all_records(), function ( array $record ) use ( $enabled_only, $mode ): bool {
			if ( 'trashed' === ( $record['status'] ?? '' ) ) {
				return false;
			}
			if ( 'all' !== $mode ) {
				return VisibilityRules::eligible( $record, $mode, $this->compatible );
			}
			return ! $enabled_only || ! empty( $record['enabled'] );
		} ) );
	}

	/** @return array<string, mixed>|null */
	public function lookup( string $slug ): ?array {
		$record = $this->repository->find_slug( $slug );
		return null !== $record && 'trashed' !== ( $record['status'] ?? '' ) ? $record : null;
	}

	/** @return array<string, mixed>|null */
	public function identify( int $id ): ?array {
		$record = $this->repository->find_id( $id );
		return null !== $record && 'trashed' !== ( $record['status'] ?? '' ) ? $record : null;
	}

	/** @param array<string, mixed> $input */
	public function store( array $input, string $token = '' ): int|\WP_Error {
		foreach ( [ 'verification_count', 'semantic_fingerprint', 'trust', 'trusted', 'verified', 'history', 'revision', 'source_kind', 'source_id', 'conflicts' ] as $claim ) {
			if ( array_key_exists( $claim, $input ) ) {
				return new \WP_Error( 'stonewright_skill_authority_claim', 'A local save cannot manufacture provenance, verification, trust, or review findings. Use the separately authenticated evidence workflow.' );
			}
		}
		$slug = isset( $input['slug'] ) && is_string( $input['slug'] ) ? RecordRules::identity( $input['slug'] ) : '';
		$previous = $this->repository->find_slug( $slug );
		if ( array_key_exists( 'source', $input ) && ( ( null === $previous && 'user' !== $input['source'] ) || ( null !== $previous && ( $previous['source'] ?? 'user' ) !== $input['source'] ) ) ) {
			return new \WP_Error( 'stonewright_skill_authority_claim', 'A local save cannot change the skill provenance.' );
		}
		if ( null !== $previous && ( LifecycleDecisions::protected_origin( $previous ) || 'trashed' === ( $previous['status'] ?? '' ) ) ) {
			return new \WP_Error( 'stonewright_skill_protected', 'This skill is not writable through a local save.' );
		}
		$record = RecordRules::normalize( $input, $previous );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		if ( ! in_array( $record['source'], [ 'user', 'uploaded', 'candidate' ], true ) ) {
			return new \WP_Error( 'stonewright_skill_protected', 'Local saves cannot claim product or external provenance.' );
		}
		if ( null !== $previous && ( (int) ( $previous['verification_count'] ?? 0 ) > 0 || 'candidate' === ( $previous['source'] ?? '' ) ) ) {
			foreach ( [ 'content', 'description', 'topic', 'version_constraints' ] as $semantic_field ) {
				if ( ( $record[ $semantic_field ] ?? null ) !== ( $previous[ $semantic_field ] ?? null ) ) {
					$record = array_replace( $record, [ 'verification_count' => 0, 'semantic_fingerprint' => '', 'status' => 'draft', 'enabled' => false, 'enable_agentic' => false, 'enable_prompt' => false ] );
					unset( $record['trust'], $record['trusted'], $record['verified'] );
					break;
				}
			}
		}
		if ( null === $previous ) {
			if ( $this->reserved_identity( $record['slug'] ) ) {
				return $this->reserved_refusal();
			}
			$ready = $this->activation_check( $record );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			unset( $record['id'] );
			$record['revision'] = 1;
			$result = $this->mutate( 'save', $record, $token, fn() => $this->repository->insert_unique( $record ) );
			return is_int( $result ) && $result > 0 ? $result : ( is_wp_error( $result ) ? $result : $this->failed() );
		}
		$result = $this->exchange( 'save', $previous, $record, $token );
		return true === $result ? (int) $previous['id'] : $result;
	}

	public function set_exposure( int $id, bool $enabled, string $token = '' ): bool|\WP_Error {
		$previous = $this->repository->find_id( $id );
		if ( null === $previous || 'trashed' === ( $previous['status'] ?? '' ) || 'external' === ( $previous['source_kind'] ?? '' ) || 'external' === ( $previous['source'] ?? '' ) ) {
			return new \WP_Error( 'stonewright_skill_toggle_invalid', 'The skill cannot be enabled or disabled here.' );
		}
		$record = array_replace( $previous, [ 'enabled' => $enabled ] );
		if ( $enabled ) {
			// Enabling promotes only a draft; stale and retired guidance stays out of service.
			if ( ! in_array( $previous['status'] ?? 'draft', [ 'draft', 'active' ], true ) ) {
				return new \WP_Error( 'stonewright_skill_toggle_invalid', 'A stale or retired skill cannot be enabled again.' );
			}
			$record['status'] = 'active';
		}
		return $this->exchange( 'toggle', $previous, $record, $token );
	}

	public function put_in_trash( int $id, string $token = '' ): bool|\WP_Error {
		return $this->lifecycle( 'trash', $id, $token );
	}

	public function recover( int $id, string $token = '' ): bool|\WP_Error {
		return $this->lifecycle( 'restore', $id, $token );
	}

	public function erase( int $id, string $token = '' ): bool|\WP_Error {
		$record = $this->repository->find_id( $id );
		if ( null === $record || LifecycleDecisions::protected_origin( $record ) || 'trashed' !== ( $record['status'] ?? '' ) ) {
			return new \WP_Error( 'stonewright_skill_delete_invalid', 'Permanent deletion requires a trashed local skill.' );
		}
		$revision = $this->revision_number( $record );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		$result = $this->mutate( 'destroy', $record, $token, fn() => $this->repository->remove_record( $id, $revision ) );
		return true === $result ? true : ( is_wp_error( $result ) ? $result : $this->failed() );
	}

	/** @return array<int, array<string, mixed>> */
	public function revisions( string $slug ): array {
		return $this->repository->snapshots( $slug );
	}

	public function restore_revision( string $slug, int $revision, string $token = '' ): bool|\WP_Error {
		$current = $this->repository->find_slug( $slug );
		$snapshot = $this->repository->read_snapshot( $slug, $revision );
		if ( null === $current || null === $snapshot ) {
			return new \WP_Error( 'stonewright_skill_rollback_failed', 'The skill or requested snapshot is unavailable.' );
		}
		$record = LifecycleDecisions::rollback( $current, $snapshot );
		return is_wp_error( $record ) ? $record : $this->exchange( 'rollback', $current, $record, $token );
	}

	/** @param array<string, mixed> $review */
	public function import_review( array $review, string $receipt = '' ): int|\WP_Error {
		if ( '' === $receipt ) {
			return new \WP_Error( 'stonewright_skill_import_review_required', 'The authenticated write boundary must validate a server-bound import review receipt.' );
		}
		$record = ImportReview::confirm( $review, $this->tool_names );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		if ( $this->reserved_identity( $record['slug'] ) ) {
			return $this->reserved_refusal();
		}
		if ( null !== $this->repository->find_slug( $record['slug'] ) ) {
			return new \WP_Error( 'stonewright_skill_import_exists', 'An import never replaces an existing skill.' );
		}
		$record['revision'] = 1;
		// Confirmation verified this hash; it binds the reviewed identity and bytes for the receipt check.
		$result = $this->mutate( 'import', $record, $receipt, fn() => $this->repository->insert_unique( $record ), (string) $review['review_hash'] );
		return is_int( $result ) && $result > 0 ? $result : ( is_wp_error( $result ) ? $result : $this->failed() );
	}

	public function export_document( int $id ): string|\WP_Error {
		$record = $this->repository->find_id( $id );
		return null === $record ? new \WP_Error( 'stonewright_skill_not_found', 'Skill not found.' ) : DocumentCodec::write( $record );
	}

	/** @return array<int, array<string, mixed>> */
	public function topic_matches( string $topic ): array {
		return array_values( array_filter( $this->browse( false, 'agentic' ), static fn( array $record ): bool => ( $record['topic'] ?? '' ) === $topic ) );
	}

	public function instruction_index(): string {
		$lines = [];
		foreach ( $this->browse( false, 'agentic' ) as $record ) {
			$lines[] = '- ' . (string) $record['slug'] . ': ' . str_replace( [ "\r", "\n" ], ' ', (string) ( $record['description'] ?? '' ) );
		}
		return implode( "\n", $lines );
	}

	private function lifecycle( string $action, int $id, string $token ): bool|\WP_Error {
		$previous = $this->repository->find_id( $id );
		if ( null === $previous ) {
			return new \WP_Error( 'stonewright_skill_not_found', 'Skill not found.' );
		}
		$record = 'trash' === $action ? LifecycleDecisions::trash( $previous ) : LifecycleDecisions::restore( $previous );
		return is_wp_error( $record ) ? $record : $this->exchange( $action, $previous, $record, $token );
	}

	/** @param array<string, mixed> $previous @param array<string, mixed> $record */
	private function exchange( string $action, array $previous, array $record, string $token ): bool|\WP_Error {
		$revision = $this->revision_number( $previous );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		$record['id'] = $previous['id'];
		$record['slug'] = $previous['slug'];
		$record['revision'] = $revision + 1;
		// Only a save carries new text. Disabling, trashing, restoring, and rollback keep stored
		// text as it is, so they are validated only when the result would be live.
		if ( 'save' === $action || self::live( $record ) ) {
			$record = RecordRules::normalize( $record );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$ready = $this->activation_check( $record );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}
		$result = $this->mutate( $action, $record, $token, fn() => $this->repository->exchange_record( (int) $previous['id'], $record, $revision ) );
		return true === $result ? true : ( is_wp_error( $result ) ? $result : $this->failed() );
	}

	/** @param array<string, mixed> $record */
	private function revision_number( array $record ): int|\WP_Error {
		return isset( $record['revision'], $record['id'] ) && is_int( $record['revision'] ) && $record['revision'] > 0 && is_int( $record['id'] ) && $record['id'] > 0
			? $record['revision'] : new \WP_Error( 'stonewright_skill_repository_contract', 'The repository did not supply a positive logical id and revision.' );
	}

	/** @param array<string, mixed> $record @param callable(): int|bool|\WP_Error $write */
	private function mutate( string $action, array $record, string $token, callable $write, ?string $review_hash = null ): int|bool|\WP_Error {
		// Lifecycle moves can carry stored rows that were never normalized, so every field has a default.
		$summary = [ 'skill_id' => (int) ( $record['id'] ?? 0 ), 'slug' => (string) $record['slug'], 'revision' => (int) $record['revision'], 'source' => (string) ( $record['source'] ?? '' ), 'status' => (string) ( $record['status'] ?? '' ), 'verification_count' => (int) ( $record['verification_count'] ?? 0 ), 'content_hash' => hash( 'sha256', (string) ( $record['content'] ?? '' ) ) ];
		if ( null !== $review_hash ) {
			$summary['review_hash'] = $review_hash;
		}
		$permission = $this->boundary->authorize( $action, $summary, $token );
		if ( true !== $permission ) {
			$this->boundary->record( $action, $summary, 'blocked' );
			return is_wp_error( $permission ) ? $permission : new \WP_Error( 'stonewright_skill_permission_denied', 'The authenticated write boundary refused this action.' );
		}
		try {
			$result = $write();
		} catch ( \Throwable $error ) {
			$result = $this->failed();
		}
		$this->boundary->record( $action, $summary, is_wp_error( $result ) || false === $result || 0 === $result ? 'error' : 'ok' );
		return $result;
	}

	private function failed(): \WP_Error {
		return new \WP_Error( 'stonewright_skill_write_failed', 'The repository could not complete the atomic skill mutation.' );
	}

	/**
	 * Only lint findings block activation. Missing plugin components never do: enabled records the
	 * site's choice, and visibility hides the skill from agents until its components are present.
	 *
	 * @param array<string, mixed> $record
	 */
	private function activation_check( array $record ): bool|\WP_Error {
		if ( ! self::live( $record ) ) {
			return true;
		}
		$lint = RecordRules::review( $record, $this->tool_names, $this->trigger_policy );
		return [] === RecordRules::blocking( $record, $lint['errors'] )
			? true : new \WP_Error( 'stonewright_skill_lint_failed', 'Resolve lint findings before activating the skill.', [ 'lint' => $lint ] );
	}

	/** Matches runtime visibility, where a record without a lifecycle state counts as active. @param array<string, mixed> $record */
	private static function live( array $record ): bool {
		return ! empty( $record['enabled'] ) && 'active' === ( $record['status'] ?? 'active' );
	}

	private function reserved_identity( string $slug ): bool {
		return in_array( $slug, $this->reserved, true );
	}

	private function reserved_refusal(): \WP_Error {
		return new \WP_Error( 'stonewright_skill_identity_reserved', 'This identity is reserved for a built-in skill.' );
	}
}
