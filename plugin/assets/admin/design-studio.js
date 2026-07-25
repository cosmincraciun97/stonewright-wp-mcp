/**
 * Stonewright Design Studio.
 *
 * The page owns no design rules. Every read and every write goes through the
 * Design Studio REST routes, which delegate to the typed design abilities, so
 * the admin UI inherits their validation, backup, confirmation-token, audit,
 * and readback guarantees.
 *
 * Direction names, evidence strings, and repair hints are stored content, so
 * this file builds DOM nodes and assigns textContent rather than composing
 * markup. Confirmation happens in a review drawer, never in a native dialog.
 *
 * No third-party dependencies.
 */
( function () {
	'use strict';

	var boot = window.stonewrightDesignStudio || {};
	var root = document.querySelector( '[data-sw-design-studio]' );

	if ( ! root || ! boot.restRoot || ! window.fetch ) {
		return;
	}

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var DIRECTIONS_ROUTE = '/directions';
	var QUALITY_ROUTE = '/quality';
	var DRAFT_PREFIX = 'stonewright.design-studio.draft.';
	var DIALS = [ 'variance', 'density', 'motion' ];
	var VIEWS = Array.isArray( boot.views ) && boot.views.length
		? boot.views.slice()
		: [ 'overview', 'editor', 'quality', 'history' ];

	var statusNode = root.querySelector( '[data-sw-ds-status]' );
	var tabNodes = [].slice.call( root.querySelectorAll( '[data-sw-view]' ) );
	var panelNodes = [].slice.call( root.querySelectorAll( '[data-sw-panel]' ) );

	var state = {
		view: root.getAttribute( 'data-sw-current-view' ) || VIEWS[ 0 ],
		directions: [],
		activeId: 0,
		selectedId: boot.activeDirection ? Number( boot.activeDirection.id ) || 0 : 0,
		direction: null,
		versions: [],
		draft: null,
		errors: [],
		isDirty: false,
		recovered: false,
		postId: 0,
		reports: [],
		reportIndex: 0,
		filters: { severity: '', viewport: '', rule: '' },
		listLoaded: false,
		busy: false
	};

	var canWrite = !! ( boot.can && boot.can.manageDesign );

	/* ------------------------------------------------------------------ */
	/* DOM helpers                                                         */
	/* ------------------------------------------------------------------ */

	function el( tag, options, children ) {
		var node = document.createElement( tag );
		var config = options || {};
		var key;

		if ( config.className ) {
			node.className = config.className;
		}
		if ( typeof config.text !== 'undefined' && null !== config.text ) {
			node.textContent = String( config.text );
		}
		if ( config.attrs ) {
			for ( key in config.attrs ) {
				if ( Object.prototype.hasOwnProperty.call( config.attrs, key ) && null !== config.attrs[ key ] ) {
					node.setAttribute( key, String( config.attrs[ key ] ) );
				}
			}
		}
		if ( config.on ) {
			for ( key in config.on ) {
				if ( Object.prototype.hasOwnProperty.call( config.on, key ) ) {
					node.addEventListener( key, config.on[ key ] );
				}
			}
		}
		appendAll( node, children );

		return node;
	}

	function appendAll( parent, children ) {
		if ( ! children ) {
			return parent;
		}
		var list = Array.isArray( children ) ? children : [ children ];
		list.forEach( function ( child ) {
			if ( null === child || false === child || typeof child === 'undefined' ) {
				return;
			}
			parent.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );

		return parent;
	}

	function clear( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}

		return node;
	}

	var ICON_PATHS = {
		check: 'M3 8.4l3.3 3.3L13 4.6',
		plus: 'M8 3v10M3 8h10',
		sync: 'M13.4 7.2A5.4 5.4 0 003 6.2M2.6 8.8A5.4 5.4 0 0013 9.8M2.6 4.4v2.8h2.8M13.4 11.6V8.8h-2.8',
		clock: 'M8 4.2V8l2.4 1.6M14 8A6 6 0 112 8a6 6 0 0112 0z',
		alert: 'M8 5.6v3.2M8 11.2h.01M8 2.4l6 11.2H2z'
	};

	function icon( name ) {
		var svg = document.createElementNS( SVG_NS, 'svg' );
		var path = document.createElementNS( SVG_NS, 'path' );

		svg.setAttribute( 'class', 'sw-ds-button__icon' );
		svg.setAttribute( 'viewBox', '0 0 16 16' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		path.setAttribute( 'd', ICON_PATHS[ name ] || ICON_PATHS.check );
		path.setAttribute( 'stroke-linecap', 'round' );
		path.setAttribute( 'stroke-linejoin', 'round' );
		svg.appendChild( path );

		return svg;
	}

	function button( label, options ) {
		var config = options || {};
		var node = el(
			'button',
			{
				className: 'sw-ds-button' + ( config.variant ? ' sw-ds-button--' + config.variant : '' ),
				attrs: { type: 'button' },
				on: config.onClick ? { click: config.onClick } : null
			},
			[ config.icon ? icon( config.icon ) : null, el( 'span', { text: label } ) ]
		);

		if ( config.disabled ) {
			node.disabled = true;
		}
		if ( config.hint ) {
			node.setAttribute( 'title', config.hint );
		}

		return node;
	}

	function badge( label, tone ) {
		return el( 'span', { className: 'sw-ds-badge' + ( tone ? ' sw-ds-badge--' + tone : '' ), text: label } );
	}

	function fact( label, value ) {
		return el( 'li', { className: 'sw-ds-fact' }, [
			el( 'span', { className: 'sw-ds-fact__label', text: label } ),
			el( 'span', { className: 'sw-ds-fact__value', text: value } )
		] );
	}

	function shortHash( value ) {
		var hash = String( value || '' );

		return hash.length > 12 ? hash.slice( 0, 12 ) : hash;
	}

	function label( value, fallback ) {
		var text = String( typeof value === 'undefined' || null === value ? '' : value ).trim();

		return '' === text ? fallback : text;
	}

	/* ------------------------------------------------------------------ */
	/* Live region                                                         */
	/* ------------------------------------------------------------------ */

	function announce( message, tone ) {
		if ( ! statusNode ) {
			return;
		}
		statusNode.textContent = message ? String( message ) : '';
		if ( tone ) {
			statusNode.setAttribute( 'data-tone', tone );
		} else {
			statusNode.removeAttribute( 'data-tone' );
		}
	}

	function emit( name, detail ) {
		root.dispatchEvent( new CustomEvent( name, { bubbles: true, detail: detail || {} } ) );
	}

	/* ------------------------------------------------------------------ */
	/* Transport                                                           */
	/* ------------------------------------------------------------------ */

	function readBody( response ) {
		return response.text().then( function ( body ) {
			if ( ! body ) {
				return {};
			}
			try {
				return JSON.parse( body );
			} catch ( error ) {
				return {};
			}
		} );
	}

	function failure( payload, status ) {
		var message = payload && payload.message ? String( payload.message ) : 'Request failed (' + status + ').';
		var error = new Error( message );

		error.code = payload && payload.code ? String( payload.code ) : '';
		error.payload = payload || {};

		return error;
	}

	function get( path ) {
		return window.fetch( boot.restRoot + path, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-WP-Nonce': boot.nonce || '' }
		} ).then( function ( response ) {
			return readBody( response ).then( function ( payload ) {
				if ( ! response.ok ) {
					throw failure( payload, response.status );
				}

				return payload;
			} );
		} );
	}

	function post( path, body ) {
		return window.fetch( boot.restRoot + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce': boot.nonce || ''
			},
			body: JSON.stringify( body || {} )
		} ).then( function ( response ) {
			return readBody( response ).then( function ( payload ) {
				if ( ! response.ok ) {
					throw failure( payload, response.status );
				}

				return payload;
			} );
		} );
	}

	function busy( isBusy ) {
		state.busy = !! isBusy;
		root.setAttribute( 'aria-busy', state.busy ? 'true' : 'false' );
	}

	/* ------------------------------------------------------------------ */
	/* Review drawer                                                       */
	/* ------------------------------------------------------------------ */

	var openScrim = null;
	var lastFocused = null;

	function focusableIn( node ) {
		return [].slice.call(
			node.querySelectorAll( 'a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])' )
		);
	}

	function trapFocus( drawer, event ) {
		var items = focusableIn( drawer );

		if ( ! items.length ) {
			event.preventDefault();
			drawer.focus();

			return;
		}

		var first = items[ 0 ];
		var last = items[ items.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	function restoreFocus() {
		if ( lastFocused && document.contains( lastFocused ) ) {
			lastFocused.focus();
		}
		lastFocused = null;
	}

	function closeDrawer() {
		if ( ! openScrim ) {
			return;
		}
		var scrim = openScrim;

		openScrim = null;
		document.removeEventListener( 'keydown', scrim.swKeydown, true );
		if ( scrim.parentNode ) {
			scrim.parentNode.removeChild( scrim );
		}
		restoreFocus();
	}

	/**
	 * Opens the review drawer. `options.rows` is the review summary the user
	 * confirms against: what changes, on which record, against which hash.
	 */
	function openDrawer( options ) {
		var config = options || {};

		closeDrawer();
		lastFocused = document.activeElement;

		var titleId = 'sw-ds-drawer-title';
		var rows = ( config.rows || [] ).map( function ( row ) {
			return el( 'li', null, [
				el( 'span', { className: 'sw-ds-review__key', text: row.key } ),
				el( 'span', {
					className: 'sw-ds-review__value' + ( row.tone ? ' sw-ds-review__value--' + row.tone : '' ),
					text: row.value
				} )
			] );
		} );

		var confirmButton = button( config.confirmLabel || 'Confirm', {
			variant: config.confirmTone || 'primary',
			icon: config.confirmIcon || 'check',
			onClick: function () {
				var result = config.onConfirm ? config.onConfirm() : null;

				closeDrawer();
				return result;
			}
		} );

		var drawer = el(
			'div',
			{
				className: 'sw-ds-drawer',
				attrs: {
					'data-sw-ds-drawer': '',
					role: 'dialog',
					'aria-modal': 'true',
					'aria-labelledby': titleId,
					tabindex: '-1'
				}
			},
			[
				el( 'h2', { className: 'sw-ds-drawer__title', text: config.title || 'Review', attrs: { id: titleId } } ),
				config.lede ? el( 'p', { className: 'sw-ds-drawer__lede', text: config.lede } ) : null,
				el( 'div', { className: 'sw-ds-drawer__body' }, [
					rows.length ? el( 'ul', { className: 'sw-ds-review' }, rows ) : null,
					config.extra || null
				] ),
				el( 'div', { className: 'sw-ds-drawer__footer' }, [
					button( config.cancelLabel || 'Cancel', {
						onClick: function () {
							closeDrawer();
							if ( config.onCancel ) {
								config.onCancel();
							}
						}
					} ),
					confirmButton
				] )
			]
		);

		var scrim = el( 'div', { className: 'sw-ds-scrim' }, [ drawer ] );

		scrim.addEventListener( 'click', function ( event ) {
			if ( event.target === scrim ) {
				closeDrawer();
				if ( config.onCancel ) {
					config.onCancel();
				}
			}
		} );

		scrim.swKeydown = function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				closeDrawer();
				if ( config.onCancel ) {
					config.onCancel();
				}
			} else if ( 'Tab' === event.key ) {
				trapFocus( drawer, event );
			}
		};

		document.body.appendChild( scrim );
		document.addEventListener( 'keydown', scrim.swKeydown, true );
		openScrim = scrim;

		window.requestAnimationFrame( function () {
			scrim.classList.add( 'is-open' );
			confirmButton.focus();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Contract helpers                                                    */
	/* ------------------------------------------------------------------ */

	function emptyContract() {
		return {
			schema_version: '1.0',
			identity: { name: '', summary: '' },
			tokens: { colors: {}, typography: {}, spacing: {}, radii: {}, elevation: {}, motion: {} },
			components: {},
			dials: { variance: 0, density: 0, motion: 0 },
			guidance: { do: [], avoid: [] },
			provenance: {},
			waivers: [],
			readiness: { ready: false, sync_ready: false, issues: [] }
		};
	}

	function contractOf( direction ) {
		if ( direction && direction.contract && typeof direction.contract === 'object' ) {
			return direction.contract;
		}

		return emptyContract();
	}

	function draftKey( id ) {
		return DRAFT_PREFIX + String( id || 0 );
	}

	function readStoredDraft( id ) {
		try {
			var raw = window.sessionStorage.getItem( draftKey( id ) );

			return raw ? JSON.parse( raw ) : null;
		} catch ( error ) {
			return null;
		}
	}

	function writeStoredDraft( id, draft ) {
		try {
			window.sessionStorage.setItem( draftKey( id ), JSON.stringify( draft ) );
		} catch ( error ) {
			// A full or blocked sessionStorage must not break editing.
		}
	}

	function dropStoredDraft( id ) {
		try {
			window.sessionStorage.removeItem( draftKey( id ) );
		} catch ( error ) {
			// Nothing to recover from; ignore.
		}
	}

	function draftFromDirection( direction ) {
		var contract = contractOf( direction );
		var identity = contract.identity || {};
		var dials = contract.dials || {};
		var guidance = contract.guidance || {};

		return {
			id: direction ? Number( direction.id ) || 0 : 0,
			slug: direction ? String( direction.slug || '' ) : '',
			status: direction ? String( direction.status || 'draft' ) : 'draft',
			name: String( identity.name || '' ),
			summary: String( identity.summary || '' ),
			variance: Number( dials.variance || 0 ),
			density: Number( dials.density || 0 ),
			motion: Number( dials.motion || 0 ),
			tokens: JSON.stringify( contract.tokens || {}, null, 2 ),
			guidanceDo: ( guidance.do || [] ).join( '\n' ),
			guidanceAvoid: ( guidance.avoid || [] ).join( '\n' )
		};
	}

	function linesOf( value ) {
		return String( value || '' )
			.split( '\n' )
			.map( function ( line ) {
				return line.trim();
			} )
			.filter( function ( line ) {
				return '' !== line;
			} );
	}

	function validateDraft( draft ) {
		var errors = [];

		if ( '' === String( draft.name || '' ).trim() ) {
			errors.push( { field: 'name', message: 'A direction needs a name.' } );
		}
		if ( draft.slug && ! /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test( draft.slug ) ) {
			errors.push( { field: 'slug', message: 'A slug uses lowercase letters, digits, and single hyphens.' } );
		}
		DIALS.forEach( function ( dial ) {
			var value = Number( draft[ dial ] );

			if ( ! isFinite( value ) || value < 0 || value > 100 || Math.floor( value ) !== value ) {
				errors.push( { field: dial, message: 'The ' + dial + ' dial is a whole number from 0 to 100.' } );
			}
		} );
		try {
			var tokens = JSON.parse( draft.tokens || '{}' );

			if ( ! tokens || typeof tokens !== 'object' || Array.isArray( tokens ) ) {
				errors.push( { field: 'tokens', message: 'Tokens must be a JSON object of token groups.' } );
			}
		} catch ( error ) {
			errors.push( { field: 'tokens', message: 'Tokens are not valid JSON: ' + error.message } );
		}

		return errors;
	}

	function contractFromDraft( draft ) {
		var contract = emptyContract();
		var tokens = {};

		try {
			tokens = JSON.parse( draft.tokens || '{}' );
		} catch ( error ) {
			tokens = {};
		}

		contract.identity = { name: String( draft.name || '' ).trim(), summary: String( draft.summary || '' ).trim() };
		contract.tokens = tokens && typeof tokens === 'object' ? tokens : {};
		contract.dials = {
			variance: Number( draft.variance ) || 0,
			density: Number( draft.density ) || 0,
			motion: Number( draft.motion ) || 0
		};
		contract.guidance = { do: linesOf( draft.guidanceDo ), avoid: linesOf( draft.guidanceAvoid ) };

		return contract;
	}

	function markDirty( isDirty ) {
		state.isDirty = !! isDirty;
		if ( state.isDirty && state.draft ) {
			writeStoredDraft( state.draft.id, state.draft );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Data loading                                                        */
	/* ------------------------------------------------------------------ */

	function loadDirections() {
		return get( DIRECTIONS_ROUTE ).then( function ( payload ) {
			state.directions = Array.isArray( payload.directions ) ? payload.directions : [];
			state.activeId = Number( payload.active_id ) || 0;
			state.listLoaded = true;
			if ( ! state.selectedId ) {
				state.selectedId = state.activeId || ( state.directions[ 0 ] ? Number( state.directions[ 0 ].id ) || 0 : 0 );
			}

			return state.directions;
		} );
	}

	function loadDirection( id ) {
		if ( ! id ) {
			state.direction = null;
			state.versions = [];

			return Promise.resolve( null );
		}

		return get( DIRECTIONS_ROUTE + '/' + encodeURIComponent( String( id ) ) ).then( function ( payload ) {
			state.direction = payload.direction || null;
			state.versions = Array.isArray( payload.versions ) ? payload.versions : [];

			return state.direction;
		} );
	}

	function loadReports( postId ) {
		return get( QUALITY_ROUTE + '?post_id=' + encodeURIComponent( String( postId ) ) ).then( function ( payload ) {
			state.reports = Array.isArray( payload.reports ) ? payload.reports : [];
			state.reportIndex = 0;

			return state.reports;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Views                                                               */
	/* ------------------------------------------------------------------ */

	function panelFor( view ) {
		var match = null;

		panelNodes.forEach( function ( node ) {
			if ( node.getAttribute( 'data-sw-panel' ) === view ) {
				match = node;
			}
		} );

		return match;
	}

	function pending( panel, message ) {
		clear( panel ).appendChild( el( 'div', { className: 'sw-ds-panel__loading', text: message } ) );
	}

	function renderError( panel, error ) {
		clear( panel ).appendChild(
			el( 'div', { className: 'sw-ds-error-summary' }, [
				el( 'h2', { text: 'That request was refused' } ),
				el( 'ul', null, [ el( 'li', { text: error && error.message ? error.message : 'Unknown error.' } ) ] )
			] )
		);
		announce( error && error.message ? error.message : 'Request failed.', 'danger' );
	}

	function specimen( contract ) {
		var colors = ( contract.tokens && contract.tokens.colors ) || {};
		var dials = contract.dials || {};
		var swatches = Object.keys( colors ).slice( 0, 8 ).map( function ( name ) {
			var chip = el( 'span', { className: 'sw-ds-swatch__chip' } );
			var value = colors[ name ];

			if ( typeof value === 'string' ) {
				chip.style.background = value;
			}

			return el( 'li', { className: 'sw-ds-swatch' }, [
				chip,
				el( 'span', { className: 'sw-ds-swatch__name', text: name } )
			] );
		} );

		var dialRows = DIALS.map( function ( name ) {
			var value = Math.max( 0, Math.min( 100, Number( dials[ name ] ) || 0 ) );
			var fill = el( 'span', { className: 'sw-ds-dial__fill' } );

			fill.style.width = value + '%';

			return el( 'div', { className: 'sw-ds-dial' }, [
				el( 'span', { text: name } ),
				el( 'span', { className: 'sw-ds-dial__track' }, [ fill ] ),
				el( 'span', { className: 'sw-ds-dial__value', text: String( value ) } )
			] );
		} );

		return el( 'div', { className: 'sw-ds-specimen' }, [
			el( 'p', { className: 'sw-ds-specimen__title', text: 'Specimen' } ),
			swatches.length
				? el( 'ul', { className: 'sw-ds-swatches' }, swatches )
				: el( 'p', { className: 'sw-ds-field__hint', text: 'No colour tokens recorded yet.' } ),
			el( 'div', { className: 'sw-ds-dials' }, dialRows )
		] );
	}

	function directionBadges( row ) {
		var badges = [ badge( label( row.status, 'draft' ), 'draft' === row.status ? 'draft' : 'info' ) ];

		if ( row.active ) {
			badges.push( badge( 'active', 'active' ) );
		}
		badges.push( badge( row.ready ? 'ready' : 'not ready', row.ready ? 'ok' : 'warn' ) );
		badges.push( badge( row.sync_ready ? 'sync ready' : 'sync blocked', row.sync_ready ? 'ok' : 'warn' ) );
		if ( row.issue_count ) {
			badges.push( badge( row.issue_count + ' issues', 'error' ) );
		}

		return el( 'div', { className: 'sw-ds-badges' }, badges );
	}

	function renderOverview( panel ) {
		pending( panel, 'Loading design directions.' );

		loadDirections()
			.then( function () {
				return loadDirection( state.selectedId );
			} )
			.then( function () {
				clear( panel );

				if ( ! state.directions.length ) {
					panel.appendChild(
						el( 'div', { className: 'sw-ds-empty' }, [
							el( 'h2', { text: 'No design direction yet' } ),
							el( 'p', {
								text: 'A direction records the tokens, dials, and guidance every rendered section is measured against. Create one and the quality checks gain something to compare with.'
							} ),
							canWrite
								? button( 'Create the first direction', {
									variant: 'primary',
									icon: 'plus',
									onClick: function () {
										state.selectedId = 0;
										state.direction = null;
										state.draft = null;
										requestView( 'editor' );
									}
								} )
								: el( 'p', { className: 'sw-ds-field__hint', text: 'Your account cannot create design directions.' } )
						] )
					);
					announce( 'No design direction is stored yet.' );

					return;
				}

				var row = state.directions.filter( function ( item ) {
					return Number( item.id ) === state.selectedId;
				} )[ 0 ] || state.directions[ 0 ];

				state.selectedId = Number( row.id ) || 0;

				var contract = contractOf( state.direction );

				panel.appendChild(
					el( 'div', { className: 'sw-ds-hero' }, [
						el( 'div', { className: 'sw-ds-card' }, [
							el( 'h2', { className: 'sw-ds-card__title', text: label( row.name, label( row.slug, 'Untitled direction' ) ) } ),
							el( 'p', {
								className: 'sw-ds-card__meta',
								text: 'revision ' + row.revision + ' · ' + shortHash( row.contract_hash ) + ' · updated ' + label( row.updated_at, 'unknown' )
							} ),
							directionBadges( row ),
							el( 'ul', { className: 'sw-ds-hero__facts' }, [
								fact( 'Slug', label( row.slug, 'none' ) ),
								fact( 'Source', label( row.source_type, 'manual' ) ),
								fact( 'Revision', String( row.revision ) ),
								fact( 'Issues', String( row.issue_count ) )
							] ),
							overviewActions( row )
						] ),
						specimen( contract )
					] )
				);

				panel.appendChild( directionPicker() );
				announce( 'Loaded ' + state.directions.length + ' design directions.' );
			} )
			.catch( function ( error ) {
				renderError( panel, error );
			} );
	}

	function directionPicker() {
		var select = el( 'select', { attrs: { id: 'sw-ds-direction-picker' } } );

		state.directions.forEach( function ( row ) {
			var option = el( 'option', { text: label( row.name, row.slug ) + ( row.active ? ' (active)' : '' ) } );

			option.value = String( row.id );
			if ( Number( row.id ) === state.selectedId ) {
				option.selected = true;
			}
			select.appendChild( option );
		} );

		select.addEventListener( 'change', function () {
			state.selectedId = Number( select.value ) || 0;
			state.direction = null;
			state.draft = null;
			state.isDirty = false;
			render( state.view );
		} );

		return el( 'div', { className: 'sw-ds-filters' }, [
			el( 'div', { className: 'sw-ds-filter' }, [
				el( 'label', { text: 'Direction', attrs: { for: 'sw-ds-direction-picker' } } ),
				select
			] )
		] );
	}

	function overviewActions( row ) {
		var actions = [
			button( 'Open in editor', {
				onClick: function () {
					requestView( 'editor' );
				}
			} )
		];

		if ( canWrite ) {
			actions.push(
				button( 'Activate', {
					variant: 'primary',
					icon: 'check',
					disabled: !! row.active,
					hint: row.active ? 'This direction is already active.' : '',
					onClick: function () {
						reviewActivate( row );
					}
				} )
			);
			actions.push(
				button( 'Plan Elementor sync', {
					icon: 'sync',
					onClick: function () {
						runSyncPlan( row );
					}
				} )
			);
		}

		return el( 'div', { className: 'sw-ds-actions' }, actions );
	}

	/* ---------------------------- Editor ------------------------------ */

	function field( config ) {
		var id = 'sw-ds-field-' + config.name;
		var control;

		if ( 'textarea' === config.type ) {
			control = el( 'textarea', { attrs: { id: id, rows: String( config.rows || 6 ) } } );
			control.value = config.value || '';
		} else {
			control = el( 'input', { attrs: { id: id, type: config.type || 'text' } } );
			if ( 'number' === config.type ) {
				control.setAttribute( 'min', '0' );
				control.setAttribute( 'max', '100' );
				control.setAttribute( 'step', '1' );
			}
			control.value = typeof config.value === 'undefined' ? '' : String( config.value );
		}

		var error = el( 'span', { className: 'sw-ds-field__error', attrs: { id: id + '-error' } } );
		var wrapper = el( 'div', { className: 'sw-ds-field', attrs: { 'data-sw-field': config.name } }, [
			el( 'label', { className: 'sw-ds-field__label', text: config.label, attrs: { for: id } } ),
			config.hint ? el( 'span', { className: 'sw-ds-field__hint', text: config.hint } ) : null,
			control,
			error
		] );

		control.addEventListener( 'input', function () {
			config.onInput( control.value );
			markDirty( true );
			refreshValidation();
		} );

		return wrapper;
	}

	function refreshValidation() {
		var panel = panelFor( 'editor' );

		if ( ! panel || ! state.draft ) {
			return;
		}

		state.errors = validateDraft( state.draft );

		var byField = {};

		state.errors.forEach( function ( item ) {
			byField[ item.field ] = item.message;
		} );

		[].slice.call( panel.querySelectorAll( '[data-sw-field]' ) ).forEach( function ( node ) {
			var name = node.getAttribute( 'data-sw-field' );
			var message = byField[ name ] || '';
			var errorNode = node.querySelector( '.sw-ds-field__error' );
			var control = node.querySelector( 'input, textarea, select' );

			node.classList.toggle( 'is-invalid', '' !== message );
			if ( errorNode ) {
				errorNode.textContent = message;
			}
			if ( control ) {
				control.setAttribute( 'aria-invalid', '' !== message ? 'true' : 'false' );
			}
		} );

		var summary = panel.querySelector( '[data-sw-ds-error-summary]' );

		if ( summary ) {
			clear( summary );
			if ( state.errors.length ) {
				summary.hidden = false;
				summary.appendChild( el( 'h2', { text: state.errors.length + ' fields need attention' } ) );
				summary.appendChild(
					el(
						'ul',
						null,
						state.errors.map( function ( item ) {
							return el( 'li', null, [
								el( 'a', {
									text: item.message,
									attrs: { href: '#sw-ds-field-' + item.field }
								} )
							] );
						} )
					)
				);
			} else {
				summary.hidden = true;
			}
		}

		var save = panel.querySelector( '[data-sw-ds-save]' );

		if ( save ) {
			save.disabled = state.errors.length > 0 || ! canWrite;
		}
	}

	function renderEditor( panel ) {
		if ( state.selectedId && ! state.direction ) {
			pending( panel, 'Loading the direction.' );
			loadDirection( state.selectedId )
				.then( function () {
					renderEditor( panel );
				} )
				.catch( function ( error ) {
					renderError( panel, error );
				} );

			return;
		}

		if ( ! state.draft ) {
			var stored = readStoredDraft( state.selectedId );

			state.draft = draftFromDirection( state.direction );
			state.recovered = false;
			if ( stored && JSON.stringify( stored ) !== JSON.stringify( state.draft ) ) {
				state.recovered = true;
			}
			state.stored = stored;
		}

		var draft = state.draft;

		clear( panel );

		if ( state.recovered ) {
			panel.appendChild(
				el( 'div', { className: 'sw-ds-recovery' }, [
					el( 'span', { text: 'An unsaved draft from this browser session is available for this direction.' } ),
					button( 'Restore draft', {
						onClick: function () {
							state.draft = state.stored;
							state.recovered = false;
							markDirty( true );
							render( 'editor' );
						}
					} ),
					button( 'Discard draft', {
						onClick: function () {
							dropStoredDraft( state.selectedId );
							state.recovered = false;
							render( 'editor' );
						}
					} )
				] )
			);
		}

		var summary = el( 'div', { className: 'sw-ds-error-summary', attrs: { 'data-sw-ds-error-summary': '', role: 'alert' } } );

		summary.hidden = true;
		panel.appendChild( summary );

		var identity = el( 'fieldset', { className: 'sw-ds-fieldset' }, [
			el( 'legend', { text: 'Identity' } ),
			field( {
				name: 'name',
				label: 'Name',
				value: draft.name,
				onInput: function ( value ) {
					draft.name = value;
				}
			} ),
			field( {
				name: 'slug',
				label: 'Slug',
				hint: 'Optional. Defaults to the name.',
				value: draft.slug,
				onInput: function ( value ) {
					draft.slug = value;
				}
			} ),
			field( {
				name: 'summary',
				label: 'Summary',
				type: 'textarea',
				rows: 3,
				value: draft.summary,
				onInput: function ( value ) {
					draft.summary = value;
				}
			} )
		] );

		var dials = el(
			'fieldset',
			{ className: 'sw-ds-fieldset' },
			[ el( 'legend', { text: 'Dials' } ) ].concat(
				DIALS.map( function ( name ) {
					return field( {
						name: name,
						label: name.charAt( 0 ).toUpperCase() + name.slice( 1 ),
						type: 'number',
						hint: 'Whole number, 0 to 100.',
						value: draft[ name ],
						onInput: function ( value ) {
							draft[ name ] = '' === value ? '' : Number( value );
							updateSpecimen();
						}
					} );
				} )
			)
		);

		var tokens = el( 'fieldset', { className: 'sw-ds-fieldset' }, [
			el( 'legend', { text: 'Tokens' } ),
			field( {
				name: 'tokens',
				label: 'Token groups',
				type: 'textarea',
				rows: 14,
				hint: 'JSON object. Only the allowlisted groups are stored: colors, typography, spacing, radii, elevation, motion.',
				value: draft.tokens,
				onInput: function ( value ) {
					draft.tokens = value;
					updateSpecimen();
				}
			} )
		] );

		var guidance = el( 'fieldset', { className: 'sw-ds-fieldset' }, [
			el( 'legend', { text: 'Guidance' } ),
			field( {
				name: 'guidanceDo',
				label: 'Do',
				type: 'textarea',
				rows: 4,
				hint: 'One instruction per line.',
				value: draft.guidanceDo,
				onInput: function ( value ) {
					draft.guidanceDo = value;
				}
			} ),
			field( {
				name: 'guidanceAvoid',
				label: 'Avoid',
				type: 'textarea',
				rows: 4,
				hint: 'One instruction per line.',
				value: draft.guidanceAvoid,
				onInput: function ( value ) {
					draft.guidanceAvoid = value;
				}
			} )
		] );

		var save = button( 'Review and save', {
			variant: 'primary',
			icon: 'check',
			onClick: function () {
				reviewSave();
			}
		} );

		save.setAttribute( 'data-sw-ds-save', '' );

		var main = el( 'div', { className: 'sw-ds-editor__main' }, [
			identity,
			dials,
			tokens,
			guidance,
			el( 'div', { className: 'sw-ds-actions' }, [
				save,
				button( 'Revert to stored', {
					onClick: function () {
						state.draft = draftFromDirection( state.direction );
						dropStoredDraft( state.selectedId );
						markDirty( false );
						render( 'editor' );
						announce( 'Reverted to the stored contract.' );
					}
				} )
			] )
		] );

		var aside = el( 'div', { className: 'sw-ds-editor__aside', attrs: { 'data-sw-ds-aside': '' } }, [
			specimen( contractFromDraft( draft ) ),
			provenanceInspector()
		] );

		panel.appendChild( el( 'div', { className: 'sw-ds-editor' }, [ main, aside ] ) );
		refreshValidation();
	}

	function updateSpecimen() {
		var panel = panelFor( 'editor' );
		var aside = panel ? panel.querySelector( '[data-sw-ds-aside]' ) : null;

		if ( ! aside || ! state.draft ) {
			return;
		}
		clear( aside );
		aside.appendChild( specimen( contractFromDraft( state.draft ) ) );
		aside.appendChild( provenanceInspector() );
	}

	function provenanceInspector() {
		var direction = state.direction || {};
		var contract = contractOf( state.direction );
		var provenance = contract.provenance || {};
		var rows = [
			{ key: 'Revision', value: String( direction.revision || 0 ) },
			{ key: 'Contract hash', value: shortHash( direction.contract_hash ) },
			{ key: 'Source', value: label( direction.source_type, 'manual' ) },
			{ key: 'Updated', value: label( direction.updated_at, 'never' ) }
		];

		Object.keys( provenance ).slice( 0, 8 ).forEach( function ( key ) {
			var value = provenance[ key ];

			rows.push( { key: key, value: typeof value === 'string' ? value : JSON.stringify( value ) } );
		} );

		return el( 'div', { className: 'sw-ds-card' }, [
			el( 'h3', { className: 'sw-ds-card__title', text: 'Provenance' } ),
			el(
				'ul',
				{ className: 'sw-ds-provenance' },
				rows.map( function ( row ) {
					return el( 'li', null, [
						el( 'span', { className: 'sw-ds-provenance__key', text: row.key } ),
						el( 'span', { className: 'sw-ds-provenance__value', text: row.value } )
					] );
				} )
			)
		] );
	}

	function reviewSave() {
		if ( ! state.draft || state.errors.length ) {
			announce( 'Fix the highlighted fields before saving.', 'danger' );

			return;
		}

		var draft = state.draft;
		var contract = contractFromDraft( draft );

		openDrawer( {
			title: 'Save this direction',
			lede: 'The contract is validated server-side before anything is stored. A new revision is recorded, and the previous one stays restorable.',
			rows: [
				{ key: 'Name', value: contract.identity.name },
				{ key: 'Slug', value: label( draft.slug, 'derived from the name' ) },
				{ key: 'Direction', value: draft.id ? 'id ' + draft.id : 'new record' },
				{ key: 'Current revision', value: String( ( state.direction && state.direction.revision ) || 0 ) },
				{ key: 'Token groups', value: Object.keys( contract.tokens ).join( ', ' ) || 'none' },
				{ key: 'Guidance', value: contract.guidance.do.length + ' do, ' + contract.guidance.avoid.length + ' avoid' }
			],
			confirmLabel: 'Save direction',
			onConfirm: function () {
				var body = { contract: contract };

				if ( draft.slug ) {
					body.slug = draft.slug;
				}
				busy( true );
				announce( 'Saving the direction.' );
				post( DIRECTIONS_ROUTE, body )
					.then( function ( payload ) {
						busy( false );
						state.selectedId = Number( payload.id ) || state.selectedId;
						state.direction = null;
						state.draft = null;
						markDirty( false );
						dropStoredDraft( draft.id );
						dropStoredDraft( state.selectedId );
						announce(
							'Saved revision ' + payload.revision + ' (' + shortHash( payload.contract_hash ) + ').',
							payload.effect_verified ? 'ok' : 'warn'
						);
						emit( 'stonewright:direction-saved', payload );
						render( 'editor' );
					} )
					.catch( function ( error ) {
						busy( false );
						announce( error.message, 'danger' );
					} );
			}
		} );
	}

	function reviewActivate( row ) {
		openDrawer( {
			title: 'Activate this direction',
			lede: 'The active direction is the one every quality check and every Elementor sync reads. Activation is recorded in the audit log and can be reversed by activating the previous direction.',
			rows: [
				{ key: 'Direction', value: label( row.name, row.slug ) },
				{ key: 'Revision', value: String( row.revision ) },
				{ key: 'Contract hash', value: shortHash( row.contract_hash ) },
				{ key: 'Readiness', value: row.ready ? 'ready' : 'not ready', tone: row.ready ? null : 'warn' },
				{ key: 'Currently active', value: state.activeId ? 'id ' + state.activeId : 'none' }
			],
			confirmLabel: 'Activate',
			onConfirm: function () {
				busy( true );
				post( DIRECTIONS_ROUTE + '/' + encodeURIComponent( String( row.id ) ) + '/activate', {} )
					.then( function ( payload ) {
						busy( false );
						state.activeId = Number( payload.active_id ) || 0;
						announce( 'Activated direction ' + payload.id + '.', 'ok' );
						emit( 'stonewright:direction-activated', payload );
						render( state.view );
					} )
					.catch( function ( error ) {
						busy( false );
						announce( error.message, 'danger' );
					} );
			}
		} );
	}

	function runSyncPlan( row ) {
		busy( true );
		announce( 'Planning the Elementor kit sync.' );
		post( DIRECTIONS_ROUTE + '/' + encodeURIComponent( String( row.id ) ) + '/sync-plan', {} )
			.then( function ( plan ) {
				busy( false );
				reviewSyncApply( row, plan );
			} )
			.catch( function ( error ) {
				busy( false );
				announce( error.message, 'danger' );
			} );
	}

	function reviewSyncApply( row, plan ) {
		var operations = Array.isArray( plan.operations ) ? plan.operations : [];
		var warnings = Array.isArray( plan.warnings ) ? plan.warnings : [];
		var blocked = Array.isArray( plan.blocked ) ? plan.blocked : [];
		var extra = null;

		if ( blocked.length ) {
			extra = el(
				'ul',
				{ className: 'sw-ds-findings' },
				blocked.slice( 0, 10 ).map( function ( item ) {
					return el( 'li', { className: 'sw-ds-finding' }, [
						el( 'span', { className: 'sw-ds-finding__rule', text: label( item.path || item.reason, 'blocked' ) } ),
						el( 'p', { className: 'sw-ds-finding__repair', text: label( item.reason || item.message, '' ) } )
					] );
				} )
			);
		}

		openDrawer( {
			title: 'Apply this sync to the Elementor kit',
			lede: 'The kit is snapshotted before the write, and the apply is refused if the live kit no longer matches the hash this plan was built against.',
			rows: [
				{ key: 'Direction', value: label( row.name, row.slug ) },
				{ key: 'Kit', value: 'id ' + ( plan.kit_id || 0 ) },
				{ key: 'Base hash', value: shortHash( plan.base_hash ) },
				{ key: 'Operations', value: String( operations.length ) },
				{ key: 'Warnings', value: String( warnings.length ), tone: warnings.length ? 'warn' : null },
				{ key: 'Blocked', value: String( blocked.length ), tone: blocked.length ? 'danger' : null },
				{ key: 'Ready to apply', value: plan.ready_to_apply ? 'yes' : 'no', tone: plan.ready_to_apply ? null : 'danger' }
			],
			extra: extra,
			confirmLabel: plan.ready_to_apply ? 'Apply sync' : 'Apply is blocked',
			confirmTone: plan.ready_to_apply ? 'primary' : 'danger',
			onConfirm: function () {
				if ( ! plan.ready_to_apply ) {
					announce( 'The sync plan is blocked. Resolve the blocked entries first.', 'danger' );

					return;
				}
				busy( true );
				post( DIRECTIONS_ROUTE + '/' + encodeURIComponent( String( row.id ) ) + '/sync-apply', {
					base_hash: plan.base_hash,
					kit_id: plan.kit_id
				} )
					.then( function ( payload ) {
						busy( false );
						announce(
							'Applied ' + payload.applied + ' kit operations. Snapshot ' + label( payload.snapshot_id, 'unknown' ) + '.',
							payload.effect_verified ? 'ok' : 'warn'
						);
						render( state.view );
					} )
					.catch( function ( error ) {
						busy( false );
						announce( error.message, 'danger' );
					} );
			}
		} );
	}

	/* ---------------------------- Quality ----------------------------- */

	function severityTone( severity ) {
		if ( 'error' === severity || 'critical' === severity ) {
			return 'error';
		}
		if ( 'warning' === severity || 'warn' === severity ) {
			return 'warn';
		}

		return 'info';
	}

	function renderQuality( panel ) {
		clear( panel );

		var postInput = el( 'input', { attrs: { id: 'sw-ds-post-id', type: 'number', min: '1', step: '1' } } );

		postInput.value = state.postId ? String( state.postId ) : '';

		var severity = filterSelect( 'severity', [ '', 'error', 'warning', 'info' ] );
		var viewport = filterSelect( 'viewport', [ '', 'mobile', 'tablet', 'desktop' ] );
		var rule = el( 'input', { attrs: { id: 'sw-ds-filter-rule', type: 'text' } } );

		rule.value = state.filters.rule;
		rule.addEventListener( 'input', function () {
			state.filters.rule = rule.value;
			paintFindings( panel );
		} );

		panel.appendChild(
			el( 'div', { className: 'sw-ds-filters' }, [
				el( 'div', { className: 'sw-ds-filter' }, [
					el( 'label', { text: 'Post id', attrs: { for: 'sw-ds-post-id' } } ),
					postInput
				] ),
				el( 'div', { className: 'sw-ds-filter' }, [
					el( 'label', { text: 'Severity', attrs: { for: 'sw-ds-filter-severity' } } ),
					severity
				] ),
				el( 'div', { className: 'sw-ds-filter' }, [
					el( 'label', { text: 'Viewport', attrs: { for: 'sw-ds-filter-viewport' } } ),
					viewport
				] ),
				el( 'div', { className: 'sw-ds-filter' }, [
					el( 'label', { text: 'Rule contains', attrs: { for: 'sw-ds-filter-rule' } } ),
					rule
				] ),
				button( 'Load reports', {
					icon: 'clock',
					onClick: function () {
						var postId = Number( postInput.value ) || 0;

						if ( postId <= 0 ) {
							announce( 'Enter a positive post id.', 'danger' );

							return;
						}
						state.postId = postId;
						busy( true );
						loadReports( postId )
							.then( function () {
								busy( false );
								announce( 'Loaded ' + state.reports.length + ' quality reports for post ' + postId + '.' );
								paintFindings( panel );
							} )
							.catch( function ( error ) {
								busy( false );
								announce( error.message, 'danger' );
							} );
					}
				} )
			] )
		);

		panel.appendChild( el( 'div', { attrs: { 'data-sw-ds-findings': '' } } ) );
		paintFindings( panel );
	}

	function filterSelect( name, values ) {
		var select = el( 'select', { attrs: { id: 'sw-ds-filter-' + name } } );

		values.forEach( function ( value ) {
			var option = el( 'option', { text: '' === value ? 'any' : value } );

			option.value = value;
			if ( state.filters[ name ] === value ) {
				option.selected = true;
			}
			select.appendChild( option );
		} );

		select.addEventListener( 'change', function () {
			state.filters[ name ] = select.value;
			paintFindings( panelFor( 'quality' ) );
		} );

		return select;
	}

	function paintFindings( panel ) {
		var host = panel ? panel.querySelector( '[data-sw-ds-findings]' ) : null;

		if ( ! host ) {
			return;
		}

		clear( host );

		var report = state.reports[ state.reportIndex ];

		if ( ! report ) {
			host.appendChild(
				el( 'div', { className: 'sw-ds-empty' }, [
					el( 'h2', { text: 'No quality report loaded' } ),
					el( 'p', {
						text: 'Quality reports are written by the design verification abilities after a section renders. Enter the post id you verified to read its measured evidence.'
					} )
				] )
			);

			return;
		}

		var coverage = report.coverage || {};
		var checked = Array.isArray( coverage.checked ) ? coverage.checked : [];
		var notChecked = Array.isArray( coverage.not_checked ) ? coverage.not_checked : [];

		host.appendChild(
			el( 'div', { className: 'sw-ds-card' }, [
				el( 'h2', { className: 'sw-ds-card__title', text: 'Report ' + label( report.report_id, '' ) } ),
				el( 'p', {
					className: 'sw-ds-card__meta',
					text: 'direction revision ' + ( report.direction_revision || 0 ) +
						' · contract ' + shortHash( report.direction_hash ) +
						' · render ' + shortHash( report.render_hash )
				} ),
				el( 'div', { className: 'sw-ds-badges' }, [
					badge( label( report.status, 'unknown' ), 'pass' === report.status ? 'pass' : 'fail' ),
					badge( checked.length + ' rules checked', 'info' ),
					badge( notChecked.length + ' not checked', notChecked.length ? 'warn' : 'ok' )
				] ),
				notChecked.length
					? el( 'p', {
						className: 'sw-ds-field__hint',
						text: 'Not checked: ' + notChecked.join( ', ' ) + '. Treat these rules as unverified, not as passing.'
					} )
					: null
			] )
		);

		var findings = ( Array.isArray( report.findings ) ? report.findings : [] ).filter( function ( finding ) {
			if ( state.filters.severity && finding.severity !== state.filters.severity ) {
				return false;
			}
			if ( state.filters.viewport && finding.viewport !== state.filters.viewport ) {
				return false;
			}
			if ( state.filters.rule && String( finding.rule_id || '' ).indexOf( state.filters.rule ) === -1 ) {
				return false;
			}

			return true;
		} );

		if ( ! findings.length ) {
			host.appendChild( el( 'p', { className: 'sw-ds-field__hint', text: 'No findings match the current filters.' } ) );

			return;
		}

		host.appendChild(
			el(
				'ul',
				{ className: 'sw-ds-findings' },
				findings.map( function ( finding ) {
					var evidence = finding.evidence;
					var item = el( 'li', { className: 'sw-ds-finding', attrs: { tabindex: '0' } }, [
						el( 'div', { className: 'sw-ds-finding__head' }, [
							el( 'span', { className: 'sw-ds-finding__rule', text: label( finding.rule_id, 'rule' ) } ),
							badge( label( finding.severity, 'info' ), severityTone( finding.severity ) ),
							badge( label( finding.viewport, 'any viewport' ), 'info' ),
							finding.waived ? badge( 'waived', 'warn' ) : null
						] ),
						finding.element_ref
							? el( 'p', { className: 'sw-ds-finding__repair', text: 'Element: ' + finding.element_ref } )
							: null,
						el( 'pre', {
							className: 'sw-ds-finding__evidence',
							text: typeof evidence === 'string' ? evidence : JSON.stringify( evidence, null, 2 )
						} ),
						finding.repair_hint
							? el( 'p', { className: 'sw-ds-finding__repair', text: finding.repair_hint } )
							: null,
						finding.waiver_reason
							? el( 'p', { className: 'sw-ds-finding__repair', text: 'Waiver: ' + finding.waiver_reason } )
							: null
					] );

					item.addEventListener( 'click', function () {
						emit( 'stonewright:quality-selected', { report: report, finding: finding } );
						announce( 'Selected finding ' + label( finding.rule_id, '' ) + '.' );
					} );

					return item;
				} )
			)
		);
	}

	/* ---------------------------- History ----------------------------- */

	function renderHistory( panel ) {
		if ( ! state.selectedId ) {
			clear( panel ).appendChild(
				el( 'div', { className: 'sw-ds-empty' }, [
					el( 'h2', { text: 'No direction selected' } ),
					el( 'p', { text: 'Pick a direction on the overview to read its revision history.' } )
				] )
			);

			return;
		}

		pending( panel, 'Loading revisions.' );

		loadDirection( state.selectedId )
			.then( function () {
				clear( panel );

				if ( ! state.versions.length ) {
					panel.appendChild(
						el( 'div', { className: 'sw-ds-empty' }, [
							el( 'h2', { text: 'No earlier revisions' } ),
							el( 'p', { text: 'Every save records a revision. This direction has only its current one.' } )
						] )
					);

					return;
				}

				var head = el( 'tr', null, [
					el( 'th', { text: 'Revision' } ),
					el( 'th', { text: 'Status' } ),
					el( 'th', { text: 'Source' } ),
					el( 'th', { text: 'Contract hash' } ),
					el( 'th', { text: 'Created' } ),
					el( 'th', { text: 'Action' } )
				] );

				var body = el(
					'tbody',
					null,
					state.versions.map( function ( version ) {
						return el( 'tr', null, [
							el( 'td', { text: String( version.revision ) } ),
							el( 'td', { text: label( version.status, 'draft' ) } ),
							el( 'td', { text: label( version.source_type, 'manual' ) } ),
							el( 'td', { className: 'sw-ds-table__hash', text: shortHash( version.contract_hash ) } ),
							el( 'td', { text: label( version.created_at, 'unknown' ) } ),
							el( 'td', null, [
								canWrite
									? button( 'Restore', {
										onClick: function () {
											reviewRestore( version );
										}
									} )
									: el( 'span', { className: 'sw-ds-field__hint', text: 'read only' } )
							] )
						] );
					} )
				);

				panel.appendChild(
					el( 'div', { className: 'sw-ds-table-wrap' }, [
						el( 'table', { className: 'sw-ds-table' }, [ el( 'thead', null, [ head ] ), body ] )
					] )
				);
				announce( 'Loaded ' + state.versions.length + ' revisions.' );
			} )
			.catch( function ( error ) {
				renderError( panel, error );
			} );
	}

	function reviewRestore( version ) {
		var current = state.direction || {};

		openDrawer( {
			title: 'Restore revision ' + version.revision,
			lede: 'Restoring writes the stored contract back as a new revision. The revision you are on now stays in the history, so this is reversible.',
			rows: [
				{ key: 'Current revision', value: String( current.revision || 0 ) },
				{ key: 'Current hash', value: shortHash( current.contract_hash ) },
				{ key: 'Restoring revision', value: String( version.revision ) },
				{ key: 'Restoring hash', value: shortHash( version.contract_hash ) },
				{
					key: 'Same contract',
					value: current.contract_hash === version.contract_hash ? 'yes, nothing would change' : 'no',
					tone: current.contract_hash === version.contract_hash ? 'warn' : null
				}
			],
			confirmLabel: 'Restore revision',
			onConfirm: function () {
				busy( true );
				post( DIRECTIONS_ROUTE + '/' + encodeURIComponent( String( state.selectedId ) ) + '/restore', {
					revision: Number( version.revision )
				} )
					.then( function ( payload ) {
						busy( false );
						state.direction = null;
						state.draft = null;
						markDirty( false );
						announce( 'Restored revision ' + payload.restored_revision + ' as revision ' + payload.revision + '.', 'ok' );
						emit( 'stonewright:direction-saved', payload );
						render( 'history' );
					} )
					.catch( function ( error ) {
						busy( false );
						announce( error.message, 'danger' );
					} );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* View plumbing                                                       */
	/* ------------------------------------------------------------------ */

	function render( view ) {
		var panel = panelFor( view );

		if ( ! panel ) {
			return;
		}

		if ( 'overview' === view ) {
			renderOverview( panel );
		} else if ( 'editor' === view ) {
			renderEditor( panel );
		} else if ( 'quality' === view ) {
			renderQuality( panel );
		} else if ( 'history' === view ) {
			renderHistory( panel );
		}
	}

	function showView( view ) {
		state.view = view;
		root.setAttribute( 'data-sw-current-view', view );

		tabNodes.forEach( function ( tab ) {
			var isCurrent = tab.getAttribute( 'data-sw-view' ) === view;

			tab.classList.toggle( 'is-current', isCurrent );
			tab.setAttribute( 'aria-selected', isCurrent ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', isCurrent ? '0' : '-1' );
		} );

		panelNodes.forEach( function ( node ) {
			node.hidden = node.getAttribute( 'data-sw-panel' ) !== view;
		} );

		render( view );
	}

	function pushView( view ) {
		var url = new URL( window.location.href );

		url.searchParams.set( 'view', view );
		window.history.pushState( { stonewrightView: view }, '', url.toString() );
	}

	function requestView( view ) {
		if ( VIEWS.indexOf( view ) === -1 ) {
			view = VIEWS[ 0 ];
		}
		if ( view === state.view ) {
			return;
		}

		if ( state.isDirty && 'editor' === state.view ) {
			openDrawer( {
				title: 'Leave the editor with unsaved changes?',
				lede: 'The draft stays in this browser session, so you can come back to the editor and restore it.',
				rows: [
					{ key: 'Direction', value: state.draft ? label( state.draft.name, 'untitled' ) : 'untitled' },
					{ key: 'Going to', value: view },
					{ key: 'Draft kept', value: 'yes, in this browser session' }
				],
				confirmLabel: 'Leave the editor',
				onConfirm: function () {
					pushView( view );
					showView( view );
				}
			} );

			return;
		}

		pushView( view );
		showView( view );
	}

	function moveTabFocus( fromIndex, delta ) {
		if ( ! tabNodes.length ) {
			return;
		}
		var next = ( fromIndex + delta + tabNodes.length ) % tabNodes.length;

		tabNodes[ next ].focus();
		requestView( tabNodes[ next ].getAttribute( 'data-sw-view' ) );
	}

	tabNodes.forEach( function ( tab, index ) {
		tab.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			requestView( tab.getAttribute( 'data-sw-view' ) );
		} );

		tab.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
				event.preventDefault();
				moveTabFocus( index, 1 );
			} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
				event.preventDefault();
				moveTabFocus( index, -1 );
			} else if ( 'Home' === event.key ) {
				event.preventDefault();
				moveTabFocus( -1, 1 );
			} else if ( 'End' === event.key ) {
				event.preventDefault();
				moveTabFocus( 0, -1 );
			}
		} );
	} );

	window.addEventListener( 'popstate', function () {
		var url = new URL( window.location.href );
		var view = url.searchParams.get( 'view' ) || VIEWS[ 0 ];

		showView( VIEWS.indexOf( view ) === -1 ? VIEWS[ 0 ] : view );
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( ! state.isDirty ) {
			return undefined;
		}
		event.preventDefault();
		event.returnValue = '';

		return '';
	} );

	showView( VIEWS.indexOf( state.view ) === -1 ? VIEWS[ 0 ] : state.view );
}() );
