( function () {
	'use strict';

	var certificateSearch = document.querySelector( '[data-certificate-search]' );
	if ( certificateSearch ) {
		var certificateRows   = Array.prototype.slice.call( document.querySelectorAll( '[data-certificate-row]' ) );
		var certificateEmpty  = document.querySelector( '[data-certificate-empty]' );
		var certificateStatus = document.querySelector( '[data-certificate-status]' );

		certificateSearch.addEventListener(
			'input',
			function () {
				var query        = certificateSearch.value.toLocaleLowerCase().trim();
				var visibleCount = 0;

				certificateRows.forEach(
					function ( row ) {
						var matches = ! query || row.textContent.toLocaleLowerCase().indexOf( query ) !== -1;
						row.hidden  = ! matches;
						if ( matches ) {
								visibleCount += 1;
						}
					}
				);

				if ( certificateEmpty ) {
					certificateEmpty.hidden = visibleCount !== 0;
				}
				if ( certificateStatus ) {
					certificateStatus.textContent = visibleCount + ( visibleCount === 1 ? ' certificate record shown.' : ' certificate records shown.' );
				}
			}
		);
	}

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

	gate.querySelector( '[data-compound-age-confirm]' ).addEventListener(
		'click',
		function () {
			try {
				window.localStorage.setItem( 'compound_age_21_confirmed', 'yes' );
			} catch ( error ) {
				// The gate still closes for this page when storage is unavailable.
			}
			gate.hidden = true;
			document.documentElement.classList.remove( 'compound-age-gate-open' );
		}
	);

	gate.querySelector( '[data-compound-age-deny]' ).addEventListener(
		'click',
		function () {
			gate.querySelector( '[data-compound-age-denied]' ).hidden  = false;
			gate.querySelector( '[data-compound-age-confirm]' ).hidden = true;
			this.hidden = true;
		}
	);
}() );
