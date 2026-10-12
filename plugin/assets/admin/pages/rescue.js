/**
 * Stonewright > Rescue. Adds a confirmation dialog, copy buttons, a typed-phrase gate and a busy state to markup that
 * already works without it: no request is made from here and nothing polls.
 *
 * Roll back is a link to a review at its own address, which changes nothing and holds the form that does. Here the link
 * opens the same review as a dialog; without this script, or in a browser with no showModal, the link is followed. The
 * server checks the confirmation either way (the token, the typed phrase, the state of the item).
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

	/**
	 * The text node of a button's visible label. A button can carry more after it (the words that name what it acts on,
	 * for assistive technology), so a label is changed here and never by replacing the text of the whole button.
	 */
	function labelOf( button ) {
		var node = button.firstChild;
		while ( node && node.nodeType !== 3 ) {
			node = node.nextSibling;
		}
		if ( ! node ) {
			node = document.createTextNode( '' );
			button.insertBefore( node, button.firstChild );
		}
		return node;
	}

	/** The copy buttons are in the page hidden; they only make sense with a script. */
	function initCopy( root, live ) {
		root.querySelectorAll( '[data-sw-rescue-copy], [data-sw-rescue-copy-text]' ).forEach( function ( button ) {
			var source = button.getAttribute( 'data-sw-rescue-copy' );
			var box = source ? document.getElementById( source ) : null;
			var text = button.getAttribute( 'data-sw-rescue-copy-text' );
			var label = labelOf( button );
			var idle = label.nodeValue;
			var timer = null;
			if ( ! box && null === text ) {
				return;
			}
			button.hidden = false;
			button.addEventListener( 'click', function () {
				copyText( box ? box.value : text, button ).then( function ( copied ) {
					label.nodeValue = copied ? 'Copied' : 'Copy failed';
					announce( live, copied ? 'Copied to the clipboard.' : 'Copying failed. Select the text and press Ctrl+C.' );
					window.clearTimeout( timer );
					timer = window.setTimeout( function () {
						label.nodeValue = idle;
					}, 2500 );
				} );
			} );
		} );
	}

	/**
	 * A typed phrase holds the confirm button back when the page asks for one. The server prints the button enabled, so
	 * the form works without this script, and the server compares the phrase whatever the button says.
	 */
	function initPhrases( root ) {
		root.querySelectorAll( '[data-sw-rescue-phrase]' ).forEach( function ( phrase ) {
			var form = phrase.closest( 'form' );
			var submit = form ? form.querySelector( '[data-sw-rescue-submit]' ) : null;

			function sync() {
				if ( submit ) {
					submit.disabled = phrase.value.trim() !== phrase.getAttribute( 'data-sw-rescue-phrase' );
				}
			}

			sync();
			phrase.addEventListener( 'input', sync );
			if ( form ) {
				form.addEventListener( 'reset', function () {
					window.setTimeout( sync, 0 );
				} );
			}
		} );
	}

	/**
	 * The Roll back link shows the dialog instead of going to the review. Cancel has the first focus and closes the
	 * dialog in place, and focus goes back to the link when the dialog closes.
	 */
	function initDialogs( root ) {
		root.querySelectorAll( '[data-sw-rescue-open]' ).forEach( function ( opener ) {
			var dialog = document.getElementById( opener.getAttribute( 'data-sw-rescue-open' ) || '' );
			var cancel;
			var phrase;

			// Without dialog support the link goes to the review, which is the same confirmation as a page.
			if ( ! dialog || typeof dialog.showModal !== 'function' ) {
				return;
			}
			cancel = dialog.querySelector( '[data-sw-rescue-cancel]' );
			phrase = dialog.querySelector( '[data-sw-rescue-phrase]' );

			opener.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( phrase ) {
					phrase.value = '';
					phrase.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				}
				dialog.showModal();
				if ( cancel ) {
					cancel.focus();
				}
			} );
			if ( cancel ) {
				cancel.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					dialog.close();
				} );
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
			var face = submit ? labelOf( submit ) : null;
			var idle = face ? face.nodeValue : '';
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
				face.nodeValue = label;
				announce( status || live, label );
			} );

			// Coming back through the browser history must not leave the form stuck.
			window.addEventListener( 'pageshow', function () {
				busy = false;
				form.removeAttribute( 'aria-busy' );
				submit.removeAttribute( 'aria-disabled' );
				face.nodeValue = idle;
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
		initPhrases( root );
		initDialogs( root );
		initForms( root, live );
	} );
}() );
