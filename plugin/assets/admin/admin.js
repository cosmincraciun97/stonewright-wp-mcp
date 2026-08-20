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

	function showCopyFallbackModal( value ) {
		var modal = document.querySelector( '[data-stonewright-copy-modal]' );
		if ( ! modal ) {
			modal = document.createElement( 'div' );
			modal.className = 'sw-copy-modal';
			modal.setAttribute( 'data-stonewright-copy-modal', '' );

			var dialog = document.createElement( 'div' );
			dialog.className = 'sw-copy-modal__dialog';
			dialog.setAttribute( 'role', 'dialog' );
			dialog.setAttribute( 'aria-modal', 'true' );

			var hint = document.createElement( 'p' );
			hint.textContent = 'Press Ctrl/Cmd+C';

			var field = document.createElement( 'textarea' );
			field.setAttribute( 'readonly', '' );

			var dismiss = document.createElement( 'button' );
			dismiss.type = 'button';
			dismiss.className = 'button';
			dismiss.setAttribute( 'data-stonewright-copy-modal-dismiss', '' );
			dismiss.textContent = 'Close';

			dialog.appendChild( hint );
			dialog.appendChild( field );
			dialog.appendChild( dismiss );
			modal.appendChild( dialog );
			document.body.appendChild( modal );
		}

		var textarea = modal.querySelector( 'textarea' );
		var dismissBtn = modal.querySelector( '[data-stonewright-copy-modal-dismiss]' );
		if ( ! textarea ) {
			return;
		}

		textarea.value = String( value || '' );
		modal.hidden = false;
		textarea.focus();
		textarea.select();

		if ( dismissBtn && dismissBtn.getAttribute( 'data-stonewright-copy-modal-bound' ) !== '1' ) {
			dismissBtn.setAttribute( 'data-stonewright-copy-modal-bound', '1' );
			dismissBtn.addEventListener( 'click', function () {
				modal.hidden = true;
			} );
		}
	}

	var bindCopyButtons = function () {};

	function initCopyButtons() {
		bindCopyButtons = function () {
			document.querySelectorAll( '[data-stonewright-copy]' ).forEach( function ( button ) {
				if ( button.getAttribute( 'data-stonewright-copy-bound' ) === '1' ) {
					return;
				}
				button.setAttribute( 'data-stonewright-copy-bound', '1' );
				button.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					var target = document.getElementById( button.getAttribute( 'data-stonewright-copy' ) );
					var value = textFromTarget( target );
					if ( ! value ) {
						return;
					}
					var fallbackCopy = function () {
						if ( copyWithTextarea( value ) ) {
							setButtonFeedback( button, 'Copied ✓' );
							return;
						}
						showCopyFallbackModal( value );
					};
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( value ).then( function () {
							setButtonFeedback( button, 'Copied ✓' );
						} ).catch( fallbackCopy );
					} else {
						fallbackCopy();
					}
				} );
			} );
		};
		bindCopyButtons();
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

	function initCompanionUpdateStatus() {
		document.querySelectorAll( '[data-stonewright-companion-status]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var url = button.getAttribute( 'data-rest-url' );
				var nonce = button.getAttribute( 'data-rest-nonce' );
				var panel = document.querySelector( '[data-stonewright-companion-result]' );
				if ( ! url || ! panel ) {
					return;
				}

				button.disabled = true;
				setButtonFeedback( button, 'Checking release…' );
				var refreshUrl = new window.URL( url, window.location.href );
				refreshUrl.searchParams.set( 'force', '1' );
				refreshUrl.searchParams.set( '_', String( Date.now() ) );
				window.fetch( refreshUrl.toString(), {
					method: 'GET',
					credentials: 'same-origin',
					cache: 'no-store',
					headers: {
						'X-WP-Nonce': nonce || '',
						'Accept': 'application/json',
					},
				} ).then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} ).then( function ( result ) {
					var data = result.data || {};
					if ( ! result.ok ) {
						throw new Error( 'status request failed' );
					}

					panel.hidden = false;
					var summary = panel.querySelector( '[data-stonewright-companion-summary]' );
					var plugin = panel.querySelector( '[data-stonewright-plugin-version]' );
					var release = panel.querySelector( '[data-stonewright-release-version]' );
					var bridge = panel.querySelector( '[data-stonewright-bridge-version]' );
					var prompt = panel.querySelector( '[data-stonewright-companion-prompt]' );
					var download = panel.querySelector( '[data-stonewright-companion-download]' );
					var checksums = panel.querySelector( '[data-stonewright-companion-checksums]' );
					var bridgeVersion = data.bridge && data.bridge.version ? data.bridge.version : 'Not visible from WordPress';

					if ( summary ) {
						summary.textContent = data.plugin_update_available
							? 'A newer release exists. Update the plugin and companion together.'
							: ( data.companion_status === 'outdated'
								? 'The configured HTTP bridge is outdated.'
								: ( data.companion_status === 'current'
									? 'The configured HTTP bridge matches the latest release.'
									: ( data.companion_status === 'mismatch'
										? 'The configured HTTP bridge does not match the target release.'
										: data.boundary || 'Local stdio version must be verified in the AI client.' ) ) );
						summary.className = 'sw-companion-update-result__summary sw-companion-update-result__summary--' + (
							data.plugin_update_available || [ 'outdated', 'mismatch' ].indexOf( data.companion_status ) !== -1 ? 'warn' : 'info'
						);
					}
					if ( plugin ) {
						plugin.textContent = data.plugin_version || 'Unknown';
					}
					if ( release ) {
						release.textContent = data.latest_release_version || 'Unavailable';
					}
					if ( bridge ) {
						bridge.textContent = bridgeVersion;
					}
					if ( prompt ) {
						prompt.value = data.update_prompt || '';
					}
					if ( download ) {
						download.hidden = ! data.companion_package;
						download.href = data.companion_package || '#';
					}
					if ( checksums ) {
						checksums.hidden = ! data.checksums;
						checksums.href = data.checksums || '#';
					}
					setButtonFeedback( button, data.ok ? 'Release checked' : 'Release unavailable' );
				} ).catch( function () {
					panel.hidden = false;
					var summary = panel.querySelector( '[data-stonewright-companion-summary]' );
					if ( summary ) {
						summary.textContent = 'Could not read the official release. Check network access and try again.';
						summary.className = 'sw-companion-update-result__summary sw-companion-update-result__summary--warn';
					}
					setButtonFeedback( button, 'Check failed' );
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
			button.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
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
		var enabled = document.getElementById( 'stonewright_enabled' );
		var mode = document.getElementById( 'stonewright_mode' );
		var atomic = document.getElementById( 'stonewright_elementor_v4_atomic' );
		var status = document.querySelector( '[data-sw-mcp-surface-status]' );
		if ( ! button || ! select ) {
			return;
		}

		var applyQueue = window.Promise.resolve();
		function applyStepOne( showButtonFeedback ) {
			if ( ! window.stonewrightSetup || ! window.stonewrightSetup.ajaxUrl ) {
				if ( status ) {
					status.textContent = 'Setup AJAX is not available. Use Save settings instead.';
				}
				return;
			}

			var surface = select.value || 'essential';
			button.disabled = true;
			var body = new window.URLSearchParams();
			body.set( 'action', 'stonewright_apply_mcp_surface' );
			body.set( 'nonce', window.stonewrightSetup.nonce || '' );
			body.set( 'surface', surface );
			body.set( 'mode', mode ? mode.value : 'development' );
			body.set( 'enabled', enabled && enabled.checked ? '1' : '0' );
			body.set( 'elementor_v4_atomic', atomic && atomic.checked ? '1' : '0' );

			applyQueue = applyQueue.then( function () {
				return window.fetch( window.stonewrightSetup.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
				} );
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
					if ( showButtonFeedback ) {
						setButtonFeedback( button, 'Applied' );
					}
					if ( payload.surface && select.value !== payload.surface ) {
						select.value = payload.surface;
					}
				} else {
					var err = ( payload && payload.message ) ? payload.message : 'Could not apply MCP surface.';
					if ( status ) {
						status.textContent = err;
					}
					if ( showButtonFeedback ) {
						setButtonFeedback( button, 'Failed' );
					}
				}
			} ).catch( function () {
				if ( status ) {
					status.textContent = 'Network error applying MCP surface.';
				}
				if ( showButtonFeedback ) {
					setButtonFeedback( button, 'Failed' );
				}
			} ).finally( function () {
				button.disabled = false;
			} );
		}

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			applyStepOne( true );
		} );
		[ select, enabled, mode, atomic ].forEach( function ( control ) {
			if ( control ) {
				control.addEventListener( 'change', function () {
					applyStepOne( false );
				} );
			}
		} );
	}

	/**
	 * In-memory Application Password only (never query string / storage / data-*).
	 * Cleared on auth/client change, revoke, hide/unload, or replacement generate.
	 */
	var appPasswordMemory = {
		password: '',
		uuid: '',
		name: '',
	};

	/** @type {Array<{el: Element, template: string}>|null} */
	var appPasswordSnippetTemplates = null;

	function captureAppPasswordSnippetTemplates() {
		if ( appPasswordSnippetTemplates ) {
			return;
		}
		appPasswordSnippetTemplates = [];
		document.querySelectorAll( '[data-stonewright-method-snippet] pre code, [data-stonewright-method-snippet] pre' ).forEach( function ( block ) {
			var text = block.textContent || '';
			if ( text.indexOf( '<your-application-password>' ) === -1 ) {
				return;
			}
			// Prefer <code> nodes; skip parent <pre> when it already wraps a captured code child.
			if ( block.tagName && block.tagName.toLowerCase() === 'pre' && block.querySelector( 'code' ) ) {
				return;
			}
			appPasswordSnippetTemplates.push( {
				el: block,
				template: text,
			} );
		} );
	}

	function restoreAppPasswordSnippetPlaceholders() {
		if ( ! appPasswordSnippetTemplates ) {
			return;
		}
		appPasswordSnippetTemplates.forEach( function ( entry ) {
			if ( entry && entry.el && typeof entry.template === 'string' ) {
				entry.el.textContent = entry.template;
			}
		} );
	}

	function clearAppPasswordMemory() {
		appPasswordMemory.password = '';
		appPasswordMemory.uuid = '';
		appPasswordMemory.name = '';
		restoreAppPasswordSnippetPlaceholders();
		var live = document.querySelector( '[data-stonewright-app-password-live]' );
		if ( live ) {
			live.hidden = true;
			live.innerHTML = '';
		}
		var input = document.getElementById( 'stonewright-generated-app-password' );
		if ( input ) {
			input.value = '';
			if ( input.parentNode ) {
				input.parentNode.remove();
			}
		}
	}

	function showAppPasswordLive( payload ) {
		var live = document.querySelector( '[data-stonewright-app-password-live]' );
		if ( ! live ) {
			return;
		}
		live.hidden = false;
		live.innerHTML = '';
		var strong = document.createElement( 'strong' );
		strong.textContent = 'Application password generated.';
		live.appendChild( strong );
		var note = document.createElement( 'span' );
		note.textContent = ' Shown once in this browser session. The paste-to-agent prompt stays credential-free.';
		live.appendChild( note );
		var row = document.createElement( 'div' );
		row.className = 'stonewright-inline-controls sw-actions';
		var field = document.createElement( 'input' );
		field.type = 'text';
		field.readOnly = true;
		field.className = 'regular-text';
		field.id = 'stonewright-generated-app-password';
		field.value = payload.password || '';
		field.setAttribute( 'autocomplete', 'off' );
		var copyBtn = document.createElement( 'button' );
		copyBtn.type = 'button';
		copyBtn.className = 'button button-small';
		copyBtn.textContent = 'Copy password only';
		copyBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			var value = appPasswordMemory.password || field.value || '';
			if ( ! value ) {
				return;
			}
			var done = function ( ok ) {
				setButtonFeedback( copyBtn, ok === false ? 'Copy failed' : 'Copied ✓' );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( value ).then( function () {
					done( true );
				} ).catch( function () {
					done( copyWithTextarea( value ) );
				} );
			} else {
				done( copyWithTextarea( value ) );
			}
		} );
		row.appendChild( field );
		row.appendChild( copyBtn );
		live.appendChild( row );
	}

	function ensurePasswordInventoryTable() {
		var tbody = document.querySelector( '.stonewright-app-password-table tbody' );
		if ( tbody ) {
			return tbody;
		}
		var list = document.querySelector( '.stonewright-app-passwords-list' );
		if ( ! list ) {
			return null;
		}
		var empty = list.querySelector( ':scope > p.description' );
		if ( empty ) {
			empty.remove();
		}
		var table = document.createElement( 'table' );
		table.className = 'widefat striped stonewright-app-password-table';
		var thead = document.createElement( 'thead' );
		var headRow = document.createElement( 'tr' );
		[ 'Name', 'UUID', 'Actions' ].forEach( function ( label ) {
			var th = document.createElement( 'th' );
			th.textContent = label;
			headRow.appendChild( th );
		} );
		thead.appendChild( headRow );
		table.appendChild( thead );
		tbody = document.createElement( 'tbody' );
		table.appendChild( tbody );
		list.appendChild( table );
		return tbody;
	}

	function updatePasswordInventorySummary( count ) {
		var summary = document.querySelector( '.stonewright-app-passwords-list summary' );
		if ( ! summary ) {
			return;
		}
		summary.textContent = 'Manage existing application passwords (' + String( count ) + ')';
	}

	function refreshPasswordInventory( passwords ) {
		if ( ! Array.isArray( passwords ) ) {
			return;
		}
		var tbody = ensurePasswordInventoryTable();
		if ( ! tbody ) {
			return;
		}
		tbody.innerHTML = '';
		updatePasswordInventorySummary( passwords.length );
		var inventory = document.querySelector( '.stonewright-app-passwords-list' );
		if ( inventory && passwords.length > 0 ) {
			inventory.open = true;
		}
		passwords.forEach( function ( item ) {
			var tr = document.createElement( 'tr' );
			var nameTd = document.createElement( 'td' );
			nameTd.textContent = item.name || '';
			var uuidTd = document.createElement( 'td' );
			uuidTd.textContent = item.uuid || '';
			var actionTd = document.createElement( 'td' );
			var form = document.createElement( 'form' );
			form.method = 'post';
			form.setAttribute( 'data-stonewright-app-password-revoke', item.uuid || '' );
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button button-small';
			btn.textContent = 'Revoke';
			btn.setAttribute( 'data-confirm', 'Revoke this Application Password? The connected client will lose access immediately.' );
			btn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( ! window.confirm( btn.getAttribute( 'data-confirm' ) ) ) { // eslint-disable-line no-alert
					return;
				}
				revokeAppPassword( item.uuid || '', btn );
			} );
			form.appendChild( btn );
			actionTd.appendChild( form );
			tr.appendChild( nameTd );
			tr.appendChild( uuidTd );
			tr.appendChild( actionTd );
			tbody.appendChild( tr );
		} );
	}

	function revokeAppPassword( uuid, button ) {
		if ( ! uuid ) {
			return;
		}
		var setup = window.stonewrightSetup || {};
		var url = setup.appPasswordUrl || '';
		var nonce = setup.restNonce || '';
		if ( ! url ) {
			return;
		}
		if ( button ) {
			button.disabled = true;
		}
		window.fetch( url + '?uuid=' + encodeURIComponent( uuid ), {
			method: 'DELETE',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				'X-WP-Nonce': nonce,
				'Accept': 'application/json',
			},
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				return { ok: response.ok, data: data };
			} );
		} ).then( function ( result ) {
			if ( ! result.ok ) {
				throw new Error( 'revoke failed' );
			}
			if ( appPasswordMemory.uuid === uuid ) {
				clearAppPasswordMemory();
			}
			return window.fetch( url, {
				method: 'GET',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: {
					'X-WP-Nonce': nonce,
					'Accept': 'application/json',
				},
			} );
		} ).then( function ( response ) {
			if ( ! response ) {
				return null;
			}
			return response.json();
		} ).then( function ( list ) {
			if ( list && list.passwords ) {
				refreshPasswordInventory( list.passwords );
			}
		} ).catch( function () {
			/* keep no-JS revoke form as fallback on next full page */
		} ).finally( function () {
			if ( button ) {
				button.disabled = false;
			}
		} );
	}

	function updateSnippetsWithPassword( password ) {
		// Private client snippets may include the one-time password in-page only.
		// Never write into the paste-to-agent prompt (credential-free contract).
		// Always re-render from captured templates so clear/regenerate cannot leave
		// secrets in the DOM or lose the placeholder permanently.
		captureAppPasswordSnippetTemplates();
		if ( ! password ) {
			restoreAppPasswordSnippetPlaceholders();
			return;
		}
		if ( ! appPasswordSnippetTemplates ) {
			return;
		}
		appPasswordSnippetTemplates.forEach( function ( entry ) {
			if ( ! entry || ! entry.el || typeof entry.template !== 'string' ) {
				return;
			}
			entry.el.textContent = entry.template.split( '<your-application-password>' ).join( password );
		} );
	}

	function initAppPasswordForm() {
		var form = document.querySelector( '[data-stonewright-app-password-form]' );
		if ( ! form ) {
			return;
		}

		// Capture credential placeholders before any generate/clear mutates them.
		captureAppPasswordSnippetTemplates();

		var hint = form.querySelector( '[data-stonewright-app-password-nojs-hint]' );
		if ( hint ) {
			hint.hidden = true;
		}

		form.addEventListener( 'submit', function ( event ) {
			var setup = window.stonewrightSetup || {};
			var url = form.getAttribute( 'data-rest-url' ) || setup.appPasswordUrl || '';
			var nonce = form.getAttribute( 'data-rest-nonce' ) || setup.restNonce || '';
			if ( ! url || ! window.fetch ) {
				// no-JS / no-REST: allow full navigation fallback.
				return;
			}
			event.preventDefault();

			var nameInput = form.querySelector( '#stonewright_app_password_name' );
			var name = nameInput ? String( nameInput.value || '' ).trim() : '';
			var submit = form.querySelector( '[type="submit"]' );
			var live = form.querySelector( '[data-stonewright-app-password-live]' );
			if ( ! name ) {
				if ( live ) {
					live.hidden = false;
					live.textContent = 'Enter a name before generating an Application Password.';
				}
				return;
			}

			if ( submit ) {
				submit.disabled = true;
				setButtonFeedback( submit, 'Generating…' );
			}

			// Replacement generate clears prior in-memory password first.
			clearAppPasswordMemory();

			window.fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: {
					'X-WP-Nonce': nonce,
					'Accept': 'application/json',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( { name: name } ),
			} ).then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data, headers: response.headers };
				} );
			} ).then( function ( result ) {
				if ( ! result.ok || ! result.data || ! result.data.password ) {
					var message = ( result.data && result.data.message ) ? result.data.message : 'Could not generate Application Password.';
					if ( live ) {
						live.hidden = false;
						live.textContent = message;
					}
					setButtonFeedback( submit, 'Failed' );
					return;
				}

				appPasswordMemory.password = String( result.data.password || '' );
				appPasswordMemory.uuid = String( result.data.uuid || '' );
				appPasswordMemory.name = String( result.data.name || name );
				showAppPasswordLive( {
					password: appPasswordMemory.password,
					uuid: appPasswordMemory.uuid,
					name: appPasswordMemory.name,
				} );
				updateSnippetsWithPassword( appPasswordMemory.password );
				setButtonFeedback( submit, 'Generated' );

				// Refresh inventory without password values.
				return window.fetch( url, {
					method: 'GET',
					credentials: 'same-origin',
					cache: 'no-store',
					headers: {
						'X-WP-Nonce': nonce,
						'Accept': 'application/json',
					},
				} ).then( function ( response ) {
					return response.json();
				} ).then( function ( list ) {
					if ( list && list.passwords ) {
						refreshPasswordInventory( list.passwords );
					}
					if ( list && list.username ) {
						// Username display is already on page; keep memory free of extra copies.
					}
				} );
			} ).catch( function () {
				if ( live ) {
					live.hidden = false;
					live.textContent = 'Network error generating Application Password.';
				}
				if ( submit ) {
					setButtonFeedback( submit, 'Failed' );
				}
			} ).finally( function () {
				if ( submit ) {
					submit.disabled = false;
				}
			} );
		} );

		// Clear in-memory password when auth method or client changes.
		document.querySelectorAll( '[data-stonewright-auth-method]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				clearAppPasswordMemory();
				var method = button.getAttribute( 'data-stonewright-auth-method' ) || '';
				if ( method && window.stonewrightSetup && window.stonewrightSetup.ajaxUrl ) {
					var body = new window.URLSearchParams();
					body.set( 'action', 'stonewright_set_setup_client' );
					body.set( 'nonce', window.stonewrightSetup.nonce || '' );
					body.set( 'auth_method', method );
					window.fetch( window.stonewrightSetup.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: body.toString(),
					} ).catch( function () { /* best-effort */ } );
				}
			} );
		} );
		document.querySelectorAll( '[data-stonewright-client-card]' ).forEach( function ( card ) {
			card.addEventListener( 'click', function () {
				clearAppPasswordMemory();
			} );
		} );

		window.addEventListener( 'pagehide', clearAppPasswordMemory );
		window.addEventListener( 'beforeunload', clearAppPasswordMemory );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'hidden' ) {
				clearAppPasswordMemory();
			}
		} );
	}

	function diagnosticIcon( status ) {
		if ( status === 'ok' ) {
			return '✓';
		}
		if ( status === 'warn' ) {
			return '!';
		}
		if ( status === 'info' ) {
			return 'ⓘ';
		}
		return '✗';
	}

	function formatDiagnosticsCopy( report ) {
		var lines = [ 'Stonewright diagnostics' ];
		var versions = report && report.versions ? report.versions : {};
		if ( report && report.mode ) {
			lines.push( 'Mode: ' + report.mode );
		}
		if ( versions.plugin ) {
			lines.push( 'Plugin: ' + versions.plugin );
		}
		if ( versions.companion_contract ) {
			lines.push( 'Companion HTTP contract: ' + versions.companion_contract );
		}
		if ( versions.wordpress ) {
			lines.push( 'WordPress: ' + versions.wordpress );
		}
		if ( versions.php ) {
			lines.push( 'PHP: ' + versions.php );
		}
		lines.push( '' );
		( ( report && report.checks ) || [] ).forEach( function ( check ) {
			lines.push( '[' + ( check.status || 'error' ) + '] ' + ( check.label || '' ) );
			if ( check.detail ) {
				lines.push( check.detail );
			}
			if ( check.ticket ) {
				lines.push( check.ticket );
			}
			lines.push( '' );
		} );
		return lines.join( '\n' ).trim();
	}

	function paintDiagnosticCards( root, report ) {
		var cards = root.querySelector( '[data-stonewright-diag-cards]' );
		if ( ! cards ) {
			return;
		}
		cards.textContent = '';
		var errorCount = 0;
		var warnCount = 0;
		( ( report && report.checks ) || [] ).forEach( function ( check ) {
			var status = normalizeChecklistStatus( check.status || 'error' );
			if ( status === 'error' ) {
				errorCount += 1;
			}
			if ( status === 'warn' ) {
				warnCount += 1;
			}

			var card = document.createElement( 'div' );
			card.className = 'sw-diag-card sw-diag-card--' + status;
			card.setAttribute( 'data-status', status );

			var icon = document.createElement( 'span' );
			icon.className = 'sw-diag-card__icon';
			icon.setAttribute( 'aria-hidden', 'true' );
			icon.textContent = diagnosticIcon( status );

			var bodyEl = document.createElement( 'span' );
			bodyEl.className = 'sw-diag-card__body';

			var label = document.createElement( 'strong' );
			label.className = 'sw-diag-card__label';
			label.textContent = check.label || '';

			var detail = document.createElement( 'span' );
			detail.className = 'sw-diag-card__detail';
			detail.textContent = check.detail || '';

			bodyEl.appendChild( label );
			bodyEl.appendChild( detail );

			if ( check.ticket ) {
				var ticketId = 'stonewright-diag-ticket-' + String( check.id || 'check' ).replace( /[^a-z0-9_-]/gi, '' );
				var ticketBtn = document.createElement( 'button' );
				ticketBtn.type = 'button';
				ticketBtn.className = 'button';
				ticketBtn.setAttribute( 'data-stonewright-copy', ticketId );
				ticketBtn.textContent = 'Copy ticket';

				var ticketArea = document.createElement( 'textarea' );
				ticketArea.id = ticketId;
				ticketArea.className = 'sw-diag-copy-source';
				ticketArea.setAttribute( 'readonly', '' );
				ticketArea.hidden = true;
				ticketArea.value = String( check.ticket );

				bodyEl.appendChild( ticketBtn );
				bodyEl.appendChild( ticketArea );
			}

			card.appendChild( icon );
			card.appendChild( bodyEl );
			cards.appendChild( card );
		} );

		var problems = root.querySelector( '[data-stonewright-diag-problems]' );
		var warnings = root.querySelector( '[data-stonewright-diag-warnings]' );
		if ( problems ) {
			problems.textContent = errorCount + ' Problems';
			problems.hidden = errorCount === 0;
		}
		if ( warnings ) {
			warnings.textContent = warnCount + ' Warnings';
			warnings.hidden = warnCount === 0;
		}

		var copy = document.getElementById( 'stonewright-diagnostics-copy' );
		if ( copy ) {
			copy.value = formatDiagnosticsCopy( report || {} );
		}

		bindCopyButtons();
	}

	function initRunDiagnostics() {
		var root = document.querySelector( '[data-stonewright-diagnostics]' );
		var button = root ? root.querySelector( '[data-stonewright-run-diagnostics]' ) : null;
		var form = root ? root.querySelector( '.sw-diagnostics-run' ) : null;
		if ( ! root || ! button || ! form ) {
			return;
		}

		var symptom = root.querySelector( '[data-stonewright-diag-symptom]' );
		var help = root.querySelector( '[data-stonewright-diag-help]' );
		var helpText = {
			tools: 'Confirm Stonewright is enabled and the MCP surface is Essential or Full, then restart the AI client so it re-lists tools.',
			auth: 'Confirm HTTPS or a local WordPress environment, then reconnect from Setup.',
			unreachable: 'Check the site URL, TLS, and whether a firewall or login wall is blocking the MCP endpoint.',
			other: 'Run diagnostics above, then copy the report for support.',
		};
		if ( symptom && help ) {
			symptom.addEventListener( 'change', function () {
				var text = helpText[ symptom.value ] || '';
				help.textContent = text;
				help.hidden = ! text;
			} );
		}

		function scrollToFirstIssue() {
			var first = root.querySelector( '.sw-diag-card--error, .sw-diag-card--warn' );
			if ( first && first.scrollIntoView ) {
				first.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}

		var problems = root.querySelector( '[data-stonewright-diag-problems]' );
		var warnings = root.querySelector( '[data-stonewright-diag-warnings]' );
		if ( problems ) {
			problems.addEventListener( 'click', scrollToFirstIssue );
		}
		if ( warnings ) {
			warnings.addEventListener( 'click', scrollToFirstIssue );
		}

		var running = false;
		function finishLoading() {
			running = false;
			button.disabled = false;
			button.classList.remove( 'is-loading' );
			button.setAttribute( 'aria-busy', 'false' );
		}

		function runDiagnostics( event ) {
			if ( ! window.stonewrightSetup || ! window.stonewrightSetup.ajaxUrl ) {
				return;
			}
			event.preventDefault();
			if ( running ) {
				return;
			}

			var modeSelect = root.querySelector( '[data-stonewright-diag-mode]' );
			var mode = modeSelect && modeSelect.value ? modeSelect.value : 'both';
			running = true;
			button.disabled = true;
			button.classList.add( 'is-loading' );
			button.setAttribute( 'aria-busy', 'true' );

			var body = new window.URLSearchParams();
			body.set( 'action', 'stonewright_run_diagnostics' );
			body.set( 'nonce', window.stonewrightSetup.nonce || '' );
			body.set( 'mode', mode );

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
				var success = !!( result.data && result.data.success );
				var payload = result.data && result.data.data ? result.data.data : ( result.data || {} );
				if ( success ) {
					paintDiagnosticCards( root, payload );
				}
			} ).catch( function () {
				/* Keep the last painted cards. */
			} ).finally( finishLoading );
		}

		button.addEventListener( 'click', runDiagnostics );
		form.addEventListener( 'submit', runDiagnostics );
	}

	function initContextToggleBadge() {
		var checkbox = document.getElementById( 'stonewright_user_context_enabled' );
		var badge = document.querySelector( '[data-sw-context-state]' );

		if ( ! checkbox || ! badge ) {
			return;
		}

		var onLabel = badge.getAttribute( 'data-context-on' ) || 'On';
		var offLabel = badge.getAttribute( 'data-context-off' ) || 'Off';

		function syncBadge() {
			var label = checkbox.checked ? onLabel : offLabel;
			while ( badge.firstChild ) {
				badge.removeChild( badge.firstChild );
			}
			badge.appendChild( document.createTextNode( label ) );
		}

		checkbox.addEventListener( 'change', syncBadge );
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
		initCompanionUpdateStatus();
		initAbilitySearch();
		initAbilityBulkControls();
		initDeclarativeToggles();
		initSkillEditorControls();
		initPromptLibrary();
		initApplyMcpSurface();
		initRunDiagnostics();
		initAppPasswordForm();
		initContextToggleBadge();
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
