( function () {
	'use strict';

	if ( typeof iftpNfReturn === 'undefined' ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		// Stripped immediately, not on popup dismiss: the address bar is
		// "live" as `wp_get_referer()` for as long as it carries this stuff,
		// so a resubmit before the user ever closes the popup would still
		// hand `IfthenpayGateway::resolve_return_base_url()` a polluted URL.
		stripReturnParamsFromUrl();

		// A "Paid" confirmation configured as a page/URL redirect (see
		// `Admin\ConfirmationPage`) should never have to flash the ordinary
		// "we're waiting for your payment" popup first just to immediately
		// replace it with a real navigation a moment later — the webhook
		// essentially never beats the browser back here (that's the rare
		// case `Plugin::maybe_redirect_paid_confirmation()` already handles
		// server-side, before this script is even enqueued), so in practice
		// this quick check is the only thing standing between "still
		// pending" and "paid" for a great many returns. Held behind a brief,
		// wordless spinner instead — see `deferInitialPopupForQuickCheck()`.
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

	/**
	 * Runs the same one-shot transaction-status check `maybeWatchForPaidStatus()`
	 * would otherwise run first, but behind a brief spinner overlay (reusing
	 * `pay-by-link.js`'s own shell) instead of the real popup — then either
	 * redirects (resolved "paid" with a redirect configured), reveals the
	 * real popup already showing the correct final state (resolved to
	 * anything else, including "paid" with no redirect — e.g. the setting
	 * changed between page load and this check), or reveals the real popup
	 * still showing "pending" and falls through to the normal watch loop
	 * (not yet resolved — nothing lost, this is exactly where
	 * `maybeWatchForPaidStatus()` would have started polling anyway).
	 */
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

	/**
	 * Builds and shows the return-status popup: a centered dialog over a
	 * dimmed backdrop, dismissible via the close (X) button, the "OK"
	 * button, a backdrop click, or Escape. Returns a small handle
	 * (`updateIftpNfModalContent()`'s `refs`) that later live-status updates
	 * use to update this same popup in place instead of tearing it down and
	 * rebuilding it.
	 */
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

		// Kept in the DOM (hidden via inline `display`, not removed) for as
		// long as this page is open — a payment can still resolve to "paid"
		// after the customer dismisses a "pending" popup (see
		// `maybeWatchForPaidStatus()`/`pollPaymentStatus()`), and that must
		// still be shown rather than silently missed.
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

		// `resolved` is flipped by `applyResolvedStatus()` once the payment is
		// confirmed "paid" — checked by both the scheduled poll loop and the
		// `visibilitychange` re-check below so neither does any more work
		// past that point. `status` (the last status actually rendered) is
		// how `updateIftpNfModalContent()` tells a genuine transition apart
		// from a redundant poll tick reporting the same status again — only
		// the former reopens a dismissed popup, rebuilds the icon, or shakes
		// the source form.
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

	/**
	 * Swaps the popup's icon, status class and message in place — used both
	 * for the very first render and for every later live-status update (see
	 * `maybeWatchForPaidStatus()`/`pollPaymentStatus()`), so a payment that
	 * resolves while the customer is still looking at "pending" updates with
	 * no perceptible delay instead of requiring a page reload.
	 *
	 * Only a genuine status change (tracked via `refs.status`) rebuilds the
	 * icon, reopens the popup, or shakes the source form — a redundant poll
	 * tick reporting the same unchanged status is otherwise a no-op:
	 * - the pending dots' bounce and the paid checkmark/confetti are
	 *   one-shot or looping animations that must keep running undisturbed
	 *   across repeated ticks, not restart every 3-10 seconds;
	 * - re-opening on every tick would undo the customer's own OK/close
	 *   dismissal every few seconds for as long as polling keeps running,
	 *   even though nothing new actually happened.
	 */
	function updateIftpNfModalContent( refs, status, message, entryData ) {
		var statusChanged = refs.status !== status;

		refs.modal.className = 'iftp-nf-modal iftp-nf-modal--' + status;

		if ( statusChanged ) {
			renderIftpNfIcon( refs.icon, status );
		}

		// `innerHTML`, not `textContent`: an admin can format a status
		// message with simple markup (bold/italic) via its "Normal" view on
		// the "Confirmation Type" settings tab (`assets/js/confirmation.js`),
		// and `Ajax\Controller::save_confirmation_settings()` already
		// sanitizes it with `wp_kses_post()` before it's ever stored.
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

	/**
	 * Builds (once per genuine transition into "paid") the "Show Entry Data"
	 * box (`Admin\ConfirmationPage`'s checkbox) between the message and the
	 * OK button, then reveals it. Removes any previous box first — relevant
	 * only if a popup somehow re-enters a non-"paid" status after already
	 * showing one, which never actually happens today ("paid" is terminal)
	 * but keeps this safe to call on every status change regardless.
	 *
	 * The box is inserted already at its full final height (see
	 * `frontend.css`) so nothing else in the popup shifts when it appears;
	 * only its own `clip-path` animates, opening symmetrically from its
	 * vertical center outward toward both edges at once — matching the
	 * "opening" look already used for the failed/cancelled X icon
	 * (`buildXIcon()`), just for a box of readable text instead of a plain
	 * bar, where a `transform: scale()` would otherwise visibly stretch the
	 * label/value text while it animated.
	 */
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

			// Each row's own fade/slide-in (see frontend.css) is delayed a
			// little further behind the box's own reveal than the row
			// before it, so the whole thing cascades open one line at a
			// time instead of every row arriving in one flat instant.
			// Capped past the sixth row so a long entry doesn't stretch the
			// reveal out for several seconds.
			var delay = 450 + Math.min( index, 6 ) * 140;
			dt.style.transitionDelay = delay + 'ms';
			dd.style.transitionDelay = ( delay + 70 ) + 'ms';

			box.appendChild( dt );
			box.appendChild( dd );
		} );

		refs.modal.insertBefore( box, refs.okBtn );
		refs.entryDataEl = box;

		// A single forced reflow (`void box.offsetHeight`) is the usual fix
		// for "adding a class right after inserting an element skips its
		// transition", but it only guarantees the *style* for the closed
		// state was computed before `is-open` is added — not that the
		// browser ever actually *painted* it, which is what a transition
		// needs to have something to animate from. This popup builds the
		// whole modal (including this box) in one synchronous burst, so
		// there's a real risk of that closed state never reaching the
		// screen at all and the box simply appearing already open. Two
		// nested `requestAnimationFrame()` calls wait for an actual paint
		// in between instead: the first fires only after the browser has
		// rendered this frame (the box closed), and the second — scheduled
		// from inside the first — fires on the very next one, guaranteeing
		// `is-open` lands in a frame after the closed state was visible.
		requestAnimationFrame( function () {
			requestAnimationFrame( function () {
				box.classList.add( 'is-open' );
			} );
		} );
	}

	/**
	 * Builds the icon's contents for `status` — a dedicated small animation
	 * per outcome (see frontend.css) rather than a single static glyph:
	 * bouncing dots for "pending", an X drawn open from its center for
	 * "failed"/"cancelled", and a drawn checkmark with a confetti burst for
	 * "paid". Anything else ("expired", or an unrecognised value) falls back
	 * to the plain glyph `iftpNfIconFor()` already provided — no specific
	 * animation was asked for those.
	 */
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

	// No white — pieces travel well past the green circle onto the modal's
	// own white background, where a white piece would simply vanish.
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
		// A 1.5x scale of the Feather "check" polyline (4,12 / 9,17 / 20,6),
		// mathematically centered on both axes of the 36-unit viewBox at that
		// scale — bigger and better balanced than the original hand-picked
		// points, which read as small and slightly off-center.
		path.setAttribute( 'd', 'M6 18 L13.5 25.5 L30 9' );
		path.setAttribute( 'class', 'iftp-nf-check-path' );

		svg.appendChild( path );
		wrap.appendChild( svg );
		wrap.appendChild( buildConfetti() );

		return wrap;
	}

	/**
	 * A fixed 12-piece burst radiating out from the checkmark — see
	 * frontend.css's `.iftp-nf-confetti-piece`/`-inner` for why each piece
	 * is two nested elements (a fixed "aim" rotate plus an independently
	 * animated tumble/translate). Left in the DOM after the animation ends
	 * rather than cleaned up — a one-time dozen small `<span>`s for the
	 * lifetime of this popup is negligible, and this only ever runs once
	 * per payment (paid stops all further polling).
	 */
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

	/**
	 * Shakes the actual Ninja Forms form the payment came from — found by
	 * `iftpNfReturn.formId` (`Gateway\IfthenpayGateway::process()`'s own
	 * `$form_id`, via `SubmissionStore`), matching Ninja Forms core's own
	 * wrapper markup (`includes/Templates/display-form-container.html.php`:
	 * `#nf-form-{id}-cont`) rather than searching by class alone, in case
	 * more than one form is on this page. Falls back to the first
	 * `.nf-form-cont` on the page if that specific id isn't found (e.g. the
	 * form was since removed from the page) — better than shaking nothing at
	 * all. A no-op if neither exists.
	 */
	function shakeSourceForm() {
		var container = ( iftpNfReturn.formId
			? document.getElementById( 'nf-form-' + iftpNfReturn.formId + '-cont' )
			: null ) || document.querySelector( '.nf-form-cont' );

		if ( ! container ) {
			return;
		}

		// Removed first (and reflow forced) so a second failed/cancelled
		// attempt on the same page re-triggers the animation instead of the
		// class already being present being a no-op.
		container.classList.remove( 'iftp-nf-form-shake' );
		void container.offsetWidth;
		container.classList.add( 'iftp-nf-form-shake' );

		container.addEventListener( 'animationend', function onShakeEnd() {
			container.classList.remove( 'iftp-nf-form-shake' );
			container.removeEventListener( 'animationend', onShakeEnd );
		} );
	}

	/**
	 * Kicks off this page's live watch for a payment that was still
	 * unresolved when it first rendered — never runs at all once the popup
	 * already shows "paid", and does nothing without a `ref` to poll with
	 * (only ever missing if `Plugin::maybe_enqueue_return_banner()` itself
	 * found no stored record, which already short-circuits before this
	 * script is even enqueued).
	 *
	 * `iftpNfReturn.transactionId` is only ever present on a genuine success
	 * return (see `Gateway\IfthenpayGateway::build_return_url()`'s
	 * `[TRANSACTIONID]` placeholder) — when it is, this checks it once,
	 * immediately, via ifthenpay's own transaction-status API
	 * (`Api\Webhook\WebhookController::confirm_via_transaction_status()`)
	 * rather than only ever waiting on the asynchronous webhook. Whether or
	 * not that quick check resolves anything, `pollPaymentStatus()` below is
	 * always the fallback that actually guarantees this popup eventually
	 * catches up.
	 */
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

	/**
	 * The one-shot check via ifthenpay's own transaction-status API — shared
	 * by `maybeWatchForPaidStatus()` (already showing the real popup) and
	 * `deferInitialPopupForQuickCheck()` (still holding a spinner, no real
	 * popup built yet) so this exact request is only ever made once per
	 * page load either way.
	 */
	function runQuickPaidCheck( callback ) {
		verifyPayment( 'success', iftpNfReturn.transactionId, callback );
	}

	/**
	 * Starts the ongoing watch for a payment still unresolved after the
	 * quick check above (or one that never had a transaction id to check in
	 * the first place): the backgrounded-tab re-check plus the scheduled
	 * poll loop (`pollPaymentStatus()`).
	 */
	function watchForPaidStatus( modalRefs ) {
		// Browsers throttle or fully suspend `setTimeout` in a hidden/
		// backgrounded tab (e.g. the customer switches to their banking app
		// to actually pay a Multibanco reference, or switches tabs to check
		// email) — the scheduled poll loop below can silently stall for
		// exactly as long as the tab stays hidden. Re-checking immediately
		// the moment the tab is foregrounded again closes that gap instead
		// of waiting for whatever's left of the current interval (or,
		// worse, for a timer the browser never resumes at all until some
		// later interaction).
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

	/**
	 * Background poll for a payment still unresolved after the quick
	 * transaction-id check (or one that never had a transaction id to check
	 * in the first place). Same cadence as `ifthenpay-payments-for-wpforms`'s
	 * own polling: fast for a short window right after the popup appears —
	 * catches most remaining cases (the webhook landing moments later, or a
	 * transiently failed transaction-status check succeeding on this next
	 * try) with no perceptible delay — then a steadier cadence for the long
	 * tail, since by then there's no reliable signal left to say this
	 * specific payment is any more likely to resolve soon than a
	 * Multibanco/Payshop reference that can stay genuinely pending for days.
	 */
	function pollPaymentStatus( modalRefs, attempt ) {
		var fastAttempts = 10;
		var fastIntervalMs = 3000;
		var slowIntervalMs = 10000;
		// ~24 minutes total (30s fast + 143 * 10s slow) — generous on purpose:
		// a Multibanco/Payshop reference can take a while even for a customer
		// who keeps the tab open, and a backgrounded tab's throttled timers
		// (see the `visibilitychange` listener in `maybeWatchForPaidStatus()`)
		// can themselves eat into this budget before ever firing.
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
	 * Updates the popup with whatever `verifyPayment()` reported and tells
	 * the caller whether to keep watching. An empty `status` means the
	 * request itself failed (network error or a malformed response) rather
	 * than the payment resolving to anything — left exactly as shown, the
	 * caller's own polling schedule retries regardless.
	 *
	 * @return {boolean} true once resolved "paid" — the only status this
	 *                    popup ever stops watching for, matching
	 *                    `SubmissionStore::update_status()`: every other
	 *                    status can still turn into "paid" later.
	 *
	 * A "paid" resolution carrying a non-empty `redirectUrl` (the "Paid"
	 * confirmation type is a WordPress page or a custom URL, not the
	 * default popup — see `Admin\ConfirmationPage`) navigates the browser
	 * there instead of ever rendering the checkmark popup. A "paid at load"
	 * request never reaches here at all in that case — it's already been
	 * redirected server-side by `Plugin::maybe_redirect_paid_confirmation()`
	 * — so this only ever fires for a payment that resolves to "paid"
	 * *after* this page has already rendered with a still-pending popup
	 * showing.
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

	/**
	 * Calls `Ajax\FrontendController::verify_payment()`. `returnAction`
	 * "success" (with `transactionId`) is only ever used once, by
	 * `maybeWatchForPaidStatus()`/`deferInitialPopupForQuickCheck()`; every
	 * poll tick after that uses "poll" with no transaction id, matching the
	 * same two-action vocabulary `ifthenpay-payments-for-wpforms`'s own
	 * `ajax_verify_payment()` uses.
	 */
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
				// Malformed/non-JSON response — treated the same as a
				// network error below; the caller's own schedule retries
				// regardless.
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
			// The page's original `iftp_nf_pay` value (see
			// `Plugin::maybe_enqueue_return_banner()`) — unrelated to
			// `returnAction` above — kept alongside every poll tick so
			// `Ajax\FrontendController::verify_payment()` can keep applying
			// the same "still pending, but the browser already told us
			// error/cancel" fallback this popup's very first render used
			// (`Plugin::resolve_display_status()`), instead of the popup
			// flipping back to "pending" the moment a tick reads the raw
			// stored status before the webhook has caught up.
			'query_status=' + encodeURIComponent( iftpNfReturn.queryStatus || '' ),
		];

		if ( transactionId ) {
			params.push( 'transaction_id=' + encodeURIComponent( transactionId ) );
		}

		xhr.send( params.join( '&' ) );
	}

	// `iftp_nf_pay`/`ref`/`transaction_id` are ours (`Gateway\IfthenpayGateway::build_return_url()`).
	// The rest are ifthenpay's own hosted card-payment page decorating the
	// return redirect with transaction-verification data (an anti-phishing
	// key and a masked card number among them) — never read by this plugin,
	// so there's nothing to lose by dropping them too, and every reason to:
	// left in place, they'd sit in browser history indefinitely and get
	// carried into the next Pay-by-Link attempt's return URL as a stale
	// referer (see `resolve_return_base_url()`).
	var RETURN_PARAMS = [ 'iftp_nf_pay', 'ref', 'transaction_id', 'id', 'amount', 'requestId', 'sk', 'brand', 'pan' ];

	/**
	 * Drops every return/verification param above from the address bar as
	 * soon as the page loads, so refreshing afterwards doesn't re-show the
	 * same result and nothing sensitive lingers in the URL bar or history.
	 */
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
