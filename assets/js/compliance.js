( function () {
	'use strict';

	var gate = document.querySelector( '[data-compound-age-gate]' );
	if ( ! gate ) {
		return;
	}

	var confirmed = false;
	try {
		confirmed = window.localStorage.getItem( 'compound_age_21_confirmed' ) === 'yes';
	} catch ( error ) {
		confirmed = false;
	}

	if ( confirmed ) {
		return;
	}

	gate.hidden = false;
	document.documentElement.classList.add( 'compound-age-gate-open' );
	gate.querySelector( '[data-compound-age-confirm]' ).focus();

	gate.querySelector( '[data-compound-age-confirm]' ).addEventListener( 'click', function () {
		try {
			window.localStorage.setItem( 'compound_age_21_confirmed', 'yes' );
		} catch ( error ) {
			// The gate still closes for this page when storage is unavailable.
		}
		gate.hidden = true;
		document.documentElement.classList.remove( 'compound-age-gate-open' );
	} );

	gate.querySelector( '[data-compound-age-deny]' ).addEventListener( 'click', function () {
		gate.querySelector( '[data-compound-age-denied]' ).hidden = false;
		gate.querySelector( '[data-compound-age-confirm]' ).hidden = true;
		this.hidden = true;
	} );
}() );
