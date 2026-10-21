/**
 * Editor-Seite des Blocks „Bewertungs-Badge“. Die Ausgabe kommt vom Server.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	/**
	 * Schalter für ein boolesches Attribut.
	 */
	function toggleControl( props, key, label ) {
		return el( components.ToggleControl, {
			label: label,
			checked: !! props.attributes[ key ],
			onChange: function ( value ) {
				var update = {};
				update[ key ] = value;
				props.setAttributes( update );
			}
		} );
	}

	blocks.registerBlockType( 'local-reviews/badge', {
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps();
			var attrs = props.attributes;

			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Inhalt', 'local-reviews' ) },
						el( components.SelectControl, {
							label: __( 'Variante', 'local-reviews' ),
							value: attrs.variant,
							options: [
								{ value: '', label: __( 'Wie in den Einstellungen', 'local-reviews' ) },
								{ value: 'classic', label: __( 'Klassisch (eine Zeile)', 'local-reviews' ) },
								{ value: 'stacked', label: __( 'Gestapelt mit Plattformen und Linktext', 'local-reviews' ) }
							],
							onChange: function ( value ) {
								props.setAttributes( { variant: value } );
							}
						} ),
						el( components.SelectControl, {
							label: __( 'Logo', 'local-reviews' ),
							value: attrs.logo || ( attrs.brand === false ? 'none' : '' ),
							options: [
								{ value: '', label: __( 'Wie in den Einstellungen', 'local-reviews' ) },
								{ value: 'google', label: __( 'Google-Schriftzug', 'local-reviews' ) },
								{ value: 'none', label: __( 'Kein Logo', 'local-reviews' ) },
								{ value: 'image', label: __( 'Bild aus den Einstellungen', 'local-reviews' ) }
							],
							onChange: function ( value ) {
								// Der alte Schalter brand wird zurückgesetzt, logo entscheidet.
								props.setAttributes( { logo: value, brand: true } );
							}
						} ),
						toggleControl( props, 'rating', __( 'Note als Zahl', 'local-reviews' ) ),
						// Gestapelt steht der Pfeil hinter dem Linktext; ohne Linktext hätte der Schalter keine Wirkung.
						! ( 'classic' !== attrs.variant && '' === attrs.link_text ) && el( components.ToggleControl, {
							label: __( 'Pfeil am Ende', 'local-reviews' ),
							help: __( 'Klassisch „›“ hinter der Anzahl, gestapelt „→“ hinter dem Linktext.', 'local-reviews' ),
							checked: !! attrs.arrow,
							onChange: function ( value ) {
								props.setAttributes( { arrow: value } );
							}
						} ),
						// Nur für die gestapelte Variante; bei „Wie in den Einstellungen“ ebenfalls zeigen,
						// weil der Editor die globale Einstellung nicht kennt.
						'classic' !== attrs.variant && el( components.TextControl, {
							label: __( 'Linktext rechts (gestapelt)', 'local-reviews' ),
							help: __( 'Leer = ausblenden.', 'local-reviews' ),
							value: attrs.link_text,
							onChange: function ( value ) {
								props.setAttributes( { link_text: value } );
							}
						} ),
						'classic' !== attrs.variant && el( components.TextControl, {
							label: __( 'Zeile unter der Anzahl (gestapelt)', 'local-reviews' ),
							help: __( 'Leer = Plattformen automatisch, z. B. „u. a. auf Google, 11880 & GoLocal“.', 'local-reviews' ),
							value: attrs.subline,
							onChange: function ( value ) {
								props.setAttributes( { subline: value } );
							}
						} ),
						el( components.SelectControl, {
							label: __( 'Anzahl', 'local-reviews' ),
							value: attrs.count,
							options: [
								{ value: '', label: __( 'Wie in den Einstellungen', 'local-reviews' ) },
								{ value: 'google', label: __( 'Anzahl laut Google', 'local-reviews' ) },
								{ value: 'local', label: __( 'Bewertungen im Plugin', 'local-reviews' ) }
							],
							onChange: function ( value ) {
								props.setAttributes( { count: value } );
							}
						} ),
						el( components.TextControl, {
							label: __( 'Text hinter der Anzahl', 'local-reviews' ),
							value: attrs.label,
							onChange: function ( value ) {
								props.setAttributes( { label: value } );
							}
						} )
					),
					el(
						components.PanelBody,
						{ title: __( 'Link', 'local-reviews' ) },
						el( components.SelectControl, {
							label: __( 'Ziel', 'local-reviews' ),
							help: __( 'Für ein Modal mit GenerateBlocks Pro: „Kein Link“ wählen und den Badge in das Element legen, das das Overlay öffnet. Siehe Einstellungen > Local Reviews > Anleitung.', 'local-reviews' ),
							value: attrs.link,
							options: [
								{ value: 'none', label: __( 'Kein Link', 'local-reviews' ) },
								{ value: 'google', label: __( 'Bewertungen bei Google', 'local-reviews' ) },
								{ value: 'custom', label: __( 'Eigene URL oder Anker', 'local-reviews' ) }
							],
							onChange: function ( value ) {
								props.setAttributes( { link: value } );
							}
						} ),
						'custom' === attrs.link && el( components.TextControl, {
							label: __( 'URL oder Anker', 'local-reviews' ),
							help: __( 'z. B. #bewertungen oder https://…', 'local-reviews' ),
							value: attrs.url,
							onChange: function ( value ) {
								props.setAttributes( { url: value } );
							}
						} )
					),
					el(
						components.PanelBody,
						{ title: __( 'Erweitert', 'local-reviews' ), initialOpen: false },
						el( components.TextControl, {
							label: __( 'Eigene CSS-Klassen', 'local-reviews' ),
							value: attrs.wrapper_class,
							onChange: function ( value ) {
								props.setAttributes( { wrapper_class: value } );
							}
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( serverSideRender, {
						block: 'local-reviews/badge',
						attributes: attrs,
						skipBlockSupportAttributes: true
					} )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.serverSideRender, window.wp.i18n );
