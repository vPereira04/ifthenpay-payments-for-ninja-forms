( function () {
	'use strict';

	if ( typeof iftpNfAdmin === 'undefined' ) {
		return;
	}

	function post( action, params ) {
		var body = new URLSearchParams();

		body.append( 'action', action );
		body.append( 'nonce', iftpNfAdmin.nonce );

		Object.keys( params ).forEach( function ( key ) {
			var value = params[ key ];

			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );

		return fetch( iftpNfAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function refreshMethodsTable( html ) {
		var wrapper = document.getElementById( 'iftp-nf-methods-table-wrapper' );

		if ( wrapper ) {
			wrapper.innerHTML = html;
			bindMethodsTable();
		}
	}

	function bindConnection() {
		var connect = document.getElementById( 'iftp-nf-connect' );
		var disconnect = document.getElementById( 'iftp-nf-disconnect' );
		var message = document.getElementById( 'iftp-nf-connection-message' );

		if ( connect ) {
			connect.addEventListener( 'click', function () {
				var input = document.getElementById( 'iftp-nf-backoffice-key' );
				connect.disabled = true;
				connect.textContent = iftpNfAdmin.i18n.connecting;

				post( 'iftp_nf_connect_backoffice', { backoffice_key: input.value } ).then( function ( res ) {
					if ( res.success ) {
						window.location.reload();
						return;
					}

					connect.disabled = false;
					connect.textContent = 'Connect';
					message.textContent = res.data && res.data.message ? res.data.message : iftpNfAdmin.i18n.error;
				} );
			} );
		}

		if ( disconnect ) {
			disconnect.addEventListener( 'click', function () {
				post( 'iftp_nf_disconnect_backoffice', {} ).then( function () {
					window.location.reload();
				} );
			} );
		}
	}

	function bindGatewayKeySelect() {
		var select = document.getElementById( 'iftp-nf-gateway-key' );

		if ( ! select ) {
			return;
		}

		select.addEventListener( 'change', function () {
			select.disabled = true;

			post( 'iftp_nf_select_gateway_key', { gateway_key: select.value } ).then( function ( res ) {
				select.disabled = false;

				if ( res.success ) {
					refreshMethodsTable( res.data.table_html );
				} else {
					window.alert( res.data && res.data.message ? res.data.message : iftpNfAdmin.i18n.error );
				}
			} );
		} );
	}

	function bindMethodsTable() {
		var rows = document.querySelectorAll( '.iftp-nf-method-row' );

		rows.forEach( function ( row ) {
			var enabledCheckbox = row.querySelector( '.iftp-nf-method-enabled' );
			var radio = row.querySelector( '.iftp-nf-default-method' );
			var star = row.querySelector( '.iftp-nf-star' );

			if ( ! enabledCheckbox || ! radio || ! star ) {
				return;
			}

			enabledCheckbox.addEventListener( 'change', function () {
				radio.disabled = ! enabledCheckbox.checked;
				star.classList.toggle( 'iftp-nf-star--hidden', ! enabledCheckbox.checked );

				if ( ! enabledCheckbox.checked && radio.checked ) {
					radio.checked = false;
				}
			} );

			radio.addEventListener( 'change', function () {
				star.classList.remove( 'iftp-nf-star--wink' );
				// Force reflow so the animation can replay on repeated clicks.
				void star.offsetWidth;
				star.classList.add( 'iftp-nf-star--wink' );
			} );
		} );

		var activationButtons = document.querySelectorAll( '.iftp-nf-request-activation' );

		activationButtons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var entity = button.getAttribute( 'data-entity' );
				var originalLabel = button.textContent;

				button.disabled = true;
				button.textContent = iftpNfAdmin.i18n.requesting;

				post( 'iftp_nf_request_activation', { entity: entity } ).then( function ( res ) {
					if ( res.success ) {
						button.textContent = iftpNfAdmin.i18n.requested;
						return;
					}

					button.disabled = false;
					button.textContent = originalLabel;
					window.alert( res.data && res.data.message ? res.data.message : iftpNfAdmin.i18n.error );
				} );
			} );
		} );
	}

	function bindSettingsForm() {
		var form = document.getElementById( 'iftp-nf-settings-form' );
		var saveButton = document.getElementById( 'iftp-nf-save-settings' );
		var saveStatus = form ? form.querySelector( '.iftp-nf-save-status' ) : null;
		var saveStatusTimer = null;

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var enabled = Array.prototype.map.call(
				form.querySelectorAll( '.iftp-nf-method-enabled:checked' ),
				function ( el ) {
					return el.value;
				}
			);

			var defaultMethod = form.querySelector( '.iftp-nf-default-method:checked' );

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

			post( 'iftp_nf_save_settings', {
				enabled_methods: enabled,
				default_method: defaultMethod ? defaultMethod.value : '',
				description: form.querySelector( '#iftp-nf-description' ).value,
				expiry_days: form.querySelector( '#iftp-nf-expiry-days' ).value,
			} ).then( function ( res ) {
				if ( saveButton ) {
					saveButton.disabled = false;
					saveButton.classList.remove( 'is-saving' );
				}

				if ( res.success ) {
					refreshMethodsTable( res.data.table_html );

					if ( saveStatus ) {
						saveStatus.classList.add( 'is-visible' );
						saveStatusTimer = window.setTimeout( function () {
							saveStatus.classList.remove( 'is-visible' );
						}, 2000 );
					}
				} else {
					window.alert( res.data && res.data.message ? res.data.message : iftpNfAdmin.i18n.error );
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindConnection();
		bindGatewayKeySelect();
		bindMethodsTable();
		bindSettingsForm();
	} );
} )();
