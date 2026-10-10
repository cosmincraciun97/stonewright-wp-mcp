/**
 * Changes page: the diff drawer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Enhances server-rendered markup. When the address names a change, the server prints the drawer already open, so
 * it reads without script. Here the open dialog becomes the layer's modal drawer: sw-ui.js opens it, keeps focus
 * inside it, closes it on Escape, the Close control or a click outside, and gives focus back to the row's "View
 * diff" link. This script makes no request and builds no markup; it only opens the dialog and, when the drawer
 * closes, takes the change out of the address so a reload shows the list.
 */
( function () {
	'use strict';

	function openerFor( id ) {
		var links = document.querySelectorAll( '[data-sw-changes-open]' );
		for ( var index = 0; index < links.length; index++ ) {
			if ( links[ index ].getAttribute( 'data-sw-changes-open' ) === id ) {
				return links[ index ];
			}
		}
		return document.getElementById( 'sw-main' );
	}

	function leaveAddress() {
		if ( ! window.history || typeof window.history.replaceState !== 'function' || typeof URL !== 'function' ) {
			return;
		}
		var url = new URL( window.location.href );
		url.searchParams.delete( 'change' );
		url.searchParams.delete( 'view' );
		window.history.replaceState( null, '', url.toString() );
	}

	function init() {
		var drawer = document.querySelector( '[data-sw-changes-drawer]' );
		if ( ! drawer || typeof drawer.showModal !== 'function' ) {
			return;
		}
		var layer = window.Stonewright && window.Stonewright.ui;
		var opener = openerFor( drawer.getAttribute( 'data-sw-changes-id' ) );

		drawer.addEventListener( 'close', leaveAddress );
		// A dialog that is already open cannot be shown as a modal.
		drawer.removeAttribute( 'open' );
		if ( layer && typeof layer.openDialog === 'function' ) {
			layer.openDialog( drawer, opener );
		} else {
			drawer.showModal();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
