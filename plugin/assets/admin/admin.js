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
		// Silent fallback for HTTP / older browsers. Never use alert/prompt.
		if ( ! document.body ) {
			return false;
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

		return copied;
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
				var done = function ( ok ) {
					setButtonFeedback( button, ok === false ? 'Copy failed' : 'Copied ✓' );
				};
				var fallbackCopy = function () {
					done( copyWithTextarea( value ) );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( value ).then( function () {
						done( true );
					} ).catch( fallbackCopy );
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

	function persistSetupPreference( client, method ) {
		if ( ! window.stonewrightSetup || ! window.stonewrightSetup.ajaxUrl ) {
			return;
		}
		var body = new window.URLSearchParams();
		body.set( 'action', 'stonewright_set_setup_client' );
		body.set( 'nonce', window.stonewrightSetup.nonce || '' );
		if ( client ) {
			body.set( 'client', client );
		}
		if ( method ) {
			body.set( 'method', method );
		}
		window.fetch( window.stonewrightSetup.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).catch( function () {
			/* preference is best-effort */
		} );
	}

	function currentSetupClient() {
		var active = document.querySelector( '[data-stonewright-client-card].is-active' );
		return active ? ( active.getAttribute( 'data-stonewright-client-card' ) || '' ) : '';
	}

	function currentSetupMethod() {
		var active = document.querySelector( '[data-stonewright-method-picker] [data-stonewright-method].is-active' );
		return active ? ( active.getAttribute( 'data-stonewright-method' ) || 'stdio' ) : 'stdio';
	}

	function showMethodSnippets( method ) {
		document.querySelectorAll( '[data-stonewright-method-snippet]' ).forEach( function ( block ) {
			if ( block.getAttribute( 'data-stonewright-method-snippet' ) === method ) {
				block.removeAttribute( 'hidden' );
			} else {
				block.setAttribute( 'hidden', '' );
			}
		} );
	}

	function initClientCards() {
		document.querySelectorAll( '[data-stonewright-client-card]' ).forEach( function ( card ) {
			card.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var slug = card.getAttribute( 'data-stonewright-client-card' );
				if ( ! slug ) {
					return;
				}
				document.querySelectorAll( '[data-stonewright-client-card]' ).forEach( function ( item ) {
					item.classList.remove( 'is-active' );
					item.setAttribute( 'aria-selected', 'false' );
				} );
				document.querySelectorAll( '[data-stonewright-client-panel]' ).forEach( function ( panel ) {
					panel.classList.remove( 'is-active' );
					panel.setAttribute( 'hidden', '' );
				} );
				card.classList.add( 'is-active' );
				card.setAttribute( 'aria-selected', 'true' );
				var target = document.getElementById( 'sw-client-panel-' + slug );
				if ( target ) {
					target.classList.add( 'is-active' );
					target.removeAttribute( 'hidden' );
				}
				persistSetupPreference( slug, currentSetupMethod() );
			} );
		} );
	}

	function initMethodPicker() {
		var picker = document.querySelector( '[data-stonewright-method-picker]' );
		if ( ! picker ) {
			return;
		}
		picker.querySelectorAll( '[data-stonewright-method]' ).forEach( function ( option ) {
			option.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var method = option.getAttribute( 'data-stonewright-method' );
				if ( ! method || ( method !== 'stdio' && method !== 'http' ) ) {
					return;
				}
				picker.querySelectorAll( '[data-stonewright-method]' ).forEach( function ( item ) {
					item.classList.remove( 'is-active' );
					item.setAttribute( 'aria-checked', 'false' );
				} );
				option.classList.add( 'is-active' );
				option.setAttribute( 'aria-checked', 'true' );
				showMethodSnippets( method );
				persistSetupPreference( currentSetupClient(), method );
			} );
		} );
	}

	function normalizeChecklistStatus( status ) {
		if ( status === 'passed' || status === 'ok' ) {
			return 'ok';
		}
		if ( status === 'warn' ) {
			return 'warn';
		}
		if ( status === 'info' ) {
			return 'info';
		}
		return 'error';
	}

	function humanizeStepId( id ) {
		var labels = {
			mint_credential: 'Mint credential',
			initialize: 'Initialize',
			tools_list: 'tools/list',
			task_start: 'task-start',
			cleanup: 'Cleanup',
			request: 'Request',
		};
		return labels[ id ] || id || '';
	}

	function renderConnectionResults( list, checks ) {
		list.innerHTML = '';
		list.hidden = false;
		( checks || [] ).forEach( function ( check ) {
			var status = normalizeChecklistStatus( check.status || 'error' );
			var icon = status === 'ok' ? '✓' : ( status === 'warn' ? '!' : '✗' );
			var li = document.createElement( 'li' );
			li.className = 'sw-checklist__item sw-checklist__item--' + status;
			li.setAttribute( 'data-status', status );
			li.innerHTML =
				'<span class="sw-checklist__icon" aria-hidden="true">' + icon + '</span>' +
				'<span class="sw-checklist__body">' +
				'<strong class="sw-checklist__label"></strong>' +
				'<span class="sw-checklist__detail"></span>' +
				'</span>';
			li.querySelector( '.sw-checklist__label' ).textContent = check.label || humanizeStepId( check.id ) || '';
			var detail = check.detail || '';
			if ( check.fix ) {
				detail = detail ? ( detail + ' — ' + check.fix ) : check.fix;
			}
			li.querySelector( '.sw-checklist__detail' ).textContent = detail;
			list.appendChild( li );
		} );
	}

	function initConnectionTest() {
		document.querySelectorAll( '[data-stonewright-connection-test]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var url = button.getAttribute( 'data-rest-url' );
				var nonce = button.getAttribute( 'data-rest-nonce' );
				var list = document.querySelector( '[data-stonewright-connection-results]' );
				if ( ! url || ! list ) {
					return;
				}
				button.disabled = true;
				setButtonFeedback( button, 'Running preflight…' );
				window.fetch( url, {
					method: 'GET',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': nonce || '',
						'Accept': 'application/json',
					},
				} ).then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} ).then( function ( result ) {
					var checks = ( result.data && result.data.checks ) ? result.data.checks : [];
					if ( ! result.ok && checks.length === 0 ) {
						checks = [ {
							id: 'request',
							status: 'error',
							label: 'Preflight',
							detail: 'Request failed.',
							fix: 'Reload the page and try again.',
						} ];
					}
					renderConnectionResults( list, checks );
					setButtonFeedback(
						button,
						result.data && result.data.ready
							? 'Preflight ready — next: Verify connection'
							: 'Issues found'
					);
				} ).catch( function () {
					renderConnectionResults( list, [ {
						id: 'request',
						status: 'error',
						label: 'Preflight',
						detail: 'Network error.',
						fix: 'Check that you are logged in as an administrator.',
					} ] );
					setButtonFeedback( button, 'Failed' );
				} ).finally( function () {
					button.disabled = false;
				} );
			} );
		} );
	}

	function initConnectionVerify() {
		document.querySelectorAll( '[data-stonewright-connection-verify]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var url = button.getAttribute( 'data-rest-url' );
				var nonce = button.getAttribute( 'data-rest-nonce' );
				var list = document.querySelector( '[data-stonewright-connection-verify-results]' );
				if ( ! url || ! list ) {
					return;
				}
				button.disabled = true;
				setButtonFeedback( button, 'Verifying MCP…' );
				window.fetch( url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': nonce || '',
						'Accept': 'application/json',
						'Content-Type': 'application/json',
					},
					body: '{}',
				} ).then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} ).then( function ( result ) {
					var steps = ( result.data && result.data.steps ) ? result.data.steps : [];
					if ( ! result.ok && steps.length === 0 ) {
						steps = [ {
							id: 'request',
							status: 'failed',
							detail: 'Request failed.',
							fix: 'Reload the page and try again.',
						} ];
					}
					renderConnectionResults( list, steps );
					var verified = !!( result.data && result.data.ok );
					setButtonFeedback(
						button,
						verified
							? 'MCP loopback verified'
							: 'Verification failed'
					);
				} ).catch( function () {
					renderConnectionResults( list, [ {
						id: 'request',
						status: 'failed',
						detail: 'Network error.',
						fix: 'Check that you are logged in as an administrator.',
					} ] );
					setButtonFeedback( button, 'Failed' );
				} ).finally( function () {
					button.disabled = false;
				} );
			} );
		} );
	}

	function escapeRegExp( value ) {
		return String( value ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function clearAbilityHighlights( root ) {
		root.querySelectorAll( 'mark[data-sw-highlight]' ).forEach( function ( mark ) {
			var parent = mark.parentNode;
			if ( ! parent ) {
				return;
			}
			parent.replaceChild( document.createTextNode( mark.textContent || '' ), mark );
			parent.normalize();
		} );
	}

	function highlightAbilityText( node, query ) {
		if ( ! node || ! query ) {
			return;
		}
		var text = node.textContent || '';
		var lower = text.toLowerCase();
		var index = lower.indexOf( query );
		if ( index === -1 ) {
			return;
		}
		var before = text.slice( 0, index );
		var match = text.slice( index, index + query.length );
		var after = text.slice( index + query.length );
		var frag = document.createDocumentFragment();
		if ( before ) {
			frag.appendChild( document.createTextNode( before ) );
		}
		var mark = document.createElement( 'mark' );
		mark.setAttribute( 'data-sw-highlight', '1' );
		mark.textContent = match;
		frag.appendChild( mark );
		if ( after ) {
			frag.appendChild( document.createTextNode( after ) );
		}
		node.textContent = '';
		node.appendChild( frag );
	}

	function initAbilitySearch() {
		var searchInput = document.getElementById( 'stonewright-ability-search' );
		if ( ! searchInput ) {
			return;
		}
		var emptyState = document.querySelector( '[data-sw-abilities-empty]' );
		searchInput.addEventListener( 'input', function () {
			var query = searchInput.value.toLowerCase().trim();
			var totalVisible = 0;
			document.querySelectorAll( '.stonewright-provider-group, .sw-ability-category' ).forEach( function ( group ) {
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
					clearAbilityHighlights( row );
					if ( match ) {
						visible++;
						totalVisible++;
						if ( query ) {
							highlightAbilityText( row.querySelector( '.sw-ability-label' ), query );
							highlightAbilityText( row.querySelector( '.sw-ability-tool' ), query );
						}
					}
				} );
				group.classList.toggle( 'is-filtered-empty', visible === 0 );
			} );
			if ( emptyState ) {
				emptyState.hidden = totalVisible > 0 || ! query;
			}
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

		document.querySelectorAll( '[data-sw-bulk-action]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var action = button.getAttribute( 'data-sw-bulk-action' ) || '';
				var category = button.getAttribute( 'data-sw-bulk-category' ) || '';
				var actionSelect = document.querySelector( 'select[name="stonewright_bulk_action"]' );
				var categorySelect = document.querySelector( 'select[name="stonewright_bulk_category"]' );
				if ( actionSelect ) {
					actionSelect.value = action;
				}
				if ( categorySelect ) {
					categorySelect.value = category;
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

	function initApplyMcpSurface() {
		var button = document.querySelector( '[data-sw-apply-mcp-surface]' );
		var select = document.getElementById( 'stonewright_mcp_surface' );
		var status = document.querySelector( '[data-sw-mcp-surface-status]' );
		if ( ! button || ! select ) {
			return;
		}

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( ! window.stonewrightSetup || ! window.stonewrightSetup.ajaxUrl ) {
				if ( status ) {
					status.textContent = 'Setup AJAX is not available. Use Save settings instead.';
				}
				return;
			}

			var surface = select.value || 'bootstrap';
			button.disabled = true;
			var body = new window.URLSearchParams();
			body.set( 'action', 'stonewright_apply_mcp_surface' );
			body.set( 'nonce', window.stonewrightSetup.nonce || '' );
			body.set( 'surface', surface );

			window.fetch( window.stonewrightSetup.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} ).then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} ).then( function ( result ) {
				var payload = result.data && result.data.data ? result.data.data : ( result.data || {} );
				var success = !!( result.data && result.data.success );
				if ( success ) {
					var msg = payload.message || 'MCP surface applied.';
					var truth = payload.transport_truth || '';
					if ( status ) {
						status.textContent = truth ? ( msg + ' ' + truth ) : msg;
					}
					setButtonFeedback( button, 'Applied' );
					if ( payload.surface && select.value !== payload.surface ) {
						select.value = payload.surface;
					}
				} else {
					var err = ( payload && payload.message ) ? payload.message : 'Could not apply MCP surface.';
					if ( status ) {
						status.textContent = err;
					}
					setButtonFeedback( button, 'Failed' );
				}
			} ).catch( function () {
				if ( status ) {
					status.textContent = 'Network error applying MCP surface.';
				}
				setButtonFeedback( button, 'Failed' );
			} ).finally( function () {
				button.disabled = false;
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initDeleteConfirm();
		initAutoDismissNotices();
		initCopyButtons();
		initSecretToggles();
		initTokenGenerators();
		initClientCards();
		initMethodPicker();
		initConnectionTest();
		initConnectionVerify();
		initAbilitySearch();
		initAbilityBulkControls();
		initDeclarativeToggles();
		initSkillEditorControls();
		initPromptLibrary();
		initApplyMcpSurface();
	} );

	function initPromptLibrary() {
		var search = document.querySelector( '[data-stonewright-prompt-search]' );
		var cards = document.querySelectorAll( '[data-stonewright-prompt-card], [data-sw-prompt-card]' );
		if ( ! cards.length ) {
			return;
		}

		function applyFilter() {
			var q = search ? String( search.value || '' ).toLowerCase().trim() : '';
			var visibleBySection = {};
			cards.forEach( function ( card ) {
				var hay = String(
					card.getAttribute( 'data-search' )
					|| ( card.getAttribute( 'data-title' ) || '' ) + ' ' + ( card.getAttribute( 'data-outcome' ) || '' )
				).toLowerCase();
				var matchQuery = ! q || hay.indexOf( q ) !== -1;
				card.hidden = ! matchQuery;
				var section = card.closest( '[data-sw-prompt-outcome], .sw-section' );
				if ( section ) {
					var key = section.getAttribute( 'data-sw-prompt-outcome' ) || section.id || 'default';
					if ( ! visibleBySection[ key ] ) {
						visibleBySection[ key ] = { section: section, count: 0 };
					}
					if ( matchQuery ) {
						visibleBySection[ key ].count += 1;
					}
				}
			} );
			Object.keys( visibleBySection ).forEach( function ( key ) {
				var row = visibleBySection[ key ];
				if ( row && row.section ) {
					row.section.hidden = q !== '' && row.count === 0;
				}
			} );
		}

		if ( search ) {
			search.addEventListener( 'input', applyFilter );
		}
	}
}() );
