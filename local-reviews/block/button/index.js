/**
 * Editor-Seite des Blocks „Bewertungs-Button“. Die Ausgabe kommt vom Server.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	/**
	 * Textfeld für ein String-Attribut.
	 */
	function textControl( props, key, label, help ) {
		return el( components.TextControl, {
			label: label,
			help: help,
			value: props.attributes[ key ],
			onChange: function ( value ) {
				var update = {};
				update[ key ] = value;
				props.setAttributes( update );
			}
		} );
	}

	/**
	 * Auswahlfeld für ein String-Attribut.
	 */
	function selectControl( props, key, label, options, help ) {
		return el( components.SelectControl, {
			label: label,
			help: help,
			value: props.attributes[ key ],
			options: options,
			onChange: function ( value ) {
				var update = {};
				update[ key ] = value;
				props.setAttributes( update );
			}
		} );
	}

	blocks.registerBlockType( 'local-reviews/button', {
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
						{ title: __( 'Button', 'local-reviews' ) },
						textControl( props, 'text', __( 'Text', 'local-reviews' ) ),
						selectControl( props, 'target', __( 'Ziel', 'local-reviews' ), [
							{ value: 'reviews', label: __( 'Bewertungen bei Google lesen', 'local-reviews' ) },
							{ value: 'write', label: __( 'Bewertung bei Google schreiben', 'local-reviews' ) }
						] ),
						el( components.ToggleControl, {
							label: __( 'Google-Logo zeigen', 'local-reviews' ),
							checked: !! attrs.icon,
							onChange: function ( value ) {
								props.setAttributes( { icon: value } );
							}
						} ),
						selectControl( props, 'align', __( 'Ausrichtung', 'local-reviews' ), [
							{ value: 'left', label: __( 'Links', 'local-reviews' ) },
							{ value: 'center', label: __( 'Mittig', 'local-reviews' ) },
							{ value: 'right', label: __( 'Rechts', 'local-reviews' ) }
						] )
					),
					el(
						components.PanelBody,
						{ title: __( 'Stil', 'local-reviews' ) },
						selectControl( props, 'button_style', __( 'Stil', 'local-reviews' ), [
							{ value: 'google', label: __( 'Google (weiß mit Schatten)', 'local-reviews' ) },
							{ value: 'theme', label: __( 'Button des Themes', 'local-reviews' ) },
							{ value: 'none', label: __( 'Ohne Stil (nur eigene Klassen)', 'local-reviews' ) }
						] ),
						textControl( props, 'button_class', __( 'Button-Klassen', 'local-reviews' ), __( 'Eigene Klassen am Link, z. B. für GenerateBlocks-Global-Styles.', 'local-reviews' ) ),
						textControl( props, 'wrapper_class', __( 'Klassen am äußeren Element', 'local-reviews' ) )
					)
				),
				el(
					'div',
					blockProps,
					el( serverSideRender, {
						block: 'local-reviews/button',
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
