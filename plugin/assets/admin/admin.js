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

	function textFromTarget( target ) {
		if ( ! target ) {
			return '';
		}
		if ( 'value' in target ) {
			return target.value || '';
		}
		if ( target.dataset && target.dataset.stonewrightTextFull ) {
			return target.dataset.stonewrightTextFull || '';
		}
		return target.textContent || '';
	}

	function setButtonFeedback( button, label ) {
		var original = button.getAttribute( 'data-stonewright-original-label' );
		if ( ! original ) {
			original = button.textContent.trim() || 'Copy';
			button.setAttribute( 'data-stonewright-original-label', original );
		}

		button.textContent = label;
		window.clearTimeout( button.stonewrightFeedbackTimer );
		button.stonewrightFeedbackTimer = window.setTimeout( function () {
			button.textContent = original;
		}, 1600 );
	}

	function bridgeEnvText( token ) {
		var value = token || '<choose-a-long-random-token>';
		return [
			'STONEWRIGHT_HTTP_ENABLE=1',
			'PORT=8765',
			'COMPANION_BEARER_TOKEN=' + value,
			'COMPANION_ALLOWED_ORIGINS=http://localhost,http://127.0.0.1',
		].join( '\n' );
	}

	function updateBridgeEnvBlocks( tokenInput ) {
		if ( ! tokenInput ) {
			return;
		}
		document.querySelectorAll( '[data-stonewright-bridge-token-source="' + tokenInput.id + '"]' ).forEach( function ( block ) {
			block.textContent = bridgeEnvText( tokenInput.value || '' );
		} );
	}

	function generateToken() {
		var bytes = new Uint8Array( 32 );
		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( bytes );
		} else {
			for ( var i = 0; i < bytes.length; i++ ) {
				bytes[ i ] = Math.floor( Math.random() * 256 );
			}
		}
		return Array.prototype.map.call( bytes, function ( byte ) {
			return byte.toString( 16 ).padStart( 2, '0' );
		} ).join( '' );
	}

	function copyWithTextarea( value ) {
		if ( ! document.body ) {
			window.prompt( 'Copy value', value ); // eslint-disable-line no-alert
			return;
		}

		var textarea = document.createElement( 'textarea' );
		textarea.value = value;
		textarea.setAttribute( 'readonly', '' );
		textarea.style.position = 'fixed';
		textarea.style.top = '0';
		textarea.style.left = '-9999px';
		textarea.style.opacity = '0';
		document.body.appendChild( textarea );
		textarea.focus();
		textarea.select();

		var copied = false;
		try {
			copied = document.execCommand( 'copy' ); // eslint-disable-line deprecation/deprecation
		} catch ( error ) {
			copied = false;
		}

		if ( textarea.parentNode ) {
			textarea.parentNode.removeChild( textarea );
		}

		if ( ! copied ) {
			window.prompt( 'Copy value', value ); // eslint-disable-line no-alert
		}
	}

	function initCopyButtons() {
		document.querySelectorAll( '[data-stonewright-copy]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var target = document.getElementById( button.getAttribute( 'data-stonewright-copy' ) );
				var value = textFromTarget( target );
				if ( ! value ) {
					return;
				}
				var done = function () {
					setButtonFeedback( button, 'Copied' );
				};
				var fallbackCopy = function () {
					copyWithTextarea( value );
					done();
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( value ).then( done ).catch( fallbackCopy );
				} else {
					fallbackCopy();
				}
			} );
		} );
	}

	function initSecretToggles() {
		document.querySelectorAll( '[data-stonewright-secret-toggle]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var input = document.getElementById( button.getAttribute( 'data-stonewright-secret-toggle' ) );
				if ( ! input ) {
					return;
				}
				var hidden = input.getAttribute( 'type' ) === 'password';
				input.setAttribute( 'type', hidden ? 'text' : 'password' );
				button.textContent = hidden ? 'Hide' : 'Reveal';
			} );
		} );
	}

	function initTokenGenerators() {
		document.querySelectorAll( '[data-stonewright-generate-token]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var input = document.getElementById( button.getAttribute( 'data-stonewright-generate-token' ) );
				if ( ! input ) {
					return;
				}
				input.value = generateToken();
				updateBridgeEnvBlocks( input );
				setButtonFeedback( button, 'Generated' );
			} );
		} );

		document.querySelectorAll( '[data-stonewright-bridge-token-source]' ).forEach( function ( block ) {
			var input = document.getElementById( block.getAttribute( 'data-stonewright-bridge-token-source' ) );
			if ( ! input ) {
				return;
			}
			input.addEventListener( 'input', function () {
				updateBridgeEnvBlocks( input );
			} );
			updateBridgeEnvBlocks( input );
		} );
	}

	function initClientTabs() {
		document.querySelectorAll( '[data-stonewright-client-tab]' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var targetId = tab.getAttribute( 'data-stonewright-client-tab' );
				document.querySelectorAll( '[data-stonewright-client-tab]' ).forEach( function ( item ) {
					item.classList.remove( 'is-active' );
					item.setAttribute( 'aria-selected', 'false' );
				} );
				document.querySelectorAll( '.stonewright-client-panel' ).forEach( function ( panel ) {
					panel.classList.remove( 'is-active' );
					panel.setAttribute( 'hidden', '' );
				} );
				tab.classList.add( 'is-active' );
				tab.setAttribute( 'aria-selected', 'true' );
				var target = document.getElementById( targetId );
				if ( target ) {
					target.classList.add( 'is-active' );
					target.removeAttribute( 'hidden' );
				}
			} );
		} );
	}

	function initAbilitySearch() {
		var searchInput = document.getElementById( 'stonewright-ability-search' );
		if ( ! searchInput ) {
			return;
		}
		searchInput.addEventListener( 'input', function () {
			var query = searchInput.value.toLowerCase();
			document.querySelectorAll( '.stonewright-provider-group' ).forEach( function ( group ) {
				var visible = 0;
				group.querySelectorAll( '.stonewright-ability-row' ).forEach( function ( row ) {
					var haystack = [
						row.dataset.name || '',
						row.dataset.label || '',
						row.dataset.tool || '',
						row.dataset.category || '',
						row.dataset.kind || '',
					].join( ' ' ).toLowerCase();
					var match = ! query || haystack.indexOf( query ) !== -1;
					row.hidden = ! match;
					if ( match ) {
						visible++;
					}
				} );
				group.classList.toggle( 'is-filtered-empty', visible === 0 );
			} );
		} );
	}

	function initAbilityBulkControls() {
		var selectAll = document.querySelector( '[data-stonewright-select-all]' );
		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				document.querySelectorAll( '.stonewright-ability-row:not([hidden]) input[name="stonewright_abilities[]"]' ).forEach( function ( checkbox ) {
					checkbox.checked = selectAll.checked;
				} );
			} );
		}

		document.querySelectorAll( '[data-stonewright-submit-form]' ).forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', function () {
				var form = document.getElementById( checkbox.getAttribute( 'data-stonewright-submit-form' ) );
				if ( form ) {
					form.requestSubmit ? form.requestSubmit() : form.submit();
				}
			} );
		} );
	}

	function focusTarget( id ) {
		var target = id ? document.getElementById( id ) : null;
		if ( target && target.focus ) {
			window.setTimeout( function () {
				target.focus();
			}, 0 );
		}
	}

	function initDeclarativeToggles() {
		document.querySelectorAll( '[data-stonewright-text-toggle]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var target = document.getElementById( button.getAttribute( 'data-stonewright-text-toggle' ) );
				if ( ! target || ! target.dataset ) {
					return;
				}
				var expanded = target.dataset.stonewrightExpanded === 'true';
				target.textContent = expanded ? target.dataset.stonewrightTextPreview || '' : target.dataset.stonewrightTextFull || '';
				target.dataset.stonewrightExpanded = expanded ? 'false' : 'true';
				button.textContent = expanded ? 'Show full text' : 'Show less';
				button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
				document.querySelectorAll( '[data-stonewright-text-collapse="' + target.id + '"]' ).forEach( function ( collapse ) {
					collapse.hidden = expanded;
				} );
			} );
		} );

		document.querySelectorAll( '[data-stonewright-text-collapse]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var target = document.getElementById( button.getAttribute( 'data-stonewright-text-collapse' ) );
				if ( ! target || ! target.dataset ) {
					return;
				}
				target.textContent = target.dataset.stonewrightTextPreview || '';
				target.dataset.stonewrightExpanded = 'false';
				document.querySelectorAll( '[data-stonewright-text-toggle="' + target.id + '"]' ).forEach( function ( toggle ) {
					toggle.textContent = 'Show full text';
					toggle.setAttribute( 'aria-expanded', 'false' );
				} );
				button.hidden = true;
			} );
		} );

		document.querySelectorAll( '[data-stonewright-toggle-target]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var target = document.getElementById( button.getAttribute( 'data-stonewright-toggle-target' ) );
				if ( ! target ) {
					return;
				}
				var shouldShow = target.hidden;
				target.hidden = ! shouldShow;
				button.setAttribute( 'aria-expanded', shouldShow ? 'true' : 'false' );
				if ( shouldShow ) {
					focusTarget( button.getAttribute( 'data-stonewright-focus-target' ) );
				}
			} );
		} );

		document.querySelectorAll( '[data-stonewright-hide-target]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var target = document.getElementById( button.getAttribute( 'data-stonewright-hide-target' ) );
				if ( target ) {
					target.hidden = true;
				}
			} );
		} );

		document.querySelectorAll( '[data-stonewright-row-toggle]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var target = document.getElementById( button.getAttribute( 'data-stonewright-row-toggle' ) );
				if ( ! target ) {
					return;
				}
				target.hidden = ! target.hidden;
				button.setAttribute( 'aria-expanded', target.hidden ? 'false' : 'true' );
			} );
		} );
	}

	function initSkillEditorControls() {
		var titleInput = document.getElementById( 'sw-new-title' );
		var slugInput = document.getElementById( 'sw-new-slug' );
		if ( titleInput && slugInput ) {
			titleInput.addEventListener( 'input', function () {
				if ( slugInput.dataset.userEdited ) {
					return;
				}
				slugInput.value = titleInput.value
					.toLowerCase()
					.replace( /[^a-z0-9]+/g, '-' )
					.replace( /^-+|-+$/g, '' );
			} );
			slugInput.addEventListener( 'input', function () {
				slugInput.dataset.userEdited = '1';
			} );
		}

		document.querySelectorAll( '[data-stonewright-skill-toggle]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var target = document.getElementById( button.getAttribute( 'data-stonewright-skill-toggle' ) );
				if ( ! target ) {
					return;
				}
				target.hidden = ! target.hidden;
				button.setAttribute( 'aria-expanded', target.hidden ? 'false' : 'true' );
				button.textContent = target.hidden ? 'View / Edit' : 'Close';
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initDeleteConfirm();
		initAutoDismissNotices();
		initCopyButtons();
		initSecretToggles();
		initTokenGenerators();
		initClientTabs();
		initAbilitySearch();
		initAbilityBulkControls();
		initDeclarativeToggles();
		initSkillEditorControls();
	} );
}() );
