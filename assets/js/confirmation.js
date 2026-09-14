( function () {
	'use strict';

	if ( typeof iftpNfConfirmation === 'undefined' ) {
		return;
	}

	function post( action, params ) {
		var body = new URLSearchParams();

		body.append( 'action', action );
		body.append( 'nonce', iftpNfConfirmation.nonce );

		Object.keys( params ).forEach( function ( key ) {
			body.append( key, params[ key ] );
		} );

		return fetch( iftpNfConfirmation.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * The Paid/Pending/Failed/Cancelled top-level tabs — deliberately its own
	 * small pill nav (see admin.css) rather than reusing Ninja Forms' own
	 * `.nav-tab-wrapper` styling above it, at Victor's request, so the two
	 * navs read as distinct levels instead of a visual duplicate.
	 */
	function bindTabs() {
		var tabs = document.querySelectorAll( '.iftp-nf-confirmation-tab' );
		var panels = document.querySelectorAll( '.iftp-nf-confirmation-panel' );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var target = tab.getAttribute( 'data-target' );

				tabs.forEach( function ( t ) {
					var isActive = t === tab;
					t.classList.toggle( 'is-active', isActive );
					t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				} );

				panels.forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-panel' ) !== target;
				} );
			} );
		} );
	}

	/**
	 * The "Confirmation Type" control is a segmented pill switch (see
	 * admin.css's `.iftp-nf-type-switch`/`.iftp-nf-type-option`) matching the
	 * Paid/Pending/Failed/Cancelled tabs right above it, not a plain
	 * `<select>` — a separate class from `.iftp-nf-confirmation-tab` on
	 * purpose (styled identically, see admin.css) so `bindTabs()`'s own
	 * `querySelectorAll` above never picks these buttons up too.
	 *
	 * The actual submitted value lives in the hidden
	 * `#iftp-nf-confirmation-paid-type` input; clicking a pill just updates
	 * that input and which Popup/Page/URL field is shown — the other two
	 * stay in the DOM (still present, just hidden).
	 */
	function bindPaidType() {
		var hiddenInput = document.getElementById( 'iftp-nf-confirmation-paid-type' );
		var buttons = document.querySelectorAll( '.iftp-nf-type-option' );
		var options = document.querySelectorAll( '[data-panel="paid"] .iftp-nf-confirmation-option' );

		if ( ! hiddenInput || ! buttons.length ) {
			return;
		}

		function apply( value ) {
			options.forEach( function ( option ) {
				option.hidden = option.getAttribute( 'data-option' ) !== value;
			} );
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var value = button.getAttribute( 'data-value' );

				buttons.forEach( function ( b ) {
					var isActive = b === button;
					b.classList.toggle( 'is-active', isActive );
					b.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				} );

				hiddenInput.value = value;
				apply( value );
			} );
		} );

		apply( hiddenInput.value );
	}

	/**
	 * Each popup message field is two synced views over the same value: a
	 * "Normal" `contenteditable` div (what the admin types into, with a
	 * small bold/italic toolbar) and a "Raw" `<textarea>` showing/editing
	 * the underlying HTML directly — only the textarea actually submits
	 * with the form, so every edit in the Normal view is mirrored into it
	 * immediately, and every edit in Raw is mirrored back into Normal so
	 * switching tabs never shows stale content either way.
	 */
	function bindMessageEditors() {
		document.querySelectorAll( '.iftp-nf-message-editor' ).forEach( function ( editor ) {
			var tabs = editor.querySelectorAll( '.iftp-nf-message-tab' );
			var normalView = editor.querySelector( '.iftp-nf-message-normal' );
			var rawView = editor.querySelector( '.iftp-nf-message-raw' );

			if ( ! normalView || ! rawView ) {
				return;
			}

			tabs.forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					var mode = tab.getAttribute( 'data-mode' );

					tabs.forEach( function ( t ) {
						var isActive = t === tab;
						t.classList.toggle( 'is-active', isActive );
						t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
					} );

					normalView.hidden = 'normal' !== mode;
					rawView.hidden = 'raw' !== mode;

					if ( 'raw' === mode ) {
						rawView.focus();
					} else {
						normalView.focus();
					}
				} );
			} );

			normalView.addEventListener( 'input', function () {
				rawView.value = normalView.innerHTML;
			} );

			rawView.addEventListener( 'input', function () {
				normalView.innerHTML = rawView.value;
			} );

			editor.querySelectorAll( '.iftp-nf-message-format' ).forEach( function ( button ) {
				// Without this, the button would take focus on mousedown —
				// before its own `click` fires — collapsing whatever text
				// selection `execCommand()` below is supposed to act on.
				button.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
				} );

				button.addEventListener( 'click', function () {
					document.execCommand( button.getAttribute( 'data-command' ) );
					rawView.value = normalView.innerHTML;
				} );
			} );
		} );
	}

	function bindForm() {
		var form = document.getElementById( 'iftp-nf-confirmation-form' );
		var saveButton = document.getElementById( 'iftp-nf-save-confirmation' );
		var saveStatus = form ? form.querySelector( '.iftp-nf-save-status' ) : null;
		var saveStatusTimer = null;

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( saveStatusTimer ) {
				window.clearTimeout( saveStatusTimer );
			}

			if ( saveStatus ) {
				saveStatus.classList.remove( 'is-visible' );
			}

			if ( saveButton ) {
				saveButton.disabled = true;
				saveButton.classList.add( 'is-saving' );
			}

			post( 'iftp_nf_save_confirmation_settings', {
				paid_type: form.querySelector( '#iftp-nf-confirmation-paid-type' ).value,
				paid_message: form.querySelector( '#iftp-nf-confirmation-paid-message' ).value,
				paid_page_id: form.querySelector( '#iftp-nf-confirmation-paid-page' ).value,
				paid_url: form.querySelector( '#iftp-nf-confirmation-paid-url' ).value,
				show_entry_data: form.querySelector( '#iftp-nf-confirmation-show-entry-data' ).checked ? '1' : '',
				pending_message: form.querySelector( '#iftp-nf-confirmation-pending-message' ).value,
				failed_message: form.querySelector( '#iftp-nf-confirmation-failed-message' ).value,
				cancelled_message: form.querySelector( '#iftp-nf-confirmation-cancelled-message' ).value,
			} ).then( function ( res ) {
				if ( saveButton ) {
					saveButton.disabled = false;
					saveButton.classList.remove( 'is-saving' );
				}

				if ( res.success ) {
					if ( saveStatus ) {
						saveStatus.classList.add( 'is-visible' );
						saveStatusTimer = window.setTimeout( function () {
							saveStatus.classList.remove( 'is-visible' );
						}, 2000 );
					}
				} else {
					window.alert( res.data && res.data.message ? res.data.message : iftpNfConfirmation.i18n.error );
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindTabs();
		bindPaidType();
		bindMessageEditors();
		bindForm();
	} );
} )();
