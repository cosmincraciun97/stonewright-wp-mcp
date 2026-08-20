<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\PublicApiContractSnapshot;

/**
 * Ratchet: every write/delete ability must invoke ConfirmationGuard (or
 * ConfirmationToken::verify_or_error) in production-safe, be hard-blocked
 * (V4FeatureGate write path), or sit on a reviewed low-risk allowlist.
 *
 * Registry metadata has no write/delete flag, so this test classifies from
 * ability meta(), mutating name verbs, contract Write detection, and
 * EXTRA_WRITERS. Task 2 closes remaining ungated writers; do not weaken
 * this assertion to stay green.
 *
 * @covers \Stonewright\WpMcp\Abilities\Common\ConfirmationGuard
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class ProductionSafeCoverageTest extends TestCase {

	/**
	 * Reviewed writers that may skip the production-safe confirmation token.
	 * Values are one-line justifications. Keep this tiny. Do not list anything
	 * that creates, updates, or deletes posts, users, menus, templates,
	 * skills, memory, plugins, or settings.
	 *
	 * @var array<string, string>
	 */
	private const LOW_RISK_ALLOWLIST = [
		'stonewright/media-set-alt' => 'Updates attachment alt meta only; no post/user/menu/template/skill/memory/plugin/settings mutation.',
		'stonewright/site-backup-page' => 'Creates a restore snapshot; does not change live post content.',
		'stonewright/elementor-v3-backup-page' => 'Creates an Elementor restore snapshot; does not change live post content.',
		'stonewright/elementor-post-write-verify' => 'Cache/CSS regen and HTML assertions after a write; does not mutate post content.',
	];

	/**
	 * Writers the name/contract classifiers miss. Add a name here only after
	 * confirming the ability mutates WordPress or Stonewright state.
	 *
	 * @var list<string>
	 */
	private const EXTRA_WRITERS = [
		'stonewright/design-quality-check',
	];

	/**
	 * Name fragments that must never appear on the low-risk allowlist.
	 *
	 * @var list<string>
	 */
	private const MUST_GATE_NAME_MARKERS = [
		'content-',
		'user-',
		'menu-',
		'template',
		'skills-',
		'memory-',
		'plugin-',
		'settings-',
		'learning-record',
		'feedback-capture',
		'instructions-set',
	];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => false,
			'stonewright_mcp_surface'               => 'full',
			'stonewright_essential_extra_abilities' => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_writer_enumeration_includes_p0_bulk_upsert(): void {
		$writers = $this->write_delete_abilities();
		$this->assertArrayHasKey(
			'stonewright/content-bulk-upsert-posts',
			$writers,
			'P0 essential writer must be in the confirmation-coverage inventory.'
		);
		$this->assertGreaterThan(
			0,
			count( $writers ),
			'Inventory must enumerate at least one write/delete ability.'
		);
	}

	public function test_allowlist_entries_are_registered_writers_with_justifications(): void {
		$writers     = $this->write_delete_abilities();
		$violations  = [];
		foreach ( self::LOW_RISK_ALLOWLIST as $name => $justification ) {
			if ( ! isset( $writers[ $name ] ) ) {
				$violations[] = $name . ' is not a classified write/delete ability';
			}
			if ( '' === trim( $justification ) ) {
				$violations[] = $name . ' is missing a one-line justification';
			}
		}

		$this->assertSame( [], $violations );
	}

	public function test_allowlist_excludes_must_gate_domains(): void {
		$violations = [];
		foreach ( array_keys( self::LOW_RISK_ALLOWLIST ) as $name ) {
			foreach ( self::MUST_GATE_NAME_MARKERS as $marker ) {
				if ( str_contains( $name, $marker ) ) {
					$violations[] = $name . ' matches must-gate domain ' . $marker;
				}
			}
		}

		$this->assertSame( [], $violations );
	}

	public function test_extra_writers_are_registered_ability_names(): void {
		$registered = $this->all_abilities_by_name();
		$unknown    = [];
		foreach ( self::EXTRA_WRITERS as $name ) {
			if ( ! isset( $registered[ $name ] ) ) {
				$unknown[] = $name;
			}
		}

		$this->assertSame( [], $unknown );
	}

	public function test_every_write_or_delete_ability_is_gated_hard_blocked_or_allowlisted(): void {
		$ungated = [];
		foreach ( $this->write_delete_abilities() as $name => $class ) {
			if ( isset( self::LOW_RISK_ALLOWLIST[ $name ] ) ) {
				continue;
			}

			$source = $this->ability_source_with_parents( $class );
			if ( $this->invokes_confirmation_gate( $source ) || $this->is_hard_blocked_in_production_safe( $source ) ) {
				continue;
			}

			$ungated[] = $name;
		}

		sort( $ungated );

		$this->assertSame(
			[],
			$ungated,
			"Ungated production-safe writers (Task 2 must-gate, " . count( $ungated ) . "):\n" . implode( "\n", $ungated )
		);
	}

	/**
	 * @return array<string, class-string<Ability>>
	 */
	private function write_delete_abilities(): array {
		$out = [];
		foreach ( $this->all_abilities_by_name() as $name => $class ) {
			if ( $this->is_write_or_delete( $name, $class ) ) {
				$out[ $name ] = $class;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @return array<string, class-string<Ability>>
	 */
	private function all_abilities_by_name(): array {
		$out = [];
		foreach ( AbilityRegistry::list() as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}
			$ability = new $class();
			if ( ! $ability instanceof Ability ) {
				continue;
			}
			$out[ $ability->name() ] = $class;
		}
		return $out;
	}

	/**
	 * @param class-string<Ability> $class
	 */
	private function is_write_or_delete( string $name, string $class ): bool {
		if ( in_array( $name, self::EXTRA_WRITERS, true ) ) {
			return true;
		}

		$ability = new $class();
		$meta    = $ability->meta();
		if ( true === ( $meta['destructive'] ?? false ) || true === ( $meta['write'] ?? false ) ) {
			return true;
		}

		if ( self::name_is_mutating( $name ) ) {
			return true;
		}

		$row = PublicApiContractSnapshot::collect_ability( $class );
		return is_array( $row ) && 'Write' === ( $row['kind'] ?? '' );
	}

	private static function name_is_mutating( string $name ): bool {
		return 1 === preg_match(
			'/-(?:create|update|write|delete|remove|apply|insert|save|set|move|upload|optimize|activate|deactivate|toggle|register|define|bulk|record|duplicate|add|patch|restore|purge|import|migrate|repair|capture|generalize|execute|upsert|mutate|edit|run|queue|assign)\b/',
			$name
		);
	}

	private function invokes_confirmation_gate( string $source ): bool {
		return str_contains( $source, 'confirmation_token_error(' )
			|| str_contains( $source, 'production_safe_token_error(' )
			|| str_contains( $source, 'ConfirmationToken::verify_or_error' )
			|| str_contains( $source, 'ConfirmationToken::verify(' )
			|| str_contains( $source, 'require_sandbox_confirmation(' )
			|| str_contains( $source, 'require_confirmation(' )
			|| str_contains( $source, 'require_production_safe_token(' )
			|| str_contains( $source, 'audit_write(' );
	}

	private function is_hard_blocked_in_production_safe( string $source ): bool {
		if ( str_contains( $source, 'stonewright_v4_experimental_production_block' ) ) {
			return true;
		}

		return 1 === preg_match(
			'/V4FeatureGate::check\s*\(\s*(?:true|\$write|!\s*\$dry_run|!\s*\(bool\)|\(bool\)\s*\(\s*\$args\[.apply)/',
			$source
		);
	}

	/**
	 * @param class-string $class
	 */
	private function ability_source_with_parents( string $class ): string {
		$stop_at = [
			AbilityKernel::class,
			Ability::class,
		];
		$sources = [];
		try {
			$ref = new ReflectionClass( $class );
			while ( false !== $ref ) {
				$name = $ref->getName();
				if ( in_array( $name, $stop_at, true ) ) {
					break;
				}
				$file = $ref->getFileName();
				if ( is_string( $file ) && is_readable( $file ) ) {
					$contents = file_get_contents( $file );
					if ( is_string( $contents ) ) {
						$sources[] = $contents;
					}
				}
				$parent = $ref->getParentClass();
				$ref    = false === $parent ? false : $parent;
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}

		return implode( "\n", $sources );
	}
}
