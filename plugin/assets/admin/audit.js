/**
 * Audit Log page — two-step inline purge confirmation.
 */
( function () {
	'use strict';

	function initAuditLogPurge() {
		var root = document.querySelector( '[data-sw-audit-purge]' );
		if ( ! root ) {
			return;
		}

		var openBtn = root.querySelector( '[data-sw-audit-purge-open]' );
		var card = root.querySelector( '[data-sw-audit-purge-card]' );
		var cancelBtn = root.querySelector( '[data-sw-audit-purge-cancel]' );
		var input = root.querySelector( '[data-sw-audit-purge-input]' );
		var submitBtn = root.querySelector( '[data-sw-audit-purge-submit]' );
		if ( ! openBtn || ! card || ! input || ! submitBtn ) {
			return;
		}

		function syncSubmit() {
			submitBtn.disabled = input.value !== 'DELETE';
		}

		function showCard() {
			card.removeAttribute( 'hidden' );
			input.focus();
			syncSubmit();
		}

		function hideCard() {
			card.setAttribute( 'hidden', '' );
			input.value = '';
			syncSubmit();
			openBtn.focus();
		}

		openBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			showCard();
		} );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				hideCard();
			} );
		}
		input.addEventListener( 'input', syncSubmit );
		syncSubmit();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAuditLogPurge );
	} else {
		initAuditLogPurge();
	}
} )();
