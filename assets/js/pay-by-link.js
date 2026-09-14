( function () {
	'use strict';

	if ( typeof jQuery === 'undefined' ) {
		return;
	}

	/**
	 * Ninja Forms core's own submit AJAX handler
	 * (`ninja-forms/assets/js/min/front-end.js`, `controllers/actionRedirect`)
	 * fires `window.location = response.data.actions.redirect` synchronously
	 * off its own `nfRadio.channel('forms')` "submit:response" event the
	 * moment `IfthenpayGateway::process()` halts the submission with a
	 * redirect action — but that assignment only *starts* navigation; the
	 * browser doesn't actually leave the page until the current script
	 * finishes running. Right after triggering that Radio event, core also
	 * fires the plain jQuery event `nfFormSubmitResponse` on `document` with
	 * the same response payload, which is the one documented, non-Radio hook
	 * point available to code outside Ninja Forms core — used here instead
	 * of touching anything under `ninja-forms/`.
	 */
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

		// The browser is expected to navigate away within moments of this
		// showing (see the comment above) and unloading the page naturally
		// tears this down with it. This fallback exists only so a user
		// isn't left staring at a spinner forever in the unlikely event
		// navigation itself never happens (e.g. an invalid/blocked redirect
		// URL) — it never fires on the normal, successful path.
		window.setTimeout( function () {
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
				document.body.style.overflow = '';
			}
		}, 20000 );
	}
} )();
