<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

/**
 * A read-only description of somewhere skills come from.
 *
 * A source never fetches, executes, or evaluates anything. It is handed a list
 * of rows that already exist in memory and reports what it holds, so adding a
 * source can widen the catalog but can never widen what Stonewright runs.
 *
 * @stonewright-status stable
 */
final class SkillSource {

	public const KIND_BUILTIN  = 'builtin';
	public const KIND_DATABASE = 'database';
	public const KIND_EXTERNAL = 'external';

	private string $id;

	private string $label;

	private string $kind;

	/** @var list<array<string, mixed>> */
	private array $skills;

	/**
	 * @param string                     $id     Machine id, lowercase letters, digits, and hyphens.
	 * @param string                     $label  Human-readable name shown in the admin.
	 * @param string                     $kind   One of the KIND_* constants.
	 * @param list<array<string, mixed>> $skills Rows the source offers.
	 */
	public function __construct( string $id, string $label, string $kind = self::KIND_EXTERNAL, array $skills = [] ) {
		$this->id    = self::sanitize_id( $id );
		$this->label = trim( $label );
		$this->kind  = in_array( $kind, [ self::KIND_BUILTIN, self::KIND_DATABASE, self::KIND_EXTERNAL ], true )
			? $kind
			: self::KIND_EXTERNAL;
		$this->skills = array_values(
			array_filter(
				$skills,
				static fn( mixed $skill ): bool => is_array( $skill ) && '' !== (string) ( $skill['slug'] ?? '' )
			)
		);
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return '' !== $this->label ? $this->label : $this->id;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function is_external(): bool {
		return self::KIND_EXTERNAL === $this->kind;
	}

	/** @return list<array<string, mixed>> */
	public function skills(): array {
		return $this->skills;
	}

	/**
	 * Slugs an external source is allowed to claim.
	 *
	 * Built-in and database sources own the unqualified namespace; everyone
	 * else has to say who they are in the slug itself.
	 */
	public function slug_prefix(): string {
		return $this->is_external() ? $this->id . '/' : '';
	}

	/**
	 * Downgrade a caller-declared kind to external.
	 *
	 * Only Stonewright builds built-in and database sources; anything arriving
	 * through register() or the filter is external no matter what it claims.
	 */
	public function as_external(): self {
		return $this->is_external() ? $this : new self( $this->id, $this->label, self::KIND_EXTERNAL, $this->skills );
	}

	/** @return array{id: string, label: string, kind: string, count: int} */
	public function to_array(): array {
		return [
			'id'    => $this->id,
			'label' => $this->label(),
			'kind'  => $this->kind,
			'count' => count( $this->skills ),
		];
	}

	private static function sanitize_id( string $id ): string {
		return (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( trim( $id ) ) );
	}
}
