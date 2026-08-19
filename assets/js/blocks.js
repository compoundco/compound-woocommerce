/**
 * Compound payment method for the WooCommerce Checkout block. Renders the card / bank transfer /
 * crypto rail chooser and, on submit, hands the chosen rail back to the server as
 * `compound_method` (via paymentMethodData). WooCommerce Blocks copies that into $_POST before
 * calling the classic gateway's process_payment, so the server path is identical to the classic
 * checkout.
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
		const [ cardNumber, setCardNumber ] = useState( '4242424242424242' );
		const [ routingNumber, setRoutingNumber ] = useState( '110000000' );
		const [ accountNumber, setAccountNumber ] = useState( '000123456789' );
		const [ cryptoReference, setCryptoReference ] = useState( 'crypto_success' );

		useEffect( () => {
			const unsubscribe = onPaymentSetup( () => {
				const paymentMethodData = { compound_method: method };
				if ( settings.sandbox && method === 'card' ) {
					paymentMethodData.compound_card_number = cardNumber;
				}
				if ( settings.sandbox && method === 'ach' ) {
					paymentMethodData.compound_ach_routing_number = routingNumber;
					paymentMethodData.compound_ach_account_number = accountNumber;
				}
				if ( settings.sandbox && method === 'crypto' ) {
					paymentMethodData.compound_crypto_reference = cryptoReference;
				}
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: { paymentMethodData: paymentMethodData },
				};
			} );
			return unsubscribe;
		}, [ method, cardNumber, routingNumber, accountNumber, cryptoReference, onPaymentSetup, emitResponse.responseTypes.SUCCESS ] );

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
		if ( settings.sandbox ) {
			children.push( createElement( 'p', { key: 'sandbox-title' },
				createElement( 'strong', null, 'Sandbox test payment' )
			) );
			if ( method === 'card' ) {
				children.push( createElement( 'label', { key: 'card-number', style: { display: 'block' } },
					'Test card number ',
					createElement( 'input', {
						value: cardNumber,
						inputMode: 'numeric',
						autoComplete: 'off',
						onChange: function ( event ) { setCardNumber( event.target.value ); },
					} )
				) );
			}
			if ( method === 'ach' ) {
				children.push( createElement( 'label', { key: 'routing-number', style: { display: 'block' } },
					'Test ACH routing number ',
					createElement( 'input', {
						value: routingNumber,
						inputMode: 'numeric',
						autoComplete: 'off',
						onChange: function ( event ) { setRoutingNumber( event.target.value ); },
					} )
				) );
				children.push( createElement( 'label', { key: 'account-number', style: { display: 'block' } },
					'Test ACH account number ',
					createElement( 'input', {
						value: accountNumber,
						inputMode: 'numeric',
						autoComplete: 'off',
						onChange: function ( event ) { setAccountNumber( event.target.value ); },
					} )
				) );
			}
			if ( method === 'crypto' ) {
				children.push( createElement( 'label', { key: 'crypto-reference', style: { display: 'block' } },
					'Test crypto session ',
					createElement( 'input', {
						value: cryptoReference,
						autoComplete: 'off',
						onChange: function ( event ) { setCryptoReference( event.target.value ); },
					} )
				) );
			}
			children.push( createElement( 'p', { key: 'sandbox-help' }, 'Test values only. No money will move.' ) );
		}
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
