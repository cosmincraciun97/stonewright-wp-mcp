/**
 * Stonewright admin JavaScript.
 * Enqueued only on stonewright_* admin pages.
 * No third-party dependencies required.
 */
( function () {
	'use strict';

	/**
	 * Intercept delete-form submissions and show a confirmation dialog.
	 * Each form has a data-confirm attribute with the confirmation message.
	 */
	function initDeleteConfirm() {
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			if ( ! form || form.nodeType !== 1 ) {
				return;
			}

			var submitBtn = form.querySelector( 'button[type="submit"][data-confirm]' );
			if ( ! submitBtn ) {
				return;
			}

			var message = submitBtn.getAttribute( 'data-confirm' );
			if ( ! message ) {
				return;
			}

			if ( ! window.confirm( message ) ) { // eslint-disable-line no-alert
				event.preventDefault();
			}
		} );
	}

	/**
	 * Auto-dismiss notice elements after 5 seconds if they have
	 * the is-dismissible class (mirrors WP core admin notices).
	 */
	function initAutoDismissNotices() {
		var notices = document.querySelectorAll( '.notice.is-dismissible' );
		notices.forEach( function ( notice ) {
			window.setTimeout( function () {
				notice.style.transition = 'opacity 0.4s';
				notice.style.opacity = '0';
				window.setTimeout( function () {
					if ( notice.parentNode ) {
						notice.parentNode.removeChild( notice );
					}
				}, 400 );
			}, 5000 );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initDeleteConfirm();
		initAutoDismissNotices();
	} );
}() );
