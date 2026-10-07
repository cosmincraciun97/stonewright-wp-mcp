<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\FormField;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Table;
use Stonewright\WpMcp\Admin\Ui\UtcTime;
use Stonewright\WpMcp\Knowledge\KnowledgeBundle;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * Admin page: Memory & Instructions (slug: stonewright-memory).
 *
 * The entries come first. Adding, editing and every other action ends in a message on this page: a redirect carries
 * a short `memory_notice` code (and the id of the entry it concerns), and the page turns it into a notice. A new
 * entry never replaces one that holds the same scope and key; it is refused and says which entry holds the pair.
 */
final class MemoryInstructionsPage {

	public const SLUG       = 'stonewright-memory';
	public const CAP        = 'manage_options';
	public const OPT_GROUP  = 'stonewright_memory_settings';

	/** The most entries one view lists. */
	private const LIST_LIMIT = 200;

	private const ADD_ID = 'sw-memory-add';

	// ------------------------------------------------------------------
	// Registration
	// ------------------------------------------------------------------

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_post_stonewright_memory_create', [ self::class, 'handle_create' ] );
		add_action( 'admin_post_stonewright_memory_update', [ self::class, 'handle_update' ] );
		add_action( 'admin_post_stonewright_memory_delete', [ self::class, 'handle_delete' ] );
		add_action( 'admin_post_stonewright_memory_approve_draft', [ self::class, 'handle_approve_draft' ] );
		add_action( 'admin_post_stonewright_memory_discard_draft', [ self::class, 'handle_discard_draft' ] );
		add_action( 'admin_post_stonewright_learning_disable', [ self::class, 'handle_learning_disable' ] );
		add_action( 'admin_post_stonewright_knowledge_export', [ self::class, 'handle_export' ] );
		add_action( 'admin_post_stonewright_knowledge_import', [ self::class, 'handle_import' ] );
		add_action( 'admin_post_stonewright_memory_migrate_feedback', [ self::class, 'handle_migrate_feedback' ] );
	}

	public static function add_submenu(): void {
		// IA group: Safety & Diagnostics — slug stonewright-memory unchanged.
		add_submenu_page(
			'stonewright',
			__( 'Memory & instructions', 'stonewright' ),
			__( 'Memory', 'stonewright' ),
			self::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function register_settings(): void {
		register_setting(
			self::OPT_GROUP,
			'stonewright_custom_instructions',
			[
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ): string {
					// Allow newlines; just cap length to 4000 chars.
					return mb_substr( (string) $v, 0, 4000 );
				},
				'default'           => '',
			]
		);

		register_setting(
			self::OPT_GROUP,
			'stonewright_custom_instructions_enabled',
			[
				'type'              => 'boolean',
				'sanitize_callback' => static fn( $v ): bool => (bool) $v,
				'default'           => true,
			]
		);

		register_setting(
			self::OPT_GROUP,
			'stonewright_memory_enabled',
			[
				'type'              => 'boolean',
				'sanitize_callback' => static fn( $v ): bool => (bool) $v,
				'default'           => true,
			]
		);
	}

	// ------------------------------------------------------------------
	// Render
	// ------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stonewright' ) );
		}

		$instructions = (string) get_option( 'stonewright_custom_instructions', '' );
		$enabled      = (bool) get_option( 'stonewright_custom_instructions_enabled', true );
		$mem_enabled  = (bool) get_option( 'stonewright_memory_enabled', true );

		// Determine active tab / type filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : '';
		$tabs     = [ 'all', 'user', 'project', 'verified_repairs', 'unresolved_incidents', 'incidents', 'audit_feedback', 'reference' ];
		$active   = ( '' === $raw_type || 'all' === $raw_type ) ? 'all' : ( in_array( $raw_type, $tabs, true ) ? $raw_type : 'all' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only incident filter.
		$incident_state = isset( $_GET['incident_state'] ) ? sanitize_key( wp_unslash( (string) $_GET['incident_state'] ) ) : '';
		if ( ! in_array( $incident_state, [ 'open', 'observing', 'resolved', 'suppressed' ], true ) ) {
			$incident_state = '';
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view selectors.
		$edit_id  = isset( $_GET['edit'] ) ? absint( wp_unslash( (string) $_GET['edit'] ) ) : 0;
		$add_open = ! empty( $_GET['add'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$all_entries   = Memory::list_all( 10000 );
		$counts        = array_fill_keys( $tabs, 0 );
		$counts['all'] = count( $all_entries );
		foreach ( $all_entries as $e ) {
			$bucket = self::entry_bucket( $e );
			if ( isset( $counts[ $bucket ] ) ) {
				++$counts[ $bucket ];
			}
		}
		$incident_counts                = IncidentStore::counts();
		$counts['incidents']            = array_sum( $incident_counts );
		$counts['unresolved_incidents'] = (int) ( $incident_counts['open'] ?? 0 ) + (int) ( $incident_counts['observing'] ?? 0 );
		$legacy_unresolved_count        = count( array_filter( $all_entries, static fn( array $entry ): bool => 'unresolved_incidents' === self::entry_bucket( $entry ) ) );
		$incident_rows                  = IncidentStore::recent( 200, '' === $incident_state ? [] : [ 'state' => $incident_state ] );
		if ( 'unresolved_incidents' === $active ) {
			$incident_rows = array_values( array_filter( IncidentStore::recent( 200 ), static fn( array $row ): bool => in_array( (string) ( $row['state'] ?? '' ), [ 'open', 'observing' ], true ) ) );
		}
		$in_view = 'all' === $active
			? $all_entries
			: array_values( array_filter( $all_entries, static fn( array $entry ): bool => self::entry_bucket( $entry ) === $active ) );
		$entries = array_slice( $in_view, 0, self::LIST_LIMIT );

		$edit_entry = $edit_id > 0 ? Memory::get_by_id( $edit_id ) : null;

		$html  = self::notices_html( $edit_id > 0 && null === $edit_entry );
		$html .= self::schema_html();
		$html .= Notice::callout(
			'info',
			__( 'This page shows the authoritative plugin-site store.', 'stonewright' ),
			__( 'Direct-local receipts live only on the companion machine and cannot appear here; use an explicit export and import instead of assuming synchronization.', 'stonewright' )
		);
		$html .= self::guidance_html();
		if ( null !== $edit_entry ) {
			$html .= self::edit_card( $edit_entry );
		}
		$html .= self::entries_card( $entries, count( $in_view ), $counts, $active, $incident_rows, $incident_counts, $incident_state, $legacy_unresolved_count, $mem_enabled, $add_open, $all_entries );
		$html .= self::learned_rules_card( $all_entries );
		$html .= self::settings_card( $enabled, $mem_enabled, $instructions );
		$html .= self::bundle_card();
		$html .= self::tools_card();

		AdminShell::open(
			self::SLUG,
			[ 'actions' => Button::render( __( 'Add entry', 'stonewright' ), [ 'variant' => 'primary', 'icon' => 'plus', 'href' => self::add_url() ] ) ]
		);
		echo Scope::wrap( Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $html ), [ 'page' => true, 'class' => 'sw-memory' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	private static function page_url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	private static function add_url(): string {
		return self::page_url( [ 'add' => '1' ] ) . '#' . self::ADD_ID;
	}

	/**
	 * What the last action did, from the flags its redirect carries. Codes are looked up, never printed, so nothing
	 * a request carries reaches the page as markup. A confirmation is a status message; a refusal or failure is an alert,
	 * and none of them goes away by itself.
	 */
	private static function notices_html( bool $missing_edit_target ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result flags from a redirect.
		$code     = isset( $_GET['memory_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['memory_notice'] ) ) : '';
		$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( (string) $_GET['entry'] ) ) : 0;
		$settings = isset( $_GET['settings-updated'] );
		$migrated = isset( $_GET['migrated'] ) ? absint( wp_unslash( (string) $_GET['migrated'] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$html  = self::import_notice();
		$entry = $entry_id > 0 ? Memory::get_by_id( $entry_id ) : null;
		$name  = null !== $entry ? (string) $entry['name'] : '';

		if ( $settings ) {
			$html .= Notice::render( 'ok', __( 'Settings saved.', 'stonewright' ) );
		}
		if ( null !== $migrated ) {
			$html .= Notice::render(
				'ok',
				0 === $migrated
					? __( 'No legacy feedback needed classifying.', 'stonewright' )
					/* translators: %d: number of entries */
					: sprintf( _n( '%d legacy feedback entry was classified.', '%d legacy feedback entries were classified.', $migrated, 'stonewright' ), $migrated ),
				__( 'Nothing was deleted; audit history is unchanged.', 'stonewright' )
			);
		}
		if ( $missing_edit_target && 'not-found' !== $code ) {
			$code = 'not-found';
		}

		return $html . match ( $code ) {
			'created'   => Notice::render( 'ok', __( 'Memory entry created.', 'stonewright' ), self::entry_sentence( $entry, __( 'It is stored and agents can read it.', 'stonewright' ) ) ),
			'updated'   => Notice::render( 'ok', __( 'Memory entry saved.', 'stonewright' ), '' !== $name ? $name : '' ),
			'deleted'   => Notice::render( 'ok', __( 'Memory entry deleted.', 'stonewright' ), __( 'Agents no longer load it.', 'stonewright' ) ),
			'approved'  => Notice::render( 'ok', __( 'Lesson approved.', 'stonewright' ), __( 'Agents can now load it. Who approved it, and when, is recorded with the entry.', 'stonewright' ) ),
			'discarded' => Notice::render( 'ok', __( 'Draft lesson discarded.', 'stonewright' ), __( 'It is kept as rejected and agents never load it.', 'stonewright' ) ),
			'disabled'  => Notice::render( 'ok', __( 'Learned rule disabled.', 'stonewright' ), __( 'It is kept, marked stale, and no longer reaches agents.', 'stonewright' ) ),
			'exists'    => Notice::render(
				'warn',
				__( 'No entry was created: that scope and key are already in use.', 'stonewright' ),
				'',
				[
					'text_html'    => Html::text( null !== $entry ? sprintf( /* translators: 1: entry name */ __( 'Nothing was changed. "%1$s" already holds that scope and key. Edit it, or choose a different key.', 'stonewright' ), $name ) : __( 'Nothing was changed. Edit the existing entry, or choose a different key.', 'stonewright' ) ),
					'actions_html' => null !== $entry ? Button::render( __( 'Edit', 'stonewright' ), [ 'size' => 'sm', 'href' => self::page_url( [ 'edit' => (string) $entry_id ] ) . '#sw-memory-edit', 'context' => $name ] ) : '',
				]
			),
			'blocked'   => Notice::render( 'danger', __( 'The entry was not saved.', 'stonewright' ), __( 'Memory refuses text that looks like a password, key or token, and the memory table must be writable. Remove any secret and try again.', 'stonewright' ) ),
			'invalid'   => Notice::render( 'danger', __( 'The entry needs a name and a key.', 'stonewright' ), __( 'Fill in both fields and try again. Nothing was saved.', 'stonewright' ) ),
			'failed'    => Notice::render( 'danger', __( 'The entry could not be saved.', 'stonewright' ), __( 'Check that the memory table is writable and try again.', 'stonewright' ) ),
			'not-found' => Notice::render( 'danger', __( 'That memory entry no longer exists.', 'stonewright' ), __( 'It may have been deleted already. The list below is current.', 'stonewright' ) ),
			'not-draft' => Notice::render( 'warn', __( 'That entry is not a draft, so nothing changed.', 'stonewright' ), __( 'Only draft lessons can be approved or discarded.', 'stonewright' ) ),
			default     => '',
		};
	}

	/**
	 * @param array<string, mixed>|null $entry
	 */
	private static function entry_sentence( ?array $entry, string $fallback ): string {
		if ( null === $entry ) {
			return $fallback;
		}

		/* translators: 1: entry name, 2: scope, 3: key */
		return sprintf( __( '"%1$s" is stored under %2$s / %3$s.', 'stonewright' ), (string) $entry['name'], (string) $entry['scope'], (string) $entry['memory_key'] );
	}

	private static function schema_html(): string {
		if ( Memory::table_schema_ok() ) {
			return '';
		}

		return Notice::render(
			'danger',
			__( 'Stonewright memory table is missing or outdated.', 'stonewright' ),
			__( 'Learning promotion and memory abilities cannot store entries. Deactivate and reactivate the plugin, or check database ALTER and CREATE permissions, then reload this page.', 'stonewright' )
		);
	}

	private static function guidance_html(): string {
		$rows = [
			[ __( 'What belongs here', 'stonewright' ), __( 'Store project conventions, builder rules, recurring user feedback, naming standards, and hard-won pitfalls that should survive across sessions.', 'stonewright' ) ],
			[ __( 'What stays out', 'stonewright' ), __( 'Do not store passwords, API keys, personal notes, or temporary task chatter. Memory is site-wide and visible to administrators.', 'stonewright' ) ],
			[ __( 'Token efficiency', 'stonewright' ), __( 'Prefer short, scoped entries. Discovery can find the right memory by type, scope, and key without loading a long briefing every time.', 'stonewright' ) ],
		];
		$body = '';
		foreach ( $rows as [ $title, $text ] ) {
			$body .= Html::element( 'p', [], Html::element( 'strong', [], Html::text( $title ) ) . ' ' . Html::text( $text ) );
		}

		return self::disclosure( __( 'Guidance', 'stonewright' ), $body, [ 'data-sw-ui-remember' => 'memory-guidance' ] );
	}

	/**
	 * A native disclosure: a summary of at least 40px and a body.
	 *
	 * @param array<string, scalar|list<string>|null> $attrs
	 */
	private static function disclosure( string $title, string $body_html, array $attrs = [], bool $open = false ): string {
		return Html::element(
			'details',
			array_merge( [ 'class' => 'sw-ui-disclosure' ], $attrs, [ 'open' => $open ? true : null ] ),
			Html::element( 'summary', [], Icon::render( 'chev-r' ) . Html::text( $title ) ) . Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body' ], $body_html )
		);
	}

	/**
	 * The memory entries: the add form, the lifecycle filters and the table.
	 *
	 * @param list<array<string, mixed>>   $entries
	 * @param array<string, int>           $counts
	 * @param list<array<string, mixed>>   $incident_rows
	 * @param array<string, int>           $incident_counts
	 * @param array<int, array<string, mixed>> $all_entries
	 */
	private static function entries_card( array $entries, int $in_view, array $counts, string $active, array $incident_rows, array $incident_counts, string $incident_state, int $legacy_unresolved_count, bool $mem_enabled, bool $add_open, array $all_entries ): string {
		$body  = self::add_form( $add_open );
		$body .= self::filters_nav( $counts, $active );

		if ( in_array( $active, [ 'incidents', 'unresolved_incidents' ], true ) ) {
			if ( 'unresolved_incidents' === $active && $legacy_unresolved_count > 0 ) {
				$body .= Notice::callout(
					'warn',
					sprintf( /* translators: %d: legacy memory incident count */ _n( '%d legacy incident memory remains pending reconciliation.', '%d legacy incident memories remain pending reconciliation.', $legacy_unresolved_count, 'stonewright' ), $legacy_unresolved_count )
				);
			}
			$body .= self::incident_section( $incident_rows, $incident_counts, 'unresolved_incidents' === $active ? 'unresolved' : $incident_state );
		} elseif ( [] === $entries ) {
			$body .= [] === $all_entries
				? EmptyState::render(
					__( 'No memory entries yet', 'stonewright' ),
					__( 'Memory holds durable site knowledge: conventions, rules and pitfalls that connected agents should remember. Agents add entries as they learn, or you can add one yourself.', 'stonewright' ),
					[ 'variant' => 'first-run', 'actions_html' => Button::render( __( 'Add entry', 'stonewright' ), [ 'href' => self::add_url() ] ) ]
				)
				: EmptyState::render(
					__( 'No entries in this view', 'stonewright' ),
					__( 'Nothing is stored under this lifecycle view yet.', 'stonewright' ),
					[ 'variant' => 'no-results', 'actions_html' => Button::render( __( 'Show all entries', 'stonewright' ), [ 'href' => self::page_url() ] ) ]
				);
		} else {
			$body .= self::entries_table( $entries );
			if ( $in_view > count( $entries ) ) {
				$body .= Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( sprintf( /* translators: 1: number listed, 2: total */ __( 'Showing the newest %1$d of %2$d entries.', 'stonewright' ), count( $entries ), $in_view ) ) );
			}
		}

		return Card::render(
			__( 'Memory entries', 'stonewright' ),
			$body,
			[
				'desc'         => __( 'Memory abilities let connected agents list, read, create, update, and delete these entries through Stonewright tools.', 'stonewright' ),
				'actions_html' => $mem_enabled
					? Badge::render( __( 'Memory abilities on', 'stonewright' ), [ 'variant' => 'ok', 'dot' => true ] )
					: Badge::render( __( 'Memory abilities off', 'stonewright' ), [ 'dot' => true ] ),
			]
		);
	}

	private static function add_form( bool $open ): string {
		$fields = FormField::input( __( 'Name', 'stonewright' ), 'name', [ 'id' => 'sw-memory-name', 'required' => true, 'size' => 'md' ] )
			. FormField::input(
				__( 'Scope', 'stonewright' ),
				'scope',
				[
					'id'    => 'sw-memory-scope',
					'value' => 'default',
					'size'  => 'md',
					'help'  => __( 'Groups related entries. A scope and key pair is unique: reusing one is refused, and nothing is replaced.', 'stonewright' ),
				]
			)
			. FormField::input( __( 'Key', 'stonewright' ), 'memory_key', [ 'id' => 'sw-memory-key', 'required' => true, 'size' => 'md' ] )
			. FormField::select( __( 'Type', 'stonewright' ), 'type', self::type_options(), [ 'id' => 'sw-memory-type', 'value' => 'user', 'size' => 'md' ] )
			. FormField::textarea( __( 'Value (JSON or text)', 'stonewright' ), 'value', [ 'id' => 'sw-memory-value', 'rows' => 6, 'code' => true ] )
			. Button::group( [ Button::render( __( 'Create entry', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] ) ] );

		return self::disclosure(
			__( 'Add a memory entry', 'stonewright' ),
			FormField::post_form( 'stonewright_memory_create', 'stonewright_memory', '_stonewright_nonce', $fields, [ 'class' => 'sw-ui-stack' ] ),
			[ 'id' => self::ADD_ID ],
			$open
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function type_options(): array {
		$options = [];
		foreach ( Memory::valid_types() as $type ) {
			$options[ $type ] = ucfirst( $type );
		}

		return $options;
	}

	/**
	 * @param array<string, int> $counts
	 */
	private static function filters_nav( array $counts, string $active ): string {
		$views = [
			'all'                  => __( 'All', 'stonewright' ),
			'user'                 => __( 'User', 'stonewright' ),
			'project'              => __( 'Project', 'stonewright' ),
			'verified_repairs'     => __( 'Verified repairs', 'stonewright' ),
			'unresolved_incidents' => __( 'Unresolved incidents', 'stonewright' ),
			'incidents'            => __( 'Incident lifecycle', 'stonewright' ),
			'audit_feedback'       => __( 'Audit feedback', 'stonewright' ),
			'reference'            => __( 'Reference', 'stonewright' ),
		];
		$items = '';
		foreach ( $views as $key => $label ) {
			$url    = 'all' === $key ? self::page_url() : self::page_url( [ 'type' => $key ] );
			$items .= Html::element(
				'li',
				[],
				Html::element( 'a', [ 'href' => $url, 'aria-current' => $key === $active ? 'location' : null ], Html::text( $label ) . ' ' . Badge::count( (int) ( $counts[ $key ] ?? 0 ) ) )
			);
		}

		return Html::element( 'nav', [ 'aria-label' => __( 'Memory views', 'stonewright' ) ], Html::element( 'ul', [ 'class' => 'sw-ui-toc' ], $items ) );
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 */
	private static function entries_table( array $entries ): string {
		$rows = [];
		foreach ( $entries as $e ) {
			$meta   = self::entry_meta( $e );
			$name   = (string) $e['name'];
			$status = (string) ( $e['status'] ?? '' );
			$state  = match ( $status ) {
				'active'   => Badge::render( __( 'Active', 'stonewright' ), [ 'variant' => 'ok' ] ),
				'draft'    => Badge::render( __( 'Draft', 'stonewright' ), [ 'variant' => 'warn' ] ),
				'stale'    => Badge::render( __( 'Stale', 'stonewright' ) ),
				'rejected' => Badge::render( __( 'Rejected', 'stonewright' ) ),
				default    => Badge::render( __( 'Unknown', 'stonewright' ) ),
			};
			if ( 'none' !== $meta['state'] ) {
				$state .= Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( $meta['state'] . ' · ' . $meta['verification'] ) );
			}

			$actions = [];
			if ( 'draft' === $status ) {
				$actions[] = FormField::post_form(
					'stonewright_memory_approve_draft',
					'stonewright_memory_draft',
					'_stonewright_nonce',
					Button::render( __( 'Approve', 'stonewright' ), [ 'type' => 'submit', 'size' => 'sm', 'context' => $name ] ),
					[ 'hidden' => [ 'id' => (string) (int) $e['id'] ] ]
				);
				$actions[] = FormField::post_form(
					'stonewright_memory_discard_draft',
					'stonewright_memory_draft',
					'_stonewright_nonce',
					Button::render( __( 'Discard', 'stonewright' ), [ 'type' => 'submit', 'size' => 'sm', 'context' => $name ] ),
					[ 'hidden' => [ 'id' => (string) (int) $e['id'] ] ]
				);
			}
			$actions[] = Button::render( __( 'Edit', 'stonewright' ), [ 'size' => 'sm', 'href' => self::page_url( [ 'edit' => (string) (int) $e['id'] ] ) . '#sw-memory-edit', 'context' => $name ] );

			$rows[] = [
				'name'    => [
					'html' => Html::element( 'span', [ 'class' => 'sw-ui-table__primary' ], Html::text( $name ) )
						. Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::element( 'code', [], Html::text( (string) $e['memory_key'] ) ) ),
				],
				'type'    => [ 'html' => Badge::tag( (string) $e['type'] ) ],
				'scope'   => [ 'text' => (string) $e['scope'] ],
				'status'  => [ 'html' => $state ],
				'updated' => [ 'html' => UtcTime::render( (string) $e['updated_at'] ) ],
				'actions' => [ 'html' => Button::group( $actions, true ) ],
			];
		}

		return Table::render(
			[
				[ 'key' => 'name', 'label' => __( 'Entry', 'stonewright' ), 'primary' => true ],
				[ 'key' => 'type', 'label' => __( 'Type', 'stonewright' ) ],
				[ 'key' => 'scope', 'label' => __( 'Scope', 'stonewright' ), 'secondary' => true ],
				[ 'key' => 'status', 'label' => __( 'Status', 'stonewright' ) ],
				[ 'key' => 'updated', 'label' => __( 'Updated', 'stonewright' ), 'secondary' => true ],
				[ 'key' => 'actions', 'label' => __( 'Actions', 'stonewright' ), 'actions' => true ],
			],
			$rows,
			[ 'caption' => __( 'Memory entries', 'stonewright' ) ]
		);
	}

	/**
	 * One entry: its facts, its form, and a delete that needs a second step.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function edit_card( array $entry ): string {
		$id   = (int) $entry['id'];
		$name = (string) $entry['name'];
		$meta = self::entry_meta( $entry );

		$facts = KvList::render(
			[
				[ 'label' => __( 'Backend', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( $meta['backend'] ) ) ],
				[ 'label' => __( 'Origin', 'stonewright' ), 'value' => $meta['origin'] . ' · ' . $meta['visibility'] ],
				[ 'label' => __( 'Activation', 'stonewright' ), 'value' => $meta['activation'] ],
				[ 'label' => __( 'Lifecycle', 'stonewright' ), 'value' => $meta['state'] . ' · ' . $meta['verification'] ],
				[ 'label' => __( 'Last retrieved', 'stonewright' ), 'value' => $meta['last_retrieved'] ],
				[ 'label' => __( 'Updated', 'stonewright' ), 'value_html' => UtcTime::render( (string) $entry['updated_at'] ) ],
			],
			[ 'inline' => true, 'label' => __( 'Entry facts', 'stonewright' ) ]
		);

		$fields = FormField::input( __( 'Name', 'stonewright' ), 'name', [ 'id' => 'sw-memory-edit-name', 'value' => $name, 'size' => 'md' ] )
			. FormField::input( __( 'Scope', 'stonewright' ), 'scope', [ 'id' => 'sw-memory-edit-scope', 'value' => (string) $entry['scope'], 'size' => 'md', 'help' => __( 'A scope and key pair is unique. Moving this entry onto a pair that is in use is refused.', 'stonewright' ) ] )
			. FormField::input( __( 'Key', 'stonewright' ), 'memory_key', [ 'id' => 'sw-memory-edit-key', 'value' => (string) $entry['memory_key'], 'size' => 'md' ] )
			. FormField::select( __( 'Type', 'stonewright' ), 'type', self::type_options(), [ 'id' => 'sw-memory-edit-type', 'value' => (string) $entry['type'], 'size' => 'md' ] )
			. FormField::textarea( __( 'Value (JSON or text)', 'stonewright' ), 'value', [ 'id' => 'sw-memory-edit-value', 'rows' => 8, 'code' => true, 'value' => self::value_to_text( $entry['value'] ?? null ) ] )
			. Button::group(
				[
					Button::render( __( 'Save changes', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] ),
					Button::render( __( 'Cancel', 'stonewright' ), [ 'href' => self::page_url() ] ),
				]
			);
		$form = FormField::post_form( 'stonewright_memory_update', 'stonewright_memory', '_stonewright_nonce', $fields, [ 'hidden' => [ 'id' => (string) $id ], 'class' => 'sw-ui-stack' ] );

		$delete = self::disclosure(
			__( 'Delete this entry', 'stonewright' ),
			Html::element( 'p', [], Html::text( __( 'Deleting removes the entry permanently. Agents will no longer load it, and there is no undo.', 'stonewright' ) ) )
				. FormField::post_form(
					'stonewright_memory_delete',
					'stonewright_memory',
					'_stonewright_nonce',
					Button::render( __( 'Delete entry', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'danger', 'context' => $name ] ),
					[ 'hidden' => [ 'id' => (string) $id ] ]
				)
		);

		return Card::render(
			/* translators: %s: entry name */
			sprintf( __( 'Edit %s', 'stonewright' ), $name ),
			Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $facts . $form . $delete ),
			[ 'id' => 'sw-memory-edit' ]
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param array<string, int>         $counts
	 */
	private static function incident_section( array $rows, array $counts, string $active_state ): string {
		$state_labels = [
			'open'       => __( 'Open', 'stonewright' ),
			'observing'  => __( 'Observing', 'stonewright' ),
			'resolved'   => __( 'Resolved', 'stonewright' ),
			'suppressed' => __( 'Suppressed', 'stonewright' ),
		];
		$items = '';
		foreach ( $state_labels as $state => $label ) {
			$items .= Html::element(
				'li',
				[],
				Html::element( 'a', [ 'href' => self::page_url( [ 'type' => 'incidents', 'incident_state' => $state ] ), 'aria-current' => $state === $active_state ? 'location' : null ], Html::text( $label ) . ' ' . Badge::count( (int) ( $counts[ $state ] ?? 0 ) ) )
			);
		}
		$items .= Html::element(
			'li',
			[],
			Html::element( 'a', [ 'href' => self::page_url( [ 'type' => 'incidents' ] ), 'aria-current' => '' === $active_state ? 'location' : null ], Html::text( __( 'All', 'stonewright' ) ) . ' ' . Badge::count( array_sum( $counts ) ) )
		);

		$table_rows = [];
		foreach ( $rows as $row ) {
			$incident_id = (string) ( $row['incident_id'] ?? '' );
			$audit_url   = add_query_arg( [ 'page' => AuditLogPage::SLUG, 'incident_id' => $incident_id ], admin_url( 'admin.php' ) );
			$path        = '' !== (string) ( $row['normalized_path'] ?? '' ) ? Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::element( 'code', [], Html::text( (string) $row['normalized_path'] ) ) ) : '';
			$timeline    = Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => $audit_url ], Html::text( __( 'View audit events', 'stonewright' ) ) )
				. Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], UtcTime::render( (string) ( $row['first_seen'] ?? '' ) ) . ' → ' . UtcTime::render( (string) ( $row['last_seen'] ?? '' ) ) )
				. ( '' !== (string) ( $row['resolved_at'] ?? '' ) ? Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( __( 'Resolved', 'stonewright' ) . ': ' ) . UtcTime::render( (string) $row['resolved_at'] ) ) : '' );

			$table_rows[] = [
				'_id'         => 'stonewright-incident-' . $incident_id,
				'ability'     => [ 'html' => Html::element( 'span', [ 'class' => 'sw-ui-table__primary' ], Html::element( 'code', [], Html::text( (string) ( $row['ability_name'] ?? '' ) ) ) ) . Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( (string) ( $row['category'] ?? '' ) . ' · ' . (string) ( $row['severity'] ?? '' ) ) ) ],
				'state'       => [ 'html' => Badge::tag( (string) ( $row['state'] ?? '' ) ) ],
				'error'       => [ 'html' => Html::element( 'code', [], Html::text( (string) ( $row['root_error_code'] ?? '' ) ) ) . $path ],
				'occurrences' => [ 'text' => (string) (int) ( $row['occurrence_count'] ?? 0 ) ],
				'timeline'    => [ 'html' => $timeline ],
			];
		}

		$empty = '' === $active_state ? __( 'No lifecycle incidents have been recorded.', 'stonewright' ) : ( 'unresolved' === $active_state ? __( 'No unresolved lifecycle incidents remain.', 'stonewright' ) : __( 'No incidents match this lifecycle state.', 'stonewright' ) );

		return Html::element( 'h3', [ 'class' => 'sw-memory__subtitle' ], Html::text( __( 'Incident lifecycle', 'stonewright' ) ) )
			. Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Observing incidents are below threshold. Open incidents require a correlated verified repair before they can resolve.', 'stonewright' ) ) )
			. Html::element( 'nav', [ 'aria-label' => __( 'Incident states', 'stonewright' ) ], Html::element( 'ul', [ 'class' => 'sw-ui-toc' ], $items ) )
			. ( [] === $table_rows
				? EmptyState::render( $empty, '', [ 'variant' => 'inline' ] )
				: Table::render(
					[
						[ 'key' => 'ability', 'label' => __( 'Ability', 'stonewright' ), 'primary' => true ],
						[ 'key' => 'state', 'label' => __( 'State', 'stonewright' ) ],
						[ 'key' => 'error', 'label' => __( 'Root error and path', 'stonewright' ) ],
						[ 'key' => 'occurrences', 'label' => __( 'Occurrences', 'stonewright' ), 'numeric' => true ],
						[ 'key' => 'timeline', 'label' => __( 'Timeline', 'stonewright' ) ],
					],
					$table_rows,
					[ 'caption' => __( 'Lifecycle incidents', 'stonewright' ) ]
				) );
	}

	private static function value_to_text( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		$encoded = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '' : $encoded;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function entry_bucket( array $entry ): string {
		$type = (string) ( $entry['type'] ?? 'generic' );
		if ( 'feedback' !== $type ) {
			return in_array( $type, [ 'user', 'project', 'reference' ], true ) ? $type : 'all';
		}
		$value = is_array( $entry['value'] ?? null ) ? $entry['value'] : [];
		$state = sanitize_key( (string) ( $value['state'] ?? '' ) );
		if ( in_array( $state, [ 'verified_resolved', 'promoted_learning' ], true ) || 'verified-repairs' === (string) ( $entry['scope'] ?? '' ) ) {
			return 'verified_repairs';
		}
		if ( in_array( $state, [ 'unresolved_incident', 'observed', 'repeated', 'repair_attempted', 'blocked_pending_repair' ], true ) ) {
			return 'unresolved_incidents';
		}
		return 'audit_feedback';
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{backend:string,origin:string,visibility:string,activation:string,state:string,verification:string,last_retrieved:string}
	 */
	private static function entry_meta( array $entry ): array {
		$value = is_array( $entry['value'] ?? null ) ? $entry['value'] : [];
		$state = (string) ( $value['state'] ?? '' );
		$source = (string) ( $value['source'] ?? ( 'feedback' === (string) ( $entry['type'] ?? '' ) ? 'audit-feedback' : 'operator-or-agent' ) );
		$verification = (string) ( $value['verification'] ?? '' );
		$activation = (string) ( $entry['status'] ?? '' );
		if ( '' === $activation ) {
			$activation = 'unknown';
		}
		if ( '' === $verification ) {
			$verification = in_array( $state, [ 'verified_resolved', 'promoted_learning' ], true ) ? 'verified' : 'unverified';
		}
		return [
			'backend'        => 'plugin-site',
			'origin'         => $source,
			'visibility'     => 'site-admin',
			'activation'     => $activation,
			'state'          => '' !== $state ? $state : 'none',
			'verification'   => $verification,
			'last_retrieved' => '' !== (string) ( $entry['last_retrieved_at'] ?? '' )
				? (string) $entry['last_retrieved_at'] . ' UTC'
				: 'never',
		];
	}

	/**
	 * Looks up one entry by the id the receipt carries. The result is announced where it was asked for.
	 */
	private static function receipt_result_html(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup.
		$id = isset( $_GET['receipt_id'] ) ? absint( $_GET['receipt_id'] ) : 0;
		if ( $id <= 0 ) {
			return '';
		}
		$entry = Memory::get_by_id( $id );
		if ( null === $entry ) {
			return Notice::render( 'warn', __( 'No plugin-site memory receipt exists for that ID.', 'stonewright' ), __( 'A Direct-local ID must be inspected or exported from the companion machine.', 'stonewright' ) );
		}
		$meta = self::entry_meta( $entry );

		return Notice::render(
			'ok',
			__( 'Receipt verified', 'stonewright' ),
			'',
			[
				'text_html' => Html::element( 'code', [], Html::text( 'wp:stonewright_memory#' . (string) $id ) ) . ' · ' . Html::text( $meta['backend'] . ' · ' . $meta['visibility'] . ' · ' . $meta['state'] ),
			]
		);
	}

	// ------------------------------------------------------------------
	// Handlers. Each one checks the capability and the nonce, calls a *_submission
	// method that does the work and returns where the browser goes next, and redirects.
	// ------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $args
	 */
	private static function notice_url( string $code, array $args = [] ): string {
		return self::page_url( array_merge( [ 'memory_notice' => $code ], $args ) );
	}

	public static function handle_create(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_memory', '_stonewright_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- create_submission() sanitizes each field it reads.
		wp_safe_redirect( self::create_submission( (array) wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Adds an entry and returns where the browser goes next. A scope and key pair that is already in use is refused:
	 * the entry that holds it is left exactly as it was.
	 *
	 * @param array<string, mixed> $form Unslashed form fields; the caller has checked the capability and nonce.
	 */
	public static function create_submission( array $form ): string {
		$type       = sanitize_key( is_string( $form['type'] ?? null ) ? $form['type'] : 'generic' );
		$scope      = sanitize_text_field( is_string( $form['scope'] ?? null ) ? $form['scope'] : '' );
		$memory_key = sanitize_text_field( is_string( $form['memory_key'] ?? null ) ? $form['memory_key'] : '' );
		$name       = sanitize_text_field( is_string( $form['name'] ?? null ) ? $form['name'] : '' );
		$value      = is_string( $form['value'] ?? null ) ? $form['value'] : '';
		$scope      = '' === $scope ? 'default' : $scope;

		if ( '' === $memory_key || '' === $name ) {
			return self::notice_url( 'invalid' );
		}

		$existing = Memory::find_id( $scope, $memory_key );
		if ( $existing > 0 ) {
			return self::notice_url( 'exists', [ 'entry' => (string) $existing ] );
		}

		$id = Memory::put_typed( $type, $scope, $memory_key, $name, $value );
		if ( $id <= 0 ) {
			return self::notice_url( 'blocked' );
		}

		return self::notice_url( 'created', [ 'entry' => (string) $id ] );
	}

	public static function handle_update(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_memory', '_stonewright_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- update_submission() sanitizes each field it reads.
		wp_safe_redirect( self::update_submission( (array) wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Saves an edited entry and returns where the browser goes next.
	 *
	 * @param array<string, mixed> $form Unslashed form fields; the caller has checked the capability and nonce.
	 */
	public static function update_submission( array $form ): string {
		$id    = absint( is_scalar( $form['id'] ?? null ) ? $form['id'] : 0 );
		$entry = $id > 0 ? Memory::get_by_id( $id ) : null;
		if ( null === $entry ) {
			return self::notice_url( 'not-found' );
		}

		$changes = [];
		if ( isset( $form['type'] ) && is_string( $form['type'] ) ) {
			$changes['type'] = sanitize_key( $form['type'] );
		}
		if ( isset( $form['scope'] ) && is_string( $form['scope'] ) ) {
			$changes['scope'] = sanitize_text_field( $form['scope'] );
		}
		if ( isset( $form['memory_key'] ) && is_string( $form['memory_key'] ) ) {
			$changes['memory_key'] = sanitize_text_field( $form['memory_key'] );
		}
		if ( isset( $form['name'] ) && is_string( $form['name'] ) ) {
			$changes['name'] = sanitize_text_field( $form['name'] );
		}
		if ( isset( $form['value'] ) && is_string( $form['value'] ) ) {
			$changes['value'] = $form['value'];
		}

		$scope = (string) ( $changes['scope'] ?? $entry['scope'] );
		$key   = (string) ( $changes['memory_key'] ?? $entry['memory_key'] );
		if ( $scope !== (string) $entry['scope'] || $key !== (string) $entry['memory_key'] ) {
			$holder = Memory::find_id( $scope, $key );
			if ( $holder > 0 && $holder !== $id ) {
				return self::notice_url( 'exists', [ 'entry' => (string) $holder ] );
			}
		}

		if ( ! Memory::update_by_id( $id, $changes ) ) {
			return self::notice_url( 'failed' );
		}

		return self::notice_url( 'updated', [ 'entry' => (string) $id ] );
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_memory', '_stonewright_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- delete_submission() reads one id.
		wp_safe_redirect( self::delete_submission( (array) wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Deletes an entry and returns where the browser goes next.
	 *
	 * @param array<string, mixed> $form Unslashed form fields; the caller has checked the capability and nonce.
	 */
	public static function delete_submission( array $form ): string {
		$id = absint( is_scalar( $form['id'] ?? null ) ? $form['id'] : 0 );
		if ( $id <= 0 || ! Memory::delete_by_id( $id ) ) {
			return self::notice_url( 'not-found' );
		}

		return self::notice_url( 'deleted' );
	}

	public static function handle_approve_draft(): void {
		self::handle_draft_review( 'approve' );
	}

	public static function handle_discard_draft(): void {
		self::handle_draft_review( 'discard' );
	}

	/**
	 * Promote a draft lesson to active, or reject it. Only draft rows move.
	 */
	public static function apply_draft_review( int $id, string $decision ): bool {
		if ( $id <= 0 || ! in_array( $decision, [ 'approve', 'discard' ], true ) ) {
			return false;
		}
		$entry = Memory::get_by_id( $id );
		if ( null === $entry || 'draft' !== (string) ( $entry['status'] ?? '' ) ) {
			return false;
		}
		if ( 'approve' === $decision ) {
			// Record who approved the lesson and when (UTC).
			$value             = is_array( $entry['value'] ?? null ) ? $entry['value'] : [];
			$value['approval'] = [
				'approved_at' => current_time( 'mysql', true ),
				'approved_by' => get_current_user_id(),
			];
			return Memory::update_by_id( $id, [ 'status' => 'active', 'value' => $value ] );
		}
		return Memory::update_by_id( $id, [ 'status' => 'rejected' ] );
	}

	/**
	 * Approves or discards a draft lesson and returns where the browser goes next.
	 */
	public static function draft_review_submission( int $id, string $decision ): string {
		$entry = $id > 0 ? Memory::get_by_id( $id ) : null;
		if ( null === $entry || ! in_array( $decision, [ 'approve', 'discard' ], true ) ) {
			return self::notice_url( 'not-found' );
		}
		if ( 'draft' !== (string) ( $entry['status'] ?? '' ) ) {
			return self::notice_url( 'not-draft', [ 'entry' => (string) $id ] );
		}
		if ( ! self::apply_draft_review( $id, $decision ) ) {
			return self::notice_url( 'failed' );
		}

		return self::notice_url( 'approve' === $decision ? 'approved' : 'discarded', [ 'entry' => (string) $id ] );
	}

	private static function handle_draft_review( string $decision ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_memory_draft', '_stonewright_nonce' );

		wp_safe_redirect( self::draft_review_submission( (int) ( $_POST['id'] ?? 0 ), $decision ) );
		exit;
	}

	/**
	 * Disable a learned rule (sets status=stale) without hard-deleting history.
	 */
	public static function handle_learning_disable(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_learning_disable', '_stonewright_nonce' );

		wp_safe_redirect( self::learning_disable_submission( (int) ( $_POST['id'] ?? 0 ) ) );
		exit;
	}

	/**
	 * Marks a learned rule stale and returns where the browser goes next.
	 */
	public static function learning_disable_submission( int $id ): string {
		$entry = $id > 0 ? Memory::get_by_id( $id ) : null;
		if ( null === $entry ) {
			return self::notice_url( 'not-found' );
		}
		if ( ! Memory::update_by_id( $id, [ 'status' => 'stale' ] ) ) {
			return self::notice_url( 'failed' );
		}

		return self::notice_url( 'disabled', [ 'entry' => (string) $id ] );
	}

	/**
	 * Reclassify legacy automatic feedback in-place. No rows or audit history
	 * are deleted; the operator must confirm an export was taken first.
	 */
	public static function handle_migrate_feedback(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_memory_migrate_feedback', '_stonewright_nonce' );
		if ( '1' !== (string) ( $_POST['export_confirmed'] ?? '' ) ) {
			wp_die( esc_html__( 'Export the current memory bundle before migration.', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$updated = 0;
		foreach ( Memory::list_by_type( 'feedback', 10000 ) as $entry ) {
			$value = is_array( $entry['value'] ?? null ) ? $entry['value'] : [];
			$key   = (string) ( $entry['memory_key'] ?? '' );
			$name  = strtolower( (string) ( $entry['name'] ?? '' ) );
			if ( str_contains( $name, 'post-deploy smoke' ) || str_contains( $key, 'post-deploy-smoke' ) ) {
				$value['state']  = 'historical_feedback';
				$value['source'] = (string) ( $value['source'] ?? 'audit-feedback' );
				Memory::update_by_id( (int) $entry['id'], [ 'value' => $value, 'status' => 'stale' ] );
				++$updated;
				continue;
			}
			if (
				'audit-error' !== (string) ( $value['source'] ?? '' )
				&& ! str_starts_with( $key, 'learning-audit-error-' )
			) {
				continue;
			}
			$value['state']        = (string) ( $value['state'] ?? 'unresolved_incident' );
			$value['verification'] = (string) ( $value['verification'] ?? 'unverified' );
			Memory::update_by_id( (int) $entry['id'], [ 'value' => $value, 'status' => 'stale' ] );
			++$updated;
		}

		wp_safe_redirect(
			self::page_url( [ 'migrated' => (string) $updated ] )
		);
		exit;
	}

	/**
	 * @param array<int, array<string, mixed>> $all_entries
	 */
	private static function learned_rules_card( array $all_entries ): string {
		$rules = [];
		foreach ( $all_entries as $entry ) {
			if ( 'feedback' !== (string) ( $entry['type'] ?? '' ) ) {
				continue;
			}
			$key = (string) ( $entry['memory_key'] ?? '' );
			if ( ! str_starts_with( $key, 'learning-' ) ) {
				continue;
			}
			if ( ! Memory::is_task_start_eligible( $entry ) ) {
				continue;
			}
			$rules[] = $entry;
		}

		if ( [] === $rules ) {
			return '';
		}

		$rows = [];
		foreach ( $rules as $rule ) {
			$value = is_array( $rule['value'] ?? null ) ? $rule['value'] : [];
			$name  = (string) ( $rule['name'] ?? $rule['topic'] ?? '' );
			$rows[] = [
				'rule'     => [ 'text' => $name, 'meta' => (string) ( $value['correction'] ?? '' ) ],
				'source'   => [ 'html' => Badge::tag( (string) ( $value['source'] ?? 'manual' ) ) ],
				'severity' => [ 'html' => Badge::tag( (string) ( $value['severity'] ?? 'medium' ) ) ],
				'action'   => [
					'html' => FormField::post_form(
						'stonewright_learning_disable',
						'stonewright_learning_disable',
						'_stonewright_nonce',
						Button::render( __( 'Disable', 'stonewright' ), [ 'type' => 'submit', 'size' => 'sm', 'context' => $name ] ),
						[ 'hidden' => [ 'id' => (string) (int) ( $rule['id'] ?? 0 ) ] ]
					),
				],
			];
		}

		return Card::render(
			__( 'Learned rules', 'stonewright' ),
			Table::render(
				[
					[ 'key' => 'rule', 'label' => __( 'Rule', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'source', 'label' => __( 'Source', 'stonewright' ), 'secondary' => true ],
					[ 'key' => 'severity', 'label' => __( 'Severity', 'stonewright' ), 'secondary' => true ],
					[ 'key' => 'action', 'label' => __( 'Action', 'stonewright' ), 'actions' => true ],
				],
				$rows,
				[ 'caption' => __( 'Learned rules', 'stonewright' ) ]
			),
			[
				'flush' => true,
				'desc'  => __( 'Corrections and recurring audit errors that agents load at task-start.', 'stonewright' ),
			]
		);
	}

	/**
	 * Memory abilities, custom instructions and the instructions text. They share one form, because WordPress saves
	 * every option of a group on each post and clears the ones the post did not carry.
	 */
	private static function settings_card( bool $instructions_enabled, bool $memory_enabled, string $instructions ): string {
		ob_start();
		settings_fields( self::OPT_GROUP );
		$group_fields = (string) ob_get_clean();

		$fields = FormField::switch(
			__( 'Enable memory abilities', 'stonewright' ),
			'stonewright_memory_enabled',
			[
				'id'          => 'stonewright_memory_enabled',
				'checked'     => $memory_enabled,
				'hidden_zero' => true,
				'help'        => __( 'Connected agents can list, read, create, update, and delete memory entries through Stonewright tools.', 'stonewright' ),
			]
		)
			. FormField::switch(
				__( 'Enable custom instructions', 'stonewright' ),
				'stonewright_custom_instructions_enabled',
				[
					'id'          => 'stonewright_custom_instructions_enabled',
					'checked'     => $instructions_enabled,
					'hidden_zero' => true,
				]
			)
			. FormField::textarea(
				__( 'Custom instructions', 'stonewright' ),
				'stonewright_custom_instructions',
				[
					'id'        => 'stonewright_custom_instructions',
					'rows'      => 10,
					'code'      => true,
					'maxlength' => 4000,
					'value'     => $instructions,
					'help'      => __( 'A short baseline rule set that connected agents should always see. Up to 4000 characters. Keep larger procedures as skills instead.', 'stonewright' ),
				]
			)
			. Button::group( [ Button::render( __( 'Save settings', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] ) ] );

		$form = Html::element( 'form', [ 'class' => 'sw-ui-stack', 'method' => 'post', 'action' => 'options.php' ], $group_fields . $fields );

		return Card::render( __( 'Settings', 'stonewright' ), $form );
	}

	private static function bundle_card(): string {
		$export = FormField::post_form(
			'stonewright_knowledge_export',
			'stonewright_knowledge_bundle',
			'_stonewright_nonce',
			Button::render( __( 'Export JSON', 'stonewright' ), [ 'type' => 'submit' ] )
		);
		$import = self::disclosure(
			__( 'Import JSON', 'stonewright' ),
			FormField::post_form(
				'stonewright_knowledge_import',
				'stonewright_knowledge_bundle',
				'_stonewright_nonce',
				FormField::textarea( __( 'Bundle JSON', 'stonewright' ), 'bundle_json', [ 'id' => 'sw-memory-bundle', 'rows' => 10, 'code' => true, 'required' => true ] )
					. Button::group( [ Button::render( __( 'Import bundle', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] ) ] ),
				[ 'class' => 'sw-ui-stack' ]
			)
		);

		return Card::render(
			__( 'Import and export', 'stonewright' ),
			Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $export . $import ),
			[ 'desc' => __( 'Export or import custom instructions, memory entries, and skills as a portable Stonewright JSON bundle.', 'stonewright' ) ]
		);
	}

	private static function tools_card(): string {
		$lookup = Html::element(
			'form',
			[ 'class' => 'sw-memory__inline', 'method' => 'get', 'action' => admin_url( 'admin.php' ) ],
			FormField::hidden( 'page', self::SLUG )
				. FormField::input( __( 'Plugin memory ID', 'stonewright' ), 'receipt_id', [ 'id' => 'sw-memory-receipt', 'type' => 'number', 'min' => 1, 'size' => 'md' ] )
				. Button::render( __( 'Look up receipt', 'stonewright' ), [ 'type' => 'submit' ] )
		);
		$migrate = Html::element( 'p', [], Html::text( __( 'Legacy automatic feedback can be reclassified without deleting audit history. Export JSON first; the migration preserves historical Post-deploy smoke feedback.', 'stonewright' ) ) )
			. FormField::post_form(
				'stonewright_memory_migrate_feedback',
				'stonewright_memory_migrate_feedback',
				'_stonewright_nonce',
				FormField::checkbox( __( 'I exported the current JSON bundle.', 'stonewright' ), 'export_confirmed', [ 'id' => 'sw-memory-exported', 'required' => true ] )
					. Button::render( __( 'Classify legacy feedback', 'stonewright' ), [ 'type' => 'submit' ] ),
				[ 'class' => 'sw-memory__inline' ]
			);

		return Card::render(
			__( 'Receipt lookup and feedback migration', 'stonewright' ),
			Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $lookup . self::receipt_result_html() . $migrate )
		);
	}

	public static function handle_export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_knowledge_bundle', '_stonewright_nonce' );

		$json = wp_json_encode( KnowledgeBundle::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			wp_die( esc_html__( 'Failed to encode knowledge bundle.', 'stonewright' ) );
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="stonewright-knowledge-bundle.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function handle_import(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_knowledge_bundle', '_stonewright_nonce' );

		$raw = wp_unslash( (string) ( $_POST['bundle_json'] ?? '' ) );

		wp_safe_redirect( self::import_submission( $raw ) );
		exit;
	}

	/**
	 * Imports a pasted knowledge bundle and returns where the browser goes next. The
	 * address carries how many skills arrived as disabled drafts and how many were not added.
	 *
	 * @param string $raw Unslashed bundle JSON; the caller has checked the capability and nonce.
	 */
	public static function import_submission( string $raw ): string {
		$args = [ 'page' => self::SLUG, 'import_error' => '1' ];
		try {
			$bundle = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
			if ( is_array( $bundle ) ) {
				$result = KnowledgeBundle::import( $bundle );
				$args   = [
					'page'            => self::SLUG,
					'imported'        => '1',
					'skills_imported' => (string) $result['skills_imported'],
					'skills_skipped'  => (string) count( $result['skills_skipped'] ),
				];
			}
		} catch ( \Throwable ) {
			// Not JSON, or not a bundle this plugin can read: the error flag stays.
		}

		return add_query_arg(
			$args,
			admin_url( 'admin.php' )
		);
	}

	/**
	 * What the last bundle import did, from the flags its redirect carries.
	 */
	private static function import_notice(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result flags from the import redirect.
		$failed   = ! empty( $_GET['import_error'] );
		$imported = ! empty( $_GET['imported'] );
		$added    = isset( $_GET['skills_imported'] ) ? absint( wp_unslash( $_GET['skills_imported'] ) ) : 0;
		$skipped  = isset( $_GET['skills_skipped'] ) ? absint( wp_unslash( $_GET['skills_skipped'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $failed ) {
			return Notice::render( 'danger', __( 'The bundle could not be imported.', 'stonewright' ), __( 'Paste a Stonewright knowledge bundle exported by this plugin.', 'stonewright' ) );
		}
		if ( ! $imported ) {
			return '';
		}

		$message = '';
		if ( $added > 0 ) {
			/* translators: %d: number of skills added as drafts */
			$message .= sprintf( _n( '%d skill was added as a disabled draft; review it on the Skills page before enabling it.', '%d skills were added as disabled drafts; review them on the Skills page before enabling them.', $added, 'stonewright' ), $added );
		}
		if ( $skipped >= KnowledgeBundle::SKIPPED_LIMIT ) {
			/* translators: %d: number of skills from the bundle that were not added */
			$message .= ' ' . sprintf( __( '%d or more skills from the bundle were not added: their slugs already belong to skills, or the entries were refused. Existing skills are never replaced.', 'stonewright' ), $skipped );
		} elseif ( $skipped > 0 ) {
			/* translators: %d: number of skills from the bundle that were not added */
			$message .= ' ' . sprintf( _n( '%d skill from the bundle was not added: its slug already belongs to a skill, or the entry was refused. Existing skills are never replaced.', '%d skills from the bundle were not added: their slugs already belong to skills, or the entries were refused. Existing skills are never replaced.', $skipped, 'stonewright' ), $skipped );
		}

		return Notice::render( 'ok', __( 'Knowledge bundle imported.', 'stonewright' ), trim( $message ) );
	}
}
