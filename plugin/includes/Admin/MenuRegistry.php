<?php
/**
 * The registry of Stonewright admin pages: which hub each belongs to, its tab, its title and its order.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\IncidentStore;

/**
 * One list that the sidebar order, the hub tab bars, the page headers and the Help tabs all read.
 *
 * A hub is a group of pages that share a landing page and a tab bar: Overview, Setup, AI Abilities, Knowledge,
 * Custom code and Activity. A page belongs to one hub. A page can be a tab of its own (its own slug) or a tab of
 * another page (the same slug with a different `tab` query value, as the Custom code tabs are).
 *
 * The pages that shipped before this registry are listed here. A page added later calls add() from its own class,
 * before the `admin_menu` pass of MenuOrder runs, and needs no change to this file.
 *
 * @phpstan-type Entry array{slug: string, tab: string, hub: string, label: string, title: string, lede: string, order: int, beta: bool, in_menu: bool, menu_label: string, capability: string, count: (callable(): int)|null, count_label: string, default: bool}
 * @phpstan-type Link array{label: string, url: string, current: bool, count: int|null, count_label: string}
 */
final class MenuRegistry {

	/** The slug of the top-level menu entry that holds every page. */
	public const PARENT = 'stonewright';

	/** @var array<string, Entry>|null Keyed by "slug|tab". Null until the first read. */
	private static ?array $entries = null;

	/**
	 * Hubs in sidebar order.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public static function hubs(): array {
		return [
			[ 'id' => 'overview', 'label' => __( 'Overview', 'stonewright' ) ],
			[ 'id' => 'setup', 'label' => __( 'Setup', 'stonewright' ) ],
			[ 'id' => 'abilities', 'label' => __( 'AI Abilities', 'stonewright' ) ],
			[ 'id' => 'knowledge', 'label' => __( 'Knowledge', 'stonewright' ) ],
			[ 'id' => 'custom-code', 'label' => __( 'Custom code', 'stonewright' ) ],
			[ 'id' => 'activity', 'label' => __( 'Activity', 'stonewright' ) ],
		];
	}

	/**
	 * Register a page, or one tab of a page. A second call with the same slug and tab replaces the first.
	 *
	 * @phpstan-param array{
	 *     tab?: string,
	 *     order?: int,
	 *     beta?: bool,
	 *     in_menu?: bool,
	 *     title?: string,
	 *     lede?: string,
	 *     menu_label?: string,
	 *     capability?: string,
	 *     count?: (callable(): int)|null,
	 *     count_label?: string,
	 *     default?: bool
	 * } $args "label" is the tab text. "title" is the page heading and defaults to the label. "in_menu" is false for a
	 *         page that is reached by URL and never listed in the sidebar. "menu_label" overrides the sidebar text, which
	 *         is otherwise the hub name on the first page of a hub and the label on the others. "capability" is what the
	 *         page needs (manage_options by default): the tab bar lists only the tabs the user can open. "count" returns a number
	 *         shown beside the tab, described for assistive technology by "count_label".
	 */
	public static function add( string $slug, string $label, string $hub, array $args = [] ): void {
		self::load();
		if ( '' === $slug || ! in_array( $hub, array_column( self::hubs(), 'id' ), true ) ) {
			return;
		}

		$tab                                = sanitize_key( (string) ( $args['tab'] ?? '' ) );
		self::$entries[ $slug . '|' . $tab ] = [
			'slug'        => $slug,
			'tab'         => $tab,
			'hub'         => $hub,
			'label'       => $label,
			'title'       => (string) ( $args['title'] ?? $label ),
			'lede'        => (string) ( $args['lede'] ?? '' ),
			'order'       => (int) ( $args['order'] ?? 100 ),
			'beta'        => ! empty( $args['beta'] ),
			'in_menu'     => ! array_key_exists( 'in_menu', $args ) || (bool) $args['in_menu'],
			'menu_label'  => (string) ( $args['menu_label'] ?? '' ),
			'capability'  => (string) ( $args['capability'] ?? 'manage_options' ),
			'count'       => $args['count'] ?? null,
			'count_label' => (string) ( $args['count_label'] ?? '' ),
			'default'     => ! empty( $args['default'] ),
		];
	}

	/**
	 * Every entry, hubs in sidebar order and entries by their order inside a hub.
	 *
	 * @return list<Entry>
	 */
	public static function entries(): array {
		self::load();
		$rank = array_flip( array_column( self::hubs(), 'id' ) );
		$list = array_values( self::$entries ?? [] );
		usort(
			$list,
			static fn ( array $a, array $b ): int => [ $rank[ $a['hub'] ], $a['order'], $a['slug'], $a['tab'] ] <=> [ $rank[ $b['hub'] ], $b['order'], $b['slug'], $b['tab'] ]
		);

		return $list;
	}

	/**
	 * The entries of one hub in tab order.
	 *
	 * @return list<Entry>
	 */
	public static function hub_entries( string $hub ): array {
		return array_values( array_filter( self::entries(), static fn ( array $entry ): bool => $hub === $entry['hub'] ) );
	}

	/**
	 * The entry for a page: the named tab, else the default one, else the first.
	 *
	 * @return Entry|null
	 */
	public static function entry( string $slug, string $tab = '' ): ?array {
		$found = array_values( array_filter( self::entries(), static fn ( array $entry ): bool => $slug === $entry['slug'] ) );
		if ( [] === $found ) {
			return null;
		}
		foreach ( $found as $entry ) {
			if ( '' !== $tab && $tab === $entry['tab'] ) {
				return $entry;
			}
		}
		foreach ( $found as $entry ) {
			if ( $entry['default'] || '' === $entry['tab'] ) {
				return $entry;
			}
		}

		return $found[0];
	}

	/** The hub a page belongs to, or an empty string for a page that is not registered. */
	public static function hub_for( string $slug ): string {
		$entry = self::entry( $slug );

		return null === $entry ? '' : $entry['hub'];
	}

	public static function hub_label( string $hub ): string {
		foreach ( self::hubs() as $candidate ) {
			if ( $hub === $candidate['id'] ) {
				return $candidate['label'];
			}
		}

		return '';
	}

	/**
	 * One entry per page that the sidebar lists, in sidebar order.
	 *
	 * @return list<Entry>
	 */
	public static function menu_entries(): array {
		$seen = [];
		$list = [];
		foreach ( self::entries() as $entry ) {
			if ( ! $entry['in_menu'] || isset( $seen[ $entry['slug'] ] ) ) {
				continue;
			}
			$seen[ $entry['slug'] ] = true;
			$list[]                 = $entry;
		}

		return $list;
	}

	/**
	 * The sidebar text of an entry: the hub name on the first page of a hub, the tab label on the others.
	 *
	 * @param Entry $entry
	 */
	public static function menu_label( array $entry ): string {
		if ( '' !== $entry['menu_label'] ) {
			return $entry['menu_label'];
		}
		foreach ( self::menu_entries() as $candidate ) {
			if ( $candidate['hub'] === $entry['hub'] ) {
				return $candidate['slug'] === $entry['slug'] ? self::hub_label( $entry['hub'] ) : $entry['label'];
			}
		}

		return $entry['label'];
	}

	/**
	 * Every registered page, slug to its tab label (the first tab of a page that has several).
	 *
	 * @return array<string, string>
	 */
	public static function pages(): array {
		$pages = [];
		foreach ( self::entries() as $entry ) {
			if ( ! isset( $pages[ $entry['slug'] ] ) ) {
				$pages[ $entry['slug'] ] = $entry['label'];
			}
		}

		return $pages;
	}

	/**
	 * The tab links of a hub for the page that is open.
	 *
	 * @return list<Link>
	 */
	public static function links( string $hub, string $current_slug, string $current_tab ): array {
		$entries = array_values( array_filter( self::hub_entries( $hub ), static fn ( array $entry ): bool => current_user_can( $entry['capability'] ) ) );
		$current = self::current_entry_key( $entries, $current_slug, $current_tab );
		$links   = [];
		foreach ( $entries as $entry ) {
			$args = [ 'page' => $entry['slug'] ];
			if ( '' !== $entry['tab'] ) {
				$args['tab'] = $entry['tab'];
			}
			$count   = self::count( $entry );
			$links[] = [
				'label'       => $entry['label'],
				'url'         => add_query_arg( $args, admin_url( 'admin.php' ) ),
				'current'     => $entry['slug'] . '|' . $entry['tab'] === $current,
				'count'       => $count,
				'count_label' => $entry['count_label'],
			];
		}

		return $links;
	}

	/** The `tab` value of the current request, cleaned. */
	public static function requested_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state.
		return isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	}

	/** Forget every registration, built-in ones included. For tests. */
	public static function reset_for_tests(): void {
		self::$entries = null;
	}

	/**
	 * Which entry of the hub is current: the one for this page and tab, else the default tab of this page.
	 *
	 * @param list<Entry> $entries
	 */
	private static function current_entry_key( array $entries, string $slug, string $tab ): string {
		$on_page = array_values( array_filter( $entries, static fn ( array $entry ): bool => $slug === $entry['slug'] ) );
		if ( [] === $on_page ) {
			return '';
		}
		foreach ( $on_page as $entry ) {
			if ( '' !== $tab && $tab === $entry['tab'] ) {
				return $entry['slug'] . '|' . $entry['tab'];
			}
		}
		foreach ( $on_page as $entry ) {
			if ( $entry['default'] || '' === $entry['tab'] ) {
				return $entry['slug'] . '|' . $entry['tab'];
			}
		}

		return $on_page[0]['slug'] . '|' . $on_page[0]['tab'];
	}

	/**
	 * The number beside a tab, or null when there is none to show. A counter that fails shows nothing:
	 * a navigation bar must never take a page down.
	 *
	 * @param Entry $entry
	 */
	private static function count( array $entry ): ?int {
		if ( null === $entry['count'] ) {
			return null;
		}
		try {
			$value = (int) ( $entry['count'] )();
		} catch ( \Throwable $failure ) {
			return null;
		}

		return $value > 0 ? $value : null;
	}

	private static function load(): void {
		if ( null !== self::$entries ) {
			return;
		}
		self::$entries = [];
		foreach ( self::core_pages() as $page ) {
			self::add( $page[0], $page[1], $page[2], $page[3] );
		}
	}

	/**
	 * The pages that exist without calling add(): slug, tab label, hub and arguments.
	 *
	 * @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
	 */
	private static function core_pages(): array {
		$incidents = static function (): int {
			$counts = IncidentStore::counts();

			return (int) ( $counts['open'] ?? 0 );
		};

		return [
			[ 'stonewright-status', __( 'Overview', 'stonewright' ), 'overview', [ 'order' => 10, 'lede' => __( 'Your AI connection at a glance: what is on, what needs you, and what the agents did.', 'stonewright' ) ] ],
			[ 'stonewright', __( 'Setup', 'stonewright' ), 'setup', [ 'order' => 10, 'lede' => __( 'Enable Stonewright, choose OAuth or an Application Password, then connect your AI client.', 'stonewright' ) ] ],
			[ 'stonewright-troubleshoot', __( 'Troubleshoot', 'stonewright' ), 'setup', [ 'order' => 20, 'beta' => true, 'lede' => __( 'Diagnose why an AI client cannot connect to this WordPress site.', 'stonewright' ) ] ],
			[ 'stonewright-abilities', __( 'AI Abilities', 'stonewright' ), 'abilities', [ 'order' => 10, 'lede' => __( 'Search, inspect, and toggle the MCP tool surface exposed by Stonewright.', 'stonewright' ) ] ],
			[ 'stonewright-skills', __( 'Skills', 'stonewright' ), 'knowledge', [ 'order' => 10, 'lede' => __( 'Site-owned Markdown playbooks for repeatable WordPress work. Agents skim descriptions first, then load full bodies only when a task matches.', 'stonewright' ) ] ],
			[ 'stonewright-memory', __( 'Memory', 'stonewright' ), 'knowledge', [ 'order' => 20, 'title' => __( 'Memory & instructions', 'stonewright' ), 'lede' => __( 'Durable site knowledge for connected Stonewright sessions. Memory is saved only when an operator or ability writes it, and every entry stays auditable here.', 'stonewright' ) ] ],
			[ 'stonewright-context', __( 'Context', 'stonewright' ), 'knowledge', [ 'order' => 30, 'beta' => true, 'lede' => __( 'Review the generated system instructions first, then add site-specific context. Task-start injects enabled user context into bootstrap.', 'stonewright' ) ] ],
			[ 'stonewright-design', __( 'Design', 'stonewright' ), 'knowledge', [ 'order' => 40, 'beta' => true, 'lede' => __( 'Import a DESIGN.md direction, review the active contract, and keep the quality floor on generated pages.', 'stonewright' ) ] ],
			[ 'stonewright-prompts', __( 'Prompt library', 'stonewright' ), 'knowledge', [ 'order' => 50, 'lede' => __( 'Outcome-grouped starters for Plugin and Direct mode. Connect Stonewright first, copy only the prompt you need, and keep credentials out of chat.', 'stonewright' ) ] ],
			[ 'stonewright-sandbox', __( 'Drafts', 'stonewright' ), 'custom-code', [ 'order' => 10, 'tab' => 'drafts', 'default' => true, 'title' => __( 'Custom code', 'stonewright' ), 'lede' => __( 'Draft, inspect, and activate reviewable PHP files without loading unreviewed code automatically.', 'stonewright' ) ] ],
			[ 'stonewright-sandbox', __( 'Library', 'stonewright' ), 'custom-code', [ 'order' => 20, 'tab' => 'library', 'title' => __( 'Custom code', 'stonewright' ), 'lede' => __( 'Draft, inspect, and activate reviewable PHP files without loading unreviewed code automatically.', 'stonewright' ) ] ],
			[ 'stonewright-sandbox', __( 'Active', 'stonewright' ), 'custom-code', [ 'order' => 30, 'tab' => 'mu-plugins', 'title' => __( 'Custom code', 'stonewright' ), 'lede' => __( 'Draft, inspect, and activate reviewable PHP files without loading unreviewed code automatically.', 'stonewright' ) ] ],
			[ 'stonewright-sandbox', __( 'Crash recovery', 'stonewright' ), 'custom-code', [ 'order' => 40, 'tab' => 'crash-recovery', 'title' => __( 'Custom code', 'stonewright' ), 'lede' => __( 'Draft, inspect, and activate reviewable PHP files without loading unreviewed code automatically.', 'stonewright' ) ] ],
			[ 'stonewright-custom-code-approval', __( 'Approvals', 'stonewright' ), 'custom-code', [ 'order' => 50, 'menu_label' => __( 'Code approval', 'stonewright' ), 'title' => __( 'Custom code approval', 'stonewright' ), 'lede' => __( 'Approve only the exact dry-run candidate shown here. Grants expire quickly, work once, and cannot be broadened to another path or hash.', 'stonewright' ) ] ],
			[ 'stonewright-audit-log', __( 'Audit log', 'stonewright' ), 'activity', [ 'order' => 10, 'count' => $incidents, 'count_label' => __( 'open incidents', 'stonewright' ), 'lede' => __( 'Records of what agents and Stonewright did on this site: ability calls and protected REST writes, with secrets removed. Changes made on admin screens, such as Setup settings and Memory edits, are not recorded here.', 'stonewright' ) ] ],
		];
	}
}
