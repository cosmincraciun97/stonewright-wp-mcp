<?php
/**
 * ChangeSetV1: the one change-set shape a Stonewright write returns under `change_set`.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Builds and validates a ChangeSetV1 document.
 *
 * The document is a projection of the receipt a write already produces
 * (`write_receipt`, the theme/custom-code result keys, the snapshot record), not
 * a second receipt: `ChangeSet::build()` normalizes what the caller found and
 * bounds it. Every field below is always present; the published JSON schema is
 * `docs/contracts/change-set-v1.schema.json`.
 *
 * Optional extension fields: version 1 is additions-only. A new optional field is
 * declared by adding one entry to {@see ChangeSet::EXTENSIONS} (its JSON schema
 * fragment and whether it may be null) and the same property, marked
 * `"x-extension": true` and never required, to the published schema. A producer
 * passes the value under `extensions` to `build()`; the field is omitted
 * unless the producer supplies it, and a name that is not declared is dropped.
 */
final class ChangeSet {

	public const SCHEMA = 'ChangeSetV1';

	/** Longest change list; the exact size stays in `verification.evidence.counts`. */
	public const MAX_ENTRIES = 50;

	public const STATUS_VERIFIED   = 'verified';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_UNVERIFIED = 'unverified';

	/** @var list<string> */
	public const STATUSES = [ self::STATUS_VERIFIED, self::STATUS_FAILED, self::STATUS_UNVERIFIED ];

	/**
	 * Core fields, in output order. They are never removed or retyped in version 1.
	 *
	 * @var list<string>
	 */
	public const REQUIRED_FIELDS = [
		'schema',
		'change_set_id',
		'planned',
		'applied',
		'missing',
		'unexpected',
		'before_hash',
		'after_hash',
		'verification',
		'rollback_available',
		'rollback_recipe_ref',
		'repair_of',
		'supersedes',
		'approval_reason',
	];

	/** @var list<string> */
	public const CHANGE_LISTS = [ 'planned', 'applied', 'missing', 'unexpected' ];

	/**
	 * Declared optional extension fields: name => [ 'nullable' => bool, 'schema' => JSON schema of the value ].
	 * Version 1 declares none.
	 *
	 * @var array<string, array{nullable: bool, schema: array<string, mixed>}>
	 */
	public const EXTENSIONS = [];

	private const MAX_ID_LENGTH       = 96;
	private const MAX_REF_LENGTH      = 190;
	private const MAX_NOTE_LENGTH     = 160;
	private const MAX_EVIDENCE_TEXT   = 200;
	private const MAX_EVIDENCE_KEYS   = 22;
	private const MAX_EVIDENCE_LIST   = 20;
	private const MAX_EVIDENCE_MAP    = 24;
	private const SECRET_KEY_PATTERN  = '/token|secret|password|credential|authorization|cookie/';

	/** @return array<string, array{nullable: bool, schema: array<string, mixed>}> */
	public static function extension_fields(): array {
		return self::EXTENSIONS;
	}

	/**
	 * One planned, applied, missing or unexpected change.
	 *
	 * @return array<string, mixed>
	 */
	public static function entry( string $kind, string $ref, string $action, ?int $index = null, string $note = '' ): array {
		$entry = [ 'kind' => $kind, 'ref' => $ref, 'action' => $action ];
		if ( null !== $index ) {
			$entry['index'] = $index;
		}
		if ( '' !== $note ) {
			$entry['note'] = $note;
		}
		return $entry;
	}

	/**
	 * Deterministic identifier for a write that supplies no change_set_id.
	 *
	 * @param list<mixed> $seed
	 */
	public static function derive_id( array $seed ): string {
		$parts = array_map(
			static fn ( mixed $part ): string => is_scalar( $part ) ? (string) $part : (string) wp_json_encode( $part ),
			array_values( $seed )
		);
		return 'cs-' . substr( hash( 'sha256', implode( '|', $parts ) ), 0, 24 );
	}

	/** Map a write's own verification word onto verified, failed or unverified. */
	public static function normalize_status( string $raw ): string {
		return match ( strtolower( trim( $raw ) ) ) {
			'verified', 'passed'            => self::STATUS_VERIFIED,
			'failed', 'missing', 'mismatch' => self::STATUS_FAILED,
			default                         => self::STATUS_UNVERIFIED,
		};
	}

	/**
	 * Build a ChangeSetV1 document from what a write found.
	 *
	 * Recognized input keys: change_set_id, seed, planned, applied, missing,
	 * unexpected, before_hash, after_hash, verification {status, evidence},
	 * rollback_available, rollback_recipe_ref {kind, ref, target?}, repair_of,
	 * supersedes, approval_reason and extensions. Everything is normalized and
	 * bounded; nothing is trusted.
	 *
	 * @param array<string, mixed>                                                      $in
	 * @param array<string, array{nullable: bool, schema: array<string, mixed>}>|null   $extensions Declared extension fields; defaults to the version 1 registry.
	 * @return array<string, mixed>
	 */
	public static function build( array $in, ?array $extensions = null ): array {
		$extensions = $extensions ?? self::EXTENSIONS;

		$lists     = [];
		$counts    = [];
		$truncated = [];
		foreach ( self::CHANGE_LISTS as $name ) {
			$entries         = self::entries( $in[ $name ] ?? [] );
			$counts[ $name ] = count( $entries );
			if ( count( $entries ) > self::MAX_ENTRIES ) {
				$truncated[] = $name;
				$entries     = array_slice( $entries, 0, self::MAX_ENTRIES );
			}
			$lists[ $name ] = $entries;
		}

		$verification = is_array( $in['verification'] ?? null ) ? $in['verification'] : [];
		$evidence     = [ 'counts' => $counts ];
		if ( [] !== $truncated ) {
			$evidence['truncated'] = $truncated;
		}
		$evidence += self::evidence( is_array( $verification['evidence'] ?? null ) ? $verification['evidence'] : [] );

		$recipe    = self::recipe( $in['rollback_recipe_ref'] ?? null );
		$available = true === ( $in['rollback_available'] ?? false ) && null !== $recipe;

		$id = self::text( $in['change_set_id'] ?? '', self::MAX_ID_LENGTH );
		if ( '' === $id ) {
			$id = self::derive_id( is_array( $in['seed'] ?? null ) ? $in['seed'] : [] );
		}
		$approval = self::token( $in['approval_reason'] ?? '', 64 );

		$change_set = [
			'schema'              => self::SCHEMA,
			'change_set_id'       => $id,
			'planned'             => $lists['planned'],
			'applied'             => $lists['applied'],
			'missing'             => $lists['missing'],
			'unexpected'          => $lists['unexpected'],
			'before_hash'         => self::hash( $in['before_hash'] ?? '' ),
			'after_hash'          => self::hash( $in['after_hash'] ?? '' ),
			'verification'        => [
				'status'   => self::normalize_status( is_scalar( $verification['status'] ?? null ) ? (string) $verification['status'] : '' ),
				'evidence' => $evidence,
			],
			'rollback_available'  => $available,
			'rollback_recipe_ref' => $available ? $recipe : null,
			'repair_of'           => self::nullable_id( $in['repair_of'] ?? null ),
			'supersedes'          => self::nullable_id( $in['supersedes'] ?? null ),
			'approval_reason'     => '' === $approval ? null : $approval,
		];

		$given = is_array( $in['extensions'] ?? null ) ? $in['extensions'] : [];
		foreach ( $extensions as $name => $declaration ) {
			if ( ! array_key_exists( $name, $given ) ) {
				continue;
			}
			if ( null === $given[ $name ] ) {
				if ( ! empty( $declaration['nullable'] ) ) {
					$change_set[ $name ] = null;
				}
				continue;
			}
			$change_set[ $name ] = self::bounded( $given[ $name ], 3 );
		}

		return $change_set;
	}

	/**
	 * Validate a change set against the version 1 contract (the same rules as the
	 * published JSON schema). Returns one message per violation; empty when valid.
	 *
	 * @param array<string, mixed>                                                      $change_set
	 * @param array<string, array{nullable: bool, schema: array<string, mixed>}>|null   $extensions Declared extension fields; defaults to the version 1 registry.
	 * @return list<string>
	 */
	public static function validate( array $change_set, ?array $extensions = null ): array {
		$extensions = $extensions ?? self::EXTENSIONS;
		$errors     = [];

		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $change_set ) ) {
				$errors[] = $field . ': required';
			}
		}
		foreach ( array_keys( $change_set ) as $field ) {
			if ( ! in_array( $field, self::REQUIRED_FIELDS, true ) && ! isset( $extensions[ $field ] ) ) {
				$errors[] = $field . ': unknown field';
			}
		}

		if ( array_key_exists( 'schema', $change_set ) && self::SCHEMA !== $change_set['schema'] ) {
			$errors[] = 'schema: must be ' . self::SCHEMA;
		}
		if ( array_key_exists( 'change_set_id', $change_set ) && ! self::is_id( $change_set['change_set_id'] ) ) {
			$errors[] = 'change_set_id: must be 1-96 characters';
		}
		foreach ( self::CHANGE_LISTS as $name ) {
			if ( array_key_exists( $name, $change_set ) ) {
				array_push( $errors, ...self::list_errors( $name, $change_set[ $name ] ) );
			}
		}
		foreach ( [ 'before_hash', 'after_hash' ] as $field ) {
			if ( array_key_exists( $field, $change_set ) && ( ! is_string( $change_set[ $field ] ) || 1 !== preg_match( '/^([a-f0-9]{64})?$/', $change_set[ $field ] ) ) ) {
				$errors[] = $field . ': must be a lowercase SHA-256 or empty';
			}
		}
		if ( array_key_exists( 'verification', $change_set ) ) {
			array_push( $errors, ...self::verification_errors( $change_set['verification'] ) );
		}
		array_push( $errors, ...self::rollback_errors( $change_set ) );
		foreach ( [ 'repair_of', 'supersedes' ] as $field ) {
			if ( array_key_exists( $field, $change_set ) && null !== $change_set[ $field ] && ! self::is_id( $change_set[ $field ] ) ) {
				$errors[] = $field . ': must be null or 1-96 characters';
			}
		}
		if ( array_key_exists( 'approval_reason', $change_set ) && null !== $change_set['approval_reason']
			&& ( ! is_string( $change_set['approval_reason'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $change_set['approval_reason'] ) ) ) {
			$errors[] = 'approval_reason: must be null or a lowercase code';
		}
		foreach ( $extensions as $name => $declaration ) {
			if ( array_key_exists( $name, $change_set ) ) {
				array_push( $errors, ...self::extension_errors( (string) $name, $change_set[ $name ], $declaration ) );
			}
		}

		return $errors;
	}

	/**
	 * Input properties a write ability adds to carry the repair lineage.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function input_properties(): array {
		return [
			'repair_of'  => [
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => self::MAX_ID_LENGTH,
				'description' => 'change_set_id of the failed change this write repairs (ChangeSetV1 repair_of). A failed write reports its change_set_id with its error. A verified repair resolves the incident that change opened.',
			],
			'supersedes' => [
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => self::MAX_ID_LENGTH,
				'description' => 'change_set_id of an earlier change this write replaces (ChangeSetV1 supersedes). Recorded in the lineage only; it never resolves an incident.',
			],
		];
	}

	/**
	 * Compact output-schema entry for `change_set`.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_property(): array {
		return [
			'type'        => 'object',
			'description' => 'ChangeSetV1 (docs/contracts/change-set-v1.schema.json): planned, applied, missing and unexpected changes, before and after hashes, verification with evidence, rollback recipe and repair lineage.',
			'required'    => self::REQUIRED_FIELDS,
		];
	}

	/**
	 * The repair lineage a write asked for.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array{repair_of: string|null, supersedes: string|null}
	 */
	public static function lineage_args( array $args ): array {
		return [
			'repair_of'  => self::nullable_id( $args['repair_of'] ?? null ),
			'supersedes' => self::nullable_id( $args['supersedes'] ?? null ),
		];
	}

	/**
	 * Only the lineage keys that are set, for passing to a nested ability call.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, string>
	 */
	public static function lineage_input( array $args ): array {
		return array_filter( self::lineage_args( $args ), static fn ( ?string $value ): bool => null !== $value );
	}

	/**
	 * Audit-row metadata that a change set contributes.
	 *
	 * @param array<string, mixed> $change_set
	 * @return array<string, string>
	 */
	public static function audit_metadata( array $change_set ): array {
		$metadata = [];
		foreach ( [ 'change_set_id', 'repair_of', 'supersedes' ] as $field ) {
			if ( is_string( $change_set[ $field ] ?? null ) && '' !== $change_set[ $field ] ) {
				$metadata[ $field ] = $change_set[ $field ];
			}
		}
		foreach ( [ 'before_hash' => 'before_sha256', 'after_hash' => 'after_sha256' ] as $field => $key ) {
			if ( is_string( $change_set[ $field ] ?? null ) && '' !== $change_set[ $field ] ) {
				$metadata[ $key ] = $change_set[ $field ];
			}
		}
		return $metadata;
	}

	// ---------------------------------------------------------------------
	// Normalization.
	// ---------------------------------------------------------------------

	/** @return list<array<string, mixed>> */
	private static function entries( mixed $list ): array {
		if ( ! is_array( $list ) ) {
			return [];
		}
		$out  = [];
		$seen = [];
		foreach ( $list as $candidate ) {
			$entry = self::clean_entry( $candidate );
			if ( null === $entry ) {
				continue;
			}
			$fingerprint = $entry['kind'] . '|' . $entry['ref'] . '|' . $entry['action'] . '|' . ( $entry['index'] ?? '' );
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}
			$seen[ $fingerprint ] = true;
			$out[]                = $entry;
		}
		return $out;
	}

	/** @return array<string, mixed>|null */
	private static function clean_entry( mixed $candidate ): ?array {
		if ( ! is_array( $candidate ) ) {
			return null;
		}
		$kind   = self::token( $candidate['kind'] ?? '', 32 );
		$ref    = self::text( $candidate['ref'] ?? '', self::MAX_REF_LENGTH );
		$action = self::token( $candidate['action'] ?? '', 48 );
		if ( '' === $kind || '' === $ref || '' === $action ) {
			return null;
		}
		$entry = [ 'kind' => $kind, 'ref' => $ref, 'action' => $action ];
		if ( isset( $candidate['index'] ) && is_numeric( $candidate['index'] ) && (int) $candidate['index'] >= 0 ) {
			$entry['index'] = (int) $candidate['index'];
		}
		$note = self::text( $candidate['note'] ?? '', self::MAX_NOTE_LENGTH );
		if ( '' !== $note ) {
			$entry['note'] = $note;
		}
		return $entry;
	}

	/** @return array{kind: string, ref: string, target?: string}|null */
	private static function recipe( mixed $recipe ): ?array {
		if ( ! is_array( $recipe ) ) {
			return null;
		}
		$kind = self::token( $recipe['kind'] ?? '', 32 );
		$ref  = self::text( $recipe['ref'] ?? '', self::MAX_REF_LENGTH );
		if ( '' === $kind || '' === $ref ) {
			return null;
		}
		$out    = [ 'kind' => $kind, 'ref' => $ref ];
		$target = self::text( $recipe['target'] ?? '', self::MAX_REF_LENGTH );
		if ( '' !== $target ) {
			$out['target'] = $target;
		}
		return $out;
	}

	/**
	 * @param array<mixed> $evidence
	 * @return array<string, mixed>
	 */
	private static function evidence( array $evidence ): array {
		$out = [];
		foreach ( $evidence as $key => $value ) {
			if ( count( $out ) >= self::MAX_EVIDENCE_KEYS ) {
				break;
			}
			$key = self::evidence_key( $key );
			if ( '' === $key || in_array( $key, [ 'counts', 'truncated' ], true ) ) {
				continue;
			}
			$clean = self::evidence_value( $value );
			if ( null !== $clean ) {
				$out[ $key ] = $clean;
			}
		}
		return $out;
	}

	private static function evidence_key( mixed $key ): string {
		$key = substr( sanitize_key( (string) $key ), 0, 48 );
		return 1 === preg_match( self::SECRET_KEY_PATTERN, $key ) ? '' : $key;
	}

	private static function evidence_value( mixed $value ): mixed {
		if ( is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : null;
		}
		if ( is_string( $value ) ) {
			$text = self::text( $value, self::MAX_EVIDENCE_TEXT );
			return '' === $text ? null : $text;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		if ( array_is_list( $value ) ) {
			$list = [];
			foreach ( $value as $member ) {
				if ( count( $list ) >= self::MAX_EVIDENCE_LIST ) {
					break;
				}
				$clean = is_array( $member ) ? null : self::evidence_value( $member );
				if ( null !== $clean ) {
					$list[] = $clean;
				}
			}
			return [] === $list ? null : $list;
		}
		$map = [];
		foreach ( $value as $key => $member ) {
			if ( count( $map ) >= self::MAX_EVIDENCE_MAP ) {
				break;
			}
			$key   = self::evidence_key( $key );
			$clean = '' === $key || is_array( $member ) ? null : self::evidence_value( $member );
			if ( null !== $clean ) {
				$map[ $key ] = $clean;
			}
		}
		return [] === $map ? null : $map;
	}

	/** Bounded copy of an extension value: scalars, lists and maps up to $depth levels. */
	private static function bounded( mixed $value, int $depth ): mixed {
		if ( is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return self::text( $value, 255 );
		}
		if ( ! is_array( $value ) || $depth <= 0 ) {
			return null;
		}
		$out = [];
		foreach ( array_slice( $value, 0, 50, true ) as $key => $member ) {
			if ( is_int( $key ) ) {
				$out[] = self::bounded( $member, $depth - 1 );
				continue;
			}
			$clean_key = self::evidence_key( $key );
			if ( '' !== $clean_key ) {
				$out[ $clean_key ] = self::bounded( $member, $depth - 1 );
			}
		}
		return $out;
	}

	private static function text( mixed $value, int $max ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : '';
	}

	/** Lowercase code made of letters, digits and underscores that starts with a letter; '' when none survives. */
	private static function token( mixed $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) preg_replace( '/[^a-z0-9_]+/', '_', strtolower( trim( (string) $value ) ) ), '_' );
		return 1 === preg_match( '/^[a-z]/', $value ) ? substr( $value, 0, $max ) : '';
	}

	private static function hash( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function nullable_id( mixed $value ): ?string {
		$id = is_string( $value ) ? self::text( $value, self::MAX_ID_LENGTH ) : '';
		return '' === $id ? null : $id;
	}

	// ---------------------------------------------------------------------
	// Validation.
	// ---------------------------------------------------------------------

	private static function is_id( mixed $value ): bool {
		return is_string( $value ) && '' !== $value && mb_strlen( $value ) <= self::MAX_ID_LENGTH;
	}

	/** @return list<string> */
	private static function list_errors( string $name, mixed $list ): array {
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			return [ $name . ': must be a list' ];
		}
		$errors = [];
		if ( count( $list ) > self::MAX_ENTRIES ) {
			$errors[] = $name . ': at most ' . self::MAX_ENTRIES . ' entries';
		}
		foreach ( $list as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				$errors[] = $name . '[' . $index . ']: must be an object';
				continue;
			}
			foreach ( array_diff( array_keys( $entry ), [ 'kind', 'ref', 'action', 'index', 'note' ] ) as $unknown ) {
				$errors[] = $name . '[' . $index . '].' . $unknown . ': unknown field';
			}
			if ( ! is_string( $entry['kind'] ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $entry['kind'] ) ) {
				$errors[] = $name . '[' . $index . '].kind: invalid';
			}
			if ( ! is_string( $entry['ref'] ?? null ) || '' === $entry['ref'] || mb_strlen( $entry['ref'] ) > self::MAX_REF_LENGTH ) {
				$errors[] = $name . '[' . $index . '].ref: invalid';
			}
			if ( ! is_string( $entry['action'] ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,47}$/', $entry['action'] ) ) {
				$errors[] = $name . '[' . $index . '].action: invalid';
			}
			if ( array_key_exists( 'index', $entry ) && ( ! is_int( $entry['index'] ) || $entry['index'] < 0 ) ) {
				$errors[] = $name . '[' . $index . '].index: must be a non-negative integer';
			}
			if ( array_key_exists( 'note', $entry ) && ( ! is_string( $entry['note'] ) || mb_strlen( $entry['note'] ) > self::MAX_NOTE_LENGTH ) ) {
				$errors[] = $name . '[' . $index . '].note: too long';
			}
		}
		return $errors;
	}

	/** @return list<string> */
	private static function verification_errors( mixed $verification ): array {
		if ( ! is_array( $verification ) || array_is_list( $verification ) ) {
			return [ 'verification: must be an object' ];
		}
		$errors = [];
		foreach ( array_diff( array_keys( $verification ), [ 'status', 'evidence' ] ) as $unknown ) {
			$errors[] = 'verification.' . $unknown . ': unknown field';
		}
		if ( ! in_array( $verification['status'] ?? null, self::STATUSES, true ) ) {
			$errors[] = 'verification.status: must be verified, failed or unverified';
		}
		if ( ! array_key_exists( 'evidence', $verification ) ) {
			$errors[] = 'verification.evidence: required';
			return $errors;
		}
		return array_merge( $errors, self::evidence_errors( $verification['evidence'] ) );
	}

	/** @return list<string> */
	private static function evidence_errors( mixed $evidence ): array {
		if ( ! is_array( $evidence ) || array_is_list( $evidence ) ) {
			return [ 'verification.evidence: must be an object with counts' ];
		}
		$errors = [];
		if ( count( $evidence ) > 24 ) {
			$errors[] = 'verification.evidence: at most 24 properties';
		}
		$counts = $evidence['counts'] ?? null;
		if ( ! is_array( $counts ) || array_is_list( $counts ) ) {
			$errors[] = 'verification.evidence.counts: required';
		} else {
			foreach ( self::CHANGE_LISTS as $name ) {
				if ( ! is_int( $counts[ $name ] ?? null ) || $counts[ $name ] < 0 ) {
					$errors[] = 'verification.evidence.counts.' . $name . ': must be a non-negative integer';
				}
			}
			foreach ( array_diff( array_keys( $counts ), self::CHANGE_LISTS ) as $unknown ) {
				$errors[] = 'verification.evidence.counts.' . $unknown . ': unknown field';
			}
		}
		foreach ( $evidence as $key => $value ) {
			if ( 'counts' !== $key && ! self::is_evidence_value( $value ) ) {
				$errors[] = 'verification.evidence.' . $key . ': must be text, a number, a boolean, a short list or a flat map of those';
			}
		}
		return $errors;
	}

	private static function is_evidence_value( mixed $value ): bool {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return mb_strlen( $value ) <= self::MAX_EVIDENCE_TEXT;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		$is_list = array_is_list( $value );
		if ( count( $value ) > ( $is_list ? self::MAX_EVIDENCE_LIST : self::MAX_EVIDENCE_MAP ) ) {
			return false;
		}
		foreach ( $value as $member ) {
			if ( ! ( is_bool( $member ) || is_int( $member ) || is_float( $member ) || ( is_string( $member ) && mb_strlen( $member ) <= self::MAX_EVIDENCE_TEXT ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $change_set
	 * @return list<string>
	 */
	private static function rollback_errors( array $change_set ): array {
		$errors = [];
		if ( array_key_exists( 'rollback_available', $change_set ) && ! is_bool( $change_set['rollback_available'] ) ) {
			$errors[] = 'rollback_available: must be a boolean';
		}
		if ( ! array_key_exists( 'rollback_recipe_ref', $change_set ) ) {
			return $errors;
		}
		$recipe = $change_set['rollback_recipe_ref'];
		if ( null !== $recipe ) {
			if ( ! is_array( $recipe ) || array_is_list( $recipe ) ) {
				$errors[] = 'rollback_recipe_ref: must be null or an object';
			} else {
				foreach ( array_diff( array_keys( $recipe ), [ 'kind', 'ref', 'target' ] ) as $unknown ) {
					$errors[] = 'rollback_recipe_ref.' . $unknown . ': unknown field';
				}
				if ( ! is_string( $recipe['kind'] ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $recipe['kind'] ) ) {
					$errors[] = 'rollback_recipe_ref.kind: invalid';
				}
				foreach ( [ 'ref', 'target' ] as $field ) {
					if ( array_key_exists( $field, $recipe ) && ( ! is_string( $recipe[ $field ] ) || '' === $recipe[ $field ] || mb_strlen( $recipe[ $field ] ) > self::MAX_REF_LENGTH ) ) {
						$errors[] = 'rollback_recipe_ref.' . $field . ': invalid';
					} elseif ( 'ref' === $field && ! array_key_exists( 'ref', $recipe ) ) {
						$errors[] = 'rollback_recipe_ref.ref: required';
					}
				}
			}
		}
		if ( true === ( $change_set['rollback_available'] ?? null ) && ! is_array( $recipe ) ) {
			$errors[] = 'rollback_recipe_ref: required when rollback_available is true';
		}
		if ( true !== ( $change_set['rollback_available'] ?? null ) && null !== $recipe ) {
			$errors[] = 'rollback_recipe_ref: must be null when rollback_available is not true';
		}
		return $errors;
	}

	/**
	 * @param array{nullable?: bool, schema?: array<string, mixed>} $declaration
	 * @return list<string>
	 */
	private static function extension_errors( string $name, mixed $value, array $declaration ): array {
		if ( null === $value ) {
			return empty( $declaration['nullable'] ) ? [ $name . ': null is not allowed' ] : [];
		}
		$schema = $declaration['schema'] ?? [];
		if ( [] === $schema || ! class_exists( '\\Opis\\JsonSchema\\Validator' ) ) {
			return [];
		}
		try {
			$id        = 'https://stonewright.dev/contracts/change-set-v1/extension/' . $name . '/' . md5( (string) wp_json_encode( $schema ) );
			$validator = new \Opis\JsonSchema\Validator();
			$validator->resolver()->registerRaw( json_decode( (string) wp_json_encode( $schema ) ), $id );
			$result = $validator->validate( json_decode( (string) wp_json_encode( $value ) ), $id );
			return $result->isValid() ? [] : [ $name . ': does not match its declared schema' ];
		} catch ( \Throwable $throwable ) {
			return [ $name . ': its declared schema could not be applied' ];
		}
	}
}
