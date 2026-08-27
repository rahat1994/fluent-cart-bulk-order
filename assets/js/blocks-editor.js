/**
 * Editor UI for the two FCBO blocks.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS ES5 WITH NO JSX
 * ---------------------------------------------------------------------------
 *
 * The plugin has no npm toolchain, no bundler and PHP-only CI, and it is headed
 * for the WordPress.org directory where a compiled asset means shipping and
 * justifying its sources. So this file is written the way the browser will run
 * it: wp.element.createElement by hand, `var`, no imports, no build.
 *
 * ---------------------------------------------------------------------------
 * WHY registerBlockType() PASSES NO METADATA
 * ---------------------------------------------------------------------------
 *
 * Title, icon, category, supports and — most importantly — `attributes` all come
 * from blocks/<name>/block.json, which register_block_type() reads on the server
 * and WordPress hands to the editor before this script runs (as
 * wp.blocks.unstable__bootstrapServerSideBlockDefinitions). Repeating the
 * attribute list here would create a second definition that can drift from the
 * JSON, and a client/server attribute mismatch shows up as settings silently
 * lost on save. One definition, on the server.
 *
 * ---------------------------------------------------------------------------
 * WHY THE EDITOR SHOWS A PLACEHOLDER, NOT A LIVE PREVIEW
 * ---------------------------------------------------------------------------
 *
 * Both surfaces are shells that their front-end scripts fill from the REST API,
 * on top of FluentCart's own cart and single-product bundles. Dropping that
 * markup into the editor via ServerSideRender would render an empty table with
 * none of its scripts wired up — a preview that looks broken is worse than an
 * honest placeholder. The placeholder instead reports which settings are set, so
 * the block is not a blind box.
 *
 * ---------------------------------------------------------------------------
 * EVERY CONTROL HAS A "USE STORE DEFAULT" POSITION
 * ---------------------------------------------------------------------------
 *
 * Blank text, an empty number and the "Use store default" select option all save
 * as '' and are dropped server-side, so the shortcode never receives them and
 * the store-wide default in Settings keeps applying. That is why the yes/no
 * settings are three-option selects and not toggles: a toggle has no third
 * position, so its "off" would have to double as "unset" and one of the two
 * meanings would become unreachable.
 *
 * @see includes/Shortcodes/AttributeSchema.php for the server-side half.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	if ( ! blocks || ! element || ! blockEditor || ! components ) {
		return;
	}

	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;

	var PanelBody = components.PanelBody;
	var Placeholder = components.Placeholder;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var CheckboxControl = components.CheckboxControl;
	var BaseControl = components.BaseControl;

	var TEXT_DOMAIN = 'fluent-cart-bulk-order';

	/**
	 * Canonical product-table columns, in the canonical order.
	 *
	 * Mirrors StoreDefaults::TABLE_COLUMNS. Order matters: fcbo_parse_columns_attr()
	 * re-sorts whatever it is given back into this order anyway, and rebuilding
	 * the value from this list keeps the saved attribute stable no matter which
	 * checkbox the owner clicked first.
	 */
	var COLUMNS = [
		{ value: 'id', label: __( 'ID', TEXT_DOMAIN ) },
		{ value: 'title', label: __( 'Title', TEXT_DOMAIN ) },
		{ value: 'price', label: __( 'Price', TEXT_DOMAIN ) },
		{ value: 'qty', label: __( 'Quantity', TEXT_DOMAIN ) },
		{ value: 'action', label: __( 'Action', TEXT_DOMAIN ) }
	];

	/**
	 * Object.assign in ES5 clothing.
	 *
	 * Object.assign is ES2015 and every browser WordPress supports has it, but
	 * this file is deliberately plain ES5 because nothing transpiles it. Keeping
	 * that literally true means nobody has to check a compatibility table before
	 * editing it.
	 *
	 * @param {Object} target Receives the sources' own properties.
	 * @return {Object} target.
	 */
	function extend( target ) {
		var sources = Array.prototype.slice.call( arguments, 1 );

		sources.forEach( function ( source ) {
			if ( ! source ) {
				return;
			}

			Object.keys( source ).forEach( function ( key ) {
				target[ key ] = source[ key ];
			} );
		} );

		return target;
	}

	/**
	 * The three positions every yes/no setting offers.
	 *
	 * @param {string} onLabel  Label for the "on" position.
	 * @param {string} offLabel Label for the "off" position.
	 * @return {Array} SelectControl options.
	 */
	function ternaryOptions( onLabel, offLabel ) {
		return [
			{ value: '', label: __( 'Use store default', TEXT_DOMAIN ) },
			{ value: 'true', label: onLabel },
			{ value: 'false', label: offLabel }
		];
	}

	/**
	 * Props shared by every control, so a new one cannot forget them.
	 *
	 * @param {Object} props    Block edit props.
	 * @param {string} attrName Attribute this control edits.
	 * @return {Object} value/onChange pair.
	 */
	function bind( props, attrName ) {
		return {
			value: props.attributes[ attrName ] || '',
			onChange: function ( next ) {
				var update = {};
				// Normalising undefined to '' matters: '' is what the server
				// reads as "not set", and an undefined attribute would fall back
				// to the block.json default, which is also '' - same result, but
				// only by luck. Be explicit.
				update[ attrName ] = typeof next === 'undefined' || next === null ? '' : String( next );
				props.setAttributes( update );
			}
		};
	}

	/**
	 * The `columns` checkbox set, saved as the comma string the shortcode uses.
	 *
	 * No boxes ticked means "use the store-wide column choice". Ticking all five
	 * is how an owner says "every column, whatever the store default is" - the
	 * two are different instructions and both have to stay reachable.
	 *
	 * @param {Object} props Block edit props.
	 * @return {Object} Element.
	 */
	function columnsControl( props ) {
		var current = props.attributes.columns || '';
		var chosen = current === '' ? [] : current.split( ',' );
		var binding = bind( props, 'columns' );

		return el(
			BaseControl,
			{
				__nextHasNoMarginBottom: true,
				label: __( 'Columns', TEXT_DOMAIN ),
				help: __( 'Leave every box clear to follow the store-wide column choice.', TEXT_DOMAIN )
			},
			COLUMNS.map( function ( column ) {
				return el( CheckboxControl, {
					key: column.value,
					__nextHasNoMarginBottom: true,
					label: column.label,
					checked: chosen.indexOf( column.value ) !== -1,
					onChange: function ( isChecked ) {
						// Rebuild from COLUMNS rather than pushing/splicing, so
						// the saved order is always the canonical one.
						var next = COLUMNS.filter( function ( candidate ) {
							if ( candidate.value === column.value ) {
								return isChecked;
							}

							return chosen.indexOf( candidate.value ) !== -1;
						} ).map( function ( candidate ) {
							return candidate.value;
						} );

						binding.onChange( next.join( ',' ) );
					}
				} );
			} )
		);
	}

	/**
	 * Sidebar controls for [fluent_cart_bulk_order].
	 *
	 * @param {Object} props Block edit props.
	 * @return {Array} Elements.
	 */
	function bulkOrderControls( props ) {
		return [
			el( TextControl, extend( { key: 'roles' }, bind( props, 'roles' ), {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __( 'Extra roles', TEXT_DOMAIN ),
				help: __( 'Comma-separated role slugs that may also see this form. Adds to the roles allowed in Settings; it never replaces them.', TEXT_DOMAIN )
			} ) ),
			el( TextControl, extend( { key: 'redirect' }, bind( props, 'redirect' ), {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				type: 'url',
				label: __( 'Redirect URL', TEXT_DOMAIN ),
				help: __( 'Send the shopper here instead of the store checkout page. Same site only.', TEXT_DOMAIN )
			} ) ),
			el( SelectControl, extend( { key: 'quotes' }, bind( props, 'quotes' ), {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __( 'Request a quote', TEXT_DOMAIN ),
				options: ternaryOptions( __( 'Offer', TEXT_DOMAIN ), __( 'Do not offer', TEXT_DOMAIN ) ),
				help: __( 'Lets the buyer send this order to the store for a price instead of checking out.', TEXT_DOMAIN )
			} ) )
		];
	}

	/**
	 * Sidebar controls for [fluent_cart_product_table].
	 *
	 * @param {Object} props Block edit props.
	 * @return {Array} Elements.
	 */
	function productTableControls( props ) {
		return [
			el( TextControl, extend( { key: 'per_page' }, bind( props, 'per_page' ), {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				type: 'number',
				min: 1,
				max: 100,
				label: __( 'Rows per page', TEXT_DOMAIN ),
				help: __( 'Leave blank to follow the store-wide setting. 1 to 100.', TEXT_DOMAIN )
			} ) ),
			el( Fragment, { key: 'columns' }, columnsControl( props ) ),
			el( SelectControl, extend( { key: 'search' }, bind( props, 'search' ), {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __( 'Search box', TEXT_DOMAIN ),
				options: ternaryOptions( __( 'Show', TEXT_DOMAIN ), __( 'Hide', TEXT_DOMAIN ) )
			} ) ),
			el( TextControl, extend( { key: 'category' }, bind( props, 'category' ), {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __( 'Category', TEXT_DOMAIN ),
				help: __( 'Product category slug or term ID. Leave blank for the whole catalogue.', TEXT_DOMAIN )
			} ) ),
			el( TextControl, extend( { key: 'roles' }, bind( props, 'roles' ), {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __( 'Extra roles', TEXT_DOMAIN ),
				help: __( 'Comma-separated role slugs that may also see this table. Adds to the roles allowed in Settings; it never replaces them.', TEXT_DOMAIN )
			} ) ),
			el( SelectControl, extend( { key: 'expand_variants' }, bind( props, 'expand_variants' ), {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __( 'Variants', TEXT_DOMAIN ),
				options: ternaryOptions( __( 'Expanded', TEXT_DOMAIN ), __( 'Collapsed', TEXT_DOMAIN ) )
			} ) )
		];
	}

	/**
	 * One line per setting the owner has actually set, so the placeholder says
	 * something true about this particular block rather than being decorative.
	 *
	 * @param {Object} attributes Block attributes.
	 * @return {Object} Element.
	 */
	function summary( attributes ) {
		var set = Object.keys( attributes ).filter( function ( name ) {
			return attributes[ name ] !== '' && typeof attributes[ name ] !== 'undefined';
		} );

		if ( ! set.length ) {
			return el(
				'p',
				null,
				__( 'Using the store-wide defaults. Open the block settings sidebar to override them here.', TEXT_DOMAIN )
			);
		}

		return el(
			'ul',
			null,
			set.map( function ( name ) {
				return el(
					'li',
					{ key: name },
					sprintf(
						/* translators: 1: attribute name, 2: attribute value. */
						__( '%1$s: %2$s', TEXT_DOMAIN ),
						name,
						attributes[ name ]
					)
				);
			} )
		);
	}

	/**
	 * Build the edit component for one block.
	 *
	 * @param {Object}   metadata     Title, icon and instructions for the placeholder.
	 * @param {Function} buildControls Sidebar controls factory.
	 * @return {Function} Edit component.
	 */
	function makeEdit( metadata, buildControls ) {
		return function edit( props ) {
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', TEXT_DOMAIN ), initialOpen: true },
						buildControls( props )
					)
				),
				el(
					'div',
					useBlockProps(),
					el(
						Placeholder,
						{
							icon: metadata.icon,
							label: metadata.label,
							instructions: metadata.instructions
						},
						summary( props.attributes )
					)
				)
			);
		};
	}

	// save returns null for both: these are dynamic blocks, rendered on the
	// server by the shortcode they wrap, so nothing is written into post content.
	function save() {
		return null;
	}

	blocks.registerBlockType( 'fluent-cart-bulk-order/bulk-order-form', {
		edit: makeEdit(
			{
				icon: 'clipboard',
				label: __( 'Bulk Order Form', TEXT_DOMAIN ),
				instructions: __( 'Shown on the front end to the roles allowed in Settings. Logged-out and unpermitted visitors see a short notice instead.', TEXT_DOMAIN )
			},
			bulkOrderControls
		),
		save: save
	} );

	blocks.registerBlockType( 'fluent-cart-bulk-order/product-table', {
		edit: makeEdit(
			{
				icon: 'editor-table',
				label: __( 'Product Table', TEXT_DOMAIN ),
				instructions: __( 'Shown on the front end to the roles allowed in Settings. Logged-out and unpermitted visitors see a short notice instead.', TEXT_DOMAIN )
			},
			productTableControls
		),
		save: save
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
