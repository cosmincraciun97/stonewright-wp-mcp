/**
 * Troubleshoot page: runs the connection checks in place and paints the result.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Enhances the server-rendered form: without this script the form posts to admin-post.php and the page reloads
 * with the last report. With it, the checks run through admin-ajax.php, the results region is marked busy and
 * shows placeholders while it waits, and the report is painted with the layer's classes. Every string comes from
 * stonewrightTroubleshoot.text and is written with textContent, never as markup. A failed run says so in a notice
 * that stays until the next run.
 */
( function () {
	'use strict';

	var config = window.stonewrightTroubleshoot;
	var ICONS = { ok: 'check', warning: 'alert', info: 'info', problem: 'x' };
	var VARIANTS = { ok: ' sw-ui-badge--ok', warning: ' sw-ui-badge--warn', info: ' sw-ui-badge--info', problem: ' sw-ui-badge--danger', skipped: '' };

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		return node;
	}

	/** One of a [ singular, plural ] pair, with its number filled in. */
	function plural( pair, number ) {
		return pair[ number === 1 ? 0 : 1 ].replace( '%d', String( number ) );
	}

	function icon( name ) {
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		var use = document.createElementNS( 'http://www.w3.org/2000/svg', 'use' );
		svg.setAttribute( 'class', 'sw-ui-icon' );
		svg.setAttribute( 'aria-hidden', 'true' );
		use.setAttribute( 'href', '#sw-ui-icon-' + name );
		svg.appendChild( use );
		return svg;
	}

	function normalize( status ) {
		var value = String( status || 'problem' ).replace( /[^a-z]/gi, '' ).toLowerCase();
		if ( value === 'error' ) {
			return 'problem';
		}
		if ( value === 'warn' ) {
			return 'warning';
		}
		return [ 'ok', 'info', 'warning', 'problem', 'skipped' ].indexOf( value ) === -1 ? 'problem' : value;
	}

	function badge( status ) {
		var node = el( 'span', 'sw-ui-badge' + ( VARIANTS[ status ] || '' ) );
		if ( ICONS[ status ] ) {
			node.appendChild( icon( ICONS[ status ] ) );
		}
		node.appendChild( document.createTextNode( config.text.badges[ status ] ) );
		return node;
	}

	function declaredAction( check ) {
		var action = check && check.action && typeof check.action === 'object' ? check.action : null;
		if ( ! action ) {
			return null;
		}
		var type = String( action.type || '' );
		var label = String( action.label || '' );
		var target = String( action.target || '' ).trim();
		if ( [ 'copy', 'link', 'retry' ].indexOf( type ) === -1 || ! label || ! target || ( type === 'link' && /^javascript:/i.test( target ) ) ) {
			return null;
		}
		return { type: type, label: label, target: target };
	}

	function actionCell( check, status ) {
		var cell = el( 'td', 'sw-ui-table__actions' );
		var copyText = String( check.copy || check.ticket || '' );
		var action = declaredAction( check );
		var id = String( check.id || 'check' ).replace( /[^a-z0-9_-]/gi, '' );
		if ( ! action && copyText ) {
			action = { type: 'copy', label: config.text.copyHosting, target: 'stonewright-diag-ticket-' + id };
		}
		if ( ! action ) {
			return cell;
		}
		if ( action.type === 'link' ) {
			var link = el( 'a', 'sw-ui-btn sw-ui-btn--sm', action.label );
			link.href = action.target;
			cell.appendChild( link );
		} else if ( action.type === 'retry' ) {
			var retry = el( 'button', 'sw-ui-btn sw-ui-btn--sm', action.label );
			retry.type = 'button';
			retry.setAttribute( 'data-sw-diag-run', '' );
			cell.appendChild( retry );
		} else {
			var copyId = action.target.replace( /[^a-z0-9_-]/gi, '' );
			var button = el( 'button', 'sw-ui-btn sw-ui-btn--sm', action.label );
			button.type = 'button';
			button.setAttribute( 'data-sw-ui-copy', '#' + copyId );
			button.setAttribute( 'data-sw-ui-copied-label', config.text.copied );
			button.setAttribute( 'data-sw-ui-copy-failed-label', config.text.copyFailed );
			var source = el( 'pre', '', copyText );
			source.id = copyId;
			source.hidden = true;
			cell.appendChild( button );
			cell.appendChild( source );
		}
		return cell;
	}

	function table( checks, caption ) {
		var node = el( 'table', 'sw-ui-table sw-ui-table--stack' );
		node.appendChild( el( 'caption', 'sw-ui-visually-hidden', caption ) );
		var head = el( 'tr' );
		[ config.text.colCheck, config.text.colResult, config.text.colAction ].forEach( function ( label, index ) {
			var th = el( 'th' );
			th.scope = 'col';
			if ( index === 2 ) {
				th.className = 'sw-ui-table__actions';
				th.appendChild( el( 'span', 'sw-ui-visually-hidden', label ) );
			} else {
				th.textContent = label;
			}
			head.appendChild( th );
		} );
		node.appendChild( el( 'thead' ) ).appendChild( head );
		var body = el( 'tbody' );
		checks.forEach( function ( check ) {
			var status = normalize( check.status );
			var row = el( 'tr' );
			var primary = el( 'td', 'sw-ui-table__primary-cell' );
			primary.appendChild( el( 'span', 'sw-ui-table__primary', check.label || '' ) );
			var summary = check.summary || check.detail || '';
			if ( summary ) {
				primary.appendChild( el( 'span', 'sw-ui-table__meta', summary ) );
			}
			if ( check.remedy && ( status === 'problem' || status === 'warning' ) ) {
				primary.appendChild( el( 'span', 'sw-ui-table__meta', check.remedy ) );
			}
			var result = el( 'td' );
			result.setAttribute( 'data-label', config.text.colResult );
			result.appendChild( badge( status ) );
			row.appendChild( primary );
			row.appendChild( result );
			row.appendChild( actionCell( check, status ) );
			body.appendChild( row );
		} );
		node.appendChild( body );
		return node;
	}

	function folded( summary, content ) {
		var details = el( 'details', 'sw-ui-disclosure' );
		var label = el( 'summary' );
		label.appendChild( icon( 'chev-r' ) );
		label.appendChild( document.createTextNode( summary ) );
		details.appendChild( label );
		var body = el( 'div', 'sw-ui-disclosure__body' );
		body.appendChild( content );
		details.appendChild( body );
		return details;
	}

	function summaryText( problems, warnings, unfinished ) {
		var p = plural( config.text.problem, problems );
		var w = plural( config.text.warning, warnings );
		if ( problems && warnings ) {
			return config.text.both.replace( '%1$s', p ).replace( '%2$s', w );
		}
		if ( problems || warnings ) {
			return config.text.toLookAt.replace( '%s', problems ? p : w );
		}
		return unfinished ? config.text.noProblemsYet : config.text.noProblems;
	}

	function formatReport( report ) {
		var lines = [ 'Stonewright support report' ];
		var evidenceKeys = [ 'http_status', 'duration_ms', 'error_code', 'error_class', 'timeout' ];
		var versionLabels = { plugin: 'Plugin', companion_contract: 'Companion', wordpress: 'WordPress', php: 'PHP', tool_count: 'Tools' };
		if ( report.method ) {
			lines.push( 'Method: ' + String( report.method ).replace( /[^a-z0-9_-]/gi, '' ) );
		}
		if ( report.correlation_id ) {
			lines.push( 'Correlation: ' + String( report.correlation_id ).slice( 0, 64 ) );
		}
		var versions = report.versions || {};
		Object.keys( versionLabels ).forEach( function ( key ) {
			if ( versions[ key ] !== undefined && versions[ key ] !== null ) {
				lines.push( versionLabels[ key ] + ': ' + String( versions[ key ] ) );
			}
		} );
		var counts = report.counts || {};
		var bits = [];
		[ 'problem', 'warning', 'info', 'ok', 'skipped' ].forEach( function ( key ) {
			if ( typeof counts[ key ] === 'number' ) {
				bits.push( key + '=' + counts[ key ] );
			}
		} );
		if ( bits.length ) {
			lines.push( 'Counts: ' + bits.join( ' ' ) );
		}
		( report.checks || [] ).forEach( function ( check ) {
			if ( ! check || ! check.id || ! check.status ) {
				return;
			}
			lines.push( '[' + check.status + '] ' + check.id );
			var evidence = check.evidence || {};
			evidenceKeys.forEach( function ( key ) {
				if ( evidence[ key ] !== undefined && evidence[ key ] !== null && typeof evidence[ key ] !== 'object' ) {
					lines.push( '  ' + key + '=' + String( evidence[ key ] ).slice( 0, 200 ) );
				}
			} );
		} );
		return lines.join( '\n' ).trim();
	}

	/** Paints the report into the results region and the summary; returns the first row that needs attention. */
	function paint( root, report ) {
		var results = root.querySelector( '[data-sw-diag-results]' );
		var summary = root.querySelector( '[data-sw-diag-summary]' );
		var groups = { problem: [], warning: [], skipped: [], info: [], ok: [] };
		( report.checks || [] ).forEach( function ( check ) {
			groups[ normalize( check.status ) ].push( check );
		} );
		var counts = report.counts || {};
		var problems = typeof counts.problem === 'number' ? counts.problem : groups.problem.length;
		var warnings = typeof counts.warning === 'number' ? counts.warning : groups.warning.length;

		results.textContent = '';
		var attention = groups.problem.concat( groups.warning );
		if ( attention.length ) {
			results.appendChild( table( attention, config.text.attention ) );
		}
		var other = groups.skipped.concat( groups.info );
		if ( other.length ) {
			results.appendChild( folded( plural( config.text.otherChecks, other.length ), table( other, config.text.other ) ) );
		}
		if ( groups.ok.length ) {
			results.appendChild( folded( plural( config.text.passedChecks, groups.ok.length ), table( groups.ok, config.text.passed ) ) );
		}

		summary.textContent = '';
		summary.appendChild( el( problems + warnings > 0 ? 'strong' : 'span', '', summaryText( problems, warnings, groups.info.length + groups.skipped.length > 0 ) ) );

		var source = root.querySelector( '[data-sw-diag-copy]' );
		if ( source ) {
			source.textContent = formatReport( report );
		}
		return results.querySelector( 'table tbody tr' );
	}

	function skeleton() {
		var wrap = el( 'div' );
		[ 'sw-ui-skeleton sw-ui-skeleton--row', 'sw-ui-skeleton sw-ui-skeleton--row', 'sw-ui-skeleton sw-ui-skeleton--row' ].forEach( function ( name ) {
			wrap.appendChild( el( 'span', name ) );
		} );
		wrap.appendChild( el( 'span', 'sw-ui-visually-hidden', config.text.running ) );
		return wrap;
	}

	function init() {
		var root = document.querySelector( '[data-sw-diagnostics]' );
		var form = document.getElementById( 'stonewright-diagnostics-form' );
		if ( ! root || ! form || ! config || ! config.ajaxUrl ) {
			return;
		}
		var results = root.querySelector( '[data-sw-diag-results]' );
		var summary = root.querySelector( '[data-sw-diag-summary]' );
		var mode = root.querySelector( '[data-sw-diag-mode]' );
		var symptom = root.querySelector( '[data-sw-diag-symptom]' );
		var help = root.querySelector( '[data-sw-diag-help]' );
		var running = false;

		if ( symptom && help ) {
			symptom.addEventListener( 'change', function () {
				var text = config.text.help[ symptom.value ] || '';
				help.textContent = text;
				help.hidden = ! text;
			} );
		}

		function busy( on ) {
			running = on;
			results.setAttribute( 'aria-busy', on ? 'true' : 'false' );
			root.querySelectorAll( '[data-sw-diag-run]' ).forEach( function ( button ) {
				if ( on ) {
					button.setAttribute( 'aria-busy', 'true' );
				} else {
					button.removeAttribute( 'aria-busy' );
				}
			} );
			if ( mode ) {
				mode.disabled = on;
			}
		}

		function run( event ) {
			event.preventDefault();
			if ( running ) {
				return;
			}
			busy( true );
			var previous = root.querySelector( '[data-sw-diag-error]' );
			if ( previous ) {
				previous.remove();
			}
			results.textContent = '';
			results.appendChild( skeleton() );
			summary.textContent = config.text.running;

			var body = new window.URLSearchParams();
			body.set( 'action', 'stonewright_run_diagnostics' );
			body.set( 'nonce', config.nonce || '' );
			body.set( 'mode', mode && mode.value ? mode.value : 'not-sure' );

			window.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} ).then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok || ! data || ! data.success ) {
						throw new Error( 'run failed' );
					}
					return data.data || {};
				} );
			} ).then( function ( report ) {
				var first = paint( root, report );
				if ( window.Stonewright && window.Stonewright.ui ) {
					window.Stonewright.ui.announce( config.text.done );
					if ( first ) {
						window.Stonewright.ui.scrollTo( first );
					}
				}
			} ).catch( function () {
				results.textContent = '';
				summary.textContent = '';
				if ( window.Stonewright && window.Stonewright.ui ) {
					var notice = window.Stonewright.ui.notify( results, { variant: 'danger', title: config.text.failedTitle, text: config.text.failedText } );
					notice.setAttribute( 'data-sw-diag-error', '' );
				}
			} ).then( function () {
				busy( false );
			} );
		}

		form.addEventListener( 'submit', run );
		root.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest ? event.target.closest( '[data-sw-diag-run]' ) : null;
			if ( trigger && trigger.type !== 'submit' ) {
				run( event );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
