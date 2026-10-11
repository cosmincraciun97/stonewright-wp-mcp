<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Knowledge;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateRepository;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;

/**
 * Mutates candidates. A candidate becomes an active skill only after an administrator approved that exact
 * candidate in wp-admin, and a verified success counts only when a stored audit event proves it.
 *
 * @stonewright-status experimental
 */
final class KnowledgeCandidateRecord extends AbilityKernel {

	public function name(): string {
		return 'stonewright/knowledge-candidate-record';
	}

	public function label(): string {
		return __( 'Record knowledge lifecycle event', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates, verifies, promotes, rejects, or stales a site knowledge candidate, and rolls back versioned site skills. New research creates only a disabled draft. Verify counts a success only for the event_id of a verified audit event. Promote needs an administrator to approve the candidate in wp-admin; without that approval it returns an approval link and stops.', 'stonewright' );
	}

	public function category(): string {
		return 'knowledge';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'action' ],
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'action'              => [ 'type' => 'string', 'enum' => [ 'create', 'verify', 'promote', 'reject', 'mark_stale', 'skill_rollback' ] ],
				'id'                  => [ 'type' => 'integer', 'minimum' => 1 ],
				'topic'               => [ 'type' => 'string' ],
				'widget'              => [ 'type' => 'string' ],
				'control'             => [ 'type' => 'string' ],
				'fact'                => [ 'type' => 'string' ],
				'recipe'              => [ 'type' => 'string' ],
				'source_url'          => [ 'type' => 'string' ],
				'source_hash'         => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'fetched_at'          => [ 'type' => 'string' ],
				'version_constraints' => [ 'type' => 'object' ],
				'evidence_type'       => [ 'type' => 'string', 'enum' => [ 'official_docs', 'live_schema', 'fixture', 'user', 'design' ] ],
				'confidence'          => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ],
				'expires_at'          => [ 'type' => 'string' ],
				'create_draft_skill'  => [ 'type' => 'boolean', 'default' => true ],
				'task_id'             => [ 'type' => 'string', 'description' => 'For verify: the event_id of a successful audit event whose effect was verified.' ],
				'runtime_fingerprint' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'Ignored for a successful verify; the plugin records the live runtime fingerprint.' ],
				'success'             => [ 'type' => 'boolean' ],
				'user_approved'       => [ 'type' => 'boolean', 'default' => false, 'description' => 'Ignored. Promotion needs an administrator approval in wp-admin.' ],
				'approval_note'       => [ 'type' => 'string', 'description' => 'Ignored. The approving administrator writes the note in wp-admin.' ],
				'conflict_resolution' => [ 'type' => 'string', 'enum' => [ 'reject', 'replace' ], 'default' => 'reject' ],
				'skill_slug'          => [ 'type' => 'string' ],
				'revision'            => [ 'type' => 'integer', 'minimum' => 1 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'     => [ 'type' => 'boolean' ],
				'action' => [ 'type' => 'string' ],
				'result' => [ 'type' => 'object' ],
			],
			'required'             => [ 'ok', 'action', 'result' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $input ): array|\WP_Error {
				$action = (string) ( $input['action'] ?? '' );
				$result = match ( $action ) {
					'create'         => CandidateRepository::create( $input ),
					'verify'         => self::verify( $input ),
					'promote'        => CandidateRepository::promote( (int) ( $input['id'] ?? 0 ), (string) ( $input['conflict_resolution'] ?? 'reject' ) ),
					'reject'         => CandidateRepository::set_status( (int) ( $input['id'] ?? 0 ), 'rejected' ),
					'mark_stale'     => CandidateRepository::set_status( (int) ( $input['id'] ?? 0 ), 'stale' ),
					'skill_rollback' => self::rollback( (string) ( $input['skill_slug'] ?? '' ), (int) ( $input['revision'] ?? 0 ) ),
					default          => new \WP_Error( 'stonewright_knowledge_action_invalid', 'Choose a supported knowledge lifecycle action.' ),
				};
				if ( $result instanceof \WP_Error ) {
					return $result;
				}
				return [ 'ok' => true, 'action' => $action, 'result' => $result ];
			}
		);
	}

	/**
	 * A failed attempt is recorded as given. A success needs a stored audit event.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function verify( array $input ): array|\WP_Error {
		$id = (int) ( $input['id'] ?? 0 );
		if ( true === ( $input['success'] ?? false ) ) {
			return CandidateRepository::verify_from_event( $id, (string) ( $input['task_id'] ?? '' ) );
		}

		return CandidateRepository::verify( $id, (string) ( $input['task_id'] ?? '' ), (string) ( $input['runtime_fingerprint'] ?? '' ), false );
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function rollback( string $slug, int $revision ): array|\WP_Error {
		$slug    = sanitize_title( $slug );
		$library = SkillLibraryService::open();
		$result  = '' === $slug ? false : $library->roll_back_skill( $slug, $revision );
		if ( true !== $result ) {
			return new \WP_Error(
				'stonewright_skill_rollback_failed',
				'Skill revision not found or rollback failed.',
				is_wp_error( $result ) ? [ 'status' => 409, 'root_error_code' => (string) $result->get_error_code() ] : [ 'status' => 404 ]
			);
		}
		return [ 'skill_slug' => $slug, 'restored_revision' => $revision, 'skill' => $library->find( $slug ) ];
	}

	/** @return array<int, string> */
	protected function audit_redacted_keys(): array {
		return array_values( array_unique( array_merge( parent::audit_redacted_keys(), [ 'fact', 'recipe', 'approval_note' ] ) ) );
	}
}
