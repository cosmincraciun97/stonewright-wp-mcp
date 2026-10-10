/**
 * Changes page: the diff drawer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Enhances server-rendered markup. When the address names a change, the server prints the drawer already open, so
 * it reads without script. Here the open dialog becomes the layer's modal drawer: sw-ui.js opens it, keeps focus
 * inside it, closes it on Escape, the Close control or a click outside, and gives focus back to the row's "View
 * diff" link. This script makes no request and builds no markup; it only opens the dialogs and, when the drawer
 * closes, takes the change out of the address so a reload shows the list. The Undo dialog is part of the page; its
 * form posts to admin-post.php without script too.
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

	/** Take arguments out of the address without a reload, so a reload shows what is left. */
	function dropParams( names ) {
		if ( ! window.history || typeof window.history.replaceState !== 'function' || typeof URL !== 'function' ) {
			return;
		}
		var url = new URL( window.location.href );
		names.forEach( function ( name ) {
			url.searchParams.delete( name );
		} );
		window.history.replaceState( null, '', url.toString() );
	}

	function leaveAddress() {
		dropParams( [ 'change', 'view', 'undo', 'undone', 'from' ] );
	}

	function leaveUndoAddress() {
		dropParams( [ 'undo' ] );
	}

	function openDialog( layer, dialog, opener ) {
		if ( layer && typeof layer.openDialog === 'function' ) {
			layer.openDialog( dialog, opener );
		} else {
			dialog.showModal();
		}
	}

	/**
	 * The Undo dialog is printed in the page. The Undo link opens it through the layer (which traps focus, closes it on
	 * Escape and gives focus back to the link); the address `undo=1` prints it open for a browser without script, and
	 * here it becomes a modal over the drawer. Cancel has the first focus. The form posts as it is; once it is sent
	 * the button says what is happening and cannot be pressed twice.
	 */
	function initUndo( layer ) {
		var dialog = document.getElementById( 'sw-changes-undo-dialog' );
		var form;
		var submit;
		var status;
		var idle;
		var busy = false;

		if ( ! dialog || typeof dialog.showModal !== 'function' ) {
			return;
		}
		form = dialog.querySelector( '[data-sw-changes-undo-form]' );
		submit = dialog.querySelector( '[data-sw-changes-submit]' );
		status = dialog.querySelector( '[data-sw-changes-status]' );
		idle = submit ? submit.textContent : '';

		dialog.addEventListener( 'close', leaveUndoAddress );
		if ( form && submit ) {
			form.addEventListener( 'submit', function ( event ) {
				var label;
				if ( busy ) {
					event.preventDefault();
					return;
				}
				busy = true;
				label = submit.getAttribute( 'data-sw-busy-label' ) || idle;
				form.setAttribute( 'aria-busy', 'true' );
				submit.setAttribute( 'aria-disabled', 'true' );
				submit.textContent = label;
				if ( status ) {
					status.textContent = label;
				}
			} );
			// Coming back through the browser history must not leave the form stuck.
			window.addEventListener( 'pageshow', function () {
				busy = false;
				form.removeAttribute( 'aria-busy' );
				submit.removeAttribute( 'aria-disabled' );
				submit.textContent = idle;
				if ( status ) {
					status.textContent = '';
				}
			} );
		}
		if ( dialog.hasAttribute( 'open' ) ) {
			// A dialog that is already open cannot be shown as a modal.
			dialog.removeAttribute( 'open' );
			openDialog( layer, dialog, document.querySelector( '[data-sw-ui-dialog-open="#sw-changes-undo-dialog"]' ) );
		}
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
		openDialog( layer, drawer, opener );
		initUndo( layer );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
