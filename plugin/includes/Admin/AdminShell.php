<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Shared premium admin shell: sticky header, tab nav, and mode pill.
 *
 * Presentation only — form handlers and ability gates stay on their pages.
 */
final class AdminShell {

	/**
	 * Premium IA: ≤6 menu groups. Page slugs stay stable; only labels/order change.
	 *
	 * @return list<array{id:string,label:string,pages:array<string,string>}>
	 */
	public static function menu_groups(): array {
		return [
			[
				'id'    => 'overview',
				'label' => __( 'Overview', 'stonewright' ),
				'pages' => [
					'stonewright-status' => __( 'Dashboard', 'stonewright' ),
				],
			],
			[
				'id'    => 'connect',
				'label' => __( 'Connect', 'stonewright' ),
				'pages' => [
					'stonewright' => __( 'Setup', 'stonewright' ),
				],
			],
			[
				'id'    => 'capabilities',
				'label' => __( 'Capabilities', 'stonewright' ),
				'pages' => [
					'stonewright-abilities' => __( 'AI Abilities', 'stonewright' ),
				],
			],
			[
				'id'    => 'workflows',
				'label' => __( 'Workflows', 'stonewright' ),
				'pages' => [
					'stonewright-sandbox' => __( 'Sandbox', 'stonewright' ),
				],
			],
			[
				'id'    => 'design-library',
				'label' => __( 'Design Library', 'stonewright' ),
				'pages' => [
					'stonewright-design-studio'    => __( 'Design Studio', 'stonewright' ),
					'stonewright-visual-workspace' => __( 'Visual Workspace', 'stonewright' ),
					'stonewright-blueprints'       => __( 'Blueprints', 'stonewright' ),
					'stonewright-prompts'          => __( 'Prompts', 'stonewright' ),
				],
			],
			[
				'id'    => 'safety-diagnostics',
				'label' => __( 'Safety & Diagnostics', 'stonewright' ),
				'pages' => [
					'stonewright-audit-log' => __( 'Audit Log', 'stonewright' ),
					'stonewright-memory'    => __( 'Memory', 'stonewright' ),
					'stonewright-skills'    => __( 'Skills', 'stonewright' ),
				],
			],
		];
	}

	/**
	 * Registered Stonewright admin pages (slug => label), IA order.
	 *
	 * Single source of truth for shell navigation (flattened from menu_groups).
	 *
	 * @return array<string, string>
	 */
	public static function pages(): array {
		$pages = [];
		foreach ( self::menu_groups() as $group ) {
			foreach ( $group['pages'] as $slug => $label ) {
				$pages[ $slug ] = $label;
			}
		}
		return $pages;
	}

	/**
	 * Open the shared shell (header + nav + content wrapper).
	 *
	 * @param array<string, mixed> $args Optional. Supports `title` string for page H1 in content.
	 */
	public static function open( string $current_slug, array $args = [] ): void {
		$groups  = self::menu_groups();
		$mode    = (string) get_option( 'stonewright_mode', 'development' );
		if ( ! in_array( $mode, [ 'development', 'staging', 'production-safe' ], true ) ) {
			$mode = 'development';
		}
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '';
		$classes = [ 'sw-shell', 'wrap', 'stonewright-admin-shell' ];

		$mode_class = 'sw-mode-pill--' . $mode;
		$mode_label = match ( $mode ) {
			'production-safe' => __( 'production-safe', 'stonewright' ),
			'staging'         => __( 'staging', 'stonewright' ),
			default           => __( 'development', 'stonewright' ),
		};

		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-sw-shell>
			<header class="sw-shell__header" role="banner">
				<div class="sw-shell__brand">
					<?php
					$logo_url = defined( 'STONEWRIGHT_URL' )
						? (string) constant( 'STONEWRIGHT_URL' ) . 'assets/admin/stonewright-logo.png'
						: '';
					$logo_2x  = defined( 'STONEWRIGHT_URL' )
						? (string) constant( 'STONEWRIGHT_URL' ) . 'assets/brand/stonewright-logo-512.png'
						: '';
					if ( '' !== $logo_url ) :
						?>
						<img
							class="sw-shell__logo-img"
							src="<?php echo esc_url( $logo_url ); ?>"
							<?php if ( '' !== $logo_2x ) : ?>
								srcset="<?php echo esc_url( $logo_url ); ?> 1x, <?php echo esc_url( $logo_2x ); ?> 2x"
							<?php endif; ?>
							alt="<?php echo esc_attr( __( 'Stonewright', 'stonewright' ) ); ?>"
							width="28"
							height="28"
							decoding="async"
						/>
					<?php else : ?>
						<span class="sw-shell__logo" aria-hidden="true">⬡</span>
					<?php endif; ?>
					<span class="sw-shell__product"><?php esc_html_e( 'Stonewright', 'stonewright' ); ?></span>
				</div>
				<nav class="sw-shell__nav" aria-label="<?php esc_attr_e( 'Stonewright admin', 'stonewright' ); ?>">
					<?php foreach ( $groups as $group ) : ?>
						<?php
						$group_slugs   = array_keys( $group['pages'] );
						$group_current = in_array( $current_slug, $group_slugs, true );
						$is_multi      = count( $group['pages'] ) > 1;
						?>
						<div
							class="sw-shell__nav-group<?php echo $group_current ? ' is-current-group' : ''; ?><?php echo $is_multi ? ' sw-shell__nav-group--multi' : ''; ?>"
							data-sw-nav-group="<?php echo esc_attr( $group['id'] ); ?>"
						>
							<span class="sw-shell__nav-group-label" aria-hidden="true"><?php echo esc_html( $group['label'] ); ?></span>
							<?php foreach ( $group['pages'] as $slug => $label ) : ?>
								<?php
								$url     = admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
								$current = ( $slug === $current_slug );
								?>
								<a
									class="sw-shell__nav-link<?php echo $current ? ' is-current' : ''; ?>"
									href="<?php echo esc_url( $url ); ?>"
									<?php echo $current ? ' aria-current="page"' : ''; ?>
								><?php echo esc_html( $label ); ?></a>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</nav>
				<div class="sw-shell__meta">
					<span class="sw-mode-pill <?php echo esc_attr( $mode_class ); ?>" title="<?php esc_attr_e( 'Operating mode', 'stonewright' ); ?>">
						<?php echo esc_html( $mode_label ); ?>
					</span>
					<?php if ( '' !== $version ) : ?>
						<span class="sw-shell__version" aria-hidden="true"><?php echo esc_html( $version ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<details class="sw-notice-drawer" data-sw-notice-drawer hidden>
				<summary class="sw-notice-drawer__summary">
					<?php esc_html_e( 'Other WordPress notices', 'stonewright' ); ?>
					<span class="sw-notice-drawer__count" data-sw-notice-count>0</span>
				</summary>
				<div class="sw-notice-drawer__body" data-sw-notice-body></div>
			</details>

			<div class="sw-shell__content">
		<?php
		unset( $args );
	}

	/**
	 * Close the shared shell content wrapper.
	 */
	public static function close(): void {
		?>
			</div><!-- .sw-shell__content -->
		</div><!-- .sw-shell -->
		<?php
	}
}
