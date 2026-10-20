/**
 * Editor-Seite des Blocks „Bewertungen“. Die Ausgabe kommt vom Server
 * (render.php), der Editor zeigt eine Vorschau und die Einstellungen.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	/**
	 * Zahlenfeld, das leere Eingaben als 0 speichert.
	 */
	function numberControl( props, key, label, help, min, max ) {
		return el( components.RangeControl, {
			label: label,
			help: help,
			value: props.attributes[ key ],
			min: min,
			max: max,
			onChange: function ( value ) {
				var update = {};
				update[ key ] = value === undefined ? 0 : value;
				props.setAttributes( update );
			}
		} );
	}

	/**
	 * Schalter für ein boolesches Attribut.
	 */
	function toggleControl( props, key, label, help ) {
		return el( components.ToggleControl, {
			label: label,
			help: help,
			checked: !! props.attributes[ key ],
			onChange: function ( value ) {
				var update = {};
				update[ key ] = value;
				props.setAttributes( update );
			}
		} );
	}

	blocks.registerBlockType( 'local-reviews/reviews', {
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
						{ title: __( 'Kopfbereich', 'local-reviews' ) },
						toggleControl( props, 'header', __( 'Kopfbereich zeigen', 'local-reviews' ), __( 'Firmenname, Sterne, Note und Anzahl laut Google.', 'local-reviews' ) ),
						attrs.header && toggleControl( props, 'photo', __( 'Foto zeigen', 'local-reviews' ) )
					),
					el(
						components.PanelBody,
						{ title: __( 'Layout', 'local-reviews' ) },
						numberControl( props, 'columns', __( 'Spalten (Desktop)', 'local-reviews' ), null, 1, 6 ),
						numberControl( props, 'columns_tablet', __( 'Spalten (Tablet)', 'local-reviews' ), __( '0 = wie Desktop', 'local-reviews' ), 0, 6 ),
						numberControl( props, 'columns_mobile', __( 'Spalten (Smartphone)', 'local-reviews' ), null, 1, 6 ),
						numberControl( props, 'card_width', __( 'Maximale Kartenbreite (px)', 'local-reviews' ), __( '0 = volle Spaltenbreite', 'local-reviews' ), 0, 1200 )
					),
					el(
						components.PanelBody,
						{ title: __( 'Auswahl', 'local-reviews' ) },
						numberControl( props, 'limit', __( 'Anzahl Bewertungen', 'local-reviews' ), __( '0 = alle', 'local-reviews' ), 0, 100 ),
						numberControl( props, 'min_rating', __( 'Mindestens Sterne', 'local-reviews' ), __( '0 = alle', 'local-reviews' ), 0, 5 ),
						toggleControl( props, 'hide_empty', __( 'Bewertungen ohne Text ausblenden', 'local-reviews' ) ),
						numberControl( props, 'excerpt', __( 'Text kürzen auf Zeichen', 'local-reviews' ), __( '0 = ungekürzt', 'local-reviews' ), 0, 1000 ),
						el( components.TextControl, {
							label: __( 'Nur diese Plattformen', 'local-reviews' ),
							help: __( 'Kürzel mit Komma, z. B. google,11880. Leer = alle.', 'local-reviews' ),
							value: attrs.source,
							onChange: function ( value ) {
								props.setAttributes( { source: value } );
							}
						} ),
						el( components.SelectControl, {
							label: __( 'Plattform anzeigen', 'local-reviews' ),
							value: attrs.show_source,
							options: [
								{ value: '', label: __( 'Wie in den Einstellungen', 'local-reviews' ) },
								{ value: 'off', label: __( 'Aus', 'local-reviews' ) },
								{ value: 'foreign', label: __( 'Nur andere Plattformen als Google', 'local-reviews' ) },
								{ value: 'all', label: __( 'Bei allen Bewertungen', 'local-reviews' ) }
							],
							onChange: function ( value ) {
								props.setAttributes( { show_source: value } );
							}
						} ),
						el( components.SelectControl, {
							label: __( 'Position des Plattform-Hinweises', 'local-reviews' ),
							value: attrs.source_position,
							options: [
								{ value: '', label: __( 'Wie in den Einstellungen', 'local-reviews' ) },
								{ value: 'date', label: __( 'Hinter dem Datum', 'local-reviews' ) },
								{ value: 'top', label: __( 'Oben rechts neben dem Namen', 'local-reviews' ) }
							],
							onChange: function ( value ) {
								props.setAttributes( { source_position: value } );
							}
						} ),
						el( components.SelectControl, {
							label: __( 'Sterne', 'local-reviews' ),
							value: attrs.stars_position,
							options: [
								{ value: '', label: __( 'Wie in den Einstellungen', 'local-reviews' ) },
								{ value: 'below', label: __( 'Unter dem Namen', 'local-reviews' ) },
								{ value: 'right', label: __( 'Rechts neben dem Namen', 'local-reviews' ) }
							],
							onChange: function ( value ) {
								props.setAttributes( { stars_position: value } );
							}
						} )
					),
					el(
						components.PanelBody,
						{ title: __( 'Erweitert', 'local-reviews' ), initialOpen: false },
						el( components.TextControl, {
							label: __( 'Eigene CSS-Klassen', 'local-reviews' ),
							help: __( 'Werden am äußeren Element der Ausgabe gesetzt.', 'local-reviews' ),
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
						block: 'local-reviews/reviews',
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
