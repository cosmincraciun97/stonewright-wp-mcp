<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\CopyField;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\HubNav;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\PageHeader;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Table;
use Stonewright\WpMcp\Tests\Unit\Assets\CoreAdminSchemes;

/**
 * Renders every component of the admin UI layer on one page.
 *
 * The page is checked in as plugin/tests/fixtures/admin-ui/component-sheet.html (ComponentSheetSnapshotTest keeps
 * it in step with the helpers). The e2e specs load it in a real browser inside the legacy shell stylesheets, so
 * the layer is measured next to the rules it has to coexist with: contrast, target size, names, motion and the
 * nine WordPress colour schemes. Components without a PHP helper are written out here as the markup contract.
 */
final class ComponentSheet {

	/** The whole document. */
	public static function document(): string {
		Html::reset_ids();
		Icon::reset_for_tests();

		return "<!doctype html>\n"
			. '<html lang="en">' . "\n"
			. '<head>' . "\n"
			. '<meta charset="utf-8">' . "\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
			. '<title>Stonewright UI component sheet</title>' . "\n"
			. '<link rel="stylesheet" href="sw-ui.css">' . "\n"
			. '<link rel="stylesheet" href="shell.css">' . "\n"
			. '<link rel="stylesheet" href="admin.css">' . "\n"
			. '<link rel="stylesheet" href="stonewright-admin.css">' . "\n"
			. '<style>' . self::chrome_css() . '</style>' . "\n"
			. '</head>' . "\n"
			. '<body class="wp-admin wp-core-ui admin-color-modern">' . "\n"
			. Icon::sprite() . "\n"
			. '<div id="wpadminbar" role="navigation" aria-label="Toolbar"><span>Compat Fixture</span><span>New</span></div>' . "\n"
			. '<div id="wpcontent"><main id="wpbody-content">' . "\n"
			. '<div class="sw-shell wrap"><div class="sw-shell__content">' . "\n"
			. Scope::wrap( self::sections(), [ 'page' => true ] ) . "\n"
			. '</div></div>' . "\n"
			. '</main></div>' . "\n"
			. '<script type="application/json" id="sheet-schemes">' . self::schemes_json() . '</script>' . "\n"
			. '<script src="sw-ui.js"></script>' . "\n"
			. '</body>' . "\n"
			. '</html>' . "\n";
	}

	/**
	 * The accent steps of every scheme, for the browser specs: the palette of WordPress 7.1 and the brighter one the
	 * same schemes carried earlier. A spec applies a palette by setting body.admin-color-<name> and the three
	 * custom properties, which is what the matching WordPress release does.
	 */
	private static function schemes_json(): string {
		$earlier = [];
		foreach ( CoreAdminSchemes::earlier() as $name => $steps ) {
			$earlier[ $name ] = array_map(
				static fn ( array $rgb ): string => sprintf( 'rgb(%d,%d,%d)', round( $rgb[0] ), round( $rgb[1] ), round( $rgb[2] ) ),
				$steps
			);
		}

		return (string) json_encode( [ 'current' => CoreAdminSchemes::WORDPRESS_7_1, 'earlier' => $earlier ], JSON_UNESCAPED_SLASHES );
	}

	/** Mock WordPress chrome: the admin bar, the colour scheme variables and core's base text. Not part of the layer. */
	private static function chrome_css(): string {
		// The colour scheme variables core sets: the default scheme on :root and the nine schemes of WordPress 7.1
		// on body.admin-color-<name>.
		$css = ':root{--wp-admin--admin-bar--height:32px;--wp-admin-border-width-focus:2px;' . self::scheme_variables( CoreAdminSchemes::WORDPRESS_7_1['fresh'] ) . '}';
		foreach ( CoreAdminSchemes::WORDPRESS_7_1 as $name => $steps ) {
			$css .= 'body.admin-color-' . $name . '{' . self::scheme_variables( $steps ) . '}';
		}
		$css .= 'body{margin:0;background:#f0f0f1;color:#1d2327;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif}';
		$css .= '#wpadminbar{position:fixed;z-index:99999;top:0;left:0;right:0;height:32px;display:flex;align-items:center;gap:18px;padding:0 12px;background:#1d2327;color:#f0f0f1}';
		$css .= '#wpcontent{padding:32px 20px 0}';
		$css .= '.wrap h1{font-size:23px;font-weight:400;margin:0;padding:9px 0 4px;line-height:1.3}';
		$css .= 'a{color:#0073aa}code{background:rgba(0,0,0,.07);padding:3px 5px 2px 1px;margin:0 1px;font-size:13px}';
		$css .= 'input[type=checkbox]{appearance:none;width:1rem;height:1rem;border:1px solid #8c8f94;border-radius:2px;background:#fff}input[type=checkbox]:checked::before{content:"\\2713";display:block;line-height:1rem;text-align:center}';
		$css .= '.sheet-label{margin:32px 0 8px;font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#50575e}';

		return $css;
	}

	/**
	 * The three accent steps of a scheme as custom properties.
	 *
	 * @param array{0: string, 1: string, 2: string} $steps Hex colours.
	 */
	private static function scheme_variables( array $steps ): string {
		return '--wp-admin-theme-color:' . $steps[0] . ';--wp-admin-theme-color-darker-10:' . $steps[1] . ';--wp-admin-theme-color-darker-20:' . $steps[2];
	}

	private static function label( string $text ): string {
		return '<h2 class="sheet-label">' . esc_html( $text ) . '</h2>';
	}

	private static function sections(): string {
		return self::overview() . self::hub() . self::buttons() . self::forms() . self::copy_and_code()
			. self::badges() . self::notices() . self::states() . self::tables() . self::disclosure_tabs_lineage() . self::overlays();
	}

	private static function overview(): string {
		$stats = '<div class="sw-ui-stats" role="group" aria-label="Status summary">'
			. self::stat( 'Connection', '2 clients', 'Last sign-in 3 min ago' )
			. self::stat( 'Mode', 'Production-safe', 'Confirmation tokens on' )
			. self::stat( 'Tool surface', '389', 'Essential profile' )
			. self::stat( 'Last activity', '3 min ago', '245 calls in 14 days' )
			. '</div>';

		$attention = Table::render(
			[
				[ 'key' => 'item', 'label' => 'Item', 'primary' => true ],
				[ 'key' => 'status', 'label' => 'Status' ],
				[ 'key' => 'action', 'label' => 'Action', 'actions' => true ],
			],
			[
				[ 'item' => [ 'text' => '4 open incidents', 'meta' => 'Repeated errors, last seen 3 min ago' ], 'status' => [ 'html' => Badge::render( 'Open', [ 'variant' => 'danger', 'icon' => 'x' ] ) ], 'action' => [ 'html' => Button::render( 'Review incidents', [ 'href' => '#incidents', 'size' => 'sm' ] ) ] ],
				[ 'item' => [ 'text' => '2 block changes waiting', 'meta' => 'Open the editor console to prepare them' ], 'status' => [ 'html' => Badge::render( 'Queued', [ 'variant' => 'warn', 'icon' => 'alert' ] ) ], 'action' => [ 'html' => Button::render( 'Open queue', [ 'href' => '#queue', 'size' => 'sm' ] ) ] ],
				[ 'item' => [ 'text' => 'Connection not verified yet', 'meta' => 'Run the last setup step to confirm a client can call a tool' ], 'status' => [ 'html' => Badge::render( 'Setup', [ 'variant' => 'info', 'icon' => 'info' ] ) ], 'action' => [ 'html' => Button::render( 'Verify', [ 'href' => '#verify', 'size' => 'sm', 'variant' => 'primary' ] ) ] ],
			],
			[ 'caption' => 'Items that need attention' ]
		);

		return PageHeader::render(
			'Overview',
			[
				'lede'       => 'Your AI connection at a glance: what is on, what needs you, and what the agents did.',
				'aside_html' => Badge::render( 'AI abilities on', [ 'variant' => 'ok', 'dot' => true ] ) . Badge::render( 'Production-safe' ) . Badge::tag( 'v1.0.0-beta.13.3' ),
			]
		)
			. $stats
			. '<div class="sw-ui-grid sw-ui-grid--2-1"><div class="sw-ui-stack">'
			. Card::render( 'Needs attention', $attention, [ 'flush' => true, 'actions_html' => '<a class="sw-ui-link" href="#all">View all</a>' ] )
			. '</div><div class="sw-ui-stack">'
			. Card::render(
				'Finish setup',
				'<ol class="sw-ui-lineage" aria-label="Setup steps">'
				. '<li class="sw-ui-lineage__node">' . Badge::render( 'Done', [ 'variant' => 'ok', 'icon' => 'check' ] ) . ' Enable AI abilities</li>'
				. '<li class="sw-ui-lineage__node">' . Badge::render( 'Done', [ 'variant' => 'ok', 'icon' => 'check' ] ) . ' Choose sign-in method</li>'
				. '<li class="sw-ui-lineage__node" aria-current="step">' . Badge::render( 'Next', [ 'variant' => 'accent' ] ) . ' Verify the connection</li>'
				. '</ol>',
				[ 'footer_html' => '<span>About a minute</span>' . Button::render( 'Verify connection', [ 'variant' => 'primary', 'size' => 'sm' ] ) ]
			)
			. '</div></div>';
	}

	private static function stat( string $label, string $value, string $meta ): string {
		return '<div class="sw-ui-stat"><span class="sw-ui-stat__label">' . esc_html( $label ) . '</span><span class="sw-ui-stat__value">' . esc_html( $value ) . '</span><span class="sw-ui-stat__meta">' . esc_html( $meta ) . '</span></div>';
	}

	private static function hub(): string {
		return self::label( 'Hub tab bar and in-page navigation' )
			. HubNav::render(
				[
					[ 'label' => 'Get started', 'url' => '#start', 'current' => true, 'count' => null, 'count_label' => '' ],
					[ 'label' => 'Settings', 'url' => '#settings', 'current' => false, 'count' => null, 'count_label' => '' ],
					[ 'label' => 'Connections', 'url' => '#connections', 'current' => false, 'count' => 2, 'count_label' => 'connected clients' ],
					[ 'label' => 'Updates', 'url' => '#updates', 'current' => false, 'count' => null, 'count_label' => '' ],
				],
				'Setup sections'
			)
			. '<nav aria-label="On this page"><ul class="sw-ui-toc"><li><a href="#a" aria-current="location">Incidents</a></li><li><a href="#b">Filters</a></li><li><a href="#c">Entries</a></li></ul></nav>'
			. '<div class="sw-ui-actions" role="group" aria-label="Filters">'
			. '<button type="button" class="sw-ui-chip-filter" aria-pressed="true">Errors</button>'
			. '<button type="button" class="sw-ui-chip-filter" aria-pressed="false">Writes</button>'
			. '</div>';
	}

	private static function buttons(): string {
		$row_one = Button::render( 'Primary', [ 'variant' => 'primary' ] )
			. Button::render( 'Secondary' )
			. Button::render( 'Tertiary', [ 'variant' => 'tertiary' ] )
			. Button::render( 'Destructive', [ 'variant' => 'danger' ] )
			. Button::render( 'Delete all logs', [ 'variant' => 'danger-solid' ] )
			. Button::render( 'Saving', [ 'variant' => 'primary', 'busy' => true ] )
			. Button::render( 'Disabled', [ 'disabled' => true ] );
		$row_two = Button::render( 'Small primary', [ 'variant' => 'primary', 'size' => 'sm' ] )
			. Button::render( 'Small', [ 'size' => 'sm' ] )
			. Button::render( 'Extra small', [ 'size' => 'xs' ] )
			. Button::render( 'Copy', [ 'icon' => 'copy', 'icon_only' => true, 'context' => 'MCP server URL' ] )
			. Button::render( 'Disconnect', [ 'icon' => 'trash', 'icon_only' => true, 'variant' => 'danger', 'context' => 'Example client' ] )
			. Button::render( 'Link as button', [ 'href' => '#link', 'size' => 'sm' ] )
			. Button::render( 'Add entry', [ 'icon' => 'plus' ] );

		$row_three = Button::render( 'Save changes', [ 'variant' => 'primary' ] ) . Button::render( 'Cancel' );

		return self::label( 'Buttons' ) . Card::render( 'Variants, sizes and states', '<div class="sw-ui-stack">' . Button::group( [ $row_one ] ) . Button::group( [ $row_two ] ) . Button::group( [ $row_three ] ) . '</div>' );
	}

	private static function forms(): string {
		$mode = '<select class="sw-ui-select" id="f-mode" aria-describedby="f-mode-h"><option>Production-safe</option><option>Staging</option><option>Development</option></select>'
			. '<span class="sw-ui-field__help" id="f-mode-h">Destructive and bulk writes need a fresh confirmation token per operation.</span>';
		$reuse = '<label class="sw-ui-switch"><input type="checkbox" role="switch" checked aria-describedby="f-reuse-h"><span class="sw-ui-switch__track" aria-hidden="true"></span><span>Let agents reuse saved sections</span></label>'
			. '<p class="sw-ui-field__help" id="f-reuse-h">New settings use this exact row pattern.</p>';
		$key = '<input class="sw-ui-input" id="f-key" type="password" aria-invalid="true" aria-describedby="f-key-e">'
			. '<span class="sw-ui-field__error" id="f-key-e">' . Icon::render( 'alert' ) . 'Enter a key or leave the field empty.</span>';
		$table = '<table class="sw-ui-form-table" role="presentation"><tbody>'
			. '<tr><th scope="row"><label for="f-mode">Mode</label></th><td><div class="sw-ui-field sw-ui-field--md">' . $mode . '</div></td></tr>'
			. '<tr><th scope="row">Reuse sections</th><td>' . $reuse . '</td></tr>'
			. '<tr><th scope="row"><label for="f-key">Pexels API key</label></th><td><div class="sw-ui-field sw-ui-field--md">' . $key . '</div></td></tr>'
			. '<tr><th scope="row"><label for="f-search">Search</label></th><td><input class="sw-ui-input" id="f-search" type="search" data-sw-ui-search placeholder="Press / to search"></td></tr>'
			. '<tr><th scope="row" id="f-seg">Tool surface</th><td><div class="sw-ui-segmented" role="radiogroup" aria-labelledby="f-seg">'
			. '<label><input type="radio" name="surface" checked>Essential</label><label><input type="radio" name="surface">Full</label></div></td></tr>'
			. '</tbody></table>';

		return self::label( 'Settings form (native form-table rows)' ) . Card::render( 'Settings', $table );
	}

	private static function copy_and_code(): string {
		$code = '<div class="sw-ui-code"><div class="sw-ui-code__head"><span>Add the server in a terminal</span>'
			. '<button type="button" class="sw-ui-btn sw-ui-btn--xs" data-sw-ui-copy="#code-body"><span>Copy</span></button></div>'
			. '<pre class="sw-ui-code__body" id="code-body" tabindex="0" aria-label="Command: add the server"><code>example-client mcp add --transport http stonewright-example https://example.test/wp-json/mcp/stonewright-oauth</code></pre></div>';

		return self::label( 'Copy field and code block' ) . '<div class="sw-ui-stack">'
			. CopyField::render( 'https://example.test/wp-json/mcp/stonewright-oauth', [ 'label' => 'MCP server URL' ] )
			. CopyField::render( 'tok_example_0123456789', [ 'secret' => true, 'label' => 'Bridge token' ] )
			. $code . '</div>';
	}

	private static function badges(): string {
		return self::label( 'Badges, tags, counts and status' ) . Card::render(
			'State, facts and numbers',
			'<div class="sw-ui-actions">'
			. Badge::render( 'Active', [ 'variant' => 'ok', 'dot' => true ] ) . Badge::render( 'Draft', [ 'variant' => 'warn', 'dot' => true ] )
			. Badge::render( 'Crashed', [ 'variant' => 'danger', 'dot' => true ] ) . Badge::render( 'Built-in', [ 'variant' => 'info', 'dot' => true ] )
			. Badge::render( 'Disabled', [ 'dot' => true ] ) . Badge::render( 'New', [ 'variant' => 'accent' ] )
			. Badge::tag( 'Desktop app' ) . Badge::tag( 'Write' ) . Badge::count( 43 )
			. Badge::status( 'Active', 'ok' ) . Badge::status( 'Draft', 'warn' ) . Badge::status( 'Crashed', 'danger' ) . Badge::status( 'Idle' )
			. '</div>'
		);
	}

	private static function notices(): string {
		return self::label( 'Notices and callouts' ) . '<div class="sw-ui-stack">'
			. Notice::render( 'ok', 'Settings saved', 'Connected clients pick up the new tool surface on their next request.' )
			. Notice::render( 'danger', 'The MCP endpoint answered 403', 'Ask the host to allow POST requests to the MCP route, then run the checks again.', [ 'actions_html' => Button::render( 'Run again', [ 'size' => 'sm' ] ) . '<a class="sw-ui-link" href="#hosting">Copy hosting request</a>' ] )
			. Notice::render( 'warn', 'Domain lock mismatch', 'Review the locked origin before agents write again.' )
			. Notice::render( 'info', 'Companion not used', 'Remote HTTP only.' )
			. Notice::callout( 'warn', 'Human approval only', 'Agents must show you the proposal and stop. Approve only the exact candidate shown here.' )
			. '</div>';
	}

	private static function states(): string {
		$skeleton = '<div aria-busy="true"><span class="sw-ui-skeleton sw-ui-skeleton--title"></span><br><span class="sw-ui-skeleton sw-ui-skeleton--text"></span><br><span class="sw-ui-skeleton sw-ui-skeleton--text"></span><br><span class="sw-ui-skeleton sw-ui-skeleton--pill"></span><span class="sw-ui-visually-hidden" role="status">Loading</span></div>';

		return self::label( 'Empty states and loading' ) . '<div class="sw-ui-grid sw-ui-grid--2-1"><div class="sw-ui-stack">'
			. '<section class="sw-ui-card">' . EmptyState::render( 'Nothing to approve', 'When an agent proposes custom code it stops here for you. Ask it to run the custom-code tool with a dry run, then open the link it returns.', [ 'icon' => 'shield', 'actions_html' => Button::render( 'Open Custom code', [ 'href' => '#custom-code', 'variant' => 'primary' ] ) . '<a class="sw-ui-link" href="#how">How approvals work</a>' ] ) . '</section>'
			. '<section class="sw-ui-card">' . EmptyState::render( 'No abilities match "gutenberg-x"', 'Try fewer words, or clear the category filter.', [ 'variant' => 'no-results', 'actions_html' => Button::render( 'Clear filters', [ 'size' => 'sm' ] ) ] ) . '</section>'
			. '<section class="sw-ui-card">' . EmptyState::render( 'No activity yet', 'Every change an agent makes appears here, with secrets removed.', [ 'variant' => 'first-run' ] ) . '</section>'
			. '<section class="sw-ui-card">' . EmptyState::render( 'The checks could not run', 'Check the connection and try again.', [ 'variant' => 'error', 'actions_html' => Button::render( 'Retry', [ 'size' => 'sm' ] ) ] ) . '</section>'
			. '<section class="sw-ui-card">' . EmptyState::render( 'No sandbox files are active.', 'Nothing runs until you activate one.', [ 'variant' => 'inline' ] ) . '</section>'
			. '</div><div class="sw-ui-stack">' . Card::render( 'Loading', $skeleton ) . '</div></div>';
	}

	private static function tables(): string {
		$clients = Table::render(
			[
				[ 'key' => 'client', 'label' => 'Client', 'primary' => true ],
				[ 'key' => 'by', 'label' => 'Approved by', 'secondary' => true ],
				[ 'key' => 'used', 'label' => 'Last used' ],
				[ 'key' => 'action', 'label' => 'Action', 'actions' => true ],
			],
			[
				[ 'client' => [ 'text' => 'Example command-line client', 'meta' => 'Registered automatically, 1 active sign-in' ], 'by' => 'compat_admin', 'used' => [ 'html' => '<time datetime="2026-10-06T19:38:00Z">Oct 6, 10:38 pm</time>' ], 'action' => [ 'html' => Button::render( 'Disconnect', [ 'size' => 'sm', 'variant' => 'danger', 'context' => 'Example command-line client', 'attrs' => [ 'data-sw-ui-dialog-open' => '#confirm-dialog' ] ] ) ] ],
				[ 'client' => [ 'text' => 'Example editor client', 'meta' => 'Registered automatically, 2 active sign-ins' ], 'by' => 'compat_admin', 'used' => [ 'html' => '<time datetime="2026-10-06T19:38:00Z">Oct 6, 10:38 pm</time>' ], 'action' => [ 'html' => Button::render( 'Disconnect', [ 'size' => 'sm', 'variant' => 'danger', 'context' => 'Example editor client' ] ) ] ],
			],
			[ 'caption' => 'Connected OAuth clients' ]
		);
		$facts = KvList::render( [ [ 'label' => 'Application', 'value' => 'Example client' ], [ 'label' => 'Returns you to', 'value_html' => '<code>http://127.0.0.1:7999</code>' ], [ 'label' => 'Access', 'value' => 'Stonewright MCP tools' ] ] );

		return self::label( 'Table (stacks below 783px) and facts' ) . '<div class="sw-ui-stack">' . Card::render( 'Connected OAuth clients', $clients, [ 'flush' => true ] ) . Card::render( 'Consent facts', $facts ) . '</div>';
	}

	private static function disclosure_tabs_lineage(): string {
		$disclosure = '<details class="sw-ui-disclosure" open data-sw-ui-remember="sheet-cli"><summary>' . Icon::render( 'chev-r' ) . 'Example command-line client ' . Badge::tag( 'Command line' ) . '</summary><div class="sw-ui-disclosure__body">Run the add command, then sign in with <code>example-client mcp login</code>.</div></details>'
			. '<details class="sw-ui-disclosure" data-sw-ui-remember="sheet-web"><summary>' . Icon::render( 'chev-r' ) . 'Example web client ' . Badge::render( 'Cannot reach a local site', [ 'variant' => 'warn' ] ) . '</summary><div class="sw-ui-disclosure__body">Needs a public HTTPS address.</div></details>';
		$tabs = '<div class="sw-ui-tabs" role="tablist" aria-label="Skill views" data-sw-ui-tabs>'
			. '<button class="sw-ui-tabs__tab" role="tab" id="tab-catalog" aria-controls="panel-catalog" aria-selected="true" type="button">Catalog</button>'
			. '<button class="sw-ui-tabs__tab" role="tab" id="tab-editor" aria-controls="panel-editor" aria-selected="false" tabindex="-1" type="button">Editor</button>'
			. '<button class="sw-ui-tabs__tab" role="tab" id="tab-import" aria-controls="panel-import" aria-selected="false" tabindex="-1" type="button">Import</button></div>'
			. '<div class="sw-ui-tabs__panel" role="tabpanel" id="panel-catalog" aria-labelledby="tab-catalog">Catalog panel</div>'
			. '<div class="sw-ui-tabs__panel" role="tabpanel" id="panel-editor" aria-labelledby="tab-editor" hidden>Editor panel</div>'
			. '<div class="sw-ui-tabs__panel" role="tabpanel" id="panel-import" aria-labelledby="tab-import" hidden>Import panel</div>';
		$lineage = '<ol class="sw-ui-lineage" aria-label="Operations in this change set"><li><div class="sw-ui-lineage__node">' . Badge::render( 'OK', [ 'variant' => 'ok', 'icon' => 'check' ] ) . ' <strong>Queue block change</strong> <span class="sw-ui-field__help">21:09:12</span></div>'
			. '<ol><li><div class="sw-ui-lineage__node">' . Badge::render( 'OK', [ 'variant' => 'ok', 'icon' => 'check' ] ) . ' Serialize in editor <span class="sw-ui-field__help">21:09:40</span></div></li>'
			. '<li><div class="sw-ui-lineage__node">' . Badge::render( 'Error', [ 'variant' => 'danger', 'icon' => 'x' ] ) . ' Finalize batch <a class="sw-ui-link" href="#rescue">Rescue</a></div></li></ol></li></ol>';

		return self::label( 'Disclosure, tabs and lineage' ) . '<div class="sw-ui-stack">' . '<div>' . $disclosure . '</div>' . Card::render( 'Skill views', $tabs ) . Card::render( 'Change set 7f3a91c2', $lineage ) . '</div>';
	}

	private static function overlays(): string {
		$dialog = '<dialog class="sw-ui-dialog" id="confirm-dialog" aria-labelledby="confirm-title">'
			. '<div class="sw-ui-dialog__header"><h2 class="sw-ui-dialog__title" id="confirm-title">Disconnect Example client?</h2></div>'
			. '<div class="sw-ui-dialog__body">It loses access at once and has to sign in again. Saved content is not changed.</div>'
			. '<div class="sw-ui-dialog__footer">' . Button::render( 'Cancel', [ 'attrs' => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ] ] ) . Button::render( 'Disconnect', [ 'variant' => 'danger-solid', 'attrs' => [ 'data-sw-ui-dialog-close' => true ] ] ) . '</div></dialog>';
		$typed = '<dialog class="sw-ui-dialog" id="typed-dialog" aria-labelledby="typed-title">'
			. '<div class="sw-ui-dialog__header"><h2 class="sw-ui-dialog__title" id="typed-title">Delete all logs?</h2></div>'
			. '<div class="sw-ui-dialog__body"><p>This cannot be undone. Type <code>DELETE</code> to confirm.</p>'
			. '<div class="sw-ui-field"><label class="sw-ui-field__label" for="typed-input">Confirmation</label><input class="sw-ui-input" id="typed-input" data-sw-ui-confirm-phrase="DELETE" autocomplete="off"></div></div>'
			. '<div class="sw-ui-dialog__footer">' . Button::render( 'Cancel', [ 'attrs' => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ] ] ) . Button::render( 'Delete all logs', [ 'variant' => 'danger-solid', 'disabled' => true, 'attrs' => [ 'data-sw-ui-confirm-submit' => true ] ] ) . '</div></dialog>';
		$drawer = '<dialog class="sw-ui-dialog sw-ui-drawer" id="detail-drawer" aria-labelledby="drawer-title" data-sw-ui-light-dismiss>'
			. '<div class="sw-ui-dialog__header"><h2 class="sw-ui-dialog__title" id="drawer-title">Change set 7f3a91c2</h2></div>'
			. '<div class="sw-ui-dialog__body">Details open in a drawer, not inline.</div>'
			. '<div class="sw-ui-dialog__footer">' . Button::render( 'Close', [ 'attrs' => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ] ] ) . '</div></dialog>';

		return self::label( 'Dialogs, drawer and toasts' ) . Card::render(
			'Open an overlay',
			Button::group(
				[
					Button::render( 'Disconnect a client', [ 'variant' => 'danger', 'attrs' => [ 'data-sw-ui-dialog-open' => '#confirm-dialog', 'id' => 'open-confirm' ] ] ),
					Button::render( 'Delete all logs', [ 'variant' => 'danger', 'attrs' => [ 'data-sw-ui-dialog-open' => '#typed-dialog', 'id' => 'open-typed' ] ] ),
					Button::render( 'Open a drawer', [ 'attrs' => [ 'data-sw-ui-dialog-open' => '#detail-drawer', 'id' => 'open-drawer' ] ] ),
				]
			) . $dialog . $typed . $drawer
		);
	}
}
