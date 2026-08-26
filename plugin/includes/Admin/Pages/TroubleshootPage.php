<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\DiagnosticsPanel;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Dedicated connection diagnostics page under Connect.
 */
final class TroubleshootPage {

	public const SLUG       = 'stonewright-troubleshoot';
	public const CAPABILITY = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Troubleshoot', 'stonewright' ),
			AdminShell::experimental_menu_title( __( 'Troubleshoot', 'stonewright' ) ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! Permissions::manage_options() ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		AdminShell::open( self::SLUG );
		?>
		<div class="sw-troubleshoot-page stonewright-troubleshoot-page">
			<header class="sw-setup-header">
				<div>
					<h1><?php esc_html_e( 'Troubleshoot', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Diagnose why an AI client cannot connect to this WordPress site.', 'stonewright' ); ?></p>
				</div>
			</header>
			<?php DiagnosticsPanel::render( self::SLUG, __( 'Connection checks', 'stonewright' ) ); ?>
			<?php self::render_compatibility_preflight(); ?>
			<?php self::render_elementor_provider_discovery(); ?>
		</div>
		<?php
		AdminShell::close();
	}

	private static function render_compatibility_preflight(): void {
		$report = McpAbilitiesCompatibilityPreflight::current() ?? McpAbilitiesCompatibilityPreflight::inspect();
		$compatible = true === ( $report['compatible'] ?? false );
		$symbols = [
			$report['adapter'] ?? [],
			$report['abilities']['registry'] ?? [],
			$report['abilities']['ability'] ?? [],
		];
		?>
		<section class="sw-setup-diagnostics" aria-label="<?php esc_attr_e( 'MCP runtime compatibility', 'stonewright' ); ?>">
			<h2><?php esc_html_e( 'MCP runtime compatibility', 'stonewright' ); ?></h2>
			<?php if ( $compatible ) : ?>
				<div class="sw-diag-card sw-diag-card--ok">
					<span class="sw-diag-card__icon" aria-hidden="true">✓</span>
					<span class="sw-diag-card__body">
						<strong class="sw-diag-card__label"><?php esc_html_e( 'Runtime contract compatible', 'stonewright' ); ?></strong>
						<span class="sw-diag-card__detail"><?php esc_html_e( 'Every loaded MCP and Abilities symbol satisfies its exact ABI.', 'stonewright' ); ?></span>
					</span>
				</div>
			<?php else : ?>
				<?php foreach ( $symbols as $symbol ) : ?>
					<?php
					$status = sanitize_key( (string) ( $symbol['status'] ?? '' ) );
					$abi_status = sanitize_key( (string) ( $symbol['abi']['status'] ?? '' ) );
					if ( ! in_array( $status, [ 'conflict', 'unavailable' ], true ) && ! in_array( $abi_status, [ 'incompatible', 'unavailable' ], true ) ) {
						continue;
					}
					$class = sanitize_text_field( (string) ( $symbol['class'] ?? 'unknown' ) );
					$reason = sanitize_key( (string) ( $symbol['reason'] ?? 'runtime_abi_incompatible' ) );
					$remediation = sanitize_text_field( (string) ( $symbol['remediation'] ?? '' ) );
					$issues = array_values( array_filter( array_map( 'sanitize_key', (array) ( $symbol['abi']['issues'] ?? [] ) ) ) );
					$owners = [];
					foreach ( (array) ( $symbol['owner_details'] ?? [] ) as $owner ) {
						if ( ! is_array( $owner ) ) {
							continue;
						}
						$name = sanitize_text_field( (string) ( $owner['owner'] ?? '' ) );
						$version = sanitize_text_field( (string) ( $owner['version'] ?? '' ) );
						if ( '' !== $name ) {
							$owners[] = '' === $version ? $name . ' — version unknown' : $name . ' — ' . $version;
						}
					}
					?>
					<div class="sw-diag-card sw-diag-card--error">
						<span class="sw-diag-card__icon" aria-hidden="true">×</span>
						<span class="sw-diag-card__body">
							<strong class="sw-diag-card__label"><?php echo esc_html( $class ); ?></strong>
							<span class="sw-diag-card__detail"><?php echo esc_html( [] === $owners ? __( 'Owner and version unavailable.', 'stonewright' ) : implode( '; ', $owners ) ); ?></span>
							<span class="sw-diag-card__detail"><?php echo esc_html( sprintf( __( 'Reason: %s', 'stonewright' ), $reason ) ); ?></span>
							<?php if ( [] !== $issues ) : ?>
								<span class="sw-diag-card__detail"><?php echo esc_html( sprintf( __( 'ABI issues: %s', 'stonewright' ), implode( ', ', $issues ) ) ); ?></span>
							<?php endif; ?>
							<span class="sw-diag-card__detail"><?php echo esc_html( $remediation ); ?></span>
						</span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_elementor_provider_discovery(): void {
		self::render_elementor_provider_report( ( new ProviderRouter() )->inspect( 0, 'auto' ) );
	}

	/** @param array<string,mixed> $report */
	public static function render_elementor_provider_report( array $report ): void {
		$providers = is_array( $report['providers'] ?? null ) ? $report['providers'] : [];
		$provider_count = max( count( $providers ), (int) ( $report['providers_count'] ?? 0 ) );
		$provider_suffix = true === ( $report['providers_truncated'] ?? false ) ? __( ' (showing a bounded summary)', 'stonewright' ) : '';
		$issues = is_array( $report['issues'] ?? null ) ? $report['issues'] : [];
		$preference = is_array( $report['native_preferred']['elementor/manage-default-styles'] ?? null ) ? $report['native_preferred']['elementor/manage-default-styles'] : [];
		$state = sanitize_key( (string) ( $preference['certification'] ?? 'unsupported' ) );
		$writes_enabled = true === ( $report['writes_enabled'] ?? false );
		$any_write_eligible = self::report_has_write_eligible( $providers );
		if ( $writes_enabled ) {
			$writes_copy = __( 'Upstream writes are enabled for write-eligible contracts.', 'stonewright' );
		} elseif ( $any_write_eligible ) {
			$writes_copy = __( 'Write-eligible contracts are inventoried. Upstream writes stay disabled until Stonewright safety closure can route them.', 'stonewright' );
		} else {
			$writes_copy = __( 'Upstream writes remain disabled until a write-eligible contract is certified and the full Stonewright safety closure is available.', 'stonewright' );
		}
		?>
		<section class="sw-setup-diagnostics" aria-label="<?php esc_attr_e( 'Elementor provider discovery', 'stonewright' ); ?>">
			<h2><?php esc_html_e( 'Elementor provider discovery', 'stonewright' ); ?></h2>
			<div class="sw-diag-card sw-diag-card--info">
				<span class="sw-diag-card__icon" aria-hidden="true">ⓘ</span>
				<span class="sw-diag-card__body">
					<strong class="sw-diag-card__label"><?php esc_html_e( 'elementor/manage-default-styles', 'stonewright' ); ?></strong>
					<span class="sw-diag-card__detail">
						<?php echo esc_html( sprintf( __( 'Native preference: %1$s. Providers discovered: %2$d%3$s.', 'stonewright' ), $state, $provider_count, $provider_suffix ) ); ?>
					</span>
					<?php foreach ( $providers as $provider ) : ?>
						<?php
						if ( ! is_array( $provider ) ) {
							continue;
						}
						$provider_id = self::bounded_public_token( (string) ( $provider['id'] ?? '' ) );
						$ownership_trust = self::bounded_public_token( (string) ( $provider['ownership_trust'] ?? $provider['ownership'] ?? '' ) );
						$schema_certification = self::bounded_public_token( (string) ( $provider['schema_certification'] ?? $provider['certification'] ?? '' ) );
						$write_eligible = self::provider_is_write_eligible( $provider );
						?>
						<span class="sw-diag-card__detail">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: provider id, 2: ownership trust, 3: schema certification, 4: yes/no */
									__( '%1$s — Ownership trust: %2$s. Schema certification: %3$s. Write eligible: %4$s.', 'stonewright' ),
									$provider_id,
									$ownership_trust,
									$schema_certification,
									$write_eligible ? __( 'yes', 'stonewright' ) : __( 'no', 'stonewright' )
								)
							);
							?>
						</span>
					<?php endforeach; ?>
					<span class="sw-diag-card__detail"><?php echo esc_html( $writes_copy ); ?></span>
				</span>
			</div>
			<?php foreach ( $issues as $issue ) : ?>
				<?php
				if ( ! is_array( $issue ) ) {
					continue;
				}
				$code = sanitize_key( (string) ( $issue['code'] ?? 'provider_issue' ) );
				$copy = self::provider_issue_copy( $code );
				$count = max( 1, (int) ( $issue['count'] ?? 1 ) );
				$source = self::provider_issue_source( (string) ( $issue['provider'] ?? '' ) );
				$count_copy = sprintf(
					_n( '%d occurrence', '%d occurrences', $count, 'stonewright' ),
					$count
				);
				?>
				<div class="sw-diag-card sw-diag-card--error">
					<span class="sw-diag-card__icon" aria-hidden="true">×</span>
					<span class="sw-diag-card__body">
						<strong class="sw-diag-card__label"><?php echo esc_html( $copy['label'] ); ?></strong>
						<span class="sw-diag-card__detail"><?php echo esc_html( $copy['explanation'] ); ?></span>
						<span class="sw-diag-card__detail"><?php echo esc_html( sprintf( __( '%1$s. Source: %2$s.', 'stonewright' ), $count_copy, $source ) ); ?></span>
						<span class="sw-diag-card__detail"><?php echo esc_html( $copy['remedy'] ); ?></span>
					</span>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * @param list<mixed> $providers
	 */
	private static function report_has_write_eligible( array $providers ): bool {
		foreach ( $providers as $provider ) {
			if ( is_array( $provider ) && self::provider_is_write_eligible( $provider ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $provider */
	private static function provider_is_write_eligible( array $provider ): bool {
		if ( false === ( $provider['read_only'] ?? true ) ) {
			return true;
		}
		foreach ( (array) ( $provider['capabilities'] ?? [] ) as $capability ) {
			if ( is_array( $capability ) && true === ( $capability['write_eligible'] ?? false ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{label:string,explanation:string,remedy:string} */
	private static function provider_issue_copy( string $code ): array {
		return match ( $code ) {
			'provider_discovery_failed' => [
				'label'       => __( 'Provider discovery failed', 'stonewright' ),
				'explanation' => __( 'One Elementor provider threw while Stonewright inventoried installed widgets and abilities. Other providers were still recorded.', 'stonewright' ),
				'remedy'      => __( 'Update or disable the failing Elementor add-on, then reload this page.', 'stonewright' ),
			],
			'incomplete_provider_evidence' => [
				'label'       => __( 'Incomplete provider evidence', 'stonewright' ),
				'explanation' => __( 'A discovered capability was missing a required plugin, class, or schema fingerprint, so it was omitted from inventory.', 'stonewright' ),
				'remedy'      => __( 'Update the source plugin so each widget or ability exposes complete runtime evidence.', 'stonewright' ),
			],
			'schema_unavailable' => [
				'label'       => __( 'Atomic schema unavailable', 'stonewright' ),
				'explanation' => __( 'An Atomic extension threw while exposing its props schema. Other Atomic types remain in inventory.', 'stonewright' ),
				'remedy'      => __( 'Update or disable the failing Atomic extension, then reload this page.', 'stonewright' ),
			],
			'schema_invalid' => [
				'label'       => __( 'Atomic schema invalid', 'stonewright' ),
				'explanation' => __( 'An Atomic extension returned a props schema that is not a map of prop objects.', 'stonewright' ),
				'remedy'      => __( 'Update the extension so its props schema is a finite object map.', 'stonewright' ),
			],
			'descriptor_unavailable' => [
				'label'       => __( 'Prop descriptor unavailable', 'stonewright' ),
				'explanation' => __( 'A runtime prop could not be normalized into a bounded descriptor, so that Atomic type stayed inventory-only.', 'stonewright' ),
				'remedy'      => __( 'Update the extension so props serialize to a finite JSON object.', 'stonewright' ),
			],
			'runtime_discovery_failed' => [
				'label'       => __( 'Atomic runtime discovery failed', 'stonewright' ),
				'explanation' => __( 'Elementor Atomic discovery stopped before the installed type list could be read.', 'stonewright' ),
				'remedy'      => __( 'Verify Elementor is loaded, then reload this page.', 'stonewright' ),
			],
			default => [
				'label'       => __( 'Provider discovery issue', 'stonewright' ),
				'explanation' => __( 'Stonewright recorded a bounded diagnostic and kept other providers in inventory.', 'stonewright' ),
				'remedy'      => __( 'Copy the report for support. Keep writes disabled until the issue is understood.', 'stonewright' ),
			],
		};
	}

	private static function provider_issue_source( string $provider ): string {
		return match ( $provider ) {
			'v3'        => __( 'Elementor widgets', 'stonewright' ),
			'atomic'    => __( 'Atomic widgets', 'stonewright' ),
			'abilities' => __( 'Upstream abilities', 'stonewright' ),
			''          => __( 'Elementor runtime', 'stonewright' ),
			default     => self::bounded_public_token( $provider ),
		};
	}

	private static function bounded_public_token( string $value ): string {
		$value = str_replace( '\\', '', sanitize_text_field( $value ) );
		if ( strlen( $value ) <= 100 ) {
			return $value;
		}
		return substr( $value, 0, 97 ) . '...';
	}
}
