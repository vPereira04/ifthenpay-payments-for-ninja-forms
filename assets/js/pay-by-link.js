( function () {
	'use strict';

	if ( typeof jQuery === 'undefined' ) {
		return;
	}

	// Ninja Forms core sets window.location synchronously when a redirect action
	// comes back, but that only starts navigation — the page doesn't actually
	// leave until this script finishes. Core also fires the plain jQuery event
	// below with the same payload, so I hook that instead of touching core itself.
	jQuery( document ).on( 'nfFormSubmitResponse', function ( event, payload ) {
		if ( ! payload || ! payload.response ) {
			return;
		}

		var response = payload.response;
		var hasErrors = !! ( response.errors && Object.keys( response.errors ).length > 0 );
		var redirect = response.data && response.data.actions && response.data.actions.redirect;

		if ( hasErrors || ! redirect ) {
			return;
		}

		showIftpNfRedirectSpinner();
	} );

	function showIftpNfRedirectSpinner() {
		var overlay = document.createElement( 'div' );
		overlay.className = 'iftp-nf-modal-overlay';

		var box = document.createElement( 'div' );
		box.className = 'iftp-nf-modal iftp-nf-modal--spinner';
		box.setAttribute( 'role', 'status' );
		box.setAttribute( 'aria-live', 'polite' );

		var spinner = document.createElement( 'div' );
		spinner.className = 'iftp-nf-spinner';
		spinner.setAttribute( 'aria-hidden', 'true' );

		var text = document.createElement( 'p' );
		text.className = 'iftp-nf-modal-message';
		text.textContent =
			( typeof iftpNfPayByLink !== 'undefined' && iftpNfPayByLink.redirectingLabel ) ||
			'Redirecting to secure payment…';

		box.appendChild( spinner );
		box.appendChild( text );
		overlay.appendChild( box );
		document.body.appendChild( overlay );

		document.body.style.overflow = 'hidden';

		// The browser should navigate away moments after this shows, and
		// unloading tears it down naturally. This timeout is just a fallback so
		// nobody's stuck staring at a spinner if navigation never happens —
		// it doesn't fire on the normal path.
		window.setTimeout( function () {
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
				document.body.style.overflow = '';
			}
		}, 20000 );
	}
} )();
