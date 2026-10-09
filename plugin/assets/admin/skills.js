/**
 * Stonewright skill lifecycle.
 *
 * The page owns no lifecycle rules. Reading the catalog, inspecting an upload,
 * importing, exporting, trashing, restoring, and destroying all go through the
 * skills-studio REST routes, which delegate to the skill library service — so
 * the browser hits exactly the same refusals as any other caller: protected
 * sources, re-derived import readiness and receipts, and the production-safe
 * confirmation token on a hard delete.
 *
 * Skill titles, descriptions, and imported Markdown are untrusted content, so
 * this file builds DOM nodes and assigns textContent rather than composing
 * markup. Confirmation happens in a review drawer (a native dialog of the
 * shared UI layer), never in a browser dialog.
 *
 * Buttons, badges, tags, notices, empty states, the drawer and the toasts are
 * the components of sw-ui.css and sw-ui.js; this file adds none of its own.
 *
 * No third-party dependencies.
 */
( function () {
	'use strict';

	var boot = window.stonewrightSkills || {};
	var root = document.querySelector( '[data-sw-skills]' );

	if ( ! root || ! boot.restRoot || ! window.fetch ) {
		return;
	}

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var CATALOG_ROUTE = '/catalog';
	var IMPORT_ROUTE = '/import';
	var INSPECT_ROUTE = '/import/inspect';
	var SKILLS_ROUTE = '/skills/';
	var TRASH_ACTION = '/trash';
	var RESTORE_ACTION = '/restore';
	var EXPORT_ACTION = '/export';
	var UNDO_TIMEOUT = 15000;
	var VIEWS = Array.isArray( boot.views ) && boot.views.length
		? boot.views.slice()
		: [ 'catalog', 'editor', 'import', 'trash' ];

	var statusNode = root.querySelector( '[data-sw-skills-status]' );
	var tabNodes = [].slice.call( root.querySelectorAll( '[data-sw-view]' ) );
	var panelNodes = [].slice.call( root.querySelectorAll( '[data-sw-panel]' ) );

	var state = {
		view: root.getAttribute( 'data-sw-current-view' ) || VIEWS[ 0 ],
		skills: [],
		trashed: [],
		sources: [],
		conflicts: [],
		query: '',
		loaded: false,
		loadError: null,
		inspection: null,
		inspectError: null,
		busy: false
	};

	var isProductionSafe = 'production-safe' === String( boot.mode || '' );
	var canWrite = !! ( boot.can && boot.can.manageOptions );

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

	function kit() {
		return window.Stonewright && window.Stonewright.ui ? window.Stonewright.ui : null;
	}

	/** The shared sprite holds these glyphs; a name it does not hold draws nothing. */
	var ICON_IDS = {
		check: 'check',
		plus: 'plus',
		trash: 'trash',
		restore: 'refresh',
		search: 'search',
		alert: 'alert',
		x: 'x'
	};

	function icon( name, extraClass ) {
		if ( ! ICON_IDS[ name ] ) {
			return null;
		}
		var svg = document.createElementNS( SVG_NS, 'svg' );
		var use = document.createElementNS( SVG_NS, 'use' );

		svg.setAttribute( 'class', 'sw-ui-icon' + ( extraClass ? ' ' + extraClass : '' ) );
		svg.setAttribute( 'aria-hidden', 'true' );
		use.setAttribute( 'href', '#sw-ui-icon-' + ICON_IDS[ name ] );
		svg.appendChild( use );

		return svg;
	}

	var BUTTON_VARIANTS = {
		primary: ' sw-ui-btn--primary',
		danger: ' sw-ui-btn--danger',
		'danger-solid': ' sw-ui-btn--danger-solid',
		ghost: ' sw-ui-btn--tertiary'
	};

	/**
	 * A button of the layer. `context` names what a repeated action acts on, for assistive technology only, so a
	 * list of "Inspect" buttons still has a name per skill. `autofocus` marks the safe action of a dialog.
	 */
	function button( label, options ) {
		var config = options || {};
		var node = el(
			'button',
			{
				className: 'sw-ui-btn' + ( BUTTON_VARIANTS[ config.variant ] || '' ) + ( config.size ? ' sw-ui-btn--' + config.size : '' ),
				attrs: { type: 'button' },
				on: config.onClick ? { click: config.onClick } : null
			},
			[
				config.icon ? icon( config.icon ) : null,
				label,
				config.context ? el( 'span', { className: 'sw-ui-visually-hidden', text: ' ' + config.context } ) : null
			]
		);

		if ( config.disabled ) {
			node.disabled = true;
		}
		if ( config.autofocus ) {
			node.setAttribute( 'autofocus', '' );
		}
		if ( config.close ) {
			node.setAttribute( 'data-sw-ui-dialog-close', '' );
		}
		if ( config.describedBy ) {
			node.setAttribute( 'aria-describedby', config.describedBy );
		}

		return node;
	}

	function linkButton( label, href, options ) {
		var config = options || {};

		return el(
			'a',
			{
				className: 'sw-ui-btn' + ( BUTTON_VARIANTS[ config.variant ] || '' ) + ( config.size ? ' sw-ui-btn--' + config.size : '' ),
				attrs: { href: href }
			},
			[
				config.icon ? icon( config.icon ) : null,
				label,
				config.context ? el( 'span', { className: 'sw-ui-visually-hidden', text: ' ' + config.context } ) : null
			]
		);
	}

	/** A state: ok, warn, info, accent, or neutral when no variant is given. */
	function badge( label, variant ) {
		return el( 'span', { className: 'sw-ui-badge' + ( variant ? ' sw-ui-badge--' + variant : '' ), text: label } );
	}

	/** A fact about the thing (where it comes from, how agents reach it); never a state. */
	function tag( label ) {
		return el( 'span', { className: 'sw-ui-tag', text: label } );
	}

	function sentence( value ) {
		var out = String( value || '' );

		return out.charAt( 0 ).toUpperCase() + out.slice( 1 );
	}

	/** One key and value pair of a facts list (dl.sw-ui-kv). */
	function fact( label, value, danger ) {
		return el( 'div', null, [
			el( 'dt', { text: label } ),
			el( 'dd', { className: danger ? 'sw-skills__danger' : '', text: value } )
		] );
	}

	function facts( items, label, inline ) {
		return el( 'dl', { className: 'sw-ui-kv' + ( inline ? ' sw-ui-kv--inline' : '' ), attrs: { 'aria-label': label } }, items );
	}

	function text( value, fallback ) {
		var out = String( typeof value === 'undefined' || null === value ? '' : value ).trim();

		return '' === out ? fallback : out;
	}

	/** Guidance or a refusal that belongs to its place: an icon, a title and the details. */
	function callout( variant, title, children ) {
		var glyph = { warn: 'alert', danger: 'x', info: 'alert', ok: 'check' }[ variant ] || 'alert';

		return el( 'div', { className: 'sw-ui-callout sw-ui-callout--' + variant }, [
			icon( glyph, 'sw-ui-icon--lg' ),
			el( 'div', null, [ el( 'div', { className: 'sw-ui-notice__title', text: title } ) ].concat( children || [] ) )
		] );
	}

	/** A block of text in the layer's code face, scrollable and reachable from the keyboard. */
	function codeBlock( title, body, label ) {
		return el( 'div', { className: 'sw-ui-code' }, [
			el( 'div', { className: 'sw-ui-code__head' }, [ el( 'span', { text: title } ) ] ),
			el( 'pre', { className: 'sw-ui-code__body sw-skills__source', attrs: { tabindex: '0', 'aria-label': label } }, [ el( 'code', { text: body } ) ] )
		] );
	}

	/** A message that is a state of its own: what the page has, why, and what to do next. */
	function emptyState( variant, title, message, actions, code ) {
		var glyph = 'error' === variant ? 'alert' : ( 'no-results' === variant ? 'search' : 'check' );

		return el( 'div', { className: 'sw-ui-empty' + ( 'default' === variant ? '' : ' sw-ui-empty--' + variant ) }, [
			el( 'span', { className: 'sw-ui-empty__icon' }, [ icon( glyph, 'sw-ui-icon--lg' ) ] ),
			el( 'h2', { className: 'sw-ui-empty__title', text: title } ),
			message ? el( 'p', { className: 'sw-ui-empty__text', text: message } ) : null,
			code ? el( 'p', { className: 'sw-ui-field__help', text: code } ) : null,
			actions && actions.length ? el( 'div', { className: 'sw-ui-actions' }, actions ) : null
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Messages                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Say what happened. A confirmation is a toast, which leaves by itself; a refusal or failure is a notice that
	 * stays until the next message, because an error never goes away on a timer.
	 */
	function announce( message, tone ) {
		if ( ! statusNode ) {
			return;
		}
		clear( statusNode );
		if ( ! message ) {
			return;
		}
		var ui = kit();

		if ( ! ui ) {
			statusNode.textContent = String( message );

			return;
		}
		if ( 'error' === tone ) {
			ui.notify( statusNode, { variant: 'danger', text: String( message ) } );

			return;
		}
		if ( 'ok' === tone ) {
			ui.toast( String( message ) );

			return;
		}
		ui.notify( statusNode, { variant: 'info', text: String( message ) } );
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

	function settle( response ) {
		return readBody( response ).then( function ( payload ) {
			if ( ! response.ok ) {
				throw failure( payload, response.status );
			}

			return payload;
		} );
	}

	function headers() {
		return {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			'X-WP-Nonce': boot.nonce || ''
		};
	}

	function get( path ) {
		return window.fetch( boot.restRoot + path, {
			method: 'GET',
			credentials: 'same-origin',
			headers: headers()
		} ).then( settle );
	}

	function post( path, body ) {
		return window.fetch( boot.restRoot + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers(),
			body: JSON.stringify( body || {} )
		} ).then( settle );
	}

	function remove( path, body ) {
		return window.fetch( boot.restRoot + path, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: headers(),
			body: JSON.stringify( body || {} )
		} ).then( settle );
	}

	function busy( isBusy ) {
		state.busy = !! isBusy;
		root.setAttribute( 'aria-busy', state.busy ? 'true' : 'false' );
	}

	/* ------------------------------------------------------------------ */
	/* Review drawer: a native dialog of the layer                         */
	/* ------------------------------------------------------------------ */

	var openDialogNode = null;

	function closeDrawer() {
		if ( openDialogNode ) {
			var ui = kit();

			if ( ui ) {
				ui.closeDialog( openDialogNode );
			}
		}
	}

	/**
	 * Opens the review drawer. `options.rows` is the summary the user reviews
	 * before committing: which skill, from which source, in which state. The
	 * layer closes it on Escape, keeps Tab inside it and returns focus to the
	 * control that opened it. The safe action takes focus: Cancel when the
	 * commit is destructive, the commit otherwise.
	 */
	function openDrawer( options ) {
		var config = options || {};
		var ui = kit();

		if ( ! ui ) {
			return;
		}
		closeDrawer();

		var opener = document.activeElement;
		var titleId = 'sw-skills-review-title';
		var confirmed = false;
		var destructive = 'danger' === config.confirmTone || 'danger-solid' === config.confirmTone;
		var items = ( config.rows || [] ).map( function ( row ) {
			return fact( row.key, row.value, 'danger' === row.tone );
		} );

		var commitButton = button( config.confirmLabel || 'Confirm', {
			variant: destructive ? 'danger-solid' : 'primary',
			icon: config.confirmIcon || 'check',
			autofocus: ! destructive || !! config.singleAction,
			onClick: function () {
				confirmed = true;
				var result = config.onConfirm ? config.onConfirm() : null;

				closeDrawer();
				return result;
			}
		} );
		var cancelButton = config.singleAction
			? null
			: button( config.cancelLabel || 'Cancel', { close: true, autofocus: destructive } );

		var dialog = el(
			'dialog',
			{
				className: 'sw-ui-dialog sw-ui-drawer',
				attrs: {
					'data-sw-skills-drawer': '',
					'aria-labelledby': titleId,
					'aria-modal': 'true',
					'data-sw-ui-light-dismiss': ''
				}
			},
			[
				el( 'div', { className: 'sw-ui-dialog__header' }, [
					el( 'h2', { className: 'sw-ui-dialog__title', text: config.title || 'Review', attrs: { id: titleId } } ),
					config.lede ? el( 'p', { className: 'sw-skills__lede', text: config.lede } ) : null
				] ),
				el( 'div', { className: 'sw-ui-dialog__body' }, [
					items.length ? facts( items, 'Review', false ) : null,
					config.extra || null
				] ),
				el( 'div', { className: 'sw-ui-dialog__footer' }, [ cancelButton, commitButton ] )
			]
		);

		dialog.addEventListener( 'close', function () {
			if ( openDialogNode === dialog ) {
				openDialogNode = null;
			}
			if ( dialog.parentNode ) {
				dialog.parentNode.removeChild( dialog );
			}
			if ( ! confirmed && config.onCancel ) {
				config.onCancel();
			}
		} );

		root.appendChild( dialog );
		openDialogNode = dialog;
		ui.openDialog( dialog, opener );
	}

	/* ------------------------------------------------------------------ */
	/* Skill helpers                                                       */
	/* ------------------------------------------------------------------ */

	var PROTECTED_SOURCES = [ 'builtin', 'playbook' ];

	function isProtected( skill ) {
		return PROTECTED_SOURCES.indexOf( String( skill.source || '' ) ) !== -1;
	}

	function originLabel( skill ) {
		if ( 'external' === String( skill.source_kind || '' ) ) {
			return text( skill.source_id, 'external' );
		}
		if ( isProtected( skill ) ) {
			return 'Built-in';
		}

		return sentence( text( skill.source, 'local' ) );
	}

	function findingsOf( skill ) {
		var conflicts = Array.isArray( skill.conflicts ) ? skill.conflicts.length : 0;

		return conflicts ? conflicts + ' unresolved conflict(s)' : 'none recorded';
	}

	function matchesQuery( skill, query ) {
		if ( ! query ) {
			return true;
		}
		var haystack = [
			skill.title,
			skill.slug,
			skill.description,
			skill.topic,
			skill.source,
			skill.source_id,
			skill.status
		].join( ' ' ).toLowerCase();

		return haystack.indexOf( query.toLowerCase() ) !== -1;
	}

	/** One state badge and at most two tags: where the skill comes from and how agents reach it. */
	function skillBadges( skill ) {
		var status = String( skill.status || '' );
		var out = [];

		if ( status && 'active' !== status ) {
			out.push( badge( sentence( status ), 'warn' ) );
		} else {
			out.push( Number( skill.enabled ) ? badge( 'Active', 'ok' ) : badge( 'Disabled' ) );
		}

		out.push( tag( originLabel( skill ) ) );
		if ( Number( skill.enabled ) ) {
			var auto = !! Number( skill.enable_agentic );
			var command = !! Number( skill.enable_prompt );

			if ( auto && command ) {
				out.push( tag( 'Auto and command' ) );
			} else if ( auto ) {
				out.push( tag( 'Auto' ) );
			} else if ( command ) {
				out.push( tag( 'Command' ) );
			}
		}

		return out;
	}

	/* ------------------------------------------------------------------ */
	/* Reads                                                               */
	/* ------------------------------------------------------------------ */

	function loadCatalog() {
		busy( true );

		return get( CATALOG_ROUTE ).then( function ( payload ) {
			state.skills = Array.isArray( payload.skills ) ? payload.skills : [];
			state.trashed = Array.isArray( payload.trashed ) ? payload.trashed : [];
			state.sources = Array.isArray( payload.sources ) ? payload.sources : [];
			state.conflicts = Array.isArray( payload.conflicts ) ? payload.conflicts : [];
			state.loaded = true;
			state.loadError = null;
		} ).catch( function ( error ) {
			state.loaded = true;
			state.loadError = error;
		} ).then( function () {
			busy( false );
			render( state.view );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Inspector                                                           */
	/* ------------------------------------------------------------------ */

	function openInspector( skill ) {
		var body = codeBlock( 'Skill body', text( skill.content, 'This skill has no body.' ), 'Skill body' );

		openDrawer( {
			title: text( skill.title, skill.slug ),
			lede: text( skill.description, 'This skill has no description, so agents have no trigger text to match on.' ),
			rows: [
				{ key: 'Slug', value: text( skill.slug, 'unknown' ) },
				{ key: 'Source', value: originLabel( skill ) },
				{ key: 'Status', value: text( skill.status, 'draft' ) },
				{ key: 'Revision', value: String( skill.revision || 1 ) },
				{ key: 'Verified', value: String( skill.verification_count || 0 ) + ' time(s)' },
				{ key: 'Findings', value: findingsOf( skill ) },
				{ key: 'Updated', value: text( skill.updated_at, 'unknown' ) }
			],
			extra: body,
			singleAction: true,
			confirmLabel: 'Close',
			confirmIcon: 'check'
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	function exportSkill( skill ) {
		busy( true );

		get( SKILLS_ROUTE + Number( skill.id ) + EXPORT_ACTION ).then( function ( payload ) {
			var blob = new window.Blob( [ String( payload.markdown || '' ) ], { type: 'text/markdown' } );
			var url = window.URL.createObjectURL( blob );
			var anchor = el( 'a', {
				attrs: {
					href: url,
					download: text( payload.filename, 'skill.md' )
				}
			} );

			document.body.appendChild( anchor );
			anchor.click();
			document.body.removeChild( anchor );
			window.URL.revokeObjectURL( url );
			announce( 'Exported ' + text( payload.filename, 'skill.md' ) + '.', 'ok' );
		} ).catch( function ( error ) {
			announce( error.message, 'error' );
		} ).then( function () {
			busy( false );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Trash, undo, restore, destroy                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * The undo affordance. Trash is reversible, so the page says so in place
	 * instead of making the user hunt for the trash view: a toast with an
	 * Undo action that stays long enough to reach.
	 */
	function offerUndo( skill ) {
		var ui = kit();

		if ( ! ui ) {
			return;
		}
		var toast = ui.toast( text( skill.title, skill.slug ) + ' moved to trash. It no longer reaches agents.', {
			duration: UNDO_TIMEOUT,
			action: {
				label: 'Undo',
				onClick: function () {
					restoreSkill( skill );
				}
			}
		} );

		toast.setAttribute( 'data-sw-skills-undo', '' );
	}

	function trashSkill( skill ) {
		busy( true );

		post( SKILLS_ROUTE + Number( skill.id ) + TRASH_ACTION, {} ).then( function () {
			announce( '', 'ok' );
			emit( 'stonewright:skill-trashed', { id: Number( skill.id ), slug: skill.slug } );
			offerUndo( skill );

			return loadCatalog();
		} ).catch( function ( error ) {
			announce( error.message, 'error' );
		} ).then( function () {
			busy( false );
		} );
	}

	function restoreSkill( skill ) {
		busy( true );

		post( SKILLS_ROUTE + Number( skill.id ) + RESTORE_ACTION, {} ).then( function () {
			announce( text( skill.title, skill.slug ) + ' restored as a disabled draft. Enable it when you have reviewed it.', 'ok' );
			emit( 'stonewright:skill-restored', { id: Number( skill.id ), slug: skill.slug } );

			return loadCatalog();
		} ).catch( function ( error ) {
			announce( error.message, 'error' );
		} ).then( function () {
			busy( false );
		} );
	}

	function destroySkill( skill, token ) {
		busy( true );

		remove( SKILLS_ROUTE + Number( skill.id ), { confirmation_token: token || '' } ).then( function () {
			announce( text( skill.title, skill.slug ) + ' deleted permanently.', 'ok' );
			emit( 'stonewright:skill-destroyed', { id: Number( skill.id ), slug: skill.slug } );

			return loadCatalog();
		} ).catch( function ( error ) {
			announce( error.message, 'error' );
		} ).then( function () {
			busy( false );
		} );
	}

	function reviewTrash( skill ) {
		openDrawer( {
			title: 'Move this skill to trash?',
			lede: 'Trashing disables the skill everywhere an agent could read it. Nothing is deleted, and the trash view can put it back.',
			rows: [
				{ key: 'Skill', value: text( skill.title, skill.slug ) },
				{ key: 'Slug', value: text( skill.slug, 'unknown' ) },
				{ key: 'Source', value: originLabel( skill ) },
				{ key: 'Reversible', value: 'yes, from the trash view' }
			],
			confirmLabel: 'Move to trash',
			confirmTone: 'danger',
			confirmIcon: 'trash',
			onConfirm: function () {
				trashSkill( skill );
			}
		} );
	}

	function reviewDestroy( skill ) {
		var tokenField = null;
		var extra = null;

		if ( isProductionSafe ) {
			tokenField = el( 'input', {
				className: 'sw-ui-input',
				attrs: {
					type: 'text',
					id: 'sw-skills-token',
					autocomplete: 'off',
					spellcheck: 'false',
					'aria-describedby': 'sw-skills-token-help'
				}
			} );
			extra = el( 'div', { className: 'sw-ui-field' }, [
				el( 'label', {
					className: 'sw-ui-field__label',
					text: 'Confirmation token',
					attrs: { for: 'sw-skills-token' }
				} ),
				tokenField,
				el( 'span', {
					className: 'sw-ui-field__help',
					text: 'This site runs in production-safe mode, so a permanent delete needs a token issued by stonewright/security-issue-confirmation-token for this skill id.',
					attrs: { id: 'sw-skills-token-help' }
				} )
			] );
		}

		openDrawer( {
			title: 'Delete this skill permanently?',
			lede: 'This removes the row and its stored revisions. There is no undo after this point.',
			rows: [
				{ key: 'Skill', value: text( skill.title, skill.slug ) },
				{ key: 'Slug', value: text( skill.slug, 'unknown' ) },
				{ key: 'Source', value: originLabel( skill ) },
				{ key: 'Reversible', value: 'no', tone: 'danger' }
			],
			extra: extra,
			confirmLabel: 'Delete permanently',
			confirmTone: 'danger',
			confirmIcon: 'trash',
			onConfirm: function () {
				destroySkill( skill, tokenField ? tokenField.value : '' );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Import                                                              */
	/* ------------------------------------------------------------------ */

	function inspectFile( filename, content ) {
		busy( true );
		state.inspection = null;
		state.inspectError = null;
		announce( 'Reviewing ' + filename + '. Nothing has been written yet.' );

		post( INSPECT_ROUTE, { filename: filename, content: content } ).then( function ( payload ) {
			state.inspection = payload.inspection || null;
			announce( 'Review ready for ' + filename + '.', 'ok' );
		} ).catch( function ( error ) {
			state.inspectError = error;
			announce( error.message, 'error' );
		} ).then( function () {
			busy( false );
			render( 'import' );
		} );
	}

	function readUpload( file ) {
		var reader = new window.FileReader();

		reader.onload = function () {
			inspectFile( String( file.name || '' ), String( reader.result || '' ) );
		};
		reader.onerror = function () {
			announce( 'That file could not be read.', 'error' );
		};
		reader.readAsText( file );
	}

	function runImport( inspection ) {
		busy( true );

		post( IMPORT_ROUTE, { inspection: inspection } ).then( function ( payload ) {
			state.inspection = null;
			announce( 'Imported as a disabled draft. Review it in the catalog before enabling it.', 'ok' );
			emit( 'stonewright:skill-imported', {
				id: Number( payload.skill_id ) || 0,
				slug: String( inspection.slug || '' )
			} );

			return loadCatalog();
		} ).catch( function ( error ) {
			announce( error.message, 'error' );
			render( 'import' );
		} ).then( function () {
			busy( false );
		} );
	}

	function reviewImport( inspection ) {
		var trust = inspection.trust || {};
		var lint = inspection.lint || {};
		var errors = Array.isArray( lint.errors ) ? lint.errors : [];
		var findings = Array.isArray( trust.findings ) ? trust.findings : [];

		openDrawer( {
			title: 'Import this skill?',
			lede: 'The file lands disabled, as a draft. The server re-checks it on import, whatever the report says.',
			rows: [
				{ key: 'Slug', value: text( inspection.slug, 'unknown' ) },
				{ key: 'Title', value: text( inspection.title, 'untitled' ) },
				{ key: 'Bytes', value: String( inspection.bytes || 0 ) },
				{ key: 'Lint errors', value: String( errors.length ), tone: errors.length ? 'danger' : null },
				{ key: 'Findings', value: String( findings.length ), tone: trust.blocked ? 'danger' : null },
				{ key: 'Lands as', value: 'disabled draft' }
			],
			confirmLabel: 'Import as disabled draft',
			confirmIcon: 'plus',
			onConfirm: function () {
				runImport( inspection );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Panels                                                              */
	/* ------------------------------------------------------------------ */

	function panelFor( view ) {
		var found = null;

		panelNodes.forEach( function ( node ) {
			if ( node.getAttribute( 'data-sw-panel' ) === view ) {
				found = node;
			}
		} );

		return found;
	}

	function hasServerCatalog( panel ) {
		return !!( panel && panel.querySelector( '[data-sw-skills-ssr="catalog"]' ) );
	}

	/** A skeleton that reserves the height of the content, with a status line for assistive technology. */
	function pending( panel, message ) {
		clear( panel ).appendChild(
			el( 'div', { className: 'sw-ui-stack', attrs: { 'aria-busy': 'true' } }, [
				el( 'span', { className: 'sw-ui-visually-hidden', text: message, attrs: { role: 'status' } } ),
				el( 'div', { className: 'sw-ui-skeleton sw-ui-skeleton--card' } ),
				el( 'div', { className: 'sw-ui-skeleton sw-ui-skeleton--card' } )
			] )
		);
	}

	function renderError( panel, error ) {
		clear( panel ).appendChild(
			emptyState( 'error', 'The catalog could not be loaded', error.message, [ button( 'Try again', { onClick: loadCatalog } ) ], error.code )
		);
	}

	function skillRow( skill ) {
		var title = text( skill.title, skill.slug );
		var hintId = 'sw-skills-protected-hint';
		var actions = [
			button( 'Inspect', {
				size: 'sm',
				icon: 'search',
				context: title,
				onClick: function () {
					openInspector( skill );
				}
			} ),
			button( 'Export', {
				size: 'sm',
				context: title,
				onClick: function () {
					exportSkill( skill );
				}
			} )
		];

		if ( canWrite ) {
			actions.push( linkButton( 'Edit', editorUrl( skill.slug ), { size: 'sm', context: title } ) );
			actions.push(
				button( 'Trash', {
					size: 'sm',
					variant: 'danger',
					icon: 'trash',
					context: title,
					disabled: isProtected( skill ),
					describedBy: isProtected( skill ) ? hintId : null,
					onClick: function () {
						reviewTrash( skill );
					}
				} )
			);
		}

		return el( 'li', { className: 'sw-skill-row sw-ui-card' }, [
			el( 'div', { className: 'sw-ui-card__body' }, [
				el( 'div', { className: 'sw-skill-row__head' }, [
					el( 'strong', { className: 'sw-skill-row__title', text: title } ),
					el( 'span', { className: 'sw-skill-row__badges' }, skillBadges( skill ) )
				] ),
				el( 'code', { className: 'sw-skill-row__slug', text: text( skill.slug, 'unknown' ) } ),
				el( 'p', {
					className: 'sw-skill-row__description',
					text: text( skill.description, 'No description, so agents have no trigger text to match on.' )
				} ),
				facts( [
					fact( 'Revision', String( skill.revision || 1 ) ),
					fact( 'Verified', String( skill.verification_count || 0 ) ),
					fact( 'Updated', text( skill.updated_at, 'unknown' ) )
				], 'Skill facts', true ),
				el( 'div', { className: 'sw-ui-actions' }, actions )
			] )
		] );
	}

	function searchField() {
		var input = el( 'input', {
			className: 'sw-ui-input',
			attrs: {
				type: 'search',
				id: 'sw-skills-search',
				'data-sw-skills-search': '',
				'data-sw-ui-search': '',
				placeholder: 'Filter by title, slug, topic, or source',
				autocomplete: 'off'
			},
			on: {
				input: function ( event ) {
					state.query = String( event.target.value || '' );
					paintCatalog();
				}
			}
		} );

		input.value = state.query;

		return el( 'div', { className: 'sw-ui-field' }, [
			el( 'label', { className: 'sw-ui-field__label', text: 'Search skills', attrs: { for: 'sw-skills-search' } } ),
			input
		] );
	}

	function conflictNotice() {
		if ( ! state.conflicts.length ) {
			return null;
		}

		return callout(
			'warn',
			state.conflicts.length + ' skill(s) offered by a source were dropped:',
			[
				el(
					'ul',
					{ className: 'sw-skills__issues' },
					state.conflicts.map( function ( conflict ) {
						return el( 'li', {
							text: text( conflict.slug, 'unknown' ) + ' — ' + text( conflict.reason, 'unspecified' )
						} );
					} )
				)
			]
		);
	}

	function paintCatalog() {
		var panel = panelFor( 'catalog' );
		var listHost = panel ? panel.querySelector( '[data-sw-skills-list]' ) : null;

		if ( ! listHost ) {
			return;
		}

		var visible = state.skills.filter( function ( skill ) {
			return matchesQuery( skill, state.query );
		} );

		clear( listHost );

		if ( ! visible.length ) {
			listHost.appendChild(
				state.skills.length
					? emptyState( 'no-results', 'No skill matches that filter', 'Try a shorter search, or search by slug, topic or source.' )
					: emptyState( 'first-run', 'No skills yet', 'Write one in the editor, or import a reviewed Markdown file.' )
			);

			return;
		}

		listHost.appendChild(
			el( 'ul', { className: 'sw-skills__list' }, visible.map( skillRow ) )
		);
	}

	function renderCatalog( panel ) {
		if ( ! state.loaded ) {
			if ( hasServerCatalog( panel ) ) {
				return;
			}
			pending( panel, 'Loading the catalog…' );
			return;
		}
		if ( state.loadError ) {
			renderError( panel, state.loadError );
			return;
		}

		clear( panel );
		appendAll( panel, el( 'div', { className: 'sw-ui-stack' }, [
			el( 'div', { className: 'sw-ui-toolbar' }, [
				el( 'div', { className: 'sw-ui-toolbar__search' }, [ searchField() ] ),
				el( 'div', { className: 'sw-ui-actions' }, [
					linkButton( 'New skill', editorUrl( '' ), { variant: 'primary', icon: 'plus' } ),
					button( 'Reload', { onClick: loadCatalog } )
				] )
			] ),
			el( 'p', {
				className: 'sw-ui-field__help',
				text: state.skills.length + ' skill(s) from ' + state.sources.length + ' source(s). ' + state.trashed.length + ' in trash. Skills that ship with Stonewright can be disabled but not removed.',
				attrs: { id: 'sw-skills-protected-hint' }
			} ),
			conflictNotice(),
			el( 'div', { attrs: { 'data-sw-skills-list': '' } } )
		] ) );

		paintCatalog();
	}

	function renderTrash( panel ) {
		if ( ! state.loaded ) {
			pending( panel, 'Loading the trash…' );
			return;
		}
		if ( state.loadError ) {
			renderError( panel, state.loadError );
			return;
		}

		clear( panel );

		if ( ! state.trashed.length ) {
			panel.appendChild(
				emptyState( 'default', 'The trash is empty', 'Skills you move to the trash wait here, disabled, until you restore or delete them.' )
			);

			return;
		}

		appendAll( panel, el( 'div', { className: 'sw-ui-stack' }, [
			el( 'p', {
				className: 'sw-skills__lede',
				text: 'Trashed skills never reach an agent. Restoring returns a skill as a disabled draft, so somebody has to enable it deliberately.'
			} ),
			el(
				'ul',
				{ className: 'sw-skills__list' },
				state.trashed.map( function ( skill ) {
					var title = text( skill.title, skill.slug );

					return el( 'li', { className: 'sw-skill-row sw-skill-row--trashed sw-ui-card' }, [
						el( 'div', { className: 'sw-ui-card__body' }, [
							el( 'div', { className: 'sw-skill-row__head' }, [
								el( 'strong', { className: 'sw-skill-row__title', text: title } ),
								el( 'span', { className: 'sw-skill-row__badges' }, [ badge( 'Trashed' ) ] )
							] ),
							el( 'code', { className: 'sw-skill-row__slug', text: text( skill.slug, 'unknown' ) } ),
							facts( [
								fact( 'Source', originLabel( skill ) ),
								fact( 'Trashed', text( skill.trashed_at, 'unknown' ) )
							], 'Skill facts', true ),
							el( 'div', { className: 'sw-ui-actions' }, [
								button( 'Inspect', {
									size: 'sm',
									icon: 'search',
									context: title,
									onClick: function () {
										openInspector( skill );
									}
								} ),
								button( 'Restore', {
									size: 'sm',
									icon: 'restore',
									context: title,
									disabled: ! canWrite,
									onClick: function () {
										restoreSkill( skill );
									}
								} ),
								button( 'Delete permanently', {
									size: 'sm',
									variant: 'danger',
									icon: 'trash',
									context: title,
									disabled: ! canWrite,
									onClick: function () {
										reviewDestroy( skill );
									}
								} )
							] )
						] )
					] );
				} )
			)
		] ) );
	}

	function importReport() {
		var inspection = state.inspection;
		var lint = inspection.lint || {};
		var trust = inspection.trust || {};
		var collision = inspection.collision || {};
		var errors = Array.isArray( lint.errors ) ? lint.errors : [];
		var warnings = Array.isArray( lint.warnings ) ? lint.warnings : [];
		var findings = Array.isArray( trust.findings ) ? trust.findings : [];
		var blocked = errors.length || trust.blocked || collision.exists;
		var blockedHintId = 'sw-skills-import-hint';

		return el( 'section', { className: 'sw-ui-card', attrs: { 'aria-labelledby': 'sw-skills-report-title' } }, [
			el( 'div', { className: 'sw-ui-card__header' }, [
				el( 'div', null, [
					el( 'h2', { className: 'sw-ui-card__title', text: 'Review: ' + text( inspection.title, inspection.slug ), attrs: { id: 'sw-skills-report-title' } } ),
					el( 'p', { className: 'sw-ui-card__desc', text: text( inspection.description, 'This file has no description.' ) } )
				] )
			] ),
			el( 'div', { className: 'sw-ui-card__body sw-ui-stack' }, [
				facts( [
					fact( 'Slug', text( inspection.slug, 'unknown' ) ),
					fact( 'Bytes', String( inspection.bytes || 0 ) ),
					fact( 'Lint errors', String( errors.length ), errors.length > 0 ),
					fact( 'Warnings', String( warnings.length ) ),
					fact( 'Findings', String( findings.length ), !! trust.blocked ),
					fact( 'Slug in use', collision.exists ? 'yes' : 'no', !! collision.exists )
				], 'Import review', true ),
				errors.length
					? callout( 'danger', 'Lint errors', [ el( 'ul', { className: 'sw-skills__issues' }, errors.map( function ( code ) {
						return el( 'li', { text: String( code ) } );
					} ) ) ] )
					: null,
				findings.length
					? callout( 'warn', 'Findings', [ el( 'ul', { className: 'sw-skills__issues' }, findings.map( function ( finding ) {
						return el( 'li', {
							text: String( finding.severity || 'warning' ) + ' — ' + String( finding.message || finding.rule || '' ) +
								' (line ' + String( finding.line || 0 ) + ')'
						} );
					} ) ) ] )
					: null,
				codeBlock( 'File content', text( inspection.content, '' ), 'File content' ),
				el( 'div', { className: 'sw-ui-actions' }, [
					button( 'Import as disabled draft', {
						variant: 'primary',
						icon: 'plus',
						disabled: !! blocked || ! canWrite,
						describedBy: blocked ? blockedHintId : null,
						onClick: function () {
							reviewImport( inspection );
						}
					} ),
					button( 'Discard review', {
						onClick: function () {
							state.inspection = null;
							render( 'import' );
						}
					} )
				] ),
				blocked
					? el( 'p', { className: 'sw-ui-hint', text: 'Import is off until the reported problems in the file are fixed and it is inspected again.', attrs: { id: blockedHintId } } )
					: null
			] )
		] );
	}

	function dropZone() {
		var input = el( 'input', {
			className: 'sw-ui-file',
			attrs: {
				type: 'file',
				id: 'sw-skills-file',
				accept: '.md,text/markdown'
			},
			on: {
				change: function ( event ) {
					var file = event.target.files && event.target.files[ 0 ];

					if ( file ) {
						readUpload( file );
					}
				}
			}
		} );

		var zone = el( 'div', { className: 'sw-ui-dropzone' }, [
			el( 'p', { text: 'Drop a .md skill file here, or choose one.' } ),
			el( 'label', { className: 'sw-ui-field__label', text: 'Skill file', attrs: { for: 'sw-skills-file' } } ),
			input
		] );

		zone.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
			zone.classList.add( 'sw-ui-dropzone--over' );
		} );
		zone.addEventListener( 'dragleave', function () {
			zone.classList.remove( 'sw-ui-dropzone--over' );
		} );
		zone.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			zone.classList.remove( 'sw-ui-dropzone--over' );

			var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[ 0 ];

			if ( file ) {
				readUpload( file );
			}
		} );

		return zone;
	}

	function renderImport( panel ) {
		clear( panel );
		appendAll( panel, el( 'div', { className: 'sw-ui-stack' }, [
			el( 'p', {
				className: 'sw-skills__lede',
				text: 'An import is two steps: review, then write. The review reads the file on the server and writes nothing.'
			} ),
			dropZone(),
			state.inspectError
				? emptyState( 'error', 'The file could not be reviewed', state.inspectError.message )
				: null,
			state.inspection ? importReport() : null
		] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Views                                                               */
	/* ------------------------------------------------------------------ */

	function editorUrl( slug ) {
		var url = new URL( window.location.href );

		url.searchParams.set( 'view', 'editor' );
		if ( slug ) {
			url.searchParams.set( 'skill', String( slug ) );
		} else {
			url.searchParams.delete( 'skill' );
		}

		return url.toString();
	}

	function render( view ) {
		var panel = panelFor( view );

		if ( ! panel ) {
			return;
		}

		if ( 'catalog' === view ) {
			renderCatalog( panel );
		} else if ( 'trash' === view ) {
			renderTrash( panel );
		} else if ( 'import' === view ) {
			renderImport( panel );
		}
	}

	function showView( view ) {
		state.view = view;
		root.setAttribute( 'data-sw-current-view', view );

		tabNodes.forEach( function ( tab ) {
			var isCurrent = tab.getAttribute( 'data-sw-view' ) === view;

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

		// The editor is a server-rendered form, so it is a real navigation.
		if ( 'editor' === view ) {
			window.location.assign( editorUrl( '' ) );

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
	}

	tabNodes.forEach( function ( tab, index ) {
		tab.addEventListener( 'click', function ( event ) {
			if ( 'editor' === tab.getAttribute( 'data-sw-view' ) ) {
				return;
			}
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
			} else if ( 'Enter' === event.key || ' ' === event.key ) {
				if ( 'editor' !== tab.getAttribute( 'data-sw-view' ) ) {
					event.preventDefault();
					requestView( tab.getAttribute( 'data-sw-view' ) );
				}
			}
		} );
	} );

	window.addEventListener( 'popstate', function () {
		var url = new URL( window.location.href );
		var view = url.searchParams.get( 'view' ) || VIEWS[ 0 ];

		showView( VIEWS.indexOf( view ) === -1 ? VIEWS[ 0 ] : view );
	} );

	showView( VIEWS.indexOf( state.view ) === -1 ? VIEWS[ 0 ] : state.view );
	loadCatalog();
}() );
