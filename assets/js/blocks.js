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
	const payByBank = settings.payByBank || {};
	const PBB = payByBank.method || 'pay_by_bank';

	// The shopper's name and email, read from the block checkout's own store so the linking
	// session is started for the person actually checking out. The provider requires all
	// three: the session is what its hosted flow greets them by.
	//
	// Falls back to the signed-in account's own name/email for whichever field the shopper
	// has not typed into this form yet - the block checkout only knows its own fields, not
	// their account, so without this a returning customer who has not retyped their name is
	// blocked from linking a bank they are already entitled to link. The fallback never
	// overrides something the shopper actually entered.
	function billingDetails() {
		const account = payByBank.account || {};
		let typed = { email: '', first_name: '', last_name: '' };
		try {
			const data = window.wp.data.select( 'wc/store/cart' ).getCartData();
			const a = ( data && data.billingAddress ) || {};
			typed = { email: a.email || '', first_name: a.first_name || '', last_name: a.last_name || '' };
		} catch ( e ) {
			// Cart store unavailable; fall through to the account values alone.
		}
		return {
			email: typed.email || account.email || '',
			first_name: typed.first_name || account.first_name || '',
			last_name: typed.last_name || account.last_name || '',
		};
	}

	function pbbPost( action, extra ) {
		const body = new URLSearchParams(
			Object.assign( { action: action, nonce: payByBank.nonce }, billingDetails(), extra || {} )
		);
		return fetch( payByBank.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	// The provider returns the customer id in the redirect's query string, so a shopper coming
	// back from their bank lands here with it. Consumed once and stripped from the URL, so a
	// refresh does not try to link again.
	function takeRedirectCustomerId() {
		const params = new URLSearchParams( window.location.search );
		const id = params.get( 'customerId' ) || params.get( 'customer_id' );
		if ( ! id ) {
			return '';
		}
		params.delete( 'customerId' );
		params.delete( 'customer_id' );
		const qs = params.toString();
		window.history.replaceState( {}, '', window.location.pathname + ( qs ? '?' + qs : '' ) );
		return id;
	}
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
		// Pay by bank: the token that proves a bank is linked, plus whatever we can tell the
		// shopper about it. No token means the order cannot be placed on this rail.
		const [ bankToken, setBankToken ] = useState( '' );
		const [ bankLabel, setBankLabel ] = useState( '' );
		const [ bankBusy, setBankBusy ] = useState( false );
		const [ bankStatus, setBankStatus ] = useState( '' );

		function finishLink( customerId ) {
			setBankBusy( true );
			setBankStatus( 'Confirming your bank...' );
			pbbPost( 'compound_pbb_link', { customer_id: customerId } )
				.then( function ( res ) {
					setBankBusy( false );
					if ( ! res || ! res.success ) {
						setBankStatus( ( res && res.data && res.data.message ) || 'Could not confirm the bank link.' );
						return;
					}
					setBankToken( res.data.bank_account_token );
					const name = res.data.bank_name || '';
					const last4 = res.data.account_last4 ? '****' + res.data.account_last4 : '';
					setBankLabel( ( name + ' ' + last4 ).trim() || 'Your bank account is linked.' );
					setBankStatus( '' );
				} )
				.catch( function () {
					setBankBusy( false );
					setBankStatus( 'Could not confirm the bank link.' );
				} );
		}

		// A shopper returning from their bank arrives with the customer id on the URL.
		useEffect( () => {
			const returned = takeRedirectCustomerId();
			if ( returned ) {
				finishLink( returned );
			}
			// Runs once, on mount: the redirect is consumed and stripped, so there is nothing
			// to react to afterwards.
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [] );

		useEffect( () => {
			const unsubscribe = onPaymentSetup( () => {
				const paymentMethodData = { compound_method: method };
				if ( method === PBB && bankToken ) {
					// Already linked (a returning customer): charges synchronously, unchanged.
					paymentMethodData.compound_pbb_token = bankToken;
				}
				// No token: not an error. The order-first flow starts Link Money's hosted
				// session and returns a redirect_url instead of completing the order (see
				// class-wc-gateway-compound.php's payment_method()/process_payment()) - there
				// is no "link only" step to complete before the order can be placed.
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
		}, [ method, cardNumber, routingNumber, accountNumber, cryptoReference, bankToken, onPaymentSetup, emitResponse.responseTypes.SUCCESS, emitResponse.responseTypes.ERROR ] );

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
		// Pay by bank. An already-linked customer (bankToken set) sees their bank and charges
		// synchronously on submit, same as today. A first-time customer links no bank here at
		// all - Link Money's hosted session requires a real payment amount to even start (there
		// is no link-only step for a first purchase), so placing the order is itself what
		// starts that session; the shopper is redirected there after submitting.
		if ( method === PBB ) {
			children.push(
				createElement(
					'p',
					{ key: 'pbb-status' },
					bankStatus || bankLabel ||
						( bankToken
							? ''
							: 'You will link your bank account on the next step, right after you place your order.' )
				)
			);
		}
		// Sandbox test values are for the rails where a number is typed here. Pay by bank has
		// none: its test profile is chosen inside the provider's own flow.
		if ( settings.sandbox && method !== PBB ) {
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
