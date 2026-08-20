<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionImportSanitizer;
use Stonewright\WpMcp\Design\Quality\QualityRuleRegistry;
use Stonewright\WpMcp\Security\Permissions;
use WP_Error;

/**
 * Design Direction admin tab. Writes go through the existing direction store.
 */
final class DesignPage {

	public const SLUG       = 'stonewright-design';
	public const CAPABILITY = 'manage_options';

	private const IMPORT_NONCE   = 'stonewright_design_import';
	private const ACTIVATE_NONCE = 'stonewright_design_activate';

	private static ?DesignDirectionService $service = null;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_design_import', [ self::class, 'handle_import' ] );
		add_action( 'admin_post_stonewright_design_activate', [ self::class, 'handle_activate' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Design', 'stonewright' ),
			__( 'Design', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function service(): DesignDirectionService {
		return self::$service ??= new DesignDirectionService();
	}

	public static function set_service_for_tests( ?DesignDirectionService $service ): void {
		self::$service = $service;
	}

	public static function reset_for_tests(): void {
		self::$service = null;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import_document( string $markdown, int $actor_id ) {
		$sanitized = DirectionImportSanitizer::sanitize( $markdown, 'import' );
		if ( $sanitized instanceof WP_Error ) {
			return $sanitized;
		}

		$ready  = true === ( $sanitized['contract']['readiness']['ready'] ?? false );
		$result = self::service()->save(
			[
				'contract'    => $sanitized['contract'],
				'source_type' => 'import',
				'status'      => $ready ? 'ready' : 'draft',
				'source_refs' => [
					'rationale' => (string) $sanitized['sanitized_rationale'],
				],
			],
			$actor_id
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( $ready ) {
			self::service()->activate( (int) $result['id'], $actor_id );
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_active( bool $on, int $id, int $actor_id ) {
		if ( ! $on ) {
			update_option( DesignDirectionService::ACTIVE_OPTION, 0 );
			return [
				'ok'     => true,
				'active' => false,
				'id'     => 0,
			];
		}

		return self::service()->activate( $id, $actor_id );
	}

	public static function handle_import(): void {
		if ( ! Permissions::manage_options() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( self::IMPORT_NONCE );

		$markdown = isset( $_POST['design_markdown'] )
			? (string) wp_unslash( $_POST['design_markdown'] )
			: '';
		$result   = self::import_document( $markdown, get_current_user_id() );
		$notice   = $result instanceof WP_Error ? 'import-error' : 'imported';

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                    => self::SLUG,
					'stonewright_design_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_activate(): void {
		if ( ! Permissions::manage_options() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( self::ACTIVATE_NONCE );

		$id = isset( $_POST['direction_id'] ) ? absint( $_POST['direction_id'] ) : 0;
		$on = isset( $_POST['direction_enabled'] ) && '1' === (string) $_POST['direction_enabled'];
		self::set_active( $on, $id, get_current_user_id() );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                      => self::SLUG,
					'stonewright_design_notice' => $on ? 'activated' : 'deactivated',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render(): void {
		if ( ! Permissions::manage_options() ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		$active = self::service()->active();
		$notice = isset( $_GET['stonewright_design_notice'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( (string) wp_unslash( $_GET['stonewright_design_notice'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$floor  = QualityRuleRegistry::floor();

		AdminShell::open( self::SLUG );
		?>
		<div class="sw-design-page stonewright-design-page">
			<header class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Design', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Import a DESIGN.md direction, review the active contract, and keep the quality floor on generated pages.', 'stonewright' ); ?></p>
				</div>
			</header>

			<?php if ( in_array( $notice, [ 'imported', 'activated', 'deactivated' ], true ) ) : ?>
				<div class="notice notice-success is-dismissible sw-notice"><p><?php esc_html_e( 'Design direction updated.', 'stonewright' ); ?></p></div>
			<?php elseif ( 'import-error' === $notice ) : ?>
				<div class="notice notice-error sw-notice"><p><?php esc_html_e( 'The DESIGN.md import was rejected. Check front matter, tokens, and secret-like prose.', 'stonewright' ); ?></p></div>
			<?php endif; ?>

			<section class="sw-card" aria-labelledby="stonewright-active-direction">
				<h2 id="stonewright-active-direction"><?php esc_html_e( 'Active direction', 'stonewright' ); ?></h2>
				<?php if ( ! is_array( $active ) ) : ?>
					<p><?php esc_html_e( 'No active design direction. Import a DESIGN.md file to create one.', 'stonewright' ); ?></p>
				<?php else : ?>
					<?php self::render_active( $active ); ?>
				<?php endif; ?>
			</section>

			<section class="sw-card" aria-labelledby="stonewright-design-import">
				<h2 id="stonewright-design-import"><?php esc_html_e( 'Import DESIGN.md', 'stonewright' ); ?></h2>
				<p><?php esc_html_e( 'Paste a direction document with JSON front matter (tokens, dials, Do/Don\'t). Secrets and tool instructions are stripped before storage.', 'stonewright' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="stonewright_design_import"/>
					<?php wp_nonce_field( self::IMPORT_NONCE ); ?>
					<p>
						<label class="screen-reader-text" for="design_markdown"><?php esc_html_e( 'DESIGN.md', 'stonewright' ); ?></label>
						<textarea
							id="design_markdown"
							name="design_markdown"
							class="large-text code"
							rows="12"
							required
						></textarea>
					</p>
					<p class="sw-actions">
						<button type="submit" class="sw-btn sw-btn--primary"><?php esc_html_e( 'Import', 'stonewright' ); ?></button>
					</p>
				</form>
			</section>

			<section class="sw-card" aria-labelledby="stonewright-quality-floor">
				<h2 id="stonewright-quality-floor"><?php esc_html_e( 'Quality floor', 'stonewright' ); ?></h2>
				<p><?php esc_html_e( 'Generated pages are checked against these measurable rules. Missing evidence is not a pass.', 'stonewright' ); ?></p>
				<ul class="sw-checklist">
					<?php foreach ( $floor as $rule ) : ?>
						<li>
							<code><?php echo esc_html( (string) $rule['id'] ); ?></code>
							<?php echo esc_html( (string) $rule['summary'] ); ?>
							<span class="description">(<?php echo esc_html( (string) $rule['severity'] ); ?>)</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		</div>
		<?php
		AdminShell::close();
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private static function render_active( array $record ): void {
		$contract = is_array( $record['contract'] ?? null ) ? $record['contract'] : [];
		$identity = is_array( $contract['identity'] ?? null ) ? $contract['identity'] : [];
		$tokens   = is_array( $contract['tokens'] ?? null ) ? $contract['tokens'] : [];
		$dials    = is_array( $contract['dials'] ?? null ) ? $contract['dials'] : [];
		$guidance = is_array( $contract['guidance'] ?? null ) ? $contract['guidance'] : [];
		$do       = is_array( $guidance['do'] ?? null ) ? $guidance['do'] : [];
		$avoid    = is_array( $guidance['avoid'] ?? null ) ? $guidance['avoid'] : [];
		?>
		<p>
			<strong><?php echo esc_html( (string) ( $identity['name'] ?? $record['slug'] ?? '' ) ); ?></strong>
			<?php if ( '' !== (string) ( $identity['summary'] ?? '' ) ) : ?>
				— <?php echo esc_html( (string) $identity['summary'] ); ?>
			<?php endif; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="stonewright_design_activate"/>
			<input type="hidden" name="direction_id" value="<?php echo esc_attr( (string) (int) ( $record['id'] ?? 0 ) ); ?>"/>
			<?php wp_nonce_field( self::ACTIVATE_NONCE ); ?>
			<label class="sw-switch">
				<input type="checkbox" name="direction_enabled" value="1" data-stonewright-submit-form checked />
				<span><?php esc_html_e( 'Active', 'stonewright' ); ?></span>
			</label>
		</form>
		<?php if ( [] !== $dials ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: variance, 2: density, 3: motion */
					esc_html__( 'Dials — variance %1$d, density %2$d, motion %3$d', 'stonewright' ),
					(int) ( $dials['variance'] ?? 0 ),
					(int) ( $dials['density'] ?? 0 ),
					(int) ( $dials['motion'] ?? 0 )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		$colors = is_array( $tokens['colors'] ?? null ) ? $tokens['colors'] : [];
		if ( [] !== $colors ) :
			?>
			<p><strong><?php esc_html_e( 'Colors', 'stonewright' ); ?></strong></p>
			<ul>
				<?php foreach ( $colors as $token => $value ) : ?>
					<li><code><?php echo esc_html( (string) $token ); ?></code> <?php echo esc_html( (string) $value ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( [] !== $do ) : ?>
			<p><strong><?php esc_html_e( 'Do', 'stonewright' ); ?></strong></p>
			<ul>
				<?php foreach ( $do as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( [] !== $avoid ) : ?>
			<p><strong><?php esc_html_e( 'Don\'t', 'stonewright' ); ?></strong></p>
			<ul>
				<?php foreach ( $avoid as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}
}
