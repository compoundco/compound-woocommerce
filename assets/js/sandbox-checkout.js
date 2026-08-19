( function ( $ ) {
	function updateSandboxFields() {
		const method = $( 'input[name="compound_method"]:checked' ).val() || 'card';
		$( '[data-compound-sandbox-method]' ).each( function () {
			this.hidden = $( this ).data( 'compound-sandbox-method' ) !== method;
		} );
	}

	$( document.body ).on( 'change', 'input[name="compound_method"]', updateSandboxFields );
	$( document.body ).on( 'updated_checkout', updateSandboxFields );
	$( updateSandboxFields );
} )( jQuery );
