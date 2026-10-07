<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Expertise\ThemeChrome;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\ChangeSetSources;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Updates Blocksy, Kadence, or GeneratePress chrome through the live theme API.
 *
 * @stonewright-status stable
 */
final class ThemeChromeUpdate extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/theme-chrome-update';
	}

	public function label(): string {
		return __( 'Update theme chrome', 'stonewright' );
	}

	public function description(): string {
		return __( 'Dry-runs or applies global color, typography, header, or footer changes for Blocksy, Kadence Theme, or GeneratePress. Page body stays on the Gutenberg finalizer.', 'stonewright' );
	}

	public function category(): string {
		return 'themes';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'theme' ],
			'properties'           => [
				'theme'              => [
					'type' => 'string',
					'enum' => ThemeChrome::THEMES,
				],
				'dry_run'            => [
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Preview the chrome patch without writing. Defaults to true.',
				],
				'colors'             => [ 'type' => 'object' ],
				'typography'         => [ 'type' => 'object' ],
				'header'             => [ 'type' => 'object' ],
				'footer'             => [ 'type' => 'object' ],
				'confirmation_token' => [ 'type' => 'string' ],
				'repair_of'          => ChangeSet::input_properties()['repair_of'],
				'supersedes'         => ChangeSet::input_properties()['supersedes'],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'              => [ 'type' => 'boolean' ],
				'theme'           => [ 'type' => 'string' ],
				'dry_run'         => [ 'type' => 'boolean' ],
				'changed'         => [ 'type' => 'boolean' ],
				'active'          => [ 'type' => 'boolean' ],
				'snapshot_id'     => [ 'type' => 'string' ],
				'effect_verified' => [ 'type' => 'boolean' ],
				'change_set'      => ChangeSet::output_property(),
			],
			'required'             => [ 'ok', 'theme', 'dry_run', 'changed', 'active' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$theme   = (string) ( $args['theme'] ?? '' );
				$dry_run = array_key_exists( 'dry_run', $args ) ? (bool) $args['dry_run'] : true;
				if ( ! in_array( $theme, ThemeChrome::THEMES, true ) ) {
					return $this->error( 'invalid_theme', __( 'Unknown theme chrome adapter.', 'stonewright' ) );
				}

				$current = ThemeChrome::read( $theme );
				if ( ! $current['active'] ) {
					if ( $dry_run ) {
						return [
							'ok'      => true,
							'theme'   => $theme,
							'dry_run' => true,
							'changed' => false,
							'active'  => false,
							'status'  => $current['status'],
						];
					}
					return $this->error( 'theme_inactive', __( 'The requested theme chrome adapter is not active.', 'stonewright' ) );
				}

				$plan = ThemeChrome::plan( $theme, $args );
				if ( $plan instanceof \WP_Error ) {
					return $plan;
				}

				$changes = $plan['changes'];
				$changed = [] !== $changes;
				if ( $dry_run || ! $changed ) {
					return [
						'ok'      => true,
						'theme'   => $theme,
						'dry_run' => true,
						'changed' => $changed,
						'active'  => true,
						'changes' => $changes,
					];
				}

				$verify = $args;
				unset( $verify['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$targets     = ThemeChrome::snapshot_targets( $changes );
				$snapshot_id = Backup::snapshot_options( $targets['option_keys'], $targets['theme_mod_keys'] );
				ThemeChrome::apply( $changes );
				$after       = ThemeChrome::read( $theme );
				$verified    = self::changes_match( $changes, $after );

				return [
					'ok'              => true,
					'theme'           => $theme,
					'dry_run'         => false,
					'changed'         => true,
					'active'          => true,
					'snapshot_id'     => $snapshot_id,
					'effect_verified' => $verified,
					'colors'          => $after['colors'],
					'typography'      => $after['typography'],
					'header'          => $after['header'],
					'footer'          => $after['footer'],
				];
			}
		);
	}

	/**
	 * @param list<array<string, mixed>> $changes
	 * @param array<string, mixed>       $after
	 */
	private static function changes_match( array $changes, array $after ): bool {
		foreach ( $changes as $change ) {
			$bucket = (string) $change['bucket'];
			$key    = (string) $change['key'];
			$live   = $after[ $bucket ][ $key ] ?? null;
			if ( wp_json_encode( $live ) !== wp_json_encode( $change['after'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * ChangeSetV1 of the chrome update: one planned change per theme key, hashed
	 * over the option and theme-mod values the option snapshot covers, with that
	 * snapshot as the rollback recipe.
	 *
	 * @param array<string, mixed>           $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>
	 */
	protected function change_set_inputs( array $args, array|\WP_Error $result, string $status ): ?array {
		$data    = ChangeSetSources::data( $result );
		$planned = [];
		if ( is_array( $data['changes'] ?? null ) && [] !== $data['changes'] ) {
			foreach ( array_values( $data['changes'] ) as $index => $change ) {
				$planned[] = ChangeSet::entry( 'theme_option', (string) ( $change['bucket'] ?? '' ) . '.' . (string) ( $change['key'] ?? '' ), 'set', $index );
			}
		} else {
			foreach ( [ 'colors', 'typography', 'header', 'footer' ] as $bucket ) {
				foreach ( array_keys( is_array( $args[ $bucket ] ?? null ) ? $args[ $bucket ] : [] ) as $key ) {
					$planned[] = ChangeSet::entry( 'theme_option', $bucket . '.' . $key, 'set', count( $planned ) );
				}
			}
		}
		return ChangeSetSources::options( $args, $result, $status, $planned, 'ok' === $status && array_key_exists( 'changed', $data ) && false === $data['changed'] );
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}
