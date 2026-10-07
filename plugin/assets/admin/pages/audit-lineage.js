/**
 * Audit Log change-set lineage drawer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Enhances server-rendered markup. The Lineage buttons start hidden and appear once
 * this script can open the native modal dialog. Escape, the Close button and a click
 * outside the sheet all end in the dialog's close event, which gives focus back to the
 * button that opened it. The script only toggles attributes and text; the panels are
 * rendered by the server and it makes no request.
 */
( function () {
	'use strict';

	function init() {
		var drawer = document.querySelector( '[data-sw-lineage-drawer]' );
		if ( ! drawer || typeof drawer.showModal !== 'function' ) {
			return;
		}

		var title = drawer.querySelector( 'h2' );
		var panels = drawer.querySelectorAll( '[data-sw-lineage-panel]' );
		var opener = null;

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

		function open( button ) {
			var panel = document.getElementById( button.getAttribute( 'data-sw-lineage-open' ) );
			if ( ! panel || drawer.open ) {
				return;
			}

			opener = button;
			panels.forEach( function ( item ) {
				item.hidden = item !== panel;
			} );
			if ( title && button.getAttribute( 'data-sw-lineage-title' ) ) {
				title.textContent = button.getAttribute( 'data-sw-lineage-title' );
			}
			var current = markCurrent( panel, button.getAttribute( 'data-sw-lineage-for' ) );
			drawer.showModal();
			if ( title ) {
				title.focus();
			}
			if ( current ) {
				current.scrollIntoView( { block: 'nearest' } );
			}
		}

		document.querySelectorAll( '[data-sw-lineage-open]' ).forEach( function ( button ) {
			button.hidden = false;
			button.addEventListener( 'click', function () {
				open( button );
			} );
		} );

		drawer.addEventListener( 'click', function ( event ) {
			var target = event.target;
			if ( target === drawer || target.closest( '[data-sw-lineage-close]' ) ) {
				drawer.close();
				return;
			}
			var more = target.closest( '[data-sw-lineage-more]' );
			var list = more ? document.getElementById( more.getAttribute( 'data-sw-lineage-more' ) ) : null;
			if ( list ) {
				reveal( list );
				var first = list.querySelector( 'a' );
				if ( first ) {
					first.focus();
				}
			}
		} );

		drawer.addEventListener( 'close', function () {
			if ( opener && document.contains( opener ) ) {
				opener.focus();
			}
			opener = null;
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
