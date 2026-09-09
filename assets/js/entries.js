( function () {
	'use strict';

	function bindDetailsToggles() {
		document.querySelectorAll( '.iftp-nf-details-toggle' ).forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function () {
				var target = document.getElementById( trigger.getAttribute( 'data-details-target' ) );

				if ( target ) {
					target.hidden = ! target.hidden;
				}
			} );
		} );
	}

	// Peeking ninja mascot: shake in place, then slide to the next state.
	// Cycle: scooted (middle) -> returning (left) -> scooted -> fled (fully
	// off-screen), then auto-return to "returning" after 5s and start over.
	function bindPeekingNinja() {
		var wrapper = document.getElementById( 'nf-peeking-ninja-wrapper' );

		if ( ! wrapper ) {
			return;
		}

		var states = [ 'nf-shy-ninja-scooted', 'nf-shy-ninja-returning', 'nf-shy-ninja-scooted', 'nf-shy-ninja-fled' ];
		var stateIndex = 0;
		var busy = false;

		function setState( className ) {
			wrapper.classList.remove( 'nf-shy-ninja-returning', 'nf-shy-ninja-scooted', 'nf-shy-ninja-fled' );
			wrapper.classList.add( className );
		}

		wrapper.addEventListener( 'mouseenter', function () {
			if ( busy ) {
				return;
			}

			busy = true;

			var nextState = states[ stateIndex ];
			stateIndex = ( stateIndex + 1 ) % states.length;

			wrapper.classList.add( 'nf-shy-ninja-shake' );

			window.setTimeout( function () {
				wrapper.classList.remove( 'nf-shy-ninja-shake' );
				setState( nextState );
				busy = false;

				if ( 'nf-shy-ninja-fled' === nextState ) {
					window.setTimeout( function () {
						setState( 'nf-shy-ninja-returning' );
						stateIndex = 0;
					}, 5000 );
				}
			}, 400 );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindDetailsToggles();
		bindPeekingNinja();
	} );
} )();
