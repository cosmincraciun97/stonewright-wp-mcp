<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** Read-only, bounded report of pre-existing Elementor schema debt. */
final class LegacyDebtReport extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-legacy-debt-report';
	}

	public function label(): string {
		return __( 'Elementor legacy debt report', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports bounded pre-existing Elementor schema violations and safe migration hints without normalizing or writing the document.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
				'max_issues' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'               => [ 'type' => 'integer' ],
				'architecture'          => [ 'type' => 'string' ],
				'schema_version'        => [ 'type' => 'string' ],
				'invalid_paths_count'   => [ 'type' => 'integer' ],
				'issues_truncated'      => [ 'type' => 'boolean' ],
				'issues'                => [ 'type' => 'array' ],
				'required_approval'     => [ 'type' => 'boolean' ],
				'recommended_next_tool' => [ 'type' => 'string' ],
				'migration_apply_requires_review' => [ 'type' => 'boolean' ],
				'write_performed'       => [ 'type' => 'boolean' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		$report = ( new DocumentHealth() )->execute( $args );
		if ( $report instanceof \WP_Error ) {
			return $report;
		}
		$issues = [];
		foreach ( (array) ( $report['issues'] ?? [] ) as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$code = sanitize_key( (string) ( $issue['code'] ?? 'legacy_violation' ) );
			$issues[] = [
				'target'                   => sanitize_text_field( (string) ( $issue['element_id'] ?? '' ) ),
				'widget_type'              => sanitize_key( (string) ( $issue['widget_type'] ?? '' ) ),
				'path'                     => sanitize_text_field( (string) ( $issue['path'] ?? '' ) ),
				'issue_code'               => $code,
				'safe_migration_available' => self::safe_migration_available( $code ),
				'risk'                     => self::risk( $code ),
				'required_approval'        => true,
			];
		}
		$schema_version = get_post_meta( (int) $report['post_id'], '_elementor_version', true );
		$schema_version = is_scalar( $schema_version ) ? sanitize_text_field( (string) $schema_version ) : '';

		return [
			'post_id'             => (int) $report['post_id'],
			'architecture'        => (string) ( $report['architecture'] ?? 'empty' ),
			'schema_version'      => $schema_version,
			'invalid_paths_count' => count( $issues ),
			'issues_truncated'    => (bool) ( $report['issues_truncated'] ?? false ),
			'issues'              => $issues,
			'required_approval'   => [] !== $issues,
			'recommended_next_tool'        => 'stonewright/elementor-schema',
			'migration_apply_requires_review' => true,
			'write_performed'     => false,
		];
	}

	private static function safe_migration_available( string $code ): bool {
		return in_array( $code, [ 'settings_alias_applied', 'legacy_container_alias' ], true );
	}

	private static function risk( string $code ): string {
		if ( str_contains( $code, 'unknown' ) || str_contains( $code, 'duplicate' ) ) {
			return 'high';
		}
		if ( str_contains( $code, 'type' ) || str_contains( $code, 'invalid' ) ) {
			return 'medium';
		}
		return 'low';
	}
}
