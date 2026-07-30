/**
 * Stonewright skill lifecycle.
 *
 * The page owns no lifecycle rules. Reading the catalog, inspecting an upload,
 * importing, exporting, trashing, restoring, and destroying all go through the
 * skills-studio REST routes, which delegate to `Skills`, `SkillImporter`, and
 * `SkillExporter` — so the browser hits exactly the same refusals as any other
 * caller: protected sources, re-derived import readiness, and the
 * production-safe confirmation token on a hard delete.
 *
 * Skill titles, descriptions, and imported Markdown are untrusted content, so
 * this file builds DOM nodes and assigns textContent rather than composing
 * markup. Confirmation happens in a review drawer, never in a native dialog.
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

	var ICON_PATHS = {
		check: 'M3 8.4l3.3 3.3L13 4.6',
		plus: 'M8 3v10M3 8h10',
		trash: 'M3 4.4h10M6.4 4.4V3h3.2v1.4M4.4 4.4l.7 8.2h5.8l.7-8.2',
		restore: 'M2.6 8a5.4 5.4 0 105.4-5.4c-1.8 0-3.4.9-4.4 2.2M2.6 2.6v2.6h2.6',
		download: 'M8 3v7M5 7.4L8 10.4l3-3M3 13h10',
		search: 'M7.2 12.4a5.2 5.2 0 100-10.4 5.2 5.2 0 000 10.4zM11.2 11.2L14 14',
		alert: 'M8 5.6v3.2M8 11.2h.01M8 2.4l6 11.2H2z'
	};

	function icon( name ) {
		var svg = document.createElementNS( SVG_NS, 'svg' );
		var path = document.createElementNS( SVG_NS, 'path' );

		svg.setAttribute( 'class', 'sw-skills-button__icon' );
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
				className: 'sw-skills-button' + ( config.variant ? ' sw-skills-button--' + config.variant : '' ),
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
		return el( 'span', { className: 'sw-badge' + ( tone ? ' sw-badge--' + tone : '' ), text: label } );
	}

	function fact( label, value ) {
		return el( 'li', { className: 'sw-skills-fact' }, [
			el( 'span', { className: 'sw-skills-fact__label', text: label } ),
			el( 'span', { className: 'sw-skills-fact__value', text: value } )
		] );
	}

	function text( value, fallback ) {
		var out = String( typeof value === 'undefined' || null === value ? '' : value ).trim();

		return '' === out ? fallback : out;
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
	 * Opens the review drawer. `options.rows` is the summary the user reviews
	 * before committing: which skill, from which source, in which state.
	 */
	function openDrawer( options ) {
		var config = options || {};

		closeDrawer();
		lastFocused = document.activeElement;

		var titleId = 'sw-skills-drawer-title';
		var rows = ( config.rows || [] ).map( function ( row ) {
			return el( 'li', null, [
				el( 'span', { className: 'sw-skills-review__key', text: row.key } ),
				el( 'span', {
					className: 'sw-skills-review__value' + ( row.tone ? ' sw-skills-review__value--' + row.tone : '' ),
					text: row.value
				} )
			] );
		} );

		var commitButton = button( config.confirmLabel || 'Confirm', {
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
				className: 'sw-skills-drawer',
				attrs: {
					'data-sw-skills-drawer': '',
					role: 'dialog',
					'aria-modal': 'true',
					'aria-labelledby': titleId,
					tabindex: '-1'
				}
			},
			[
				el( 'h2', { className: 'sw-skills-drawer__title', text: config.title || 'Review', attrs: { id: titleId } } ),
				config.lede ? el( 'p', { className: 'sw-skills-drawer__lede', text: config.lede } ) : null,
				el( 'div', { className: 'sw-skills-drawer__body' }, [
					rows.length ? el( 'ul', { className: 'sw-skills-review' }, rows ) : null,
					config.extra || null
				] ),
				el( 'div', { className: 'sw-skills-drawer__footer' }, [
					config.singleAction
						? null
						: button( config.cancelLabel || 'Cancel', {
							onClick: function () {
								closeDrawer();
								if ( config.onCancel ) {
									config.onCancel();
								}
							}
						} ),
					commitButton
				] )
			]
		);

		var scrim = el( 'div', { className: 'sw-skills-scrim' }, [ drawer ] );

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
			commitButton.focus();
		} );
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
			return 'built-in';
		}

		return text( skill.source, 'local' );
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

	function skillBadges( skill ) {
		var out = [ badge( originLabel( skill ), isProtected( skill ) ? 'playbook' : 'neutral' ) ];

		if ( Number( skill.enabled ) ) {
			out.push( badge( 'active', 'active' ) );
			if ( Number( skill.enable_agentic ) ) {
				out.push( badge( 'auto', 'agentic' ) );
			}
			if ( Number( skill.enable_prompt ) ) {
				out.push( badge( 'command', 'prompt' ) );
			}
		} else {
			out.push( badge( 'disabled', 'disabled' ) );
		}

		if ( 'active' !== String( skill.status || '' ) ) {
			out.push( badge( text( skill.status, 'draft' ), 'info' ) );
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
		var body = el( 'pre', {
			className: 'sw-skills-source',
			text: text( skill.content, 'This skill has no body.' )
		} );

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

	function dismissUndo() {
		var existing = root.querySelector( '[data-sw-skills-undo]' );

		if ( existing && existing.parentNode ) {
			existing.parentNode.removeChild( existing );
		}
	}

	/**
	 * The undo affordance. Trash is reversible, so the page says so in place
	 * instead of making the user hunt for the trash view.
	 */
	function offerUndo( skill ) {
		dismissUndo();

		var toast = el(
			'div',
			{
				className: 'sw-skills-toast',
				attrs: {
					'data-sw-skills-undo': '',
					role: 'status',
					'aria-live': 'polite'
				}
			},
			[
				el( 'span', { text: text( skill.title, skill.slug ) + ' moved to trash. It no longer reaches agents.' } ),
				button( 'Undo', {
					variant: 'ghost',
					icon: 'restore',
					onClick: function () {
						dismissUndo();
						restoreSkill( skill );
					}
				} ),
				button( 'Dismiss', { onClick: dismissUndo } )
			]
		);

		root.appendChild( toast );
		window.setTimeout( function () {
			if ( toast.parentNode ) {
				toast.parentNode.removeChild( toast );
			}
		}, UNDO_TIMEOUT );
	}

	function trashSkill( skill ) {
		busy( true );

		post( SKILLS_ROUTE + Number( skill.id ) + TRASH_ACTION, {} ).then( function () {
			announce( text( skill.title, skill.slug ) + ' moved to trash.', 'ok' );
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
				className: 'sw-skills-input',
				attrs: {
					type: 'text',
					id: 'sw-skills-token',
					autocomplete: 'off',
					spellcheck: 'false'
				}
			} );
			extra = el( 'div', { className: 'sw-field' }, [
				el( 'label', {
					text: 'Confirmation token',
					attrs: { for: 'sw-skills-token' }
				} ),
				el( 'p', {
					className: 'description',
					text: 'This site runs in production-safe mode, so a permanent delete needs a token issued by stonewright/security-issue-confirmation-token for this skill id.'
				} ),
				tokenField
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

	function pending( panel, message ) {
		clear( panel ).appendChild( el( 'p', { className: 'sw-skills-panel__loading', text: message } ) );
	}

	function renderError( panel, error ) {
		clear( panel ).appendChild(
			el( 'div', { className: 'sw-empty-state sw-empty-state--error' }, [
				el( 'p', { text: error.message } ),
				error.code ? el( 'p', { className: 'description', text: error.code } ) : null,
				button( 'Try again', { onClick: loadCatalog } )
			] )
		);
	}

	function skillRow( skill ) {
		var actions = [
			button( 'Inspect', {
				icon: 'search',
				onClick: function () {
					openInspector( skill );
				}
			} ),
			button( 'Export', {
				icon: 'download',
				onClick: function () {
					exportSkill( skill );
				}
			} )
		];

		if ( canWrite ) {
			actions.push(
				el( 'a', {
					className: 'sw-skills-button',
					text: 'Edit',
					attrs: { href: editorUrl( skill.slug ) }
				} )
			);
			actions.push(
				button( 'Trash', {
					variant: 'danger',
					icon: 'trash',
					disabled: isProtected( skill ),
					hint: isProtected( skill )
						? 'Skills that ship with Stonewright can be disabled but not removed.'
						: null,
					onClick: function () {
						reviewTrash( skill );
					}
				} )
			);
		}

		return el( 'li', { className: 'sw-skill-row' }, [
			el( 'div', { className: 'sw-skill-row__head' }, [
				el( 'strong', { className: 'sw-skill-row__title', text: text( skill.title, skill.slug ) } ),
				el( 'span', { className: 'sw-skill-row__badges' }, skillBadges( skill ) )
			] ),
			el( 'code', { className: 'sw-skill-row__slug', text: text( skill.slug, 'unknown' ) } ),
			el( 'p', {
				className: 'sw-skill-row__description',
				text: text( skill.description, 'No description, so agents have no trigger text to match on.' )
			} ),
			el( 'ul', { className: 'sw-skills-facts' }, [
				fact( 'Revision', String( skill.revision || 1 ) ),
				fact( 'Verified', String( skill.verification_count || 0 ) ),
				fact( 'Updated', text( skill.updated_at, 'unknown' ) )
			] ),
			el( 'div', { className: 'sw-actions' }, actions )
		] );
	}

	function searchField() {
		var input = el( 'input', {
			className: 'sw-skills-input',
			attrs: {
				type: 'search',
				id: 'sw-skills-search',
				'data-sw-skills-search': '',
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

		return el( 'div', { className: 'sw-field sw-skills-search' }, [
			el( 'label', { text: 'Search skills', attrs: { for: 'sw-skills-search' } } ),
			input
		] );
	}

	function conflictNotice() {
		if ( ! state.conflicts.length ) {
			return null;
		}

		return el( 'div', { className: 'sw-skills-notice' }, [
			el( 'p', { text: state.conflicts.length + ' skill(s) offered by a source were dropped:' } ),
			el(
				'ul',
				null,
				state.conflicts.map( function ( conflict ) {
					return el( 'li', {
						text: text( conflict.slug, 'unknown' ) + ' — ' + text( conflict.reason, 'unspecified' )
					} );
				} )
			)
		] );
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
				el( 'div', { className: 'sw-empty-state' }, [
					el( 'p', {
						text: state.skills.length
							? 'No skill matches that filter.'
							: 'No skills yet. Write one in the editor, or import a reviewed Markdown file.'
					} )
				] )
			);

			return;
		}

		listHost.appendChild(
			el( 'ul', { className: 'sw-skills-list' }, visible.map( skillRow ) )
		);
	}

	function renderCatalog( panel ) {
		if ( ! state.loaded ) {
			pending( panel, 'Loading the catalog…' );
			return;
		}
		if ( state.loadError ) {
			renderError( panel, state.loadError );
			return;
		}

		clear( panel );
		appendAll( panel, [
			el( 'div', { className: 'sw-skills-toolbar' }, [
				searchField(),
				el( 'div', { className: 'sw-actions' }, [
					el( 'a', {
						className: 'sw-skills-button sw-skills-button--primary',
						text: 'New skill',
						attrs: { href: editorUrl( '' ) }
					} ),
					button( 'Reload', { onClick: loadCatalog } )
				] )
			] ),
			el( 'p', {
				className: 'description',
				text: state.skills.length + ' skill(s) from ' + state.sources.length + ' source(s). ' + state.trashed.length + ' in trash.'
			} ),
			conflictNotice(),
			el( 'div', { attrs: { 'data-sw-skills-list': '' } } )
		] );

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
				el( 'div', { className: 'sw-empty-state' }, [
					el( 'p', { text: 'The trash is empty.' } )
				] )
			);

			return;
		}

		appendAll( panel, [
			el( 'p', {
				className: 'description',
				text: 'Trashed skills never reach an agent. Restoring returns a skill as a disabled draft, so somebody has to enable it deliberately.'
			} ),
			el(
				'ul',
				{ className: 'sw-skills-list' },
				state.trashed.map( function ( skill ) {
					return el( 'li', { className: 'sw-skill-row sw-skill-row--trashed' }, [
						el( 'div', { className: 'sw-skill-row__head' }, [
							el( 'strong', { className: 'sw-skill-row__title', text: text( skill.title, skill.slug ) } ),
							el( 'span', { className: 'sw-skill-row__badges' }, [ badge( 'trashed', 'disabled' ) ] )
						] ),
						el( 'code', { className: 'sw-skill-row__slug', text: text( skill.slug, 'unknown' ) } ),
						el( 'ul', { className: 'sw-skills-facts' }, [
							fact( 'Source', originLabel( skill ) ),
							fact( 'Trashed', text( skill.trashed_at, 'unknown' ) )
						] ),
						el( 'div', { className: 'sw-actions' }, [
							button( 'Inspect', {
								icon: 'search',
								onClick: function () {
									openInspector( skill );
								}
							} ),
							button( 'Restore', {
								icon: 'restore',
								disabled: ! canWrite,
								onClick: function () {
									restoreSkill( skill );
								}
							} ),
							button( 'Delete permanently', {
								variant: 'danger',
								icon: 'trash',
								disabled: ! canWrite,
								onClick: function () {
									reviewDestroy( skill );
								}
							} )
						] )
					] );
				} )
			)
		] );
	}

	function reportRow( key, value, tone ) {
		return el( 'li', { className: 'sw-skills-fact' }, [
			el( 'span', { className: 'sw-skills-fact__label', text: key } ),
			el( 'span', {
				className: 'sw-skills-fact__value' + ( tone ? ' sw-skills-fact__value--' + tone : '' ),
				text: value
			} )
		] );
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

		return el( 'div', { className: 'sw-card sw-skills-report' }, [
			el( 'h3', { text: 'Review: ' + text( inspection.title, inspection.slug ) } ),
			el( 'p', { className: 'description', text: text( inspection.description, 'This file has no description.' ) } ),
			el( 'ul', { className: 'sw-skills-facts' }, [
				reportRow( 'Slug', text( inspection.slug, 'unknown' ) ),
				reportRow( 'Bytes', String( inspection.bytes || 0 ) ),
				reportRow( 'Lint errors', String( errors.length ), errors.length ? 'danger' : null ),
				reportRow( 'Warnings', String( warnings.length ) ),
				reportRow( 'Findings', String( findings.length ), trust.blocked ? 'danger' : null ),
				reportRow( 'Slug in use', collision.exists ? 'yes' : 'no', collision.exists ? 'danger' : null )
			] ),
			errors.length
				? el( 'ul', { className: 'sw-skills-issues' }, errors.map( function ( code ) {
					return el( 'li', { text: String( code ) } );
				} ) )
				: null,
			findings.length
				? el( 'ul', { className: 'sw-skills-issues' }, findings.map( function ( finding ) {
					return el( 'li', {
						text: String( finding.severity || 'warning' ) + ' — ' + String( finding.message || finding.rule || '' ) +
							' (line ' + String( finding.line || 0 ) + ')'
					} );
				} ) )
				: null,
			el( 'pre', { className: 'sw-skills-source', text: text( inspection.content, '' ) } ),
			el( 'div', { className: 'sw-actions' }, [
				button( 'Import as disabled draft', {
					variant: 'primary',
					icon: 'plus',
					disabled: !! blocked || ! canWrite,
					hint: blocked ? 'Fix the reported problems in the file, then inspect it again.' : null,
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
			] )
		] );
	}

	function dropZone() {
		var input = el( 'input', {
			className: 'sw-skills-file',
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

		var zone = el( 'div', { className: 'sw-skills-dropzone' }, [
			el( 'p', { text: 'Drop a .md skill file here, or choose one.' } ),
			el( 'label', { text: 'Skill file', attrs: { for: 'sw-skills-file' } } ),
			input
		] );

		zone.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
			zone.classList.add( 'is-over' );
		} );
		zone.addEventListener( 'dragleave', function () {
			zone.classList.remove( 'is-over' );
		} );
		zone.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			zone.classList.remove( 'is-over' );

			var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[ 0 ];

			if ( file ) {
				readUpload( file );
			}
		} );

		return zone;
	}

	function renderImport( panel ) {
		clear( panel );
		appendAll( panel, [
			el( 'p', {
				className: 'description',
				text: 'An import is two steps: review, then write. The review reads the file on the server and writes nothing.'
			} ),
			dropZone(),
			state.inspectError
				? el( 'div', { className: 'sw-empty-state sw-empty-state--error' }, [
					el( 'p', { text: state.inspectError.message } )
				] )
				: null,
			state.inspection ? importReport() : null
		] );
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
