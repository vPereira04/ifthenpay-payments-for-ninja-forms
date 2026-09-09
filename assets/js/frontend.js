( function () {
	'use strict';

	if ( typeof iftpNfReturn === 'undefined' ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var banner = document.createElement( 'div' );
		banner.className = 'iftp-nf-return-banner iftp-nf-return-banner--' + iftpNfReturn.status;
		banner.setAttribute( 'role', 'status' );
		banner.textContent = iftpNfReturn.message;
		banner.style.cssText =
			'position:relative;padding:12px 16px;margin:16px 0;border-radius:4px;' +
			'font-family:sans-serif;font-size:14px;background:#f0f0f1;border-left:4px solid #787c82;';

		if ( 'paid' === iftpNfReturn.status ) {
			banner.style.borderLeftColor = '#00a32a';
		} else if ( 'failed' === iftpNfReturn.status || 'cancelled' === iftpNfReturn.status ) {
			banner.style.borderLeftColor = '#d63638';
		} else {
			banner.style.borderLeftColor = '#dba617';
		}

		document.body.insertBefore( banner, document.body.firstChild );
	} );
} )();
