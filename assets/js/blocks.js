/**
 * Compound payment method for the WooCommerce Checkout block. Renders the card / crypto rail
 * chooser and, on submit, hands the chosen rail back to the server as `compound_method` (via
 * paymentMethodData). WooCommerce Blocks copies that into $_POST before calling the classic
 * gateway's process_payment, so the server path is identical to the classic checkout.
 *
 * No build step: uses the WooCommerce Blocks + WordPress UMD globals.
 */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement, useState, useEffect } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'compound_data', {} );
	const title = decodeEntities( settings.title || 'Compound' );
	const description = decodeEntities( settings.description || '' );
	const methods = settings.methods && Object.keys( settings.methods ).length
		? settings.methods
		: { card: 'Card' };
	const railValues = Object.keys( methods );

	// The checkout content: a short description + a rail chooser. Subscribes to the payment-setup
	// event so the selected rail rides along as compound_method.
	function Content( props ) {
		const { eventRegistration, emitResponse } = props;
		const { onPaymentSetup } = eventRegistration;
		const [ method, setMethod ] = useState( railValues[ 0 ] );

		useEffect( () => {
			const unsubscribe = onPaymentSetup( () => ( {
				type: emitResponse.responseTypes.SUCCESS,
				meta: { paymentMethodData: { compound_method: method } },
			} ) );
			return unsubscribe;
		}, [ method, onPaymentSetup, emitResponse.responseTypes.SUCCESS ] );

		const children = [];
		if ( description ) {
			children.push( createElement( 'p', { key: 'desc' }, description ) );
		}
		railValues.forEach( function ( value ) {
			children.push(
				createElement(
					'label',
					{ key: value, style: { display: 'block', margin: '4px 0' } },
					createElement( 'input', {
						type: 'radio',
						name: 'compound_method',
						value: value,
						checked: method === value,
						onChange: function () {
							setMethod( value );
						},
					} ),
					' ' + decodeEntities( methods[ value ] )
				)
			);
		} );
		return createElement( 'fieldset', { style: { border: 0, padding: 0, margin: 0 } }, children );
	}

	registerPaymentMethod( {
		name: 'compound',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
