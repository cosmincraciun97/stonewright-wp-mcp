<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\Nonce;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Table;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\LiveAbilities;

/**
 * Admin page for enabling and reviewing Stonewright abilities.
 *
 * Built from the shared UI layer. The page script (assets/admin/pages/abilities.js) switches abilities through
 * the routes of AbilitiesRestApi without a reload; the bulk form and the two admin-post handlers below stay as
 * the way in when script or REST is not available. Both ways change the option through AbilityToggles.
 */
final class AbilitiesPage {

	public const SLUG              = 'stonewright-abilities';
	public const CAPABILITY        = 'manage_options';
	public const NONCE_ACTION      = 'stonewright_toggle_ability';
	public const BULK_NONCE_ACTION = 'stonewright_bulk_abilities';

	/** Result codes the page prints a notice for (the query value `stonewright_toggled`). */
	private const RESULT_CODES = [ 'enabled', 'disabled', 'bulk-enabled', 'bulk-disabled', 'bulk-no-action', 'bulk-no-selection', 'bulk-no-category', 'missing-name' ];

	/** Words that are written in capitals or in a fixed form. */
	private const WORDS = [
		'acf'         => 'ACF',
		'api'         => 'API',
		'cli'         => 'CLI',
		'css'         => 'CSS',
		'fse'         => 'FSE',
		'html'        => 'HTML',
		'mcp'         => 'MCP',
		'php'         => 'PHP',
		'seo'         => 'SEO',
		'woocommerce' => 'WooCommerce',
		'wp'          => 'WP',
	];

	/** Categories whose name is not the slug read as words. */
	private const CATEGORY_LABELS = [
		'wp-cli'           => 'WP-CLI',
		'fse'              => 'Full-site editing',
		'elementor-widget' => 'Elementor widgets',
		'registered'       => 'Registered with WordPress',
	];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_toggle_ability', [ self::class, 'handle_toggle' ] );
		add_action( 'admin_post_stonewright_bulk_abilities', [ self::class, 'handle_bulk' ] );
		add_action( 'rest_api_init', [ AbilitiesRestApi::class, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function add_submenu(): void {
		// IA group: Capabilities — slug stonewright-abilities unchanged.
		add_submenu_page(
			'stonewright',
			__( 'AI Abilities', 'stonewright' ),
			__( 'AI Abilities', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Load the page script. The stylesheet is enqueued by AdminBootstrap's page style map.
	 */
	public static function enqueue( string $hook_suffix = '' ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page && ! str_contains( $hook_suffix, self::SLUG ) ) {
			return;
		}
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.1.0';
		$base    = defined( 'STONEWRIGHT_URL' ) ? (string) constant( 'STONEWRIGHT_URL' ) : '';
		wp_enqueue_script( 'stonewright-admin-abilities', $base . 'assets/admin/pages/abilities.js', [ 'stonewright-ui' ], $version, true );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stonewright' ) );
		}

		$master_enabled     = (bool) get_option( 'stonewright_enabled', false );
		$disabled_abilities = array_map( 'strval', (array) get_option( 'stonewright_disabled_abilities', [] ) );
		$groups             = AbilityHubCatalog::grouped();
		$label_counts       = self::label_counts( $groups );
		$stats              = AbilityToggles::stats();

		$status = $master_enabled
			? Badge::render( __( 'AI abilities on', 'stonewright' ), [ 'variant' => 'ok', 'dot' => true ] )
			: Badge::render( __( 'AI abilities off', 'stonewright' ), [ 'dot' => true ] );

		$html = Scope::wrap( self::page_html( $groups, $label_counts, $disabled_abilities, $stats, $master_enabled ), [ 'page' => true ] );

		AdminShell::open( self::SLUG, [ 'actions' => $status ] );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	/**
	 * @param array<string, array{id: string, label: string, registered: bool, categories: array<string, list<array<string, mixed>>>}> $groups
	 * @param array<string, int>                                                                                                          $label_counts
	 * @param list<string>                                                                                                                $disabled
	 * @param array{enabled: int, write: int, read: int, total: int}                                                                      $stats
	 */
	private static function page_html( array $groups, array $label_counts, array $disabled, array $stats, bool $master_enabled ): string {
		$notices = '';
		if ( ! $master_enabled ) {
			$notices .= Notice::callout(
				'warn',
				__( 'AI abilities are switched off', 'stonewright' ),
				__( 'You can still set up abilities here, but calls from AI clients are refused until you switch them on. Only ping answers.', 'stonewright' ),
				[ 'actions_html' => Button::render( __( 'Open Setup', 'stonewright' ), [ 'href' => admin_url( 'admin.php?page=stonewright' ), 'size' => 'sm' ] ) ]
			);
		}
		$notices .= self::result_notice();

		$providers = '';
		foreach ( $groups as $provider => $group ) {
			$providers .= self::provider_html( (string) $provider, $group, $label_counts, $disabled );
		}
		if ( [] === $groups ) {
			$providers = EmptyState::render(
				__( 'No abilities are registered', 'stonewright' ),
				__( 'Stonewright could not read its ability list. Reload the page; if this repeats, check the PHP error log for a fatal error from a plugin that registers abilities.', 'stonewright' ),
				[ 'variant' => 'error' ]
			);
		}

		$empty = Html::element(
			'div',
			[ 'data-sw-abilities-empty' => '', 'hidden' => true, 'id' => 'sw-abilities-empty' ],
			EmptyState::render(
				__( 'No abilities match', 'stonewright' ),
				__( 'Try fewer words, or clear the search.', 'stonewright' ),
				[
					'variant'      => 'no-results',
					'actions_html' => Button::render( __( 'Clear search', 'stonewright' ), [ 'attrs' => [ 'data-sw-abilities-clear' => '' ] ] ),
				]
			)
		);

		$form = Html::element(
			'form',
			[
				'id'     => 'stonewright-bulk-form',
				'method' => 'post',
				'action' => admin_url( 'admin-post.php' ),
				'class'  => 'sw-abilities__form',
			],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_bulk_abilities' ] )
				. Nonce::field( self::BULK_NONCE_ACTION )
				. self::toolbar_html( $groups, $stats )
				. $empty
				. Html::element( 'div', [ 'class' => 'sw-abilities__list' ], $providers )
		);

		return Html::element(
			'div',
			[
				'class'             => 'sw-abilities',
				'data-sw-abilities' => '',
				'data-rest-url'     => rest_url( 'stonewright/v1/admin/abilities' ),
				'data-rest-nonce'   => wp_create_nonce( AbilitiesRestApi::REST_NONCE_ACTION ),
				'data-toggle-nonce' => wp_create_nonce( self::NONCE_ACTION ),
				'data-bulk-nonce'   => wp_create_nonce( self::BULK_NONCE_ACTION ),
				'data-strings'      => (string) wp_json_encode( self::script_strings() ),
			],
			Html::element( 'div', [ 'class' => 'sw-abilities__notices', 'data-sw-abilities-notices' => '' ], $notices )
				. self::toggle_form_html()
				. $form
		);
	}

	/** The result of a form post, printed after the redirect. A refusal is a warning and is announced as one. */
	private static function result_notice(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result flags.
		$code    = isset( $_GET['stonewright_toggled'] ) ? sanitize_key( wp_unslash( (string) $_GET['stonewright_toggled'] ) ) : '';
		$changed = isset( $_GET['stonewright_changed'] ) ? max( 0, (int) $_GET['stonewright_changed'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $code, self::RESULT_CODES, true ) ) {
			return '';
		}

		$refused = str_starts_with( $code, 'bulk-no-' ) || 'missing-name' === $code;

		return Notice::render( $refused ? 'warn' : 'ok', AbilityToggles::message( $code, $changed ) );
	}

	/**
	 * The form the page script submits when the REST route cannot be reached. It is the form the switch used to
	 * submit, one for the whole page instead of one per ability.
	 */
	private static function toggle_form_html(): string {
		return Html::element(
			'form',
			[
				'id'                          => 'sw-abilities-toggle-form',
				'method'                      => 'post',
				'action'                      => admin_url( 'admin-post.php' ),
				'hidden'                      => true,
				'data-sw-abilities-toggle-form' => '',
			],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_toggle_ability' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'ability_name', 'value' => '' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'ability_enabled', 'value' => '' ] )
				. Nonce::field( self::NONCE_ACTION )
		);
	}

	/**
	 * Search, what is shown and what is on, and the bulk controls. It sticks under the admin bar from 783px up.
	 *
	 * @param array<string, array{id: string, label: string, registered: bool, categories: array<string, list<array<string, mixed>>>}> $groups
	 * @param array{enabled: int, write: int, read: int, total: int}                                                                      $stats
	 */
	private static function toolbar_html( array $groups, array $stats ): string {
		$search = Html::element( 'label', [ 'class' => 'sw-ui-visually-hidden', 'for' => 'stonewright-ability-search' ], Html::text( __( 'Search abilities', 'stonewright' ) ) )
			. Html::void(
				'input',
				[
					'type'               => 'search',
					'id'                 => 'stonewright-ability-search',
					'class'              => 'sw-ui-input sw-ui-toolbar__search',
					'placeholder'        => __( 'Search by name, tool, category or kind', 'stonewright' ),
					'autocomplete'       => 'off',
					'data-sw-ui-search'  => '',
					'aria-controls'      => 'sw-abilities-empty',
				]
			);

		$meta = Html::element(
			'div',
			[ 'class' => 'sw-ui-toolbar__meta', 'role' => 'status', 'aria-live' => 'polite', 'aria-atomic' => 'true', 'data-sw-abilities-meta' => '' ],
			Html::element(
				'span',
				[ 'data-sw-abilities-shown' => '' ],
				Html::text( sprintf( /* translators: %d: number of abilities */ _n( '%d ability', '%d abilities', $stats['total'], 'stonewright' ), $stats['total'] ) )
			)
			. Html::element( 'span', [ 'aria-hidden' => 'true' ], ' · ' )
			. Html::element(
				'span',
				[ 'data-sw-abilities-stats' => '' ],
				Html::text(
					sprintf(
						/* translators: 1: enabled count, 2: write count, 3: read count */
						__( 'Enabled %1$d · Write %2$d · Read %3$d', 'stonewright' ),
						$stats['enabled'],
						$stats['write'],
						$stats['read']
					)
				)
			)
		);

		$category_options = Html::element( 'option', [ 'value' => '' ], Html::text( __( 'Category', 'stonewright' ) ) );
		foreach ( self::category_options( $groups ) as $category ) {
			$category_options .= Html::element( 'option', [ 'value' => $category ], Html::text( self::category_label( $category ) ) );
		}
		$action_options = Html::element( 'option', [ 'value' => '' ], Html::text( __( 'Bulk action', 'stonewright' ) ) )
			. Html::element( 'option', [ 'value' => 'enable_selected' ], Html::text( __( 'Enable selected', 'stonewright' ) ) )
			. Html::element( 'option', [ 'value' => 'disable_selected' ], Html::text( __( 'Disable selected', 'stonewright' ) ) )
			. Html::element( 'option', [ 'value' => 'enable_category' ], Html::text( __( 'Enable whole category', 'stonewright' ) ) )
			. Html::element( 'option', [ 'value' => 'disable_category' ], Html::text( __( 'Disable whole category', 'stonewright' ) ) );

		$bulk = Html::element(
			'div',
			[ 'class' => 'sw-abilities__bulk' ],
			Html::element(
				'label',
				[ 'class' => 'sw-ui-checkbox' ],
				Html::void( 'input', [ 'type' => 'checkbox', 'data-sw-abilities-select-all' => '' ] )
					. Html::element( 'span', [], Html::text( __( 'Select visible', 'stonewright' ) ) )
			)
			. Html::element( 'label', [ 'class' => 'sw-ui-visually-hidden', 'for' => 'stonewright-bulk-action' ], Html::text( __( 'Bulk action', 'stonewright' ) ) )
			. Html::element( 'select', [ 'id' => 'stonewright-bulk-action', 'name' => 'stonewright_bulk_action', 'class' => 'sw-ui-select', 'aria-describedby' => 'sw-abilities-bulk-error' ], $action_options )
			. Html::element( 'label', [ 'class' => 'sw-ui-visually-hidden', 'for' => 'stonewright-bulk-category' ], Html::text( __( 'Category for the bulk action', 'stonewright' ) ) )
			. Html::element( 'select', [ 'id' => 'stonewright-bulk-category', 'name' => 'stonewright_bulk_category', 'class' => 'sw-ui-select', 'aria-describedby' => 'sw-abilities-bulk-error' ], $category_options )
			. Button::render( __( 'Apply', 'stonewright' ), [ 'type' => 'submit', 'attrs' => [ 'data-sw-abilities-apply' => '' ] ] )
			. Html::element( 'span', [ 'class' => 'sw-abilities__selected', 'role' => 'status', 'aria-live' => 'polite', 'data-sw-abilities-selected' => '' ], '' )
			. Html::element(
				'p',
				[ 'class' => 'sw-ui-field__error sw-abilities__error', 'id' => 'sw-abilities-bulk-error', 'hidden' => true, 'data-sw-abilities-error' => '' ],
				''
			)
		);

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-toolbar sw-ui-toolbar--sticky', 'role' => 'group', 'aria-label' => __( 'Search and bulk actions', 'stonewright' ) ],
			$search . $meta . $bulk
		);
	}

	/**
	 * @param array{id: string, label: string, registered: bool, categories: array<string, list<array<string, mixed>>>} $group
	 * @param array<string, int>                                                                                          $label_counts
	 * @param list<string>                                                                                                $disabled
	 */
	private static function provider_html( string $provider, array $group, array $label_counts, array $disabled ): string {
		$total   = 0;
		$enabled = 0;
		foreach ( $group['categories'] as $abilities ) {
			foreach ( $abilities as $ability ) {
				++$total;
				if ( LiveAbilities::counts_as_enabled( $ability, $disabled ) ) {
					++$enabled;
				}
			}
		}

		$heading_id = Html::unique_id( 'provider' );
		$label      = self::humanize( $provider, (string) $group['label'] );
		$head       = Html::element( 'h2', [ 'class' => 'sw-abilities__provider-title', 'id' => $heading_id ], Html::text( $label ) )
			. Html::element(
				'span',
				[ 'class' => 'sw-ui-badge', 'data-sw-provider-count' => '' ],
				Html::text( self::count_words( $enabled, $total ) )
			)
			. ( ! $group['registered'] ? Html::element( 'span', [ 'class' => 'sw-ui-hint' ], Html::text( __( 'Not registered', 'stonewright' ) ) ) : '' );

		if ( [] === $group['categories'] ) {
			$body = $group['registered']
				? EmptyState::render( __( 'No abilities in this provider', 'stonewright' ), '', [ 'variant' => 'inline' ] )
				: EmptyState::render( __( 'Nothing registered yet', 'stonewright' ), __( 'This provider adds abilities once its plugin is active and registers them with WordPress.', 'stonewright' ), [ 'variant' => 'inline' ] );
		} else {
			$body = '';
			foreach ( $group['categories'] as $category => $abilities ) {
				$body .= self::category_html( $provider, (string) $category, $abilities, $label_counts, $disabled );
			}
		}

		return Html::element(
			'section',
			[ 'class' => 'sw-abilities__provider', 'data-provider' => $provider, 'aria-labelledby' => $heading_id ],
			Html::element( 'div', [ 'class' => 'sw-abilities__provider-head' ], $head ) . $body
		);
	}

	/**
	 * @param list<array<string, mixed>> $abilities
	 * @param array<string, int>         $label_counts
	 * @param list<string>               $disabled
	 */
	private static function category_html( string $provider, string $category, array $abilities, array $label_counts, array $disabled ): string {
		$enabled = 0;
		$rows    = [];
		foreach ( $abilities as $ability ) {
			if ( LiveAbilities::counts_as_enabled( $ability, $disabled ) ) {
				++$enabled;
			}
			$rows[] = self::ability_row( $ability, $disabled, $label_counts );
		}

		$label = self::category_label( $category );
		$table = Table::render(
			[
				[ 'key' => 'select', 'label' => __( 'Select', 'stonewright' ), 'hide_label' => true ],
				[ 'key' => 'ability', 'label' => __( 'Ability', 'stonewright' ), 'primary' => true ],
				[ 'key' => 'kind', 'label' => __( 'Kind', 'stonewright' ) ],
				[ 'key' => 'enabled', 'label' => __( 'Enabled', 'stonewright' ) ],
			],
			$rows,
			[
				'caption' => sprintf(
					/* translators: %s: category name */
					__( 'Abilities in %s', 'stonewright' ),
					$label
				),
				'class'   => 'sw-abilities__table',
			]
		);

		$actions = Html::element(
			'div',
			[ 'class' => 'sw-ui-actions sw-abilities__category-actions', 'hidden' => true, 'data-sw-category-actions' => '' ],
			Button::render(
				__( 'Enable all', 'stonewright' ),
				[ 'size' => 'sm', 'context' => sprintf( /* translators: %s: category name */ __( 'in %s', 'stonewright' ), $label ), 'attrs' => [ 'data-sw-bulk-action' => 'enable_category', 'data-sw-bulk-category' => $category ] ]
			)
			. Button::render(
				__( 'Disable all', 'stonewright' ),
				[ 'size' => 'sm', 'context' => sprintf( /* translators: %s: category name */ __( 'in %s', 'stonewright' ), $label ), 'attrs' => [ 'data-sw-bulk-action' => 'disable_category', 'data-sw-bulk-category' => $category ] ]
			)
		);

		$summary = Html::element(
			'summary',
			[],
			Icon::render( 'chev-r' )
				. Html::element( 'h3', [ 'class' => 'sw-abilities__category-title' ], Html::text( $label ) )
				. Html::element( 'span', [ 'class' => 'sw-ui-badge', 'data-sw-category-count' => '' ], Html::text( self::count_words( $enabled, count( $abilities ) ) ) )
		);

		return Html::element(
			'details',
			[
				'class'         => 'sw-ui-disclosure sw-abilities__category',
				'data-provider' => $provider,
				'data-category' => $category,
			],
			$summary . Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body sw-abilities__category-body' ], $actions . $table )
		);
	}

	/**
	 * One table row. Every control is named after the ability; when two abilities share a label the tool name
	 * tells them apart.
	 *
	 * @param array<string, mixed>                        $ability
	 * @param list<string>                                $disabled
	 * @param array<string, int>                          $label_counts
	 * @return array<string, array{html: string}>
	 */
	private static function ability_row( array $ability, array $disabled, array $label_counts ): array {
		$name       = (string) $ability['name'];
		$label      = (string) $ability['label'];
		$is_enabled = ! in_array( $name, $disabled, true );
		$kind       = AbilityKind::of( $name );
		$slug       = sanitize_html_class( str_replace( '/', '-', $name ) );
		$label_id   = 'sw-ability-' . $slug . '-label';
		$tool_id    = 'sw-ability-' . $slug . '-tool';
		$select_id  = 'sw-ability-' . $slug . '-select';
		$params_id  = 'sw-ability-' . $slug . '-params';
		$tool_name  = (string) ( $ability['mcp_tool_name'] ?? AbilityRegistry::mcp_tool_name( $name ) );
		$name_ids   = $label_id . ( ( $label_counts[ $label ] ?? 1 ) > 1 ? ' ' . $tool_id : '' );

		$checkbox = Html::element(
			'label',
			[ 'class' => 'sw-ui-checkbox' ],
			Html::void(
				'input',
				[
					'type'                     => 'checkbox',
					'name'                     => 'stonewright_abilities[]',
					'value'                    => $name,
					'aria-labelledby'          => $select_id . ' ' . $name_ids,
					'data-sw-ability-select'   => '',
				]
			)
			. Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden', 'id' => $select_id ], Html::text( __( 'Select', 'stonewright' ) ) )
		);

		$not_registered = $is_enabled && false === ( $ability['registered'] ?? true )
			? Badge::render( __( 'Not registered with WordPress', 'stonewright' ), [ 'variant' => 'warn', 'icon' => 'alert' ] )
			: '';

		$main = Html::element(
			'div',
			[
				'class'           => 'sw-abilities__main',
				'data-sw-ability' => $name,
				'data-ability-label' => $label,
				'data-tool'       => $tool_name,
				'data-category'   => (string) $ability['category'],
				'data-kind'       => $kind,
				'data-live'       => false === ( $ability['registered'] ?? true ) ? '0' : '1',
			],
			Html::element( 'span', [ 'class' => 'sw-ui-table__primary', 'id' => $label_id ], Html::text( $label ) )
				. Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( (string) $ability['description'] ) )
				. Html::element( 'code', [ 'class' => 'sw-abilities__tool', 'id' => $tool_id ], Html::text( $tool_name ) )
				. $not_registered
				. Html::element(
					'details',
					[ 'class' => 'sw-ui-disclosure sw-ui-disclosure--inline', 'data-sw-ability-params' => $name ],
					Html::element(
						'summary',
						[ 'id' => $params_id, 'aria-labelledby' => $params_id . ' ' . $name_ids ],
						Icon::render( 'chev-r' ) . Html::text( __( 'Parameters', 'stonewright' ) )
					)
					. Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body', 'data-sw-ability-params-body' => '' ], '' )
				)
		);

		$kind_cell = AbilityKind::DESTRUCTIVE === $kind
			? Badge::render( AbilityKind::label( $kind ), [ 'variant' => 'danger', 'icon' => 'alert' ] )
			: Badge::tag( AbilityKind::label( $kind ) );

		$switch = Html::element(
			'label',
			[ 'class' => 'sw-ui-switch' ],
			Html::void(
				'input',
				[
					'type'                    => 'checkbox',
					'role'                    => 'switch',
					'value'                   => '1',
					'checked'                 => $is_enabled,
					'aria-labelledby'         => $name_ids,
					'data-sw-ability-switch'  => $name,
				]
			)
			. Html::element( 'span', [ 'class' => 'sw-ui-switch__track', 'aria-hidden' => 'true' ], '' )
			. Html::element( 'span', [ 'class' => 'sw-abilities__state', 'aria-hidden' => 'true', 'data-sw-ability-state' => '' ], Html::text( $is_enabled ? __( 'On', 'stonewright' ) : __( 'Off', 'stonewright' ) ) )
		);

		return [
			'select'  => [ 'html' => $checkbox ],
			'ability' => [ 'html' => $main ],
			'kind'    => [ 'html' => $kind_cell ],
			'enabled' => [ 'html' => $switch ],
		];
	}

	/**
	 * How many abilities carry each label, so a label shared by two abilities can be told apart.
	 *
	 * @param array<string, array{categories: array<string, list<array<string, mixed>>>}> $groups
	 * @return array<string, int>
	 */
	private static function label_counts( array $groups ): array {
		$labels = [];
		foreach ( $groups as $group ) {
			foreach ( $group['categories'] as $category_abilities ) {
				foreach ( $category_abilities as $ability ) {
					$labels[] = (string) $ability['label'];
				}
			}
		}

		return array_count_values( $labels );
	}

	/**
	 * @param array<string, array{categories: array<string, list<array<string, mixed>>>}> $groups
	 * @return list<string>
	 */
	private static function category_options( array $groups ): array {
		$categories = [];
		foreach ( $groups as $group ) {
			foreach ( array_keys( $group['categories'] ) as $category ) {
				$categories[ $category ] = true;
			}
		}
		$keys = array_map( 'strval', array_keys( $categories ) );
		sort( $keys );

		return $keys;
	}

	/** "3 of 5 on": a count in words, so the number never stands alone. */
	private static function count_words( int $enabled, int $total ): string {
		return sprintf(
			/* translators: 1: abilities that are on, 2: abilities in the group */
			__( '%1$d of %2$d on', 'stonewright' ),
			$enabled,
			$total
		);
	}

	private static function category_label( string $category ): string {
		return self::CATEGORY_LABELS[ $category ] ?? self::humanize( $category );
	}

	/** A slug as words in sentence case, with acronyms and product names written as they are. */
	private static function humanize( string $slug, string $fallback = '' ): string {
		if ( 'stonewright' === $slug || 'elementor' === $slug ) {
			return '' !== $fallback ? $fallback : ucfirst( $slug );
		}
		$words = [];
		foreach ( preg_split( '/[-_\s]+/', strtolower( $slug ) ) ?: [] as $index => $word ) {
			if ( '' === $word ) {
				continue;
			}
			$words[] = self::WORDS[ $word ] ?? ( 0 === $index ? ucfirst( $word ) : $word );
		}

		return implode( ' ', $words );
	}

	// -------------------------------------------------------------------------
	// Form handlers (the way in without script)
	// -------------------------------------------------------------------------

	public static function handle_toggle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		wp_safe_redirect( self::apply_toggle_request( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
		exit;
	}

	public static function handle_bulk(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		check_admin_referer( self::BULK_NONCE_ACTION );

		wp_safe_redirect( self::apply_bulk_request( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
		exit;
	}

	/**
	 * Apply a verified switch post and return the page address to go back to.
	 *
	 * @internal Called by handle_toggle() after the capability and nonce checks.
	 * @param array<array-key, mixed> $post The request body.
	 */
	public static function apply_toggle_request( array $post ): string {
		$name   = isset( $post['ability_name'] ) ? sanitize_text_field( wp_unslash( (string) $post['ability_name'] ) ) : '';
		$enable = isset( $post['ability_enabled'] ) && '1' === $post['ability_enabled'];

		$result = AbilityToggles::set_enabled( $name, $enable );

		return self::result_url( $result['code'], $result['changed'] );
	}

	/**
	 * Apply a verified bulk post and return the page address to go back to.
	 *
	 * @internal Called by handle_bulk() after the capability and nonce checks.
	 * @param array<array-key, mixed> $post The request body.
	 */
	public static function apply_bulk_request( array $post ): string {
		$action   = isset( $post['stonewright_bulk_action'] ) ? sanitize_key( wp_unslash( (string) $post['stonewright_bulk_action'] ) ) : '';
		$category = isset( $post['stonewright_bulk_category'] ) ? sanitize_key( wp_unslash( (string) $post['stonewright_bulk_category'] ) ) : '';
		$selected = isset( $post['stonewright_abilities'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $post['stonewright_abilities'] ) ) : [];

		$result = AbilityToggles::bulk( $action, $category, $selected );

		return self::result_url( $result['code'], $result['changed'] );
	}

	private static function result_url( string $code, int $changed ): string {
		$args = [ 'page' => self::SLUG, 'stonewright_toggled' => $code ];
		if ( $changed > 0 ) {
			$args['stonewright_changed'] = $changed;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The words the page script says, translated here so the script holds no text of its own.
	 *
	 * @return array<string, string>
	 */
	private static function script_strings(): array {
		return [
			'abilities_one'    => __( '%d ability', 'stonewright' ),
			'abilities_other'  => __( '%d abilities', 'stonewright' ),
			'of_total'         => __( '%1$d of %2$d abilities', 'stonewright' ),
			'enabled_stats'    => __( 'Enabled %1$d · Write %2$d · Read %3$d', 'stonewright' ),
			'count_words'      => __( '%1$d of %2$d on', 'stonewright' ),
			'selected'         => __( '%d selected', 'stonewright' ),
			'toggled_on'       => __( '%s turned on.', 'stonewright' ),
			'toggled_off'      => __( '%s turned off.', 'stonewright' ),
			'on'               => __( 'On', 'stonewright' ),
			'off'              => __( 'Off', 'stonewright' ),
			'no_match'         => __( 'No abilities match “%s”', 'stonewright' ),
			'no_match_plain'   => __( 'No abilities match', 'stonewright' ),
			'undo'             => __( 'Undo', 'stonewright' ),
			'undone'           => __( 'Change undone.', 'stonewright' ),
			'working'          => __( 'Applying…', 'stonewright' ),
			'error_title'      => __( 'The change was not saved', 'stonewright' ),
			'error_network'    => __( 'The server could not be reached. Check your connection and try again.', 'stonewright' ),
			'error_expired'    => __( 'This page has expired. Reload it and try again.', 'stonewright' ),
			'error_generic'    => __( 'The server refused the change. Reload the page and try again.', 'stonewright' ),
			'params_none'      => __( 'No input parameters.', 'stonewright' ),
			'params_loading'   => __( 'Loading parameters…', 'stonewright' ),
			'params_error'     => __( 'Parameters could not be loaded.', 'stonewright' ),
			'params_retry'     => __( 'Try again', 'stonewright' ),
			'params_required'  => __( 'required', 'stonewright' ),
			'need_action'      => AbilityToggles::message( 'bulk-no-action' ),
			'need_selection'   => AbilityToggles::message( 'bulk-no-selection' ),
			'need_category'    => AbilityToggles::message( 'bulk-no-category' ),
		];
	}
}
