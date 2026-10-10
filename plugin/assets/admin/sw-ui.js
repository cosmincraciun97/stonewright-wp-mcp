// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Stonewright shared admin UI: the behaviour of the components in sw-ui.css.
 *
 * Everything here reacts to `data-sw-ui-*` attributes, which only markup of the shared layer carries, so a page
 * that has not adopted the layer is never touched. The public API lives on `window.Stonewright.ui`.
 *
 * Hooks
 *   [data-sw-ui-copy="#id"]            copy the text or value of #id (or data-sw-ui-copy-text) and say so
 *   [data-sw-ui-reveal="#id"]          show or hide a masked value (aria-pressed)
 *   input[data-sw-ui-live-fill="#region"] with data-sw-ui-live-fill-from="#template": while the checkbox is checked
 *                                      the region holds a copy of the template's content, otherwise it is empty
 *                                      (give the region aria-live so the change is announced)
 *   [data-sw-ui-tabs]                  an ARIA tab list: roving tabindex, Arrow, Home and End keys
 *   details[data-sw-ui-remember="k"]   remember open or closed per browser
 *   [data-sw-ui-dialog-open="#id"]     open a <dialog class="sw-ui-dialog">; [data-sw-ui-dialog-close] closes it
 *   [data-sw-ui-confirm-phrase]        an input that enables [data-sw-ui-confirm-submit] when it holds the phrase
 *   [data-sw-ui-search]                the field the "/" key focuses
 *   input[data-sw-ui-filter="#id"]     filter the [data-sw-ui-filter-item]s inside #id as you type (see the List filter block)
 *   [data-sw-ui-tip="text"]            show "text" in a tooltip above the element on hover and focus (see the Band tooltip block)
 */
( function () {
	'use strict';

	var root = ( window.Stonewright = window.Stonewright || {} );
	if ( root.ui ) {
		return;
	}

	var reducedMotion = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;

	/** False when the user asks for reduced motion; the query is live, so this always reads the current setting. */
	function motionOK() {
		return ! ( reducedMotion && reducedMotion.matches );
	}

	function scrollToElement( element ) {
		if ( element && element.scrollIntoView ) {
			element.scrollIntoView( { behavior: motionOK() ? 'smooth' : 'auto', block: 'start' } );
		}
	}

	/** Milliseconds of a --sw-dur* token as it computes on an element (0 under reduced motion), or a fallback. */
	function tokenMs( element, name, fallback ) {
		if ( ! element || ! window.getComputedStyle ) {
			return fallback;
		}
		var raw = window.getComputedStyle( element ).getPropertyValue( name ).trim();
		var value = parseFloat( raw );
		if ( isNaN( value ) ) {
			return fallback;
		}
		return /ms$/.test( raw ) ? value : /s$/.test( raw ) ? value * 1000 : fallback;
	}

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	// -----------------------------------------------------------------------------------------------
	// Portal: one `.sw-ui` element on the body holds the live regions and the toasts, so they are in
	// scope of the layer wherever the page puts its own wrapper.
	// -----------------------------------------------------------------------------------------------

	var portal = null;

	function ensurePortal() {
		if ( portal && document.body.contains( portal ) ) {
			return portal;
		}
		portal = el( 'div', 'sw-ui sw-ui-portal' );
		portal.setAttribute( 'data-sw-ui-portal', '' );

		var polite = el( 'div', 'sw-ui-visually-hidden' );
		polite.setAttribute( 'role', 'status' );
		polite.setAttribute( 'aria-live', 'polite' );
		polite.setAttribute( 'data-sw-ui-live', 'polite' );

		var assertive = el( 'div', 'sw-ui-visually-hidden' );
		assertive.setAttribute( 'role', 'alert' );
		assertive.setAttribute( 'data-sw-ui-live', 'assertive' );

		var region = el( 'div', 'sw-ui-toast-region' );
		region.setAttribute( 'aria-live', 'polite' );
		region.setAttribute( 'data-sw-ui-toasts', '' );

		portal.appendChild( polite );
		portal.appendChild( assertive );
		portal.appendChild( region );
		document.body.appendChild( portal );
		return portal;
	}

	/** Say something to assistive technology without showing it. */
	function announce( message, politeness ) {
		var region = ensurePortal().querySelector( '[data-sw-ui-live="' + ( politeness === 'assertive' ? 'assertive' : 'polite' ) + '"]' );
		if ( ! region ) {
			return;
		}
		// Clearing first makes a repeated message announce again.
		region.textContent = '';
		window.setTimeout( function () {
			region.textContent = String( message || '' );
		}, 40 );
	}

	// -----------------------------------------------------------------------------------------------
	// Toasts: ephemeral confirmation, at most three, paused while pointed at or focused.
	// -----------------------------------------------------------------------------------------------

	var MAX_TOASTS = 3;

	function dismissToast( toast ) {
		if ( ! toast || ! toast.parentNode ) {
			return;
		}
		window.clearTimeout( toast.swUiTimer );
		var wait = motionOK() ? tokenMs( toast, '--sw-dur-exit', 120 ) : 0;
		toast.setAttribute( 'data-state', 'leaving' );
		window.setTimeout( function () {
			if ( toast.parentNode ) {
				toast.parentNode.removeChild( toast );
			}
		}, wait );
	}

	/**
	 * Show a toast. options: { action: { label, onClick }, duration: ms, politeness }.
	 * It lasts 5 seconds, or 8 when it carries an action, and is announced through the live region of the portal.
	 */
	function toast( message, options ) {
		options = options || {};
		var region = ensurePortal().querySelector( '[data-sw-ui-toasts]' );
		while ( region.children.length >= MAX_TOASTS ) {
			region.removeChild( region.firstChild );
		}

		var item = el( 'div', 'sw-ui-toast' );
		item.appendChild( el( 'span', 'sw-ui-toast__text', String( message || '' ) ) );

		var duration = typeof options.duration === 'number' ? options.duration : ( options.action ? 8000 : 5000 );
		if ( options.action && options.action.label ) {
			var button = el( 'button', 'sw-ui-btn sw-ui-btn--xs', options.action.label );
			button.type = 'button';
			button.addEventListener( 'click', function () {
				if ( typeof options.action.onClick === 'function' ) {
					options.action.onClick();
				}
				dismissToast( item );
			} );
			item.appendChild( button );
		}

		function arm() {
			window.clearTimeout( item.swUiTimer );
			item.swUiTimer = window.setTimeout( function () {
				dismissToast( item );
			}, duration );
		}
		item.addEventListener( 'mouseenter', function () {
			window.clearTimeout( item.swUiTimer );
		} );
		item.addEventListener( 'focusin', function () {
			window.clearTimeout( item.swUiTimer );
		} );
		item.addEventListener( 'mouseleave', arm );
		item.addEventListener( 'focusout', arm );

		region.appendChild( item );
		arm();
		return item;
	}

	// -----------------------------------------------------------------------------------------------
	// Copy
	// -----------------------------------------------------------------------------------------------

	function targetOf( selector ) {
		if ( ! selector ) {
			return null;
		}
		try {
			return document.querySelector( selector );
		} catch ( error ) {
			return null;
		}
	}

	function textOf( node ) {
		if ( ! node ) {
			return '';
		}
		return 'value' in node ? String( node.value || '' ) : String( node.textContent || '' );
	}

	function copyViaTextarea( value ) {
		var area = el( 'textarea' );
		area.value = value;
		area.setAttribute( 'readonly', '' );
		area.className = 'sw-ui-visually-hidden';
		document.body.appendChild( area );
		area.select();
		var copied = false;
		try {
			copied = document.execCommand( 'copy' );
		} catch ( error ) {
			copied = false;
		}
		document.body.removeChild( area );
		return copied;
	}

	/** Copy text. Resolves to true when it reached the clipboard. */
	function copy( value ) {
		value = String( value || '' );
		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			return window.navigator.clipboard.writeText( value ).then( function () {
				return true;
			}, function () {
				return copyViaTextarea( value );
			} );
		}
		return Promise.resolve( copyViaTextarea( value ) );
	}

	function selectContents( node ) {
		if ( ! node ) {
			return;
		}
		if ( node.select ) {
			node.select();
			return;
		}
		if ( window.getSelection && document.createRange ) {
			var range = document.createRange();
			range.selectNodeContents( node );
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange( range );
		}
	}

	function statusFor( button ) {
		var named = targetOf( button.getAttribute( 'data-sw-ui-copy-status' ) );
		if ( named ) {
			return named;
		}
		var wrapper = button.closest ? button.closest( '.sw-ui-copy' ) : null;
		return wrapper ? wrapper.querySelector( '.sw-ui-copy__status' ) : null;
	}

	function onCopyClick( button ) {
		var source = targetOf( button.getAttribute( 'data-sw-ui-copy' ) );
		var literal = button.getAttribute( 'data-sw-ui-copy-text' );
		var value = literal !== null ? literal : textOf( source );
		var status = statusFor( button );
		if ( ! value ) {
			return;
		}

		copy( value ).then( function ( copied ) {
			window.clearTimeout( button.swUiCopyTimer );
			if ( copied ) {
				button.setAttribute( 'data-state', 'copied' );
				if ( status ) {
					status.textContent = button.getAttribute( 'data-sw-ui-copied-label' ) || 'Copied';
				}
				button.swUiCopyTimer = window.setTimeout( function () {
					button.removeAttribute( 'data-state' );
					if ( status ) {
						status.textContent = '';
					}
				}, 1600 );
				return;
			}
			// The clipboard is blocked: leave the text selected and say how to copy it.
			selectContents( source );
			if ( status ) {
				status.textContent = button.getAttribute( 'data-sw-ui-copy-failed-label' ) || 'Press Ctrl+C';
			}
		} );
	}

	function onRevealClick( button ) {
		var target = targetOf( button.getAttribute( 'data-sw-ui-reveal' ) );
		if ( ! target ) {
			return;
		}
		var shown = button.getAttribute( 'aria-pressed' ) === 'true';
		if ( target.tagName === 'INPUT' ) {
			target.type = shown ? 'password' : 'text';
		} else {
			target.hidden = shown;
		}
		button.setAttribute( 'aria-pressed', shown ? 'false' : 'true' );
		var label = shown ? button.getAttribute( 'data-sw-ui-show-label' ) : button.getAttribute( 'data-sw-ui-hide-label' );
		if ( label ) {
			button.setAttribute( 'aria-label', label );
			var text = button.querySelector( '[data-sw-ui-reveal-label]' );
			if ( text ) {
				text.textContent = label;
			}
		}
	}

	/** Fills the live region of a checkbox with the content of its template while it is checked, and empties it otherwise. */
	function onLiveFillChange( box ) {
		var region = targetOf( box.getAttribute( 'data-sw-ui-live-fill' ) );
		var template = targetOf( box.getAttribute( 'data-sw-ui-live-fill-from' ) );
		if ( ! region || ! template || ! template.content ) {
			return;
		}
		while ( region.firstChild ) {
			region.removeChild( region.firstChild );
		}
		if ( box.checked ) {
			region.appendChild( template.content.cloneNode( true ) );
		}
	}

	// -----------------------------------------------------------------------------------------------
	// Tabs (WAI-ARIA tabs pattern, automatic activation)
	// -----------------------------------------------------------------------------------------------

	function initTabList( list ) {
		if ( list.getAttribute( 'data-sw-ui-tabs-ready' ) === '1' ) {
			return;
		}
		list.setAttribute( 'data-sw-ui-tabs-ready', '1' );
		var tabs = Array.prototype.slice.call( list.querySelectorAll( '[role="tab"]' ) );

		function select( tab, focus ) {
			tabs.forEach( function ( other ) {
				var active = other === tab;
				other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				other.setAttribute( 'tabindex', active ? '0' : '-1' );
				var panel = targetOf( '#' + other.getAttribute( 'aria-controls' ) );
				if ( panel ) {
					panel.hidden = ! active;
				}
			} );
			if ( focus ) {
				tab.focus();
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				select( tab, false );
			} );
		} );

		list.addEventListener( 'keydown', function ( event ) {
			var index = tabs.indexOf( document.activeElement );
			if ( index < 0 ) {
				return;
			}
			var rtl = window.getComputedStyle( list ).direction === 'rtl';
			var next = null;
			if ( event.key === 'ArrowRight' ) {
				next = tabs[ ( index + ( rtl ? -1 : 1 ) + tabs.length ) % tabs.length ];
			} else if ( event.key === 'ArrowLeft' ) {
				next = tabs[ ( index + ( rtl ? 1 : -1 ) + tabs.length ) % tabs.length ];
			} else if ( event.key === 'Home' ) {
				next = tabs[ 0 ];
			} else if ( event.key === 'End' ) {
				next = tabs[ tabs.length - 1 ];
			}
			if ( next ) {
				event.preventDefault();
				select( next, true );
			}
		} );
	}

	function initTabs( scope ) {
		Array.prototype.forEach.call( ( scope || document ).querySelectorAll( '[data-sw-ui-tabs]' ), initTabList );
	}

	// -----------------------------------------------------------------------------------------------
	// Disclosure memory: open or closed per browser, never required for the page to work.
	// -----------------------------------------------------------------------------------------------

	function storageKey( name ) {
		return 'stonewright.ui.open.' + name;
	}

	function readOpen( name ) {
		try {
			return window.localStorage.getItem( storageKey( name ) );
		} catch ( error ) {
			return null;
		}
	}

	function writeOpen( name, open ) {
		try {
			window.localStorage.setItem( storageKey( name ), open ? '1' : '0' );
		} catch ( error ) {
			/* Storage can be blocked or full; the disclosure still works. */
		}
	}

	function initDisclosures( scope ) {
		Array.prototype.forEach.call( ( scope || document ).querySelectorAll( 'details[data-sw-ui-remember]' ), function ( details ) {
			if ( details.getAttribute( 'data-sw-ui-remember-ready' ) === '1' ) {
				return;
			}
			details.setAttribute( 'data-sw-ui-remember-ready', '1' );
			var name = details.getAttribute( 'data-sw-ui-remember' );
			var stored = readOpen( name );
			if ( stored === '1' ) {
				details.open = true;
			} else if ( stored === '0' ) {
				details.open = false;
			}
			details.addEventListener( 'toggle', function () {
				writeOpen( name, details.open );
			} );
		} );
	}

	// -----------------------------------------------------------------------------------------------
	// Dialogs
	// -----------------------------------------------------------------------------------------------

	function openDialog( dialog, opener ) {
		if ( ! dialog ) {
			return;
		}
		dialog.swUiOpener = opener || document.activeElement;
		if ( typeof dialog.showModal === 'function' ) {
			if ( ! dialog.open ) {
				dialog.showModal();
			}
		} else {
			dialog.setAttribute( 'open', '' );
			dialog.setAttribute( 'role', 'dialog' );
			dialog.setAttribute( 'aria-modal', 'true' );
			var first = dialog.querySelector( '[autofocus], button, [href], input, select, textarea' );
			if ( first ) {
				first.focus();
			}
		}
	}

	function closeDialog( dialog ) {
		if ( ! dialog ) {
			return;
		}
		if ( typeof dialog.close === 'function' ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
			resetConfirmation( dialog );
			returnFocus( dialog );
		}
	}

	function returnFocus( dialog ) {
		var opener = dialog.swUiOpener;
		if ( opener && opener.focus && document.body.contains( opener ) ) {
			opener.focus();
		}
	}

	function onConfirmInput( input ) {
		var dialog = input.closest ? input.closest( 'dialog' ) : null;
		var scope = dialog || document;
		var submit = scope.querySelector( '[data-sw-ui-confirm-submit]' );
		if ( submit ) {
			submit.disabled = input.value !== input.getAttribute( 'data-sw-ui-confirm-phrase' );
		}
	}

	/** A confirmation typed in an earlier visit never carries over: closing the dialog clears it. */
	function resetConfirmation( dialog ) {
		Array.prototype.forEach.call( dialog.querySelectorAll( '[data-sw-ui-confirm-phrase]' ), function ( input ) {
			input.value = '';
			onConfirmInput( input );
		} );
	}

	var FOCUSABLE = 'a[href], button, input:not([type="hidden"]), select, textarea, summary, [tabindex]';

	function tabStops( dialog ) {
		return Array.prototype.filter.call( dialog.querySelectorAll( FOCUSABLE ), function ( node ) {
			return ! node.disabled && node.tabIndex >= 0 && node.getClientRects().length > 0;
		} );
	}

	/** Tab and Shift+Tab wrap inside an open dialog instead of leaving it for the browser's own controls. */
	function onDialogTab( event ) {
		if ( event.key !== 'Tab' || event.defaultPrevented ) {
			return;
		}
		var dialog = event.target && event.target.closest ? event.target.closest( 'dialog.sw-ui-dialog' ) : null;
		if ( ! dialog || ! dialog.open ) {
			return;
		}
		var stops = tabStops( dialog );
		if ( ! stops.length ) {
			event.preventDefault();
			return;
		}
		var first = stops[ 0 ];
		var last = stops[ stops.length - 1 ];
		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	// -----------------------------------------------------------------------------------------------
	// Saved-row flash and inserted notices
	// -----------------------------------------------------------------------------------------------

	/** Tint an element for a moment after an inline save or toggle. No layout change. */
	function flash( element ) {
		if ( ! element ) {
			return;
		}
		element.classList.remove( 'sw-ui-flash' );
		// Reading a layout property restarts the animation when the class is added again.
		void element.offsetWidth;
		element.classList.add( 'sw-ui-flash' );
		window.setTimeout( function () {
			element.classList.remove( 'sw-ui-flash' );
		}, motionOK() ? tokenMs( element, '--sw-dur-flash', 1200 ) : 0 );
	}

	var ICONS = { ok: 'check', warn: 'alert', danger: 'x', info: 'info' };

	/**
	 * Insert a notice made by script. It is announced, and it eases in because it was not there at load.
	 * options: { variant: ok|warn|danger|info, title, text }. Resolves the element it inserted.
	 */
	function notify( container, options ) {
		options = options || {};
		var variant = ICONS[ options.variant ] ? options.variant : 'info';
		var notice = el( 'div', 'sw-ui-notice sw-ui-notice--' + variant + ' sw-ui-notice--enter' );
		notice.setAttribute( 'role', variant === 'warn' || variant === 'danger' ? 'alert' : 'status' );

		var body = el( 'div' );
		if ( options.title ) {
			body.appendChild( el( 'div', 'sw-ui-notice__title', String( options.title ) ) );
		}
		if ( options.text ) {
			body.appendChild( el( 'div', 'sw-ui-notice__text', String( options.text ) ) );
		}
		notice.appendChild( body );
		notice.addEventListener( 'animationend', function () {
			notice.classList.remove( 'sw-ui-notice--enter' );
		} );
		( container || document.body ).appendChild( notice );
		return notice;
	}

	// -----------------------------------------------------------------------------------------------
	// List filter (Knowledge pages)
	//   input[data-sw-ui-filter="#container"]   the field; it filters the items inside #container as you type
	//   [data-sw-ui-filter-item]                one item; its text to match is data-sw-ui-filter-text (lower case)
	//   [data-sw-ui-filter-group]               a group of items: hidden while none of its items matches
	//   [data-sw-ui-filter-count]               a status line: data-sw-ui-filter-label "Showing %1$s of %2$s"
	//   [data-sw-ui-filter-empty]               shown when nothing matches
	// Items and groups are hidden with the `hidden` property, so a filtered item leaves the accessibility tree.
	// -----------------------------------------------------------------------------------------------

	function applyFilter( input ) {
		var container = targetOf( input.getAttribute( 'data-sw-ui-filter' ) );
		if ( ! container ) {
			return;
		}
		var query = String( input.value || '' ).toLowerCase().trim();
		var items = Array.prototype.slice.call( container.querySelectorAll( '[data-sw-ui-filter-item]' ) );
		var shown = 0;

		items.forEach( function ( item ) {
			var text = String( item.getAttribute( 'data-sw-ui-filter-text' ) || item.textContent || '' ).toLowerCase();
			var match = query === '' || text.indexOf( query ) !== -1;
			item.hidden = ! match;
			if ( match ) {
				shown += 1;
			}
		} );
		Array.prototype.forEach.call( container.querySelectorAll( '[data-sw-ui-filter-group]' ), function ( group ) {
			group.hidden = group.querySelectorAll( '[data-sw-ui-filter-item]:not([hidden])' ).length === 0;
		} );

		var empty = container.querySelector( '[data-sw-ui-filter-empty]' );
		if ( empty ) {
			empty.hidden = shown !== 0;
		}
		var count = container.querySelector( '[data-sw-ui-filter-count]' ) || document.querySelector( '[data-sw-ui-filter-count]' );
		if ( count ) {
			var label = count.getAttribute( 'data-sw-ui-filter-label' ) || '%1$s / %2$s';
			count.textContent = label.replace( '%1$s', String( shown ) ).replace( '%2$s', String( items.length ) );
		}
	}

	function initFilters( scope ) {
		Array.prototype.forEach.call( ( scope || document ).querySelectorAll( 'input[data-sw-ui-filter]' ), function ( input ) {
			if ( input.getAttribute( 'data-sw-ui-filter-ready' ) === '1' ) {
				return;
			}
			input.setAttribute( 'data-sw-ui-filter-ready', '1' );
			input.addEventListener( 'input', function () {
				applyFilter( input );
			} );
			applyFilter( input );
		} );
	}
	// -----------------------------------------------------------------------------------------------
	// Search shortcut
	// -----------------------------------------------------------------------------------------------

	function isEditable( node ) {
		if ( ! node || node === document.body ) {
			return false;
		}
		var tag = node.tagName;
		return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || node.isContentEditable === true;
	}

	function onSearchShortcut( event ) {
		if ( event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || isEditable( event.target ) ) {
			return;
		}
		var field = document.querySelector( '[data-sw-ui-search]' );
		if ( ! field || field.offsetParent === null ) {
			return;
		}
		event.preventDefault();
		field.focus();
		if ( field.select ) {
			field.select();
		}
	}

	// -----------------------------------------------------------------------------------------------
	// Delegated events
	// -----------------------------------------------------------------------------------------------

	function init() {
		initTabs( document );
		initDisclosures( document );
		initFilters( document );

		document.addEventListener( 'click', function ( event ) {
			var target = event.target && event.target.closest ? event.target : null;
			if ( ! target ) {
				return;
			}
			var copyButton = target.closest( '[data-sw-ui-copy], [data-sw-ui-copy-text]' );
			if ( copyButton ) {
				event.preventDefault();
				onCopyClick( copyButton );
				return;
			}
			var reveal = target.closest( '[data-sw-ui-reveal]' );
			if ( reveal ) {
				event.preventDefault();
				onRevealClick( reveal );
				return;
			}
			var opener = target.closest( '[data-sw-ui-dialog-open]' );
			if ( opener ) {
				event.preventDefault();
				openDialog( targetOf( opener.getAttribute( 'data-sw-ui-dialog-open' ) ), opener );
				return;
			}
			var closer = target.closest( '[data-sw-ui-dialog-close]' );
			if ( closer ) {
				event.preventDefault();
				closeDialog( closer.closest( 'dialog' ) );
				return;
			}
			// A click on the backdrop of a dialog that allows it closes the dialog.
			if ( target.tagName === 'DIALOG' && target.hasAttribute( 'data-sw-ui-light-dismiss' ) ) {
				closeDialog( target );
			}
		} );

		document.addEventListener( 'input', function ( event ) {
			if ( event.target && event.target.hasAttribute && event.target.hasAttribute( 'data-sw-ui-confirm-phrase' ) ) {
				onConfirmInput( event.target );
			}
		} );

		document.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target.hasAttribute && event.target.hasAttribute( 'data-sw-ui-live-fill' ) ) {
				onLiveFillChange( event.target );
			}
		} );

		// close fires on the dialog itself and does not bubble, so it is captured.
		document.addEventListener( 'close', function ( event ) {
			if ( event.target && event.target.tagName === 'DIALOG' && event.target.classList.contains( 'sw-ui-dialog' ) ) {
				resetConfirmation( event.target );
				returnFocus( event.target );
			}
		}, true );

		document.addEventListener( 'keydown', onSearchShortcut );
		document.addEventListener( 'keydown', onDialogTab );
	}

	// -----------------------------------------------------------------------------------------------
	// Band tooltip (data-sw-ui-tip)
	//
	// A link of the band that carries data-sw-ui-tip shows that text in a tooltip while the pointer is over the
	// link or it has keyboard focus. One tooltip at a time, above the link and centred on it, kept inside the
	// viewport; it fades in and goes away at once on leave, blur or Escape. It is added to the portal, so it is
	// in scope of the layer's tokens and above the admin bar. While it is shown the link is described by it.
	// -----------------------------------------------------------------------------------------------

	var TIP_GAP = 8;
	var TIP_EDGE = 8;
	var tip = null;
	var tipOwner = null;
	var tipDismissed = null;

	function tipTarget( node ) {
		return node && node.closest ? node.closest( '[data-sw-ui-tip]' ) : null;
	}

	function hideTip() {
		if ( tip && tip.parentNode ) {
			tip.parentNode.removeChild( tip );
		}
		if ( tipOwner ) {
			tipOwner.removeAttribute( 'aria-describedby' );
		}
		tip = null;
		tipOwner = null;
	}

	/** Centre the tooltip over the link, 8px above it and 8px or more from both edges of the viewport. */
	function placeTip() {
		var box = tipOwner.getBoundingClientRect();
		var width = tip.offsetWidth;
		var viewport = document.documentElement.clientWidth;
		var left = Math.max( TIP_EDGE, Math.min( box.left + box.width / 2 - width / 2, viewport - width - TIP_EDGE ) );
		tip.style.left = left + window.pageXOffset + 'px';
		tip.style.top = box.top + window.pageYOffset - TIP_GAP - tip.offsetHeight + 'px';
	}

	function showTip( target ) {
		if ( target === tipOwner || target === tipDismissed ) {
			return;
		}
		var text = target.getAttribute( 'data-sw-ui-tip' );
		if ( ! text ) {
			return;
		}
		hideTip();
		tip = el( 'div', 'sw-ui-band-tip', text );
		tip.id = 'sw-ui-band-tip';
		tip.setAttribute( 'role', 'tooltip' );
		ensurePortal().appendChild( tip );
		tipOwner = target;
		target.setAttribute( 'aria-describedby', tip.id );
		placeTip();
		// Reading the width starts the fade from opacity 0.
		void tip.offsetWidth;
		tip.setAttribute( 'data-state', 'shown' );
	}

	function leaveTip( event, target ) {
		if ( ! target || ( event.relatedTarget && target.contains( event.relatedTarget ) ) ) {
			return;
		}
		if ( tipDismissed === target ) {
			tipDismissed = null;
		}
		if ( tipOwner === target ) {
			hideTip();
		}
	}

	document.addEventListener( 'mouseover', function ( event ) {
		var target = tipTarget( event.target );
		if ( target ) {
			showTip( target );
		}
	} );
	document.addEventListener( 'mouseout', function ( event ) {
		leaveTip( event, tipTarget( event.target ) );
	} );
	document.addEventListener( 'focusin', function ( event ) {
		var target = tipTarget( event.target );
		if ( target ) {
			showTip( target );
		}
	} );
	document.addEventListener( 'focusout', function ( event ) {
		leaveTip( event, tipTarget( event.target ) );
	} );
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' && tipOwner ) {
			tipDismissed = tipOwner;
			hideTip();
		}
	} );
	window.addEventListener( 'resize', hideTip );

	// -----------------------------------------------------------------------------------------------
	// Tab links and deep links
	//
	// A tab can be a link to the same page with the view in its query (`?tab=settings`), so it works without
	// script. With script a click switches the view in place and the address keeps the choice
	// (data-sw-ui-tabs-param names the argument), and a link to something inside a hidden view opens that view.
	// -----------------------------------------------------------------------------------------------

	function rememberTab( list, tab ) {
		var param = list.getAttribute( 'data-sw-ui-tabs-param' );
		if ( ! param || ! tab || tab.tagName !== 'A' || ! window.URL ) {
			return;
		}
		try {
			var target = new window.URL( tab.href, window.location.href );
			var current = new window.URL( window.location.href );
			var value = target.searchParams.get( param );
			if ( value ) {
				current.searchParams.set( param, value );
			} else {
				current.searchParams.delete( param );
			}
			window.history.replaceState( window.history.state, '', current.toString() );
			// A form that returns to the page it was sent from (the WordPress settings form) returns to this view.
			Array.prototype.forEach.call( document.querySelectorAll( 'input[name="_wp_http_referer"]' ), function ( field ) {
				field.value = current.pathname + current.search;
			} );
		} catch ( error ) {
			/* A blocked history API leaves the address as it is; the view still switched. */
		}
	}

	/** Open the view that holds `element`, when it is in a hidden one. Returns true when it opened one. */
	function revealPanelFor( element ) {
		var panel = element && element.closest ? element.closest( '[role="tabpanel"]' ) : null;
		if ( ! panel || ! panel.hidden || ! panel.id ) {
			return false;
		}
		var tab = document.querySelector( '[role="tab"][aria-controls="' + panel.id + '"]' );
		if ( ! tab ) {
			return false;
		}
		tab.click();
		return true;
	}

	function revealHash() {
		var hash = window.location.hash;
		if ( ! hash || hash.length < 2 ) {
			return;
		}
		var target = targetOf( '#' + hash.slice( 1 ).replace( /[^A-Za-z0-9_-]/g, '' ) );
		if ( target && revealPanelFor( target ) ) {
			scrollToElement( target );
		}
	}

	function initTabLinks() {
		// The tab lists must be wired before a hash can open a view by clicking its tab (initTabs is idempotent).
		initTabs( document );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-sw-ui-tabs]' ), function ( list ) {
			if ( list.getAttribute( 'data-sw-ui-tab-links-ready' ) === '1' ) {
				return;
			}
			list.setAttribute( 'data-sw-ui-tab-links-ready', '1' );
			list.addEventListener( 'click', function ( event ) {
				var tab = event.target && event.target.closest ? event.target.closest( '[role="tab"]' ) : null;
				if ( tab && tab.tagName === 'A' ) {
					event.preventDefault();
					rememberTab( list, tab );
				}
			} );
			// The arrow, Home and End keys move focus (and the selection) in the tab list's own handler; the address
			// follows the tab that took focus, whichever handler runs first.
			list.addEventListener( 'focusin', function ( event ) {
				var tab = event.target && event.target.closest ? event.target.closest( '[role="tab"]' ) : null;
				if ( tab && tab.getAttribute( 'aria-selected' ) === 'true' ) {
					rememberTab( list, tab );
				}
			} );
		} );
		revealHash();
	}

	window.addEventListener( 'hashchange', revealHash );
	ready( initTabLinks );

	root.ui = {
		version: '1',
		motionOK: motionOK,
		scrollTo: scrollToElement,
		announce: announce,
		toast: toast,
		copy: copy,
		flash: flash,
		notify: notify,
		openDialog: openDialog,
		closeDialog: closeDialog,
		initTabs: initTabs,
		initDisclosures: initDisclosures,
		initFilters: initFilters,
	};

	ready( init );
}() );
