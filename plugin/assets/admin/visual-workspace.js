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

	function initEditorConnection() {
		var button = document.querySelector( '[data-sw-visual-connect]' );
		var status = document.querySelector( '[data-sw-visual-status]' );

		if ( ! button ) {
			return;
		}

		function say( message, tone ) {
			if ( status ) {
				status.textContent = message;
				status.setAttribute( 'data-tone', tone || '' );
			}
		}

		function blockEditorReady( editorWindow ) {
			try {
				if ( ! editorWindow.wp || ! editorWindow.wp.blocks || ! editorWindow.wp.data ) {
					return false;
				}
				if ( 'function' !== typeof editorWindow.wp.blocks.getBlockTypes || 'function' !== typeof editorWindow.wp.data.select ) {
					return false;
				}
				var blocks = editorWindow.wp.data.select( 'core/block-editor' );
				var editor = editorWindow.wp.data.select( 'core/editor' );

				return !! ( blocks && 'function' === typeof blocks.getBlocks && editor && 'function' === typeof editor.getCurrentPostId );
			} catch ( error ) {
				return false;
			}
		}

		button.addEventListener( 'click', function () {
			var editorUrl = button.getAttribute( 'data-editor-url' );
			if ( ! editorUrl ) {
				say( 'No editor URL is available for this post.', 'error' );
				return;
			}

			var editorWindow = window.open( editorUrl, 'stonewright-editor-' + ( window.stonewrightVisualWorkspace && window.stonewrightVisualWorkspace.postId || 'post' ) );
			if ( ! editorWindow ) {
				say( 'The editor window was blocked. Allow pop-ups for this site and try again.', 'error' );
				return;
			}

			button.disabled = true;
			button.textContent = 'Connecting…';
			say( 'Waiting for the editor runtime. Keep the new window open.', '' );

			var attempts = 0;
			var timer = window.setInterval( function () {
				attempts += 1;
				if ( editorWindow.closed ) {
					window.clearInterval( timer );
					button.disabled = false;
					button.textContent = 'Connect editor';
					say( 'The editor window was closed before it connected.', 'error' );
					return;
				}

				var canConnect = 'function' === typeof window.stonewrightVisualConnect;
				var hasElementor = false;
				var hasBlocks = false;
				try {
					hasElementor = !! ( editorWindow.elementor && editorWindow.$e );
					hasBlocks = blockEditorReady( editorWindow );
				} catch ( error ) {
					// Same-origin navigation is still settling. The next bounded
					// poll retries without treating that transient as failure.
				}
				if ( canConnect && ( hasElementor || hasBlocks ) ) {
					window.clearInterval( timer );
					Promise.resolve( window.stonewrightVisualConnect( editorWindow ) ).then( function ( controller ) {
						button.disabled = false;
						button.textContent = 'Reconnect editor';
						if ( controller && 'failed' !== controller.getState() ) {
							say( 'Editor connected. Read-only inspection is ready.', 'success' );
						} else {
							say( 'The editor opened, but no supported adapter matched its runtime.', 'error' );
						}
					} ).catch( function ( error ) {
						button.disabled = false;
						button.textContent = 'Try again';
						say( error && error.message ? error.message : 'The editor could not be connected.', 'error' );
					} );
					return;
				}

				if ( attempts >= 120 ) {
					window.clearInterval( timer );
					button.disabled = false;
					button.textContent = 'Try again';
					say( 'The editor did not become ready within 60 seconds.', 'error' );
				}
			}, 500 );
		} );
	}

	ready( function () {
		initInspectorDrawer();
		initEditorConnection();
	} );
}() );
