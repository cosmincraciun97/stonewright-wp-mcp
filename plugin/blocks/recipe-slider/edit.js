/**
 * Stonewright Recipe Slider — block editor implementation.
 *
 * Uses plain JS via wp.element.createElement so no build step is required.
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment, useState } = wp.element;
	const {
		useBlockProps,
		InspectorControls,
		RichText,
		MediaUploadCheck,
		MediaUpload,
		BlockControls,
		PanelColorSettings,
	} = wp.blockEditor;
	const {
		PanelBody,
		ToggleControl,
		RangeControl,
		TextControl,
		Button,
		ToolbarGroup,
		ToolbarButton,
		__experimentalToggleGroupControl: ToggleGroupControl,
		__experimentalToggleGroupControlOption: ToggleGroupControlOption,
	} = wp.components;
	const { __ } = wp.i18n;

	const DIFFICULTY_OPTIONS = [ 'Ușoară', 'Medie', 'Complexă' ];

	function ChevronIcon( props ) {
		return el(
			'svg',
			{ width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', 'aria-hidden': 'true' },
			el( 'path', {
				d: props.direction === 'prev' ? 'M15 6l-6 6 6 6' : 'M9 6l6 6-6 6',
				stroke: 'currentColor',
				strokeWidth: 2,
				strokeLinecap: 'round',
				strokeLinejoin: 'round',
			} )
		);
	}

	function ChefIcon() {
		return el(
			'svg',
			{ width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', 'aria-hidden': 'true' },
			el( 'path', {
				d: 'M7 14a4 4 0 1 1-1-7.87A5 5 0 0 1 16 4a5 5 0 0 1 2 9.6V20H7v-6z',
				stroke: 'currentColor',
				strokeWidth: 1.6,
				strokeLinejoin: 'round',
			} )
		);
	}

	function ClockIcon() {
		return el(
			'svg',
			{ width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', 'aria-hidden': 'true' },
			el( 'circle', { cx: 12, cy: 13, r: 8, stroke: 'currentColor', strokeWidth: 1.6 } ),
			el( 'path', { d: 'M12 9v4l2.5 2.5M9 3h6', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round' } )
		);
	}

	function difficultyLevel( difficulty ) {
		const map = { 'Ușoară': 1, 'Usoara': 1, 'Medie': 2, 'Medium': 2, 'Complexă': 3, 'Complexa': 3 };
		return map[ difficulty ] || 1;
	}

	function styleVarsFromAttrs( attrs ) {
		return {
			'--sw-rs-bg': attrs.backgroundColor,
			'--sw-rs-text': attrs.textColor,
			'--sw-rs-accent': attrs.accentColor,
			'--sw-rs-card': attrs.cardColor,
			'--sw-rs-pv-d': attrs.perViewDesktop,
			'--sw-rs-pv-t': attrs.perViewTablet,
			'--sw-rs-pv-m': attrs.perViewMobile,
		};
	}

	function SlideCard( props ) {
		const { slide, index, updateSlide, removeSlide, moveSlide, isOnly } = props;
		const level = difficultyLevel( slide.difficulty );

		return el(
			'article',
			{ className: 'sw-rs__card sw-rs__card-edit' },
			el(
				'div',
				{ className: 'sw-rs__card-media' },
				slide.image
					? el( 'img', { src: slide.image, alt: slide.imageAlt || '' } )
					: el(
							'span',
							{ className: 'sw-rs__card-media-fallback', 'aria-hidden': 'true' },
							el(
								'svg',
								{ width: 48, height: 48, viewBox: '0 0 24 24', fill: 'none' },
								el( 'rect', { x: 3, y: 3, width: 18, height: 18, rx: 2, stroke: 'currentColor', strokeWidth: 1.5 } ),
								el( 'path', { d: 'M3 16l5-5 5 5 3-3 5 5', stroke: 'currentColor', strokeWidth: 1.5, strokeLinecap: 'round', strokeLinejoin: 'round' } ),
								el( 'circle', { cx: 9, cy: 9, r: 1.5, stroke: 'currentColor', strokeWidth: 1.5 } )
							)
					  ),
				el(
					'div',
					{ className: 'sw-rs__media-pick' },
					el( MediaUploadCheck, null,
						el( MediaUpload, {
							onSelect: function ( media ) {
								updateSlide( index, { image: media.url, imageAlt: media.alt || slide.imageAlt } );
							},
							allowedTypes: [ 'image' ],
							value: slide.imageId,
							render: function ( picker ) {
								return el(
									Button,
									{ variant: 'primary', onClick: picker.open, size: 'small' },
									slide.image ? __( 'Schimbă imagine', 'stonewright' ) : __( 'Adaugă imagine', 'stonewright' )
								);
							},
						} )
					)
				)
			),
			el(
				'div',
				{ className: 'sw-rs__card-body' },
				el( RichText, {
					tagName: 'h3',
					className: 'sw-rs__card-title',
					value: slide.title,
					onChange: function ( v ) { updateSlide( index, { title: v } ); },
					placeholder: __( 'Titlu rețetă…', 'stonewright' ),
					allowedFormats: [],
				} ),
				el(
					'div',
					{ className: 'sw-rs__card-meta' },
					el(
						'div',
						{ className: 'sw-rs__card-difficulty', 'data-level': level },
						el(
							'span',
							{ className: 'sw-rs__chefs', 'aria-hidden': 'true' },
							[ 1, 2, 3 ].map( function ( k ) {
								return el(
									'span',
									{ key: k, className: 'sw-rs__chef ' + ( k <= level ? 'is-on' : 'is-off' ) },
									el( ChefIcon )
								);
							} )
						),
						el( 'select', {
							className: 'sw-rs__card-text-input',
							value: slide.difficulty || 'Ușoară',
							onChange: function ( e ) { updateSlide( index, { difficulty: e.target.value } ); },
							style: { maxWidth: 130, marginLeft: 4 },
						},
							DIFFICULTY_OPTIONS.map( function ( opt ) {
								return el( 'option', { key: opt, value: opt }, opt );
							} )
						)
					),
					el(
						'div',
						{ className: 'sw-rs__card-time' },
						el( ClockIcon ),
						el( 'input', {
							type: 'text',
							className: 'sw-rs__card-text-input',
							value: slide.time || '',
							onChange: function ( e ) { updateSlide( index, { time: e.target.value } ); },
							placeholder: __( '45 min.', 'stonewright' ),
							style: { maxWidth: 110 },
						} )
					)
				),
				el(
					'div',
					{ className: 'sw-rs__slide-edit-toolbar' },
					el(
						Button,
						{ variant: 'tertiary', size: 'small', onClick: function () { moveSlide( index, -1 ); }, disabled: index === 0 },
						__( '←', 'stonewright' )
					),
					el(
						Button,
						{ variant: 'tertiary', size: 'small', onClick: function () { moveSlide( index, 1 ); } },
						__( '→', 'stonewright' )
					),
					el(
						Button,
						{ variant: 'tertiary', size: 'small', isDestructive: true, onClick: function () { removeSlide( index ); }, disabled: isOnly },
						__( 'Șterge', 'stonewright' )
					)
				),
				el( TextControl, {
					label: __( 'Link rețetă (URL)', 'stonewright' ),
					value: slide.url || '',
					onChange: function ( v ) { updateSlide( index, { url: v } ); },
					__nextHasNoMarginBottom: true,
				} )
			)
		);
	}

	function Edit( props ) {
		const { attributes, setAttributes } = props;
		const blockProps = useBlockProps( {
			className: 'sw-recipe-slider',
			style: styleVarsFromAttrs( attributes ),
		} );

		function updateSlide( index, patch ) {
			const next = attributes.slides.map( function ( s, i ) {
				return i === index ? Object.assign( {}, s, patch ) : s;
			} );
			setAttributes( { slides: next } );
		}

		function removeSlide( index ) {
			const next = attributes.slides.filter( function ( _, i ) { return i !== index; } );
			setAttributes( { slides: next } );
		}

		function moveSlide( index, delta ) {
			const next = attributes.slides.slice();
			const target = index + delta;
			if ( target < 0 || target >= next.length ) return;
			const tmp = next[ index ];
			next[ index ] = next[ target ];
			next[ target ] = tmp;
			setAttributes( { slides: next } );
		}

		function addSlide() {
			const next = attributes.slides.concat( [ {
				title: '',
				image: '',
				imageAlt: '',
				difficulty: 'Ușoară',
				time: '30 min.',
				url: '#',
			} ] );
			setAttributes( { slides: next } );
		}

		return el(
			Fragment,
			null,
			el(
				BlockControls,
				null,
				el(
					ToolbarGroup,
					null,
					el( ToolbarButton, {
						icon: 'plus-alt2',
						label: __( 'Adaugă slide', 'stonewright' ),
						onClick: addSlide,
					} )
				)
			),
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Cap & subtitlu', 'stonewright' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Titlu', 'stonewright' ),
						value: attributes.heading,
						onChange: function ( v ) { setAttributes( { heading: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'Subtitlu', 'stonewright' ),
						value: attributes.subheading,
						onChange: function ( v ) { setAttributes( { subheading: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'CTA text', 'stonewright' ),
						value: attributes.ctaText,
						onChange: function ( v ) { setAttributes( { ctaText: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'CTA URL', 'stonewright' ),
						value: attributes.ctaUrl,
						onChange: function ( v ) { setAttributes( { ctaUrl: v } ); },
						__nextHasNoMarginBottom: true,
					} )
				),
				el(
					PanelBody,
					{ title: __( 'Comportament slider', 'stonewright' ), initialOpen: true },
					el( ToggleControl, {
						label: __( 'Auto-rotire', 'stonewright' ),
						checked: !! attributes.autoRotate,
						onChange: function ( v ) { setAttributes( { autoRotate: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label: __( 'Interval (ms)', 'stonewright' ),
						value: attributes.interval,
						onChange: function ( v ) { setAttributes( { interval: v } ); },
						min: 2000,
						max: 15000,
						step: 500,
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label: __( 'Săgeți', 'stonewright' ),
						checked: !! attributes.showArrows,
						onChange: function ( v ) { setAttributes( { showArrows: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label: __( 'Indicatori (puncte)', 'stonewright' ),
						checked: !! attributes.showDots,
						onChange: function ( v ) { setAttributes( { showDots: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label: __( 'Slide-uri vizibile / desktop', 'stonewright' ),
						value: attributes.perViewDesktop,
						onChange: function ( v ) { setAttributes( { perViewDesktop: v } ); },
						min: 1,
						max: 6,
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label: __( 'Slide-uri vizibile / tabletă', 'stonewright' ),
						value: attributes.perViewTablet,
						onChange: function ( v ) { setAttributes( { perViewTablet: v } ); },
						min: 1,
						max: 4,
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label: __( 'Slide-uri vizibile / mobil', 'stonewright' ),
						value: attributes.perViewMobile,
						onChange: function ( v ) { setAttributes( { perViewMobile: v } ); },
						min: 1,
						max: 2,
						__nextHasNoMarginBottom: true,
					} )
				),
				el(
					PanelColorSettings,
					{
						title: __( 'Culori', 'stonewright' ),
						initialOpen: false,
						colorSettings: [
							{
								value: attributes.backgroundColor,
								onChange: function ( v ) { setAttributes( { backgroundColor: v } ); },
								label: __( 'Fundal secțiune', 'stonewright' ),
							},
							{
								value: attributes.textColor,
								onChange: function ( v ) { setAttributes( { textColor: v } ); },
								label: __( 'Text secțiune', 'stonewright' ),
							},
							{
								value: attributes.accentColor,
								onChange: function ( v ) { setAttributes( { accentColor: v } ); },
								label: __( 'CTA (accent)', 'stonewright' ),
							},
							{
								value: attributes.cardColor,
								onChange: function ( v ) { setAttributes( { cardColor: v } ); },
								label: __( 'Fundal card', 'stonewright' ),
							},
						],
					}
				)
			),
			el(
				'section',
				blockProps,
				el(
					'div',
					{ className: 'sw-rs__inner' },
					el(
						'header',
						{ className: 'sw-rs__header' },
						el( RichText, {
							tagName: 'h2',
							className: 'sw-rs__heading',
							value: attributes.heading,
							onChange: function ( v ) { setAttributes( { heading: v } ); },
							placeholder: __( 'Titlu secțiune…', 'stonewright' ),
							allowedFormats: [],
						} ),
						el( RichText, {
							tagName: 'p',
							className: 'sw-rs__subheading',
							value: attributes.subheading,
							onChange: function ( v ) { setAttributes( { subheading: v } ); },
							placeholder: __( 'Subtitlu opțional…', 'stonewright' ),
							allowedFormats: [ 'core/bold', 'core/italic' ],
						} )
					),
					el(
						'div',
						{ className: 'sw-rs__viewport' },
						el(
							'span',
							{ className: 'sw-rs__nav sw-rs__nav--prev', 'aria-hidden': 'true' },
							el( ChevronIcon, { direction: 'prev' } )
						),
						el(
							'ul',
							{ className: 'sw-rs__track', role: 'list' },
							attributes.slides.map( function ( slide, i ) {
								return el(
									'li',
									{ key: i, className: 'sw-rs__slide' },
									el( SlideCard, {
										slide: slide,
										index: i,
										updateSlide: updateSlide,
										removeSlide: removeSlide,
										moveSlide: moveSlide,
										isOnly: attributes.slides.length === 1,
									} )
								);
							} )
						),
						el(
							'span',
							{ className: 'sw-rs__nav sw-rs__nav--next', 'aria-hidden': 'true' },
							el( ChevronIcon, { direction: 'next' } )
						)
					),
					el(
						'button',
						{ type: 'button', className: 'sw-rs__add-slide', onClick: addSlide },
						__( '+ Adaugă slide', 'stonewright' )
					),
					attributes.ctaText
						? el(
								'a',
								{ className: 'sw-rs__cta', href: '#', onClick: function ( e ) { e.preventDefault(); } },
								el( 'span', null, attributes.ctaText ),
								el(
									'svg',
									{ width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none', 'aria-hidden': 'true' },
									el( 'path', {
										d: 'M5 12h14m-6-6 6 6-6 6',
										stroke: 'currentColor',
										strokeWidth: 2,
										strokeLinecap: 'round',
										strokeLinejoin: 'round',
									} )
								)
						  )
						: null
				)
			)
		);
	}

	registerBlockType( 'stonewright/recipe-slider', {
		edit: Edit,
		save: function () { return null; },
	} );
} )( window.wp );
