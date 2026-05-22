/**
 * Stonewright Recipe Hero — block editor implementation.
 * Plain JS via wp.element.createElement, no build step.
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const {
		useBlockProps,
		InspectorControls,
		RichText,
		MediaUpload,
		MediaUploadCheck,
		PanelColorSettings,
	} = wp.blockEditor;
	const {
		PanelBody,
		ToggleControl,
		TextControl,
		Button,
	} = wp.components;
	const { __ } = wp.i18n;

	function styleVars( attrs ) {
		const vars = {
			'--sw-rh-bg': attrs.backgroundColor,
			'--sw-rh-text': attrs.textColor,
			'--sw-rh-accent': attrs.accentColor,
		};
		if ( attrs.image ) {
			vars[ '--sw-rh-image' ] = 'url(' + attrs.image + ')';
		}
		return vars;
	}

	function Edit( props ) {
		const { attributes, setAttributes } = props;
		const blockProps = useBlockProps( {
			className: 'sw-recipe-hero',
			style: styleVars( attributes ),
		} );

		function updateStat( index, patch ) {
			const next = attributes.stats.map( function ( s, i ) {
				return i === index ? Object.assign( {}, s, patch ) : s;
			} );
			setAttributes( { stats: next } );
		}

		function addStat() {
			setAttributes( { stats: attributes.stats.concat( [ { label: '', value: '', unit: '' } ] ) } );
		}

		function removeStat( index ) {
			setAttributes( { stats: attributes.stats.filter( function ( _, i ) { return i !== index; } ) } );
		}

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Conținut hero', 'stonewright' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Breadcrumb', 'stonewright' ),
						value: attributes.breadcrumb,
						onChange: function ( v ) { setAttributes( { breadcrumb: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'Categorie', 'stonewright' ),
						value: attributes.category,
						onChange: function ( v ) { setAttributes( { category: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'Dificultate', 'stonewright' ),
						value: attributes.difficulty,
						onChange: function ( v ) { setAttributes( { difficulty: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'Timp', 'stonewright' ),
						value: attributes.time,
						onChange: function ( v ) { setAttributes( { time: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'Porții', 'stonewright' ),
						value: attributes.servings,
						onChange: function ( v ) { setAttributes( { servings: v } ); },
						__nextHasNoMarginBottom: true,
					} )
				),
				el(
					PanelBody,
					{ title: __( 'Video / play button', 'stonewright' ), initialOpen: false },
					el( ToggleControl, {
						label: __( 'Afișează play button', 'stonewright' ),
						checked: !! attributes.showPlayButton,
						onChange: function ( v ) { setAttributes( { showPlayButton: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'URL video', 'stonewright' ),
						value: attributes.videoUrl,
						onChange: function ( v ) { setAttributes( { videoUrl: v } ); },
						__nextHasNoMarginBottom: true,
					} )
				),
				el(
					PanelBody,
					{ title: __( 'Statistici nutriționale', 'stonewright' ), initialOpen: false },
					attributes.stats.map( function ( stat, i ) {
						return el(
							'div',
							{ key: i, style: { display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: 6, alignItems: 'end', marginBottom: 10 } },
							el( TextControl, {
								label: i === 0 ? __( 'Etichetă', 'stonewright' ) : '',
								value: stat.label,
								onChange: function ( v ) { updateStat( i, { label: v } ); },
								__nextHasNoMarginBottom: true,
							} ),
							el( TextControl, {
								label: i === 0 ? __( 'Valoare', 'stonewright' ) : '',
								value: stat.value,
								onChange: function ( v ) { updateStat( i, { value: v } ); },
								__nextHasNoMarginBottom: true,
							} ),
							el( TextControl, {
								label: i === 0 ? __( 'Unitate', 'stonewright' ) : '',
								value: stat.unit,
								onChange: function ( v ) { updateStat( i, { unit: v } ); },
								__nextHasNoMarginBottom: true,
							} ),
							el( Button, {
								variant: 'tertiary',
								size: 'small',
								isDestructive: true,
								onClick: function () { removeStat( i ); },
							}, '×' )
						);
					} ),
					el( Button, {
						variant: 'secondary',
						onClick: addStat,
						__next40pxDefaultSize: true,
					}, __( '+ Adaugă statistică', 'stonewright' ) )
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
								label: __( 'Fundal', 'stonewright' ),
							},
							{
								value: attributes.textColor,
								onChange: function ( v ) { setAttributes( { textColor: v } ); },
								label: __( 'Text', 'stonewright' ),
							},
							{
								value: attributes.accentColor,
								onChange: function ( v ) { setAttributes( { accentColor: v } ); },
								label: __( 'Accent / CTA', 'stonewright' ),
							},
						],
					}
				)
			),
			el(
				'section',
				blockProps,
				el( 'div', { className: 'sw-rh__backdrop', 'aria-hidden': 'true' } ),
				el(
					'div',
					{ className: 'sw-rh__inner' },
					el(
						'div',
						{ className: 'sw-rh__col sw-rh__col--text' },
						el( 'div', { className: 'sw-rh__breadcrumb' }, attributes.breadcrumb ),
						el( 'span', { className: 'sw-rh__category' }, attributes.category ),
						el( RichText, {
							tagName: 'h1',
							className: 'sw-rh__title',
							value: attributes.title,
							onChange: function ( v ) { setAttributes( { title: v } ); },
							placeholder: __( 'Titlu rețetă…', 'stonewright' ),
							allowedFormats: [ 'core/bold', 'core/italic' ],
						} ),
						el(
							'div',
							{ className: 'sw-rh__info-card' },
							[ 'difficulty', 'time', 'servings' ].map( function ( key ) {
								const labels = { difficulty: __( 'Dificultate', 'stonewright' ), time: __( 'Timp', 'stonewright' ), servings: __( 'Porții', 'stonewright' ) };
								return el(
									'div',
									{ key: key, className: 'sw-rh__info-item' },
									el( 'span', { className: 'sw-rh__info-label' }, labels[ key ] ),
									el( 'span', { className: 'sw-rh__info-value' }, attributes[ key ] || '—' )
								);
							} )
						),
						el(
							'ul',
							{ className: 'sw-rh__stats', role: 'list' },
							attributes.stats.map( function ( stat, i ) {
								return el(
									'li',
									{ key: i, className: 'sw-rh__stat' },
									el( 'span', { className: 'sw-rh__stat-value' },
										stat.value,
										stat.unit ? el( 'small', null, stat.unit ) : null
									),
									el( 'span', { className: 'sw-rh__stat-label' }, stat.label )
								);
							} )
						)
					),
					el(
						'div',
						{ className: 'sw-rh__col sw-rh__col--media' },
						el(
							'div',
							{ className: 'sw-rh__image-frame' },
							attributes.image
								? el( 'img', { src: attributes.image, alt: attributes.imageAlt || '' } )
								: el( 'div', { className: 'sw-rh__image-placeholder' } ),
							attributes.showPlayButton
								? el(
										'a',
										{ className: 'sw-rh__play', href: '#', onClick: function ( e ) { e.preventDefault(); } },
										el(
											'svg',
											{ width: 32, height: 32, viewBox: '0 0 24 24', fill: 'none', 'aria-hidden': 'true' },
											el( 'path', { d: 'M8 5v14l11-7-11-7z', fill: 'currentColor' } )
										)
								  )
								: null,
							el(
								'div',
								{ className: 'sw-rh__media-pick' },
								el( MediaUploadCheck, null,
									el( MediaUpload, {
										onSelect: function ( media ) {
											setAttributes( { image: media.url, imageAlt: media.alt || '' } );
										},
										allowedTypes: [ 'image' ],
										render: function ( picker ) {
											return el( Button, { variant: 'primary', onClick: picker.open },
												attributes.image ? __( 'Schimbă', 'stonewright' ) : __( 'Adaugă imagine', 'stonewright' )
											);
										},
									} )
								)
							)
						)
					)
				)
			)
		);
	}

	registerBlockType( 'stonewright/recipe-hero', {
		edit: Edit,
		save: function () { return null; },
	} );
} )( window.wp );
