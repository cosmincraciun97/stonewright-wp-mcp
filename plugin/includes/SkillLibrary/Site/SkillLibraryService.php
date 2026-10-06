<?php
/**
 * The site's skill service: the one object callers use to read and change skills.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint;
use Stonewright\WpMcp\SkillLibrary\ImportReview;
use Stonewright\WpMcp\SkillLibrary\LifecycleDecisions;
use Stonewright\WpMcp\SkillLibrary\MutationBoundary;
use Stonewright\WpMcp\SkillLibrary\PackInventory;
use Stonewright\WpMcp\SkillLibrary\RecordRules;
use Stonewright\WpMcp\SkillLibrary\Repository;
use Stonewright\WpMcp\SkillLibrary\SkillCatalog;
use Stonewright\WpMcp\SkillLibrary\SourceDirectory;
use Stonewright\WpMcp\SkillLibrary\VisibilityRules;
use Stonewright\WpMcp\Support\Logger;

/**
 * Reads return the rows callers have always read: stored columns as text plus
 * decoded `version_constraints` and `conflicts`. Trashed skills never appear in
 * them. Writes go through the library's catalog, or through the system writes
 * for the bundled pack and verified knowledge, so every mutation meets the
 * boundary this service was opened with. Errors carry an HTTP status.
 */
final class SkillLibraryService {

	/** Fields a local save may change. */
	private const EDITABLE = [ 'slug', 'title', 'description', 'content', 'enabled', 'enable_agentic', 'enable_prompt', 'topic', 'version_constraints', 'status' ];

	/** Fields only the catalog or the evidence workflow sets. Echoing the stored value back is not a claim. */
	private const BOOKKEEPING = [ 'verification_count', 'semantic_fingerprint', 'conflicts', 'revision' ];

	/** Claims a local save can never make; the catalog refuses them. */
	private const AUTHORITY = [ 'trust', 'trusted', 'verified', 'history' ];

	/** Fields the verified knowledge workflow writes on its candidate skills. */
	private const EVIDENCE = [ 'slug', 'title', 'description', 'content', 'enabled', 'enable_agentic', 'enable_prompt', 'status', 'topic', 'semantic_fingerprint', 'version_constraints', 'verification_count', 'conflicts' ];

	private const STATUS_BY_CODE = [
		'stonewright_skill_record_invalid'         => 400,
		'stonewright_skill_document_invalid'       => 400,
		'stonewright_skill_import_invalid'         => 400,
		'stonewright_skill_import_review_required' => 400,
		'stonewright_skill_lint_failed'            => 400,
		'stonewright_skill_sensitive_content'      => 400,
		'stonewright_skill_authority_claim'        => 403,
		'stonewright_skill_protected'              => 403,
		'stonewright_skill_identity_reserved'      => 403,
		'stonewright_skill_builtin'                => 403,
		'stonewright_skill_permission_denied'      => 403,
		'stonewright_skill_not_found'              => 404,
		'stonewright_skill_toggle_invalid'         => 409,
		'stonewright_skill_restore_invalid'        => 409,
		'stonewright_skill_delete_invalid'         => 409,
		'stonewright_skill_rollback_failed'        => 409,
		'stonewright_skill_import_exists'          => 409,
		'stonewright_skill_import_collision'       => 409,
		'stonewright_skill_slug_taken'             => 409,
		'stonewright_skill_write_conflict'         => 409,
		'stonewright_skill_repository_contract'    => 500,
		'stonewright_skill_write_failed'           => 500,
	];

	private Repository $repository;

	private MutationBoundary $boundary;

	/** @var callable(array<string, mixed>): bool|null */
	private $compatible;

	/** @var callable(): array<int, string> */
	private $tool_names;

	/** @var callable(): array<int, string> */
	private $reserved;

	private ?SkillCatalog $reader = null;

	private ?SkillCatalog $writer = null;

	/**
	 * @param callable(array<string, mixed>): bool|null $compatible Runtime compatibility check; the site's plugin inventory by default.
	 * @param callable(): array<int, string>|null      $tool_names Registered ability names that skill text may reference.
	 * @param callable(): array<int, string>|null      $reserved   Identities reserved for the bundled pack.
	 */
	public function __construct( Repository $repository, MutationBoundary $boundary, ?callable $compatible = null, ?callable $tool_names = null, ?callable $reserved = null ) {
		$this->repository = $repository;
		$this->boundary   = $boundary;
		$this->compatible = $compatible;
		$this->tool_names = $tool_names ?? [ self::class, 'registered_tool_names' ];
		$this->reserved   = $reserved ?? static fn(): array => BundledPack::slugs();
	}

	/**
	 * Assembles the service on the site tables for the channel a request arrives through.
	 * This is the only place the site adapters are put together.
	 */
	public static function open( string $channel = WordPressBoundary::ABILITY, ?\WP_REST_Request $request = null ): self {
		$boundary = null === $request ? new WordPressBoundary( $channel ) : WordPressBoundary::for_request( $channel, $request );
		$answers  = [];
		// Each distinct requirement is checked against the runtime once per service.
		$compatible = static function ( array $constraints ) use ( &$answers ): bool {
			$key = (string) wp_json_encode( $constraints );
			if ( ! array_key_exists( $key, $answers ) ) {
				$answers[ $key ] = RuntimeFingerprint::matches_constraints( $constraints );
			}
			return $answers[ $key ];
		};
		return new self( new WordPressRepository(), $boundary, $compatible );
	}

	/** @return array<int, string> */
	public static function registered_tool_names(): array {
		static $names = null;
		if ( null === $names ) {
			$names = [];
			foreach ( AbilityRegistry::list() as $class ) {
				if ( class_exists( $class ) ) {
					$names[] = ( new $class() )->name();
				}
			}
		}
		return $names;
	}

	/**
	 * Skills outside the trash, optionally only the enabled ones.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function records( bool $enabled_only = false ): array {
		return self::rows( $this->reader()->browse( $enabled_only, 'all' ) );
	}

	/**
	 * Skills an agent can use in a mode: `agentic`, `prompt`, or `discover`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function exposed( string $mode ): array {
		return in_array( $mode, [ 'agentic', 'prompt', 'discover' ], true ) ? self::rows( $this->reader()->browse( false, $mode ) ) : [];
	}

	/** @return array<string, mixed>|null */
	public function find( string $slug ): ?array {
		$slug   = RecordRules::identity( $slug );
		$record = '' === $slug ? null : $this->reader()->lookup( $slug );
		return null === $record ? null : RowFormat::exposed( $record );
	}

	/**
	 * The skill stored under an identity in any lifecycle state, the trash included.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_in_any_state( string $slug ): ?array {
		$slug   = RecordRules::identity( $slug );
		$record = '' === $slug ? null : $this->repository->find_slug( $slug );
		return null === $record ? null : RowFormat::exposed( $record );
	}

	/** @return array<string, mixed>|null */
	public function record_for_id( int $id ): ?array {
		$record = $id > 0 ? $this->reader()->identify( $id ) : null;
		return null === $record ? null : RowFormat::exposed( $record );
	}

	/**
	 * Requirements this runtime does not meet. An empty list means agents may use the skill.
	 *
	 * @param array<string, mixed> $skill
	 * @return array<int, string>
	 */
	public function missing_components( array $skill ): array {
		return VisibilityRules::missing( RowFormat::logical( $skill ), $this->compatible );
	}

	/**
	 * Enabled, active skills that cover a topic.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function topic_holders( string $topic ): array {
		if ( '' === $topic ) {
			return [];
		}
		return self::rows(
			array_filter(
				$this->repository->all_records(),
				static fn( array $record ): bool => $record['enabled'] && 'active' === $record['status'] && $topic === $record['topic']
			)
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function revisions_of( string $slug ): array {
		return self::rows( $this->reader()->revisions( RecordRules::identity( $slug ) ) );
	}

	/** The compact index of auto-matched skills that travels with the agent instructions. */
	public function agent_index(): string {
		$lines = $this->reader()->instruction_index();
		if ( '' === $lines ) {
			return '';
		}
		return implode(
			"\n",
			[
				'',
				'## Site Skills',
				'',
				'Auto-matched skills on this site. When a task matches one, load its body with `stonewright/skills-get` and follow it.',
				$lines,
			]
		);
	}

	/**
	 * Lint and trust findings for a proposed record.
	 *
	 * @param array<string, mixed> $record
	 * @return array{errors: array<int, string>, warnings: array<int, string>, trust: array<int, string>}
	 */
	public function review_record( array $record ): array {
		return RecordRules::review( $record, ( $this->tool_names )() );
	}

	/**
	 * Live skills from every source with their provenance, refused identities, and sources.
	 *
	 * @return array{skills: array<int, array<string, mixed>>, conflicts: array<int, array<string, string>>, sources: array<int, array<string, mixed>>}
	 */
	public function catalog_view(): array {
		$packaged = [];
		$local    = [];
		foreach ( $this->repository->all_records() as $record ) {
			if ( 'trashed' === $record['status'] ) {
				continue;
			}
			if ( LifecycleDecisions::protected_origin( $record ) ) {
				$packaged[] = $record;
			} else {
				$local[] = $record;
			}
		}
		$external = ExternalSources::read();
		$combined = SourceDirectory::combine( $packaged, $local, $external['entries'] );

		$skills = [];
		$counts = [];
		foreach ( $combined['skills'] as $record ) {
			$kind = (string) ( $record['source_kind'] ?? 'local' );
			$row  = RowFormat::exposed( $record );
			if ( 'external' === $kind ) {
				$row['id'] = '';
			}
			$skills[]       = $row;
			$key            = $kind . ':' . ( 'external' === $kind ? (string) ( $record['source_id'] ?? '' ) : $kind );
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		return [
			'skills'    => $skills,
			'conflicts' => array_values( array_merge( $combined['conflicts'], $external['refused'] ) ),
			'sources'   => self::source_list( $counts ),
		];
	}

	/** @return array<int, array<string, mixed>> */
	public function trashed(): array {
		$trashed = [];
		foreach ( $this->repository->all_records() as $record ) {
			if ( 'trashed' === $record['status'] ) {
				$record['source_kind'] = LifecycleDecisions::protected_origin( $record ) ? 'builtin' : 'local';
				$trashed[]             = $record;
			}
		}
		return self::rows( $trashed );
	}

	/**
	 * Creates a skill or updates one by identifier. Provenance is never an input:
	 * new skills are local, existing ones keep their source.
	 *
	 * @param array<string, mixed> $input
	 */
	public function save_skill( array $input, string $token = '' ): int|\WP_Error {
		$slug     = is_string( $input['slug'] ?? null ) ? RecordRules::identity( $input['slug'] ) : '';
		$previous = '' === $slug ? null : $this->repository->find_slug( $slug );
		$changes  = array_intersect_key( $input, array_flip( self::EDITABLE ) );
		if ( is_numeric( $input['revision'] ?? null ) && ( null === $previous || (int) $input['revision'] !== $previous['revision'] ) ) {
			// The caller read an earlier revision, or a skill that is gone; saving it back would discard a newer change.
			return new \WP_Error( 'stonewright_skill_write_conflict', 'The skill changed after it was read. Reload it and try again.', [ 'status' => 409 ] );
		}
		foreach ( self::BOOKKEEPING as $field ) {
			if ( array_key_exists( $field, $input ) && ! ( null !== $previous && self::echoes( $field, $input[ $field ], $previous ) ) ) {
				$changes[ $field ] = $input[ $field ];
			}
		}
		foreach ( self::AUTHORITY as $claim ) {
			if ( array_key_exists( $claim, $input ) ) {
				$changes[ $claim ] = $input[ $claim ];
			}
		}
		if ( 'trashed' === ( $changes['status'] ?? null ) ) {
			return self::invalid( 'Move a skill to the trash instead of saving it as trashed.' );
		}
		$title       = $changes['title'] ?? ( $previous['title'] ?? '' );
		$description = $changes['description'] ?? ( $previous['description'] ?? '' );
		if ( is_string( $title ) && '' !== trim( $title ) && is_string( $description ) && '' === trim( $description ) ) {
			// Without description text, the title is the trigger text agents match on.
			$changes['description'] = trim( $title );
		}
		if ( ! array_key_exists( 'status', $changes ) && null !== $previous && 'draft' === $previous['status'] && RowFormat::flag( $changes['enabled'] ?? false ) ) {
			// Saving a draft as enabled publishes it, as enabling it does.
			$changes['status'] = 'active';
		}
		$result = $this->writer()->store( $changes, $token );
		return is_wp_error( $result ) ? self::with_status( $result ) : $result;
	}

	public function set_enabled( int $id, bool $enabled ): bool|\WP_Error {
		return self::settled( $this->writer()->set_exposure( $id, $enabled ) );
	}

	public function move_to_trash( int $id ): bool|\WP_Error {
		return self::settled( $this->writer()->put_in_trash( $id ) );
	}

	public function bring_back( int $id ): bool|\WP_Error {
		return self::settled( $this->writer()->recover( $id ) );
	}

	public function erase_skill( int $id, string $token = '' ): bool|\WP_Error {
		return self::settled( $this->writer()->erase( $id, $token ) );
	}

	public function roll_back_skill( string $slug, int $revision, string $token = '' ): bool|\WP_Error {
		return self::settled( $this->writer()->restore_revision( RecordRules::identity( $slug ), $revision, $token ) );
	}

	/**
	 * Writes the candidate skill of verified knowledge, including its evidence.
	 *
	 * @param array<string, mixed> $input
	 */
	public function record_evidence( array $input ): int|\WP_Error {
		$slug = is_string( $input['slug'] ?? null ) ? RecordRules::identity( $input['slug'] ) : '';
		if ( '' === $slug ) {
			return self::invalid( 'The skill needs an identifier.' );
		}
		$record   = array_replace(
			array_intersect_key( $input, array_flip( self::EVIDENCE ) ),
			[
				'slug'   => $slug,
				'source' => 'candidate',
			]
		);
		$previous = $this->repository->find_slug( $slug );
		if ( null === $previous ) {
			if ( in_array( $slug, ( $this->reserved )(), true ) ) {
				return self::with_status( new \WP_Error( 'stonewright_skill_identity_reserved', 'This identity is reserved for a built-in skill.' ) );
			}
			$inserted = $this->system_writes()->insert( 'evidence', $record );
			return is_wp_error( $inserted ) ? self::with_status( $inserted ) : $inserted;
		}
		if ( 'candidate' !== $previous['source'] || 'trashed' === $previous['status'] ) {
			return self::with_status( new \WP_Error( 'stonewright_skill_protected', 'Verified knowledge updates only its own candidate skill.' ) );
		}
		$result = $this->system_writes()->replace( 'evidence', $previous, array_replace( $previous, $record ) );
		return is_wp_error( $result ) ? self::with_status( $result ) : (int) $previous['id'];
	}

	/**
	 * Takes a skill out of service because knowledge it relied on is stale or was
	 * replaced. Shipped skills keep their lifecycle and are only turned off.
	 */
	public function withdraw_skill( string $slug, string $reason ): bool|\WP_Error {
		$previous = $this->repository->find_slug( RecordRules::identity( $slug ) );
		if ( null === $previous || 'trashed' === $previous['status'] ) {
			return self::with_status( new \WP_Error( 'stonewright_skill_not_found', 'Skill not found.' ) );
		}
		$writes = $this->system_writes();
		if ( LifecycleDecisions::protected_origin( $previous ) ) {
			return $previous['enabled'] ? self::settled( $writes->replace( 'evidence', $previous, array_replace( $previous, [ 'enabled' => false ] ), false ) ) : true;
		}
		$withdrawn = array_replace(
			$previous,
			[
				'enabled'   => false,
				'status'    => 'stale',
				'conflicts' => [ $reason ],
			]
		);
		return self::settled( $writes->replace( 'evidence', $previous, $withdrawn, false ) );
	}

	/**
	 * Reviews an uploaded Markdown file without writing anything. The review
	 * carries a receipt bound to this user that the import must return.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function inspect_upload( string $filename, string $content ): array|\WP_Error {
		if ( 1 !== preg_match( '/\.md\z/i', $filename ) ) {
			return new \WP_Error( 'stonewright_skill_import_invalid', 'Skills import from Markdown only. Upload a .md file.', [ 'status' => 400 ] );
		}
		$review = ImportReview::examine( $filename, $content, ( $this->tool_names )() );
		if ( is_wp_error( $review ) ) {
			return self::with_status( $review );
		}
		$record    = $review['record'];
		$existing  = $this->repository->find_slug( (string) $record['slug'] );
		$reserved  = in_array( $record['slug'], ( $this->reserved )(), true );
		$findings  = self::trust_findings( $record, $review['trust'] );
		$blocked   = InstructionScreen::blocks( $findings );
		$collision = [
			'exists' => null !== $existing || $reserved,
			'source' => null !== $existing ? (string) $existing['source'] : ( $reserved ? 'builtin' : '' ),
		];

		return [
			'slug'            => $record['slug'],
			'title'           => $record['title'],
			'description'     => $record['description'],
			'content'         => $content,
			'bytes'           => strlen( $content ),
			'content_hash'    => $review['content_hash'],
			'body_hash'       => hash( 'sha256', (string) $record['content'] ),
			'lint'            => [
				'errors'     => $review['lint']['errors'],
				'warnings'   => $review['lint']['warnings'],
				'word_count' => self::word_count( (string) $record['content'] ),
			],
			'trust'           => [
				'findings' => $findings,
				'blocked'  => $blocked,
			],
			'collision'       => $collision,
			'ready_to_import' => [] === $review['lint']['errors'] && ! $collision['exists'] && ! $blocked,
			'filename'        => $filename,
			'review_hash'     => $review['review_hash'],
			'receipt'         => ImportReceipt::issue( $review['review_hash'], get_current_user_id() ),
		];
	}

	/**
	 * Imports a reviewed file as a disabled draft. The review is re-derived from
	 * the file, its receipt is verified at the boundary, and an existing skill
	 * is never replaced.
	 *
	 * @param array<string, mixed> $inspection The review returned by inspect_upload().
	 */
	public function import_upload( array $inspection, ?string $content = null, ?string $filename = null ): int|\WP_Error {
		$review = $inspection;
		if ( null !== $content ) {
			$review['content'] = $content;
		}
		if ( null !== $filename ) {
			$review['filename'] = $filename;
		}
		$receipt = is_string( $review['receipt'] ?? null ) ? $review['receipt'] : '';
		foreach ( [ 'filename', 'content', 'content_hash', 'slug', 'review_hash' ] as $field ) {
			if ( '' === $receipt || ! is_string( $review[ $field ] ?? null ) ) {
				return new \WP_Error( 'stonewright_skill_import_review_required', 'Inspect the file before importing it.', [ 'status' => 400 ] );
			}
		}
		$record = ImportReview::confirm( $review, ( $this->tool_names )() );
		if ( is_wp_error( $record ) ) {
			return self::with_status( $record );
		}
		if ( InstructionScreen::blocks( self::trust_findings( $record, [] ) ) ) {
			return new \WP_Error( 'stonewright_skill_import_blocked', 'The file tells an agent to override the plugin rules or safety gates, to disable confirmation tokens, or to send credentials elsewhere, so it was not imported.', [ 'status' => 400 ] );
		}
		if ( null !== $this->repository->find_slug( (string) $record['slug'] ) || in_array( $record['slug'], ( $this->reserved )(), true ) ) {
			return self::collision();
		}
		$result = $this->writer()->import_review( $review, $receipt );
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), [ 'stonewright_skill_import_exists', 'stonewright_skill_identity_reserved', 'stonewright_skill_slug_taken' ], true ) ) {
			return self::collision();
		}
		return is_wp_error( $result ) ? self::with_status( $result ) : $result;
	}

	/**
	 * Adds the skill of a knowledge bundle entry as a disabled draft, whatever
	 * exposure, status, or provenance the entry claims. Like a file import it never
	 * replaces a skill: an identity stored in any state, the trash included, or
	 * reserved for a built-in skill is a collision.
	 *
	 * @param array<string, mixed> $entry The `slug`, `title`, `description`, and `content` of the entry.
	 */
	public function import_bundle_skill( array $entry ): int|\WP_Error {
		$slug = is_string( $entry['slug'] ?? null ) ? RecordRules::identity( $entry['slug'] ) : '';
		if ( '' === $slug ) {
			return self::invalid( 'The skill needs an identifier.' );
		}
		if ( null !== $this->repository->find_slug( $slug ) || in_array( $slug, ( $this->reserved )(), true ) ) {
			return self::collision();
		}
		return $this->save_skill(
			array_replace(
				array_intersect_key( $entry, array_flip( [ 'title', 'description', 'content' ] ) ),
				[
					'slug'           => $slug,
					'enabled'        => false,
					'enable_agentic' => false,
					'enable_prompt'  => false,
					'status'         => 'draft',
				]
			)
		);
	}

	/** @return array{filename: string, markdown: string}|\WP_Error */
	public function export_markdown( int $id ): array|\WP_Error {
		$record = $id > 0 ? $this->repository->find_id( $id ) : null;
		if ( null === $record ) {
			return self::with_status( new \WP_Error( 'stonewright_skill_not_found', 'Skill not found.' ) );
		}
		return [
			'filename' => MarkdownExport::filename( $record ),
			'markdown' => MarkdownExport::document( $record ),
		];
	}

	/**
	 * Brings the site tables in line with the bundled pack.
	 *
	 * @return array<string, int>|\WP_Error
	 */
	public function refresh_bundled_pack( ?string $root = null ): array|\WP_Error {
		$inventory = PackInventory::scan( $root ?? BundledPack::root() );
		$result    = is_wp_error( $inventory ) ? $inventory : BundledPack::refresh( $inventory, $this->repository, $this->system_writes() );
		if ( is_wp_error( $result ) ) {
			Logger::warning( 'skill_pack_refresh_refused', [ 'code' => (string) $result->get_error_code() ] );
			return $result;
		}
		Logger::debug( 'skill_pack_refreshed', $result );
		return $result;
	}

	private function reader(): SkillCatalog {
		$this->reader ??= new SkillCatalog( $this->repository, $this->boundary, $this->compatible );
		return $this->reader;
	}

	private function writer(): SkillCatalog {
		$this->writer ??= new SkillCatalog( $this->repository, $this->boundary, $this->compatible, ( $this->tool_names )(), null, ( $this->reserved )() );
		return $this->writer;
	}

	private function system_writes(): SystemWrites {
		return new SystemWrites( $this->repository, $this->boundary, $this->tool_names );
	}

	/** @param array<string, mixed> $previous */
	private static function echoes( string $field, mixed $value, array $previous ): bool {
		return match ( $field ) {
			'verification_count', 'revision' => is_numeric( $value ) && (int) $value === $previous[ $field ],
			'semantic_fingerprint'           => is_string( $value ) && $value === $previous[ $field ],
			'conflicts'                      => RowFormat::logical( [ 'conflicts' => $value ] )['conflicts'] === $previous['conflicts'],
			default                          => false,
		};
	}

	/**
	 * @param array<int|string, array<string, mixed>> $records
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( array $records ): array {
		return array_values( array_map( [ RowFormat::class, 'exposed' ], $records ) );
	}

	/**
	 * @param array<string, int> $counts
	 * @return array<int, array<string, mixed>>
	 */
	private static function source_list( array $counts ): array {
		$sources = [];
		foreach ( $counts as $key => $count ) {
			[ $kind, $source_id ] = explode( ':', $key, 2 );
			$sources[]            = [
				'source_id' => $source_id,
				'kind'      => $kind,
				'label'     => match ( $kind ) {
					'builtin' => 'Built-in',
					'local'   => 'This site',
					default   => $source_id,
				},
				'skills'    => $count,
			];
		}
		return $sources;
	}

	/** @return array{rule: string, severity: string, message: string, line: int} */
	private static function finding( string $code ): array {
		return match ( $code ) {
			'privileged_instruction_request' => [
				'rule'     => $code,
				'severity' => 'warning',
				'message'  => 'The text mentions ignoring instructions or revealing credentials or secrets. Review how it says so before enabling it.',
				'line'     => 0,
			],
			'site_or_external_guidance'      => [
				'rule'     => $code,
				'severity' => 'info',
				'message'  => 'This guidance was written outside the shipped pack. Review it before enabling it.',
				'line'     => 0,
			],
			default                          => [
				'rule'     => $code,
				'severity' => 'warning',
				'message'  => $code,
				'line'     => 0,
			],
		};
	}

	/**
	 * Review findings for a file: the library's trust notes, which never block, and
	 * the instruction screen, whose errors keep the file out in the review and on import alike.
	 *
	 * @param array<string, mixed> $record
	 * @param array<int, string>   $trust
	 * @return array<int, array{rule: string, severity: string, message: string, line: int}>
	 */
	private static function trust_findings( array $record, array $trust ): array {
		return array_merge(
			array_map( [ self::class, 'finding' ], $trust ),
			InstructionScreen::findings( (string) ( $record['description'] ?? '' ), (string) ( $record['content'] ?? '' ) )
		);
	}

	private static function word_count( string $text ): int {
		return (int) preg_match_all( "/[\\p{L}\\p{N}]+(?:['\\x{2019}-][\\p{L}\\p{N}]+)*/u", $text );
	}

	private static function settled( bool|\WP_Error $result ): bool|\WP_Error {
		return is_wp_error( $result ) ? self::with_status( $result ) : $result;
	}

	private static function with_status( \WP_Error $error ): \WP_Error {
		$code = (string) $error->get_error_code();
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return $error;
		}
		$error->add_data( array_merge( is_array( $data ) ? $data : [], [ 'status' => self::STATUS_BY_CODE[ $code ] ?? 400 ] ), $code );
		return $error;
	}

	private static function collision(): \WP_Error {
		return new \WP_Error( 'stonewright_skill_import_collision', 'A skill with that slug already exists here. Import never overwrites; rename the file or edit the existing skill.', [ 'status' => 409 ] );
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_skill_record_invalid', $message, [ 'status' => 400 ] );
	}
}
