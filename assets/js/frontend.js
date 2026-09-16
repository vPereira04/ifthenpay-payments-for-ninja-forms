( function () {
	'use strict';

	if ( typeof iftpNfReturn === 'undefined' ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		// Stripping this right away, not on popup dismiss — the address bar
		// stays "live" for wp_get_referer() as long as it carries these params,
		// so a resubmit before the popup even closes would still see a polluted URL.
		stripReturnParamsFromUrl();

		// A "Paid" redirect confirmation shouldn't have to flash the "waiting
		// for payment" popup first just to redirect a moment later. The webhook
		// essentially never beats the browser back here, so this quick check is
		// what tells "still pending" apart from "paid" for most returns — held
		// behind a brief spinner instead of the real popup.
		if ( shouldDeferInitialPopup() ) {
			deferInitialPopupForQuickCheck();
			return;
		}

		var modalRefs = openIftpNfStatusModal( iftpNfReturn.status, iftpNfReturn.message, iftpNfReturn.entryData );

		maybeWatchForPaidStatus( modalRefs, iftpNfReturn.status );
	} );

	function shouldDeferInitialPopup() {
		return 'paid' !== iftpNfReturn.status && !! iftpNfReturn.transactionId && !! iftpNfReturn.paidRedirectUrl;
	}

	// Runs the same one-shot status check as the normal watch flow, but behind
	// a spinner instead of the real popup. Redirects if it resolves to paid
	// with a redirect configured, otherwise reveals the popup already showing
	// the right state — or, if still pending, falls through to the normal
	// watch loop with nothing lost.
	function deferInitialPopupForQuickCheck() {
		var overlay = showConfirmingOverlay();

		runQuickPaidCheck( function ( status, message, redirectUrl, entryData ) {
			hideConfirmingOverlay( overlay );

			if ( 'paid' === status && redirectUrl ) {
				window.location.href = redirectUrl;
				return;
			}

			var resolvedStatus = status || iftpNfReturn.status;
			var resolvedMessage = message || iftpNfReturn.message;
			var modalRefs = openIftpNfStatusModal( resolvedStatus, resolvedMessage, entryData );

			if ( 'paid' === resolvedStatus ) {
				modalRefs.resolved = true;
				return;
			}

			watchForPaidStatus( modalRefs );
		} );
	}

	function showConfirmingOverlay() {
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
		text.textContent = iftpNfReturn.confirmingLabel || 'Confirming your payment…';

		box.appendChild( spinner );
		box.appendChild( text );
		overlay.appendChild( box );
		document.body.appendChild( overlay );
		document.body.style.overflow = 'hidden';

		return overlay;
	}

	function hideConfirmingOverlay( overlay ) {
		if ( overlay.parentNode ) {
			overlay.parentNode.removeChild( overlay );
		}
		document.body.style.overflow = '';
	}

	// Builds and shows the return-status popup, dismissible via the close
	// button, OK, a backdrop click, or Escape. Returns a small ref handle that
	// later status updates use to update this same popup in place.
	function openIftpNfStatusModal( status, message, entryData ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'iftp-nf-modal-overlay';

		var modal = document.createElement( 'div' );
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );

		var closeLabel = ( typeof iftpNfReturn.closeLabel !== 'undefined' && iftpNfReturn.closeLabel ) || 'Close';
		var okLabel = ( typeof iftpNfReturn.okLabel !== 'undefined' && iftpNfReturn.okLabel ) || 'OK';

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'iftp-nf-modal-close';
		closeBtn.setAttribute( 'aria-label', closeLabel );
		closeBtn.innerHTML = '&times;';

		var icon = document.createElement( 'div' );
		icon.className = 'iftp-nf-modal-icon';
		icon.setAttribute( 'aria-hidden', 'true' );

		var text = document.createElement( 'p' );
		text.className = 'iftp-nf-modal-message';
		text.setAttribute( 'role', 'status' );

		var okBtn = document.createElement( 'button' );
		okBtn.type = 'button';
		okBtn.className = 'iftp-nf-modal-ok';
		okBtn.textContent = okLabel;

		modal.appendChild( closeBtn );
		modal.appendChild( icon );
		modal.appendChild( text );
		modal.appendChild( okBtn );
		overlay.appendChild( modal );
		document.body.appendChild( overlay );

		var previousBodyOverflow = document.body.style.overflow;

		// Kept in the DOM (hidden, not removed) — a payment can still resolve
		// to "paid" after the customer dismisses a "pending" popup, and that
		// still needs to show rather than being silently missed.
		function open() {
			overlay.style.display = '';
			document.body.style.overflow = 'hidden';
		}

		function close() {
			document.removeEventListener( 'keydown', onKeydown );
			overlay.style.display = 'none';
			document.body.style.overflow = previousBodyOverflow;
		}

		function onKeydown( event ) {
			if ( 'Escape' === event.key || 'Esc' === event.key ) {
				close();
			}
		}

		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				close();
			}
		} );

		closeBtn.addEventListener( 'click', close );
		okBtn.addEventListener( 'click', close );
		document.addEventListener( 'keydown', onKeydown );

		// `resolved` flips once the payment's confirmed paid, so the poll loop
		// and the visibility re-check both know to stop. `status` is the last
		// status actually rendered, so a genuine transition can be told apart
		// from a redundant poll tick reporting the same thing again.
		var refs = {
			modal: modal,
			icon: icon,
			text: text,
			okBtn: okBtn,
			open: open,
			resolved: false,
			status: '',
			entryDataEl: null,
		};

		updateIftpNfModalContent( refs, status, message, entryData );
		okBtn.focus();

		return refs;
	}

	// Swaps the popup's icon/class/message in place, for both the first
	// render and every later status update, so a resolved payment updates
	// with no reload. I only rebuild the icon, reopen the popup, or shake the
	// form on a genuine status change — a redundant poll tick reporting the
	// same status is a no-op, since re-opening would undo a dismissal and the
	// icon animations need to keep running undisturbed.
	function updateIftpNfModalContent( refs, status, message, entryData ) {
		var statusChanged = refs.status !== status;

		refs.modal.className = 'iftp-nf-modal iftp-nf-modal--' + status;

		if ( statusChanged ) {
			renderIftpNfIcon( refs.icon, status );
		}

		// innerHTML, not textContent — admins can format this message with
		// bold/italic, and it's already sanitized with wp_kses_post() before storage.
		refs.text.innerHTML = message;

		if ( statusChanged ) {
			renderEntryData( refs, status, entryData );
		}

		if ( statusChanged ) {
			refs.open();
		}

		if ( statusChanged && ( 'failed' === status || 'cancelled' === status ) ) {
			shakeSourceForm();
		}

		refs.status = status;
	}

	// Builds the "Show Entry Data" box on a genuine transition into paid.
	// I remove any previous box first — defensive, since "paid" is terminal
	// and this never actually re-runs today, but it's cheap to keep safe.
	// The box is inserted at its full height so nothing else shifts; only its
	// clip-path animates open, since a transform: scale() would visibly
	// stretch the text while animating.
	function renderEntryData( refs, status, entryData ) {
		if ( refs.entryDataEl ) {
			refs.entryDataEl.parentNode.removeChild( refs.entryDataEl );
			refs.entryDataEl = null;
		}

		if ( 'paid' !== status || ! entryData || ! entryData.length ) {
			return;
		}

		var box = document.createElement( 'dl' );
		box.className = 'iftp-nf-modal-entry-data';

		entryData.forEach( function ( row, index ) {
			var dt = document.createElement( 'dt' );
			dt.textContent = row.label;

			var dd = document.createElement( 'dd' );
			dd.textContent = row.value;

			// Each row's fade-in is delayed a bit further than the one before
			// it, so they cascade open instead of arriving all at once —
			// capped past the sixth row so a long entry doesn't drag it out.
			var delay = 450 + Math.min( index, 6 ) * 140;
			dt.style.transitionDelay = delay + 'ms';
			dd.style.transitionDelay = ( delay + 70 ) + 'ms';

			box.appendChild( dt );
			box.appendChild( dd );
		} );

		refs.modal.insertBefore( box, refs.okBtn );
		refs.entryDataEl = box;

		// A single forced reflow isn't enough here — this modal is built in
		// one synchronous burst, so the browser might never actually paint the
		// closed state before is-open lands. Two nested rAF calls wait for a
		// real paint in between instead.
		requestAnimationFrame( function () {
			requestAnimationFrame( function () {
				box.classList.add( 'is-open' );
			} );
		} );
	}

	// Builds the icon per status — bouncing dots for pending, an X for
	// failed/cancelled, a checkmark with confetti for paid. Anything else
	// falls back to the plain glyph.
	function renderIftpNfIcon( iconEl, status ) {
		iconEl.innerHTML = '';

		if ( 'pending' === status ) {
			iconEl.appendChild( buildDotsIcon() );
			return;
		}

		if ( 'failed' === status || 'cancelled' === status ) {
			iconEl.appendChild( buildXIcon() );
			return;
		}

		if ( 'paid' === status ) {
			iconEl.appendChild( buildCheckIcon() );
			return;
		}

		iconEl.textContent = iftpNfIconFor( status );
	}

	function buildDotsIcon() {
		var wrap = document.createElement( 'span' );
		wrap.className = 'iftp-nf-dots';
		wrap.setAttribute( 'aria-hidden', 'true' );

		for ( var i = 0; i < 3; i++ ) {
			wrap.appendChild( document.createElement( 'span' ) ).className = 'iftp-nf-dot';
		}

		return wrap;
	}

	function buildXIcon() {
		var wrap = document.createElement( 'span' );
		wrap.className = 'iftp-nf-x';
		wrap.setAttribute( 'aria-hidden', 'true' );

		var bar1 = document.createElement( 'span' );
		bar1.className = 'iftp-nf-x-bar iftp-nf-x-bar--1';
		var bar2 = document.createElement( 'span' );
		bar2.className = 'iftp-nf-x-bar iftp-nf-x-bar--2';

		wrap.appendChild( bar1 );
		wrap.appendChild( bar2 );

		return wrap;
	}

	// No white — pieces travel onto the modal's own white background, where
	// a white piece would just vanish.
	var CONFETTI_COLORS = [ '#ffcd3c', '#4d9de0', '#e75a7c', '#7bd389', '#9b5de5', '#ff8c42' ];
	var CONFETTI_ANGLES = [ 0, 30, 60, 90, 120, 150, 180, 210, 240, 270, 300, 330 ];

	function buildCheckIcon() {
		var wrap = document.createElement( 'span' );
		wrap.className = 'iftp-nf-icon-paid-wrap';

		var svgNS = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( svgNS, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 36 36' );
		svg.setAttribute( 'class', 'iftp-nf-check-svg' );
		svg.setAttribute( 'aria-hidden', 'true' );

		var path = document.createElementNS( svgNS, 'path' );
		// A 1.5x scale of the Feather "check" polyline, centered on the
		// viewBox — bigger and better balanced than the original hand-picked points.
		path.setAttribute( 'd', 'M6 18 L13.5 25.5 L30 9' );
		path.setAttribute( 'class', 'iftp-nf-check-path' );

		svg.appendChild( path );
		wrap.appendChild( svg );
		wrap.appendChild( buildConfetti() );

		return wrap;
	}

	// A fixed 12-piece burst radiating from the checkmark. Left in the DOM
	// after the animation — a dozen small spans is negligible, and this only
	// ever runs once per payment.
	function buildConfetti() {
		var holder = document.createElement( 'span' );
		holder.className = 'iftp-nf-confetti';
		holder.setAttribute( 'aria-hidden', 'true' );

		CONFETTI_ANGLES.forEach( function ( angle, index ) {
			var piece = document.createElement( 'span' );
			piece.className = 'iftp-nf-confetti-piece';
			piece.style.setProperty( '--iftp-angle', angle + 'deg' );
			piece.style.setProperty( '--iftp-color', CONFETTI_COLORS[ index % CONFETTI_COLORS.length ] );

			var inner = document.createElement( 'span' );
			inner.className = 'iftp-nf-confetti-piece-inner';
			piece.appendChild( inner );

			holder.appendChild( piece );
		} );

		return holder;
	}

	// Shakes the Ninja Forms form the payment came from, matched by its
	// wrapper id in case more than one form is on the page. Falls back to
	// the first .nf-form-cont if that id isn't found, and no-ops if neither exists.
	function shakeSourceForm() {
		var container = ( iftpNfReturn.formId
			? document.getElementById( 'nf-form-' + iftpNfReturn.formId + '-cont' )
			: null ) || document.querySelector( '.nf-form-cont' );

		if ( ! container ) {
			return;
		}

		// Removing the class first (and forcing a reflow) so a second
		// failed/cancelled attempt on the same page re-triggers the shake
		// instead of being a no-op.
		container.classList.remove( 'iftp-nf-form-shake' );
		void container.offsetWidth;
		container.classList.add( 'iftp-nf-form-shake' );

		container.addEventListener( 'animationend', function onShakeEnd() {
			container.classList.remove( 'iftp-nf-form-shake' );
			container.removeEventListener( 'animationend', onShakeEnd );
		} );
	}

	// Kicks off the live watch for a payment still unresolved on load. If we
	// have a transaction id, I check it once immediately via ifthenpay's own
	// transaction-status API rather than only waiting on the webhook — either
	// way, the poll loop below is the fallback that guarantees this popup
	// eventually catches up.
	function maybeWatchForPaidStatus( modalRefs, initialStatus ) {
		if ( 'paid' === initialStatus || ! iftpNfReturn.ref ) {
			return;
		}

		if ( iftpNfReturn.transactionId ) {
			runQuickPaidCheck( function ( status, message, redirectUrl, entryData ) {
				if ( applyResolvedStatus( modalRefs, status, message, redirectUrl, entryData ) ) {
					return;
				}
				watchForPaidStatus( modalRefs );
			} );
			return;
		}

		watchForPaidStatus( modalRefs );
	}

	// The one-shot transaction-status check, shared by the normal watch and
	// the deferred-popup path, so it's only ever requested once per page load.
	function runQuickPaidCheck( callback ) {
		verifyPayment( 'success', iftpNfReturn.transactionId, callback );
	}

	// Starts the ongoing watch: a re-check when the tab comes back into
	// focus, plus the scheduled poll loop.
	function watchForPaidStatus( modalRefs ) {
		// Browsers throttle/suspend timers in a hidden tab (e.g. the customer
		// switches to their banking app to pay), so the poll loop below can
		// silently stall while it's backgrounded. Re-checking the moment the
		// tab's foregrounded again closes that gap.
		document.addEventListener( 'visibilitychange', function () {
			if ( modalRefs.resolved || document.hidden ) {
				return;
			}
			verifyPayment( 'poll', '', function ( status, message, redirectUrl, entryData ) {
				applyResolvedStatus( modalRefs, status, message, redirectUrl, entryData );
			} );
		} );

		pollPaymentStatus( modalRefs, 0 );
	}

	// Background poll for a payment still unresolved — fast for a short
	// window right after the popup appears, then a steadier cadence for the
	// long tail, since a Multibanco/Payshop reference can stay pending for
	// days with no better signal.
	function pollPaymentStatus( modalRefs, attempt ) {
		var fastAttempts = 10;
		var fastIntervalMs = 3000;
		var slowIntervalMs = 10000;
		// ~24 minutes total, generous on purpose — a Multibanco/Payshop
		// reference can take a while, and a backgrounded tab's throttled
		// timers can eat into this budget before ever firing.
		var maxAttempts = 153;

		if ( modalRefs.resolved || attempt >= maxAttempts ) {
			return;
		}

		var intervalMs = attempt < fastAttempts ? fastIntervalMs : slowIntervalMs;

		window.setTimeout( function () {
			if ( modalRefs.resolved ) {
				return;
			}
			verifyPayment( 'poll', '', function ( status, message, redirectUrl, entryData ) {
				if ( applyResolvedStatus( modalRefs, status, message, redirectUrl, entryData ) ) {
					return;
				}
				pollPaymentStatus( modalRefs, attempt + 1 );
			} );
		}, intervalMs );
	}

	/**
	 * Updates the popup with whatever verifyPayment() reported and tells the
	 * caller whether to keep watching. An empty status means the request
	 * itself failed, so the caller's own polling schedule just retries.
	 *
	 * A "paid" resolution with a redirect URL navigates away instead of
	 * showing the checkmark popup — this only fires for a payment resolving
	 * to paid after the page already rendered pending, since the
	 * redirect-at-load case is handled server-side already.
	 *
	 * @return {boolean} true once resolved "paid" — the only status this
	 *                    popup stops watching for; every other status can
	 *                    still turn into "paid" later.
	 */
	function applyResolvedStatus( modalRefs, status, message, redirectUrl, entryData ) {
		if ( '' === status || modalRefs.resolved ) {
			return modalRefs.resolved;
		}

		if ( 'paid' === status && redirectUrl ) {
			modalRefs.resolved = true;
			window.location.href = redirectUrl;
			return true;
		}

		updateIftpNfModalContent( modalRefs, status, message, entryData );

		if ( 'paid' === status ) {
			modalRefs.resolved = true;
		}

		return modalRefs.resolved;
	}

	// The "success" action (with a transaction id) only ever runs once; every
	// poll tick after that uses "poll" with no transaction id.
	function verifyPayment( returnAction, transactionId, callback ) {
		if ( ! iftpNfReturn.ajaxUrl || ! iftpNfReturn.nonce ) {
			callback( '', '', '', [] );
			return;
		}

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', iftpNfReturn.ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

		xhr.onload = function () {
			var status = '';
			var message = '';
			var redirectUrl = '';
			var entryData = [];

			try {
				var response = JSON.parse( xhr.responseText );

				if ( response && response.success && response.data ) {
					status = String( response.data.status || '' );
					message = String( response.data.message || '' );
					redirectUrl = String( response.data.redirectUrl || '' );
					entryData = response.data.entryData || [];
				}
			} catch ( e ) {
				// Malformed/non-JSON response — I treat this like a network
				// error; the caller's schedule retries regardless.
			}

			callback( status, message, redirectUrl, entryData );
		};

		xhr.onerror = function () {
			callback( '', '', '', [] );
		};

		var params = [
			'action=iftp_nf_verify_payment',
			'nonce=' + encodeURIComponent( iftpNfReturn.nonce ),
			'ref=' + encodeURIComponent( iftpNfReturn.ref ),
			'return_action=' + encodeURIComponent( returnAction ),
			// query_status is the page's original iftp_nf_pay value, unrelated
			// to returnAction — I send it with every poll tick so the server
			// keeps applying the same "still pending" fallback the popup's
			// first render used, instead of flipping back to pending before
			// the webhook catches up.
			'query_status=' + encodeURIComponent( iftpNfReturn.queryStatus || '' ),
		];

		if ( transactionId ) {
			params.push( 'transaction_id=' + encodeURIComponent( transactionId ) );
		}

		xhr.send( params.join( '&' ) );
	}

	// iftp_nf_pay/ref/transaction_id are ours; the rest are ifthenpay's own
	// card-payment page decorating the return redirect (an anti-phishing key,
	// masked card number, etc.) that we never read — so I strip them all
	// rather than let them sit in browser history and leak into the next
	// Pay-by-Link return URL as a stale referer.
	var RETURN_PARAMS = [ 'iftp_nf_pay', 'ref', 'transaction_id', 'id', 'amount', 'requestId', 'sk', 'brand', 'pan' ];

	// Drops the params above from the address bar on load, so a refresh
	// doesn't re-show the same result and nothing sensitive lingers in the URL.
	function stripReturnParamsFromUrl() {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}

		var url = new URL( window.location.href );
		var changed = false;

		RETURN_PARAMS.forEach( function ( param ) {
			if ( url.searchParams.has( param ) ) {
				url.searchParams.delete( param );
				changed = true;
			}
		} );

		if ( ! changed ) {
			return;
		}

		window.history.replaceState( window.history.state, document.title, url.toString() );
	}

	function iftpNfIconFor( status ) {
		switch ( status ) {
			case 'paid':
				return '✓';
			case 'failed':
			case 'cancelled':
				return '✕';
			case 'expired':
				return '⏱';
			default:
				return '…';
		}
	}
} )();
