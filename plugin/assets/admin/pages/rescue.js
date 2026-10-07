/**
 * Stonewright > Rescue. Adds a confirmation dialog, copy buttons and a busy state to forms
 * that already work without it: no request is made from here and nothing polls.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	/** Say something to assistive technology without moving focus. */
	function announce( target, message ) {
		if ( ! target ) {
			return;
		}
		target.textContent = '';
		window.setTimeout( function () {
			target.textContent = message;
		}, 50 );
	}

	/** Copy through a temporary selection where the Clipboard API is not available. */
	function legacyCopy( value, anchor ) {
		var area = document.createElement( 'textarea' );
		var copied = false;
		area.value = value;
		area.setAttribute( 'readonly', '' );
		area.className = 'screen-reader-text';
		document.body.appendChild( area );
		area.select();
		try {
			copied = document.execCommand( 'copy' );
		} catch ( error ) {
			copied = false;
		}
		document.body.removeChild( area );
		if ( anchor ) {
			anchor.focus();
		}
		return copied;
	}

	function copyText( value, anchor ) {
		if ( navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext ) {
			return navigator.clipboard.writeText( value ).then(
				function () {
					return true;
				},
				function () {
					return legacyCopy( value, anchor );
				}
			);
		}
		return Promise.resolve( legacyCopy( value, anchor ) );
	}

	/** The copy buttons are in the page hidden; they only make sense with a script. */
	function initCopy( root, live ) {
		root.querySelectorAll( '[data-sw-rescue-copy], [data-sw-rescue-copy-text]' ).forEach( function ( button ) {
			var source = button.getAttribute( 'data-sw-rescue-copy' );
			var box = source ? document.getElementById( source ) : null;
			var text = button.getAttribute( 'data-sw-rescue-copy-text' );
			var idle = button.textContent;
			var timer = null;
			if ( ! box && null === text ) {
				return;
			}
			button.hidden = false;
			button.addEventListener( 'click', function () {
				copyText( box ? box.value : text, button ).then( function ( copied ) {
					button.textContent = copied ? 'Copied' : 'Copy failed';
					announce( live, copied ? 'Copied to the clipboard.' : 'Copying failed. Select the text and press Ctrl+C.' );
					window.clearTimeout( timer );
					timer = window.setTimeout( function () {
						button.textContent = idle;
					}, 2500 );
				} );
			} );
		} );
	}

	/**
	 * The row button shows the dialog instead of posting. Cancel has the first focus, a typed
	 * phrase gates the confirm button when the page asks for one, and focus goes back to the
	 * row button when the dialog closes.
	 */
	function initDialogs( root ) {
		root.querySelectorAll( '[data-sw-rescue-open]' ).forEach( function ( opener ) {
			var dialog = document.getElementById( opener.getAttribute( 'data-sw-rescue-open' ) || '' );
			var cancel;
			var submit;
			var phrase;

			// Without dialog support the button posts the form directly.
			if ( ! dialog || typeof dialog.showModal !== 'function' ) {
				return;
			}
			cancel = dialog.querySelector( '[data-sw-rescue-cancel]' );
			submit = dialog.querySelector( '[data-sw-rescue-submit]' );
			phrase = dialog.querySelector( '[data-sw-rescue-phrase]' );

			function sync() {
				if ( phrase && submit ) {
					submit.disabled = phrase.value.trim() !== phrase.getAttribute( 'data-sw-rescue-phrase' );
				}
			}

			opener.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( phrase ) {
					phrase.value = '';
				}
				sync();
				dialog.showModal();
				if ( cancel ) {
					cancel.focus();
				}
			} );
			if ( cancel ) {
				cancel.addEventListener( 'click', function () {
					dialog.close();
				} );
			}
			if ( phrase ) {
				phrase.addEventListener( 'input', sync );
			}
			dialog.addEventListener( 'close', function () {
				opener.focus();
			} );
			window.addEventListener( 'pageshow', function ( event ) {
				if ( event.persisted && dialog.open ) {
					dialog.close();
				}
			} );
		} );
	}

	/** One request at a time, and a label that says the site is being checked. */
	function initForms( root, live ) {
		root.querySelectorAll( '[data-sw-rescue-form]' ).forEach( function ( form ) {
			var submit = form.querySelector( '[data-sw-rescue-submit]' );
			var status = form.querySelector( '[data-sw-rescue-status]' );
			var idle = submit ? submit.textContent : '';
			var busy = false;
			if ( ! submit ) {
				return;
			}

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
				announce( status || live, label );
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
		} );
	}

	ready( function () {
		var root = document.querySelector( '[data-sw-rescue]' );
		var live;
		if ( ! root ) {
			return;
		}
		live = root.querySelector( '[data-sw-rescue-live]' );
		initCopy( root, live );
		initDialogs( root );
		initForms( root, live );
	} );
}() );
