// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * AI Abilities: search, switches and bulk actions without a page reload.
 *
 * The page works without this script: the bulk form posts to admin-post.php and the switches are not needed
 * to use it. With the script, a switch and a bulk action call the REST routes of AbilitiesRestApi (the same
 * capability, nonces and writer as the form handlers), say what happened in a toast or a notice, and offer Undo.
 * When the REST route cannot be reached the script hands the change back to the form. Text comes from the page
 * (data-strings); markup is built with textContent only.
 */
( function () {
	'use strict';

	var ui = window.Stonewright && window.Stonewright.ui;
	var root = document.querySelector( '[data-sw-abilities]' );
	if ( ! root || ! ui ) {
		return;
	}

	var strings = {};
	try {
		strings = JSON.parse( root.getAttribute( 'data-strings' ) || '{}' );
	} catch ( error ) {
		strings = {};
	}
	var restUrl = ( root.getAttribute( 'data-rest-url' ) || '' ).replace( /\/$/, '' );
	var restNonce = root.getAttribute( 'data-rest-nonce' ) || '';
	var toggleNonce = root.getAttribute( 'data-toggle-nonce' ) || '';
	var bulkNonce = root.getAttribute( 'data-bulk-nonce' ) || '';

	var form = root.querySelector( '#stonewright-bulk-form' );
	var toggleForm = root.querySelector( '[data-sw-abilities-toggle-form]' );
	var search = root.querySelector( '#stonewright-ability-search' );
	var actionSelect = root.querySelector( '#stonewright-bulk-action' );
	var categorySelect = root.querySelector( '#stonewright-bulk-category' );
	var applyButton = root.querySelector( '[data-sw-abilities-apply]' );
	var selectAll = root.querySelector( '[data-sw-abilities-select-all]' );
	var selectedOut = root.querySelector( '[data-sw-abilities-selected]' );
	var errorOut = root.querySelector( '[data-sw-abilities-error]' );
	var shownOut = root.querySelector( '[data-sw-abilities-shown]' );
	var statsOut = root.querySelector( '[data-sw-abilities-stats]' );
	var emptyBox = root.querySelector( '[data-sw-abilities-empty]' );
	var notices = root.querySelector( '[data-sw-abilities-notices]' );
	var clearButton = root.querySelector( '[data-sw-abilities-clear]' );

	function t( key, fallback ) {
		return strings[ key ] || fallback || '';
	}

	/** Fill %d, %s, %1$d and %2$d in a translated string. */
	function fmt( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return String( template ).replace( /%(?:(\d+)\$)?[ds]/g, function ( match, position ) {
			var value = position ? args[ Number( position ) - 1 ] : args[ next++ ];
			return value === undefined ? '' : String( value );
		} );
	}

	// -------------------------------------------------------------------------------------------
	// Rows
	// -------------------------------------------------------------------------------------------

	var rows = Array.prototype.map.call( root.querySelectorAll( '[data-sw-ability]' ), function ( main ) {
		var tr = main.closest( 'tr' );
		return {
			main: main,
			tr: tr,
			name: main.getAttribute( 'data-sw-ability' ),
			live: main.getAttribute( 'data-live' ) !== '0',
			kind: main.getAttribute( 'data-kind' ),
			label: main.querySelector( '.sw-ui-table__primary' ),
			tool: main.querySelector( '.sw-abilities__tool' ),
			sw: tr.querySelector( '[data-sw-ability-switch]' ),
			box: tr.querySelector( '[data-sw-ability-select]' ),
			state: tr.querySelector( '[data-sw-ability-state]' ),
			haystack: [ main.getAttribute( 'data-sw-ability' ), main.getAttribute( 'data-ability-label' ), main.getAttribute( 'data-tool' ), main.getAttribute( 'data-category' ), main.getAttribute( 'data-kind' ) ].join( ' ' ).toLowerCase(),
			details: main.closest( 'details.sw-abilities__category' ),
		};
	} );
	var byName = {};
	rows.forEach( function ( row ) {
		byName[ row.name ] = row;
	} );
	var categories = Array.prototype.slice.call( root.querySelectorAll( 'details.sw-abilities__category' ) );
	var providers = Array.prototype.slice.call( root.querySelectorAll( '.sw-abilities__provider' ) );

	function isOn( row ) {
		return !! ( row.sw && row.sw.checked );
	}

	function setRow( row, enabled ) {
		if ( ! row.sw ) {
			return;
		}
		row.sw.checked = enabled;
		if ( row.state ) {
			row.state.textContent = enabled ? t( 'on', 'On' ) : t( 'off', 'Off' );
		}
	}

	function plural( count, one, other ) {
		return fmt( count === 1 ? t( one ) : t( other ), count );
	}

	// -------------------------------------------------------------------------------------------
	// Counts
	// -------------------------------------------------------------------------------------------

	function recount() {
		var total = rows.length;
		var enabled = 0;
		var write = 0;
		var read = 0;
		rows.forEach( function ( row ) {
			if ( isOn( row ) && row.live ) {
				enabled++;
			}
			if ( row.kind === 'read' ) {
				read++;
			} else {
				write++;
			}
		} );

		function badge( container, scope ) {
			var inGroup = rows.filter( scope );
			var on = inGroup.filter( function ( row ) {
				return isOn( row ) && row.live;
			} ).length;
			var node = container;
			if ( node ) {
				node.textContent = fmt( t( 'count_words', '%1$d of %2$d on' ), on, inGroup.length );
			}
		}

		categories.forEach( function ( details ) {
			badge( details.querySelector( '[data-sw-category-count]' ), function ( row ) {
				return row.details === details;
			} );
		} );
		providers.forEach( function ( section ) {
			badge( section.querySelector( '[data-sw-provider-count]' ), function ( row ) {
				return row.details && row.details.closest( '.sw-abilities__provider' ) === section;
			} );
		} );

		if ( statsOut ) {
			statsOut.textContent = fmt( t( 'enabled_stats', 'Enabled %1$d · Write %2$d · Read %3$d' ), enabled, write, read );
		}
		return total;
	}

	// -------------------------------------------------------------------------------------------
	// Search
	// -------------------------------------------------------------------------------------------

	function clearHighlights( node ) {
		if ( ! node ) {
			return;
		}
		Array.prototype.forEach.call( node.querySelectorAll( 'mark' ), function ( mark ) {
			mark.replaceWith( document.createTextNode( mark.textContent || '' ) );
		} );
		node.normalize();
	}

	function highlight( node, query ) {
		if ( ! node || ! query ) {
			return;
		}
		var text = node.textContent || '';
		var index = text.toLowerCase().indexOf( query );
		if ( index === -1 ) {
			return;
		}
		var mark = document.createElement( 'mark' );
		mark.textContent = text.slice( index, index + query.length );
		node.textContent = '';
		node.appendChild( document.createTextNode( text.slice( 0, index ) ) );
		node.appendChild( mark );
		node.appendChild( document.createTextNode( text.slice( index + query.length ) ) );
	}

	var searching = false;

	function applySearch() {
		var query = search ? search.value.toLowerCase().trim() : '';
		var visible = 0;

		rows.forEach( function ( row ) {
			var match = ! query || row.haystack.indexOf( query ) !== -1;
			row.tr.hidden = ! match;
			clearHighlights( row.label );
			clearHighlights( row.tool );
			if ( match ) {
				visible++;
				highlight( row.label, query );
				highlight( row.tool, query );
			}
		} );

		// A category with a match opens while the search lasts and goes back to how it was afterwards.
		categories.forEach( function ( details ) {
			var any = rows.some( function ( row ) {
				return row.details === details && ! row.tr.hidden;
			} );
			if ( query ) {
				if ( ! searching ) {
					details.setAttribute( 'data-sw-was-open', details.open ? '1' : '0' );
				}
				details.hidden = ! any;
				details.open = any;
			} else {
				details.hidden = false;
				if ( details.getAttribute( 'data-sw-was-open' ) !== null ) {
					details.open = details.getAttribute( 'data-sw-was-open' ) === '1';
					details.removeAttribute( 'data-sw-was-open' );
				}
			}
		} );
		providers.forEach( function ( section ) {
			var any = categories.some( function ( details ) {
				return section.contains( details ) && ! details.hidden;
			} );
			section.hidden = !! query && ! any && categories.some( function ( details ) {
				return section.contains( details );
			} );
		} );
		searching = !! query;

		if ( shownOut ) {
			shownOut.textContent = query
				? fmt( t( 'of_total', '%1$d of %2$d abilities' ), visible, rows.length )
				: plural( rows.length, 'abilities_one', 'abilities_other' );
		}
		if ( emptyBox ) {
			emptyBox.hidden = visible > 0 || ! query;
			var title = emptyBox.querySelector( '.sw-ui-empty__title' );
			if ( title && query ) {
				title.textContent = fmt( t( 'no_match', 'No abilities match “%s”' ), search.value.trim() );
			}
		}
		syncSelectAll();
	}

	if ( search ) {
		search.addEventListener( 'input', applySearch );
		search.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && search.value ) {
				search.value = '';
				applySearch();
			}
		} );
	}
	if ( clearButton ) {
		clearButton.addEventListener( 'click', function () {
			search.value = '';
			applySearch();
			search.focus();
		} );
	}

	// -------------------------------------------------------------------------------------------
	// Selection
	// -------------------------------------------------------------------------------------------

	function selectedRows() {
		return rows.filter( function ( row ) {
			return row.box && row.box.checked;
		} );
	}

	function visibleRows() {
		return rows.filter( function ( row ) {
			return ! row.tr.hidden && ! ( row.details && row.details.hidden );
		} );
	}

	function syncSelectAll() {
		var chosen = selectedRows().length;
		if ( selectedOut ) {
			selectedOut.textContent = chosen ? fmt( t( 'selected', '%d selected' ), chosen ) : '';
		}
		if ( selectAll ) {
			var shown = visibleRows();
			var checked = shown.filter( function ( row ) {
				return row.box && row.box.checked;
			} ).length;
			selectAll.checked = shown.length > 0 && checked === shown.length;
			selectAll.indeterminate = checked > 0 && checked < shown.length;
		}
	}

	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			visibleRows().forEach( function ( row ) {
				if ( row.box ) {
					row.box.checked = selectAll.checked;
				}
			} );
			clearError();
			syncSelectAll();
		} );
	}

	root.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( target && target.matches && target.matches( '[data-sw-ability-select]' ) ) {
			clearError();
			syncSelectAll();
		}
	} );

	// -------------------------------------------------------------------------------------------
	// Messages
	// -------------------------------------------------------------------------------------------

	function showError( message, field ) {
		if ( errorOut ) {
			errorOut.textContent = message;
			errorOut.hidden = false;
		}
		[ actionSelect, categorySelect ].forEach( function ( control ) {
			if ( control ) {
				control.removeAttribute( 'aria-invalid' );
			}
		} );
		if ( field ) {
			field.setAttribute( 'aria-invalid', 'true' );
			field.focus();
		}
		ui.announce( message, 'assertive' );
	}

	function clearError() {
		if ( errorOut && ! errorOut.hidden ) {
			errorOut.hidden = true;
			errorOut.textContent = '';
		}
		[ actionSelect, categorySelect ].forEach( function ( control ) {
			if ( control ) {
				control.removeAttribute( 'aria-invalid' );
			}
		} );
	}

	/** A failure stays until the page is left: it is a notice, not a toast. */
	function failure( message ) {
		if ( ! notices ) {
			return;
		}
		Array.prototype.forEach.call( notices.querySelectorAll( '[data-sw-abilities-failure]' ), function ( old ) {
			old.remove();
		} );
		var notice = ui.notify( notices, { variant: 'danger', title: t( 'error_title', 'The change was not saved' ), text: message } );
		notice.setAttribute( 'data-sw-abilities-failure', '' );
		notice.setAttribute( 'tabindex', '-1' );
	}

	// -------------------------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------------------------

	/**
	 * Resolves { ok, status, data }. Rejects only when the server could not be reached at all (a network error).
	 * A response that is not JSON means the route is blocked or missing, and the caller falls back to the form.
	 */
	function request( path, options ) {
		options = options || {};
		var init = {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': restNonce, Accept: 'application/json' },
		};
		if ( options.body ) {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( options.body );
		}
		// With plain permalinks the route is a query value already (?rest_route=...), so more arguments follow an &.
		var address = restUrl + path.replace( '?', restUrl.indexOf( '?' ) === -1 ? '?' : '&' );
		return window.fetch( address, init ).then( function ( response ) {
			return response.json().then(
				function ( data ) {
					return { ok: response.ok, status: response.status, data: data || {}, json: true };
				},
				function () {
					return { ok: false, status: response.status, data: {}, json: false };
				}
			);
		} );
	}

	function refusal( result ) {
		var code = result.data && result.data.code;
		if ( code === 'stonewright_abilities_invalid_nonce' || code === 'rest_cookie_invalid_nonce' ) {
			return t( 'error_expired', 'This page has expired. Reload it and try again.' );
		}
		return ( result.data && result.data.message ) || t( 'error_generic', 'The server refused the change.' );
	}

	// -------------------------------------------------------------------------------------------
	// Switches
	// -------------------------------------------------------------------------------------------

	function fallbackToggle( name, enabled ) {
		if ( ! toggleForm ) {
			return;
		}
		toggleForm.querySelector( '[name="ability_name"]' ).value = name;
		toggleForm.querySelector( '[name="ability_enabled"]' ).value = enabled ? '1' : '';
		toggleForm.submit();
	}

	function labelOf( row ) {
		return row.main.getAttribute( 'data-ability-label' ) || row.name;
	}

	function sendToggle( row, enabled, quiet ) {
		row.tr.setAttribute( 'aria-busy', 'true' );
		return request( '/toggle', { method: 'POST', body: { name: row.name, enabled: enabled, action_nonce: toggleNonce } } ).then(
			function ( result ) {
				row.tr.removeAttribute( 'aria-busy' );
				if ( ! result.json && result.status >= 400 ) {
					fallbackToggle( row.name, enabled );
					return false;
				}
				if ( ! result.ok ) {
					setRow( row, ! enabled );
					recount();
					failure( refusal( result ) );
					return false;
				}
				setRow( row, enabled );
				recount();
				ui.flash( row.tr );
				return true;
			},
			function () {
				row.tr.removeAttribute( 'aria-busy' );
				setRow( row, ! enabled );
				recount();
				failure( t( 'error_network', 'The server could not be reached. Check your connection and try again.' ) );
				return false;
			}
		);
	}

	root.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.matches || ! target.matches( '[data-sw-ability-switch]' ) ) {
			return;
		}
		var row = byName[ target.getAttribute( 'data-sw-ability-switch' ) ];
		if ( ! row ) {
			return;
		}
		var enabled = target.checked;
		// The switch has already moved: show it, then confirm it with the server.
		setRow( row, enabled );
		recount();
		sendToggle( row, enabled ).then( function ( saved ) {
			if ( ! saved ) {
				return;
			}
			ui.toast( fmt( enabled ? t( 'toggled_on', '%s turned on.' ) : t( 'toggled_off', '%s turned off.' ), labelOf( row ) ), {
				action: {
					label: t( 'undo', 'Undo' ),
					onClick: function () {
						setRow( row, ! enabled );
						recount();
						sendToggle( row, ! enabled ).then( function ( undone ) {
							if ( undone ) {
								ui.toast( t( 'undone', 'Change undone.' ) );
							}
						} );
					},
				},
			} );
		} );
	} );

	// -------------------------------------------------------------------------------------------
	// Bulk
	// -------------------------------------------------------------------------------------------

	var working = false;

	function setBusy( button, busy ) {
		working = busy;
		if ( ! button ) {
			return;
		}
		if ( busy ) {
			button.setAttribute( 'aria-busy', 'true' );
		} else {
			button.removeAttribute( 'aria-busy' );
		}
	}

	function sendBulk( action, category, names ) {
		return request( '/bulk', { method: 'POST', body: { action: action, category: category, abilities: names, action_nonce: bulkNonce } } );
	}

	function runBulk( action, category, names, button ) {
		if ( working ) {
			return;
		}
		setBusy( button, true );
		sendBulk( action, category, names ).then(
			function ( result ) {
				setBusy( button, false );
				if ( ! result.json && result.status >= 400 ) {
					// The route is blocked or missing: let the form do it.
					form.submit();
					return;
				}
				if ( ! result.ok ) {
					var code = result.data && result.data.code;
					if ( code === 'stonewright_bulk_no_action' || code === 'stonewright_bulk_no_selection' || code === 'stonewright_bulk_no_category' ) {
						showError( refusal( result ), code === 'stonewright_bulk_no_category' ? categorySelect : ( code === 'stonewright_bulk_no_action' ? actionSelect : null ) );
					} else {
						failure( refusal( result ) );
					}
					return;
				}

				var data = result.data;
				var before = {};
				( data.names || [] ).forEach( function ( name ) {
					if ( byName[ name ] ) {
						before[ name ] = isOn( byName[ name ] );
						setRow( byName[ name ], data.enabled );
						ui.flash( byName[ name ].tr );
					}
				} );
				recount();
				rows.forEach( function ( row ) {
					if ( row.box ) {
						row.box.checked = false;
					}
				} );
				clearError();
				syncSelectAll();

				ui.toast( data.message || '', {
					action: {
						label: t( 'undo', 'Undo' ),
						onClick: function () {
							undoBulk( before );
						},
					},
				} );
			},
			function () {
				setBusy( button, false );
				failure( t( 'error_network', 'The server could not be reached. Check your connection and try again.' ) );
			}
		);
	}

	/** Put every ability the bulk action touched back the way it was: two calls, one per previous state. */
	function undoBulk( before ) {
		var wasOn = Object.keys( before ).filter( function ( name ) {
			return before[ name ];
		} );
		var wasOff = Object.keys( before ).filter( function ( name ) {
			return ! before[ name ];
		} );
		var calls = [];
		if ( wasOn.length ) {
			calls.push( sendBulk( 'enable_selected', '', wasOn ) );
		}
		if ( wasOff.length ) {
			calls.push( sendBulk( 'disable_selected', '', wasOff ) );
		}
		Promise.all( calls ).then(
			function ( results ) {
				var failed = results.filter( function ( result ) {
					return ! result.ok;
				} )[ 0 ];
				if ( failed ) {
					failure( refusal( failed ) );
					return;
				}
				Object.keys( before ).forEach( function ( name ) {
					if ( byName[ name ] ) {
						setRow( byName[ name ], before[ name ] );
						ui.flash( byName[ name ].tr );
					}
				} );
				recount();
				ui.toast( t( 'undone', 'Change undone.' ) );
			},
			function () {
				failure( t( 'error_network', 'The server could not be reached. Check your connection and try again.' ) );
			}
		);
	}

	function isCategoryAction( action ) {
		return action === 'enable_category' || action === 'disable_category';
	}

	function syncCategoryField() {
		if ( ! actionSelect || ! categorySelect ) {
			return;
		}
		categorySelect.disabled = ! isCategoryAction( actionSelect.value );
	}

	if ( actionSelect ) {
		actionSelect.addEventListener( 'change', function () {
			clearError();
			syncCategoryField();
		} );
		syncCategoryField();
	}
	if ( categorySelect ) {
		categorySelect.addEventListener( 'change', clearError );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var action = actionSelect ? actionSelect.value : '';
			var category = categorySelect ? categorySelect.value : '';
			var names = selectedRows().map( function ( row ) {
				return row.name;
			} );

			if ( ! action ) {
				showError( t( 'need_action' ), actionSelect );
				return;
			}
			if ( isCategoryAction( action ) && ! category ) {
				showError( t( 'need_category' ), categorySelect );
				return;
			}
			if ( ! isCategoryAction( action ) && ! names.length ) {
				showError( t( 'need_selection' ), null );
				return;
			}
			clearError();
			runBulk( action, isCategoryAction( action ) ? category : '', isCategoryAction( action ) ? [] : names, applyButton );
		} );
	}

	// The category buttons only work with script, so they are printed hidden and shown here.
	Array.prototype.forEach.call( root.querySelectorAll( '[data-sw-category-actions]' ), function ( box ) {
		box.hidden = false;
	} );
	root.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-sw-bulk-action]' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		runBulk( button.getAttribute( 'data-sw-bulk-action' ), button.getAttribute( 'data-sw-bulk-category' ) || '', [], button );
	} );

	// -------------------------------------------------------------------------------------------
	// Parameters, loaded when a row is opened
	// -------------------------------------------------------------------------------------------

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

	function renderParameters( body, parameters ) {
		body.textContent = '';
		if ( ! parameters.length ) {
			body.appendChild( el( 'p', '', t( 'params_none', 'No input parameters.' ) ) );
			return;
		}
		var list = el( 'dl', 'sw-ui-kv' );
		parameters.forEach( function ( parameter ) {
			var term = el( 'dt' );
			term.appendChild( el( 'code', '', parameter.name ) );
			if ( parameter.required ) {
				term.appendChild( document.createTextNode( ' ' ) );
				term.appendChild( el( 'span', 'sw-ui-tag', t( 'params_required', 'required' ) ) );
			}
			var detail = el( 'dd', '', parameter.type + ( parameter.description ? ' · ' + parameter.description : '' ) );
			list.appendChild( term );
			list.appendChild( detail );
		} );
		body.appendChild( list );
	}

	function loadParameters( details ) {
		var body = details.querySelector( '[data-sw-ability-params-body]' );
		var name = details.getAttribute( 'data-sw-ability-params' );
		if ( ! body || details.getAttribute( 'data-sw-loaded' ) === '1' ) {
			return;
		}
		body.setAttribute( 'aria-busy', 'true' );
		body.textContent = t( 'params_loading', 'Loading parameters…' );
		request( '/parameters?name=' + encodeURIComponent( name ) ).then(
			function ( result ) {
				body.removeAttribute( 'aria-busy' );
				if ( ! result.ok ) {
					throw new Error( 'refused' );
				}
				details.setAttribute( 'data-sw-loaded', '1' );
				renderParameters( body, result.data.parameters || [] );
			}
		).catch( function () {
			body.removeAttribute( 'aria-busy' );
			body.textContent = '';
			var message = el( 'p', 'sw-ui-field__error', t( 'params_error', 'Parameters could not be loaded.' ) );
			var retry = el( 'button', 'sw-ui-btn sw-ui-btn--sm', t( 'params_retry', 'Try again' ) );
			retry.type = 'button';
			retry.addEventListener( 'click', function () {
				loadParameters( details );
			} );
			body.appendChild( message );
			body.appendChild( retry );
		} );
	}

	// The toggle event does not bubble, so it is caught on the way down.
	root.addEventListener( 'toggle', function ( event ) {
		var details = event.target;
		if ( details && details.matches && details.matches( 'details[data-sw-ability-params]' ) && details.open ) {
			loadParameters( details );
		}
	}, true );

	recount();
	syncSelectAll();
}() );
