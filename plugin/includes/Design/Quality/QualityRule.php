<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Quality;

/**
 * One deterministic rendered-quality rule.
 *
 * A rule is data, not behaviour: it declares what it needs, how loud it is
 * allowed to be, and which unit of evidence it inspects. The evaluator owns the
 * loop. Keeping rules declarative is what makes coverage reporting possible —
 * the evaluator can say "this rule ran against 5 units and could not run
 * against 2" without every rule reimplementing that bookkeeping.
 *
 * Two callables are supplied per rule:
 *
 * - `applies` decides whether the rule is relevant to a unit at all. A focus
 *   rule is not relevant to a paragraph, and reporting that as missing evidence
 *   would drown the report in noise.
 * - `evaluate` returns `null` when the evidence the rule needs was never
 *   captured, an empty list when the unit is clean, or a list of findings. The
 *   `null` case is the important one: it becomes `not_checked`, never a pass.
 */
final class QualityRule {

	/**
	 * Allowlisted severities, loudest first.
	 *
	 * @var list<string>
	 */
	public const SEVERITIES = [ 'error', 'warning', 'info' ];

	/**
	 * Allowlisted evidence units a rule can inspect.
	 *
	 * @var list<string>
	 */
	public const SCOPES = [ 'viewport', 'element' ];

	/** @var string Stable rule identifier, used by waivers and stored reports. */
	private string $id;

	/** @var string One of self::SEVERITIES. */
	private string $severity;

	/** @var string One of self::SCOPES. */
	private string $scope;

	/**
	 * Evidence paths the rule reads, for documentation and error messages.
	 *
	 * @var list<string>
	 */
	private array $requires;

	/** @var string Short human-readable statement of what the rule checks. */
	private string $summary;

	/** @var bool True when the rule enforces direction guidance rather than a measurable defect. */
	private bool $guidance;

	/** @var callable(array<string,mixed>,array<string,mixed>):bool */
	private $applies;

	/** @var callable(array<string,mixed>,array<string,mixed>):(list<array<string,mixed>>|null) */
	private $evaluate;

	/**
	 * @param string                                                                        $id       Stable rule identifier.
	 * @param string                                                                        $severity One of self::SEVERITIES.
	 * @param string                                                                        $scope    One of self::SCOPES.
	 * @param list<string>                                                                  $requires Evidence paths the rule reads.
	 * @param string                                                                        $summary  Human-readable statement.
	 * @param bool                                                                          $guidance True for direction-guidance rules.
	 * @param callable(array<string,mixed>,array<string,mixed>):bool                        $applies  Relevance predicate.
	 * @param callable(array<string,mixed>,array<string,mixed>):(list<array<string,mixed>>|null) $evaluate Rule body.
	 */
	public function __construct(
		string $id,
		string $severity,
		string $scope,
		array $requires,
		string $summary,
		bool $guidance,
		callable $applies,
		callable $evaluate
	) {
		$this->id       = $id;
		$this->severity = $severity;
		$this->scope    = $scope;
		$this->requires = $requires;
		$this->summary  = $summary;
		$this->guidance = $guidance;
		$this->applies  = $applies;
		$this->evaluate = $evaluate;
	}

	/**
	 * Stable rule identifier.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Severity this rule reports at before waivers are applied.
	 */
	public function severity(): string {
		return $this->severity;
	}

	/**
	 * Evidence unit this rule inspects.
	 */
	public function scope(): string {
		return $this->scope;
	}

	/**
	 * Evidence paths the rule reads.
	 *
	 * @return list<string>
	 */
	public function requires(): array {
		return $this->requires;
	}

	/**
	 * Human-readable statement of what the rule checks.
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * True when the rule enforces direction guidance rather than a measurable defect.
	 */
	public function is_guidance(): bool {
		return $this->guidance;
	}

	/**
	 * Whether this rule is relevant to the given evidence unit.
	 *
	 * @param array<string,mixed> $unit    Viewport or element evidence.
	 * @param array<string,mixed> $context Evaluation context.
	 */
	public function applies_to( array $unit, array $context ): bool {
		return (bool) ( $this->applies )( $unit, $context );
	}

	/**
	 * Run the rule.
	 *
	 * @param array<string,mixed> $unit    Viewport or element evidence.
	 * @param array<string,mixed> $context Evaluation context.
	 * @return list<array<string,mixed>>|null Findings, or null when required evidence is absent.
	 */
	public function evaluate( array $unit, array $context ): ?array {
		return ( $this->evaluate )( $unit, $context );
	}
}
