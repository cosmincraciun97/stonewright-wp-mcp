/**
 * Visual Workspace page chrome.
 *
 * The browser bundle owns the workspace itself: adapter resolution, proposed
 * operations, confirmation, and verification. This file owns only the parts of
 * the page that must keep working when the bundle is absent — the inspector
 * drawer on narrow viewports, its keyboard contract, and focus restoration.
 */
( function () {
	'use strict';

	var NARROW = '(max-width: 1024px)';

	function ready( fn ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fn, { once: true } );
		} else {
			fn();
		}
	}

	function initInspectorDrawer() {
		var toggle = document.querySelector( '[data-sw-visual-inspector-toggle]' );
		var inspector = document.querySelector( '[data-sw-visual-inspector]' );

		if ( ! toggle || ! inspector ) {
			return;
		}

		var narrow = window.matchMedia ? window.matchMedia( NARROW ) : null;
		var lastFocus = null;

		function isDrawer() {
			return null !== narrow && narrow.matches;
		}

		function setOpen( open ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			inspector.classList.toggle( 'is-open', open );

			if ( isDrawer() ) {
				inspector.setAttribute( 'role', 'dialog' );
				inspector.setAttribute( 'aria-modal', open ? 'true' : 'false' );
				if ( open ) {
					inspector.removeAttribute( 'hidden' );
				} else {
					inspector.setAttribute( 'hidden', '' );
				}
			} else {
				inspector.removeAttribute( 'role' );
				inspector.removeAttribute( 'aria-modal' );
				inspector.removeAttribute( 'hidden' );
			}
		}

		function open() {
			lastFocus = document.activeElement;
			setOpen( true );
			inspector.setAttribute( 'tabindex', '-1' );
			inspector.focus();
		}

		function close() {
			setOpen( false );
			if ( lastFocus && 'function' === typeof lastFocus.focus ) {
				lastFocus.focus();
			} else {
				toggle.focus();
			}
			lastFocus = null;
		}

		function isOpen() {
			return 'true' === toggle.getAttribute( 'aria-expanded' );
		}

		toggle.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( isOpen() ) {
				close();
			} else {
				open();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && isOpen() && isDrawer() ) {
				event.preventDefault();
				close();
			}
		} );

		function syncToViewport() {
			// On a wide viewport the inspector is a permanent column: no drawer
			// semantics, always visible, and the toggle only reports state.
			if ( isDrawer() ) {
				setOpen( isOpen() );
			} else {
				setOpen( true );
			}
		}

		if ( narrow ) {
			if ( 'function' === typeof narrow.addEventListener ) {
				narrow.addEventListener( 'change', syncToViewport );
			} else if ( 'function' === typeof narrow.addListener ) {
				narrow.addListener( syncToViewport );
			}
		}

		if ( isDrawer() ) {
			setOpen( false );
		} else {
			setOpen( true );
		}
	}

	ready( initInspectorDrawer );
}() );
