/**
 * Stonewright Recipe Slider — front-end behavior.
 * Vanilla JS, no dependencies. Handles arrows, dots, swipe and auto-rotate.
 */
( function () {
	'use strict';

	const PREFERS_REDUCED_MOTION = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function clearChildren( el ) {
		while ( el && el.firstChild ) {
			el.removeChild( el.firstChild );
		}
	}

	function init( root ) {
		if ( ! root || root.dataset.swRsReady === '1' ) {
			return;
		}
		root.dataset.swRsReady = '1';

		const track = root.querySelector( '.sw-rs__track' );
		const slides = track ? Array.from( track.children ) : [];
		const prev = root.querySelector( '.sw-rs__nav--prev' );
		const next = root.querySelector( '.sw-rs__nav--next' );
		const dotsContainer = root.querySelector( '.sw-rs__dots' );

		if ( ! track || slides.length === 0 ) {
			return;
		}

		const auto = root.dataset.auto === '1';
		const interval = Math.max( 2000, parseInt( root.dataset.interval || '5000', 10 ) );

		function perView() {
			const w = window.innerWidth;
			const styles = getComputedStyle( root );
			const d = parseInt( styles.getPropertyValue( '--sw-rs-pv-d' ) || '4', 10 );
			const t = parseInt( styles.getPropertyValue( '--sw-rs-pv-t' ) || '2', 10 );
			const m = parseInt( styles.getPropertyValue( '--sw-rs-pv-m' ) || '1', 10 );
			if ( w <= 640 ) { return Math.max( 1, m ); }
			if ( w <= 1024 ) { return Math.max( 1, t ); }
			return Math.max( 1, d );
		}

		function pageCount() {
			return Math.max( 1, Math.ceil( slides.length / perView() ) );
		}

		let current = 0;

		function buildDots() {
			if ( ! dotsContainer ) return;
			clearChildren( dotsContainer );
			const count = pageCount();
			for ( let i = 0; i < count; i++ ) {
				const dot = document.createElement( 'button' );
				dot.type = 'button';
				dot.className = 'sw-rs__dot';
				dot.setAttribute( 'role', 'tab' );
				dot.setAttribute( 'aria-label', 'Slide ' + ( i + 1 ) );
				if ( i === current ) {
					dot.classList.add( 'is-active' );
					dot.setAttribute( 'aria-selected', 'true' );
				}
				dot.addEventListener( 'click', function () {
					goTo( i );
					restart();
				} );
				dotsContainer.appendChild( dot );
			}
		}

		function updateDots() {
			if ( ! dotsContainer ) return;
			Array.from( dotsContainer.children ).forEach( function ( el, i ) {
				const active = i === current;
				el.classList.toggle( 'is-active', active );
				el.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );
		}

		function updateArrows() {
			if ( prev ) {
				prev.toggleAttribute( 'disabled', current === 0 );
			}
			if ( next ) {
				next.toggleAttribute( 'disabled', current >= pageCount() - 1 );
			}
		}

		function goTo( index ) {
			const total = pageCount();
			if ( total === 0 ) return;
			current = ( ( index % total ) + total ) % total;
			const pv = perView();
			const slideIndex = Math.min( slides.length - 1, current * pv );
			const target = slides[ slideIndex ];
			if ( target ) {
				const trackLeft = track.getBoundingClientRect().left;
				const targetLeft = target.getBoundingClientRect().left;
				track.scrollTo( {
					left: track.scrollLeft + ( targetLeft - trackLeft ),
					behavior: PREFERS_REDUCED_MOTION ? 'auto' : 'smooth',
				} );
			}
			updateDots();
			updateArrows();
		}

		function nextPage() {
			const total = pageCount();
			goTo( ( current + 1 ) % total );
		}

		function prevPage() {
			const total = pageCount();
			goTo( ( current - 1 + total ) % total );
		}

		let timer = null;
		function start() {
			if ( ! auto || PREFERS_REDUCED_MOTION || pageCount() <= 1 ) return;
			stop();
			timer = window.setInterval( nextPage, interval );
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		function restart() {
			stop();
			start();
		}

		if ( prev ) {
			prev.addEventListener( 'click', function () { prevPage(); restart(); } );
		}
		if ( next ) {
			next.addEventListener( 'click', function () { nextPage(); restart(); } );
		}

		root.addEventListener( 'mouseenter', stop );
		root.addEventListener( 'mouseleave', start );
		root.addEventListener( 'focusin', stop );
		root.addEventListener( 'focusout', start );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) { stop(); } else { start(); }
		} );

		let scrollTimer = null;
		track.addEventListener( 'scroll', function () {
			if ( scrollTimer ) window.clearTimeout( scrollTimer );
			scrollTimer = window.setTimeout( function () {
				const pv = perView();
				const trackLeft = track.getBoundingClientRect().left;
				let bestIndex = 0;
				let bestDistance = Infinity;
				slides.forEach( function ( s, i ) {
					const d = Math.abs( s.getBoundingClientRect().left - trackLeft );
					if ( d < bestDistance ) {
						bestDistance = d;
						bestIndex = i;
					}
				} );
				const page = Math.floor( bestIndex / pv );
				if ( page !== current ) {
					current = page;
					updateDots();
					updateArrows();
				}
			}, 120 );
		}, { passive: true } );

		root.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' ) { prevPage(); restart(); }
			if ( e.key === 'ArrowRight' ) { nextPage(); restart(); }
		} );

		let touchStartX = null;
		track.addEventListener( 'touchstart', function ( e ) {
			touchStartX = e.touches[ 0 ].clientX;
			stop();
		}, { passive: true } );
		track.addEventListener( 'touchend', function ( e ) {
			if ( touchStartX === null ) return;
			const dx = e.changedTouches[ 0 ].clientX - touchStartX;
			if ( Math.abs( dx ) > 30 ) {
				if ( dx < 0 ) { nextPage(); } else { prevPage(); }
			}
			touchStartX = null;
			start();
		} );

		let resizeTimer = null;
		window.addEventListener( 'resize', function () {
			if ( resizeTimer ) window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( function () {
				current = Math.min( current, pageCount() - 1 );
				buildDots();
				goTo( current );
			}, 150 );
		} );

		buildDots();
		updateArrows();
		start();
	}

	function boot() {
		document.querySelectorAll( '.sw-recipe-slider' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
