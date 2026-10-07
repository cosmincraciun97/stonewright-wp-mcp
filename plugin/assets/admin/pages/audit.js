/**
 * Audit log page: the row drawer and the busy state of the filter button.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Enhances server-rendered markup. The Details buttons start hidden and appear once the native dialog is available;
 * the layer's script (sw-ui.js) opens the drawer, keeps focus inside it and gives focus back to the button. This
 * script only chooses which panel the drawer shows and sets its title, with attributes and text, never markup.
 * The delete and dismiss confirmations are layer dialogs that need no code here.
 */
( function () {
	'use strict';

	function initDrawer() {
		var drawer = document.querySelector( '[data-sw-audit-drawer]' );
		if ( ! drawer || typeof drawer.showModal !== 'function' ) {
			return;
		}
		var title = drawer.querySelector( '.sw-ui-dialog__title' );
		var panels = drawer.querySelectorAll( '[data-sw-audit-panel]' );

		document.querySelectorAll( '[data-sw-audit-open]' ).forEach( function ( button ) {
			button.hidden = false;
			// This runs on the button itself, before the layer's document handler opens the dialog.
			button.addEventListener( 'click', function () {
				var id = button.getAttribute( 'data-sw-audit-open' );
				panels.forEach( function ( panel ) {
					panel.hidden = panel.id !== id;
				} );
				if ( title ) {
					title.textContent = button.getAttribute( 'data-sw-audit-title' ) || title.textContent;
				}
			} );
		} );
	}

	function initFilterBusy() {
		var form = document.querySelector( '.sw-audit-filters' );
		var button = form ? form.querySelector( '[data-sw-audit-filter]' ) : null;
		if ( ! form || ! button ) {
			return;
		}
		form.addEventListener( 'submit', function () {
			button.setAttribute( 'aria-busy', 'true' );
		} );
	}

	function init() {
		initDrawer();
		initFilterBusy();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
