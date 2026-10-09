/**
 * Audit Log change-set lineage drawer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Enhances server-rendered markup. The drawer is the layer's drawer: sw-ui.js opens it from the Lineage button,
 * keeps focus inside it, closes it on Escape, the Close button or a click outside, and gives focus back to the
 * button that opened it. The Lineage buttons start hidden and appear once the native dialog is available. This
 * script only chooses the panel the drawer shows, marks the reader's own change set, and lists the nodes that wait
 * behind "Show more". It toggles attributes and text, makes no request and builds no markup.
 */
( function () {
	'use strict';

	function init() {
		var drawer = document.querySelector( '[data-sw-lineage-drawer]' );
		if ( ! drawer || typeof drawer.showModal !== 'function' ) {
			return;
		}

		var title = drawer.querySelector( '.sw-ui-dialog__title' );
		var panels = drawer.querySelectorAll( '[data-sw-lineage-panel]' );

		function reveal( list ) {
			var button = drawer.querySelector( '[data-sw-lineage-more="' + list.id + '"]' );
			list.hidden = false;
			if ( button ) {
				button.setAttribute( 'aria-expanded', 'true' );
				button.parentNode.hidden = true;
			}
		}

		function markCurrent( panel, id ) {
			var current = null;
			panel.querySelectorAll( '[data-sw-lineage-node]' ).forEach( function ( node ) {
				var label = node.querySelector( '[data-sw-lineage-current]' );
				var isCurrent = node.getAttribute( 'data-sw-lineage-node' ) === id;
				if ( isCurrent ) {
					node.setAttribute( 'aria-current', 'true' );
					current = node;
				} else {
					node.removeAttribute( 'aria-current' );
				}
				if ( label ) {
					label.hidden = ! isCurrent;
				}
			} );
			if ( ! current ) {
				return null;
			}

			var waiting = current.closest( 'ol[hidden]' );
			if ( waiting ) {
				reveal( waiting );
			}
			var branch = current.parentElement ? current.parentElement.closest( 'details' ) : null;
			while ( branch ) {
				branch.open = true;
				branch = branch.parentElement ? branch.parentElement.closest( 'details' ) : null;
			}
			return current;
		}

		function prepare( button ) {
			var panel = document.getElementById( button.getAttribute( 'data-sw-lineage-open' ) );
			if ( ! panel ) {
				return;
			}
			panels.forEach( function ( item ) {
				item.hidden = item !== panel;
			} );
			if ( title && button.getAttribute( 'data-sw-lineage-title' ) ) {
				title.textContent = button.getAttribute( 'data-sw-lineage-title' );
			}
			var current = markCurrent( panel, button.getAttribute( 'data-sw-lineage-for' ) );
			if ( current ) {
				// The layer opens the dialog right after this handler; the node can only scroll once it shows.
				window.setTimeout( function () {
					current.scrollIntoView( { block: 'nearest' } );
				}, 0 );
			}
		}

		document.querySelectorAll( '[data-sw-lineage-open]' ).forEach( function ( button ) {
			button.hidden = false;
			button.addEventListener( 'click', function () {
				prepare( button );
			} );
		} );

		drawer.addEventListener( 'click', function ( event ) {
			var more = event.target.closest ? event.target.closest( '[data-sw-lineage-more]' ) : null;
			var list = more ? document.getElementById( more.getAttribute( 'data-sw-lineage-more' ) ) : null;
			if ( list ) {
				reveal( list );
				var first = list.querySelector( 'a' );
				if ( first ) {
					first.focus();
				}
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
