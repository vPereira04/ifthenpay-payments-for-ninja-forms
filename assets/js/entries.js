( function () {
	'use strict';

	// Set by `bindColumnsControl()` once it's run, so a wholesale tbody swap
	// elsewhere (`bindBulkActions()`, after a delete refreshes the current
	// page) can re-apply the admin's saved column order/visibility to the
	// freshly server-rendered rows — otherwise they'd briefly show every
	// column in its default order until the next full page load.
	var reapplyColumnLayout = function () {};

	function bindDetailsToggles() {
		document.querySelectorAll( '.iftp-nf-details-toggle' ).forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function () {
				var target = document.getElementById( trigger.getAttribute( 'data-details-target' ) );

				if ( ! target ) {
					return;
				}

				if ( target.hidden ) {
					target.hidden = false;
					target.classList.remove( 'iftp-nf-details-anim' );
					void target.offsetWidth; // Restart the animation if it's toggled again.
					target.classList.add( 'iftp-nf-details-anim' );
				} else {
					target.hidden = true;
					target.classList.remove( 'iftp-nf-details-anim' );
				}
			} );
		} );
	}

	// A single reusable confirm dialog (see `EntriesPage::render_confirm_modal()`)
	// standing in for the browser's native `window.confirm()` — that OS-level
	// popup is what made bulk actions feel like blunt "buttons" rather than
	// part of the same smooth interface. `opts` is `{ title, message,
	// confirmLabel, danger }`; `onConfirm` only runs if the user confirms.
	function openConfirmModal( opts, onConfirm ) {
		var modal = document.querySelector( '[data-iftp-modal]' );

		if ( ! modal ) {
			onConfirm();
			return;
		}

		var overlay = modal.querySelector( '[data-iftp-modal-overlay]' );
		var titleEl = modal.querySelector( '[data-iftp-modal-title]' );
		var messageEl = modal.querySelector( '[data-iftp-modal-message]' );
		var cancelBtn = modal.querySelector( '[data-iftp-modal-cancel]' );
		var confirmBtn = modal.querySelector( '[data-iftp-modal-confirm]' );

		titleEl.textContent = opts.title || '';
		messageEl.textContent = opts.message || '';
		confirmBtn.textContent = opts.confirmLabel || '';
		confirmBtn.classList.toggle( 'iftp-nf-modal-confirm--danger', !! opts.danger );

		function cleanup() {
			modal.classList.remove( 'is-open' );

			window.setTimeout( function () {
				modal.hidden = true;
			}, 200 );

			confirmBtn.removeEventListener( 'click', onConfirmClick );
			cancelBtn.removeEventListener( 'click', onCancelClick );
			overlay.removeEventListener( 'click', onCancelClick );
			document.removeEventListener( 'keydown', onKeydown );
		}

		function onConfirmClick() {
			cleanup();
			onConfirm();
		}

		function onCancelClick() {
			cleanup();
		}

		function onKeydown( event ) {
			if ( 'Escape' === event.key ) {
				cleanup();
			}
		}

		confirmBtn.addEventListener( 'click', onConfirmClick );
		cancelBtn.addEventListener( 'click', onCancelClick );
		overlay.addEventListener( 'click', onCancelClick );
		document.addEventListener( 'keydown', onKeydown );

		modal.hidden = false;
		void modal.offsetWidth; // Flush the unhide before adding the class, so the open transition actually plays.
		modal.classList.add( 'is-open' );
		confirmBtn.focus();
	}

	// A single reusable toast (see `EntriesPage::render_toast()`) for bulk-action
	// success/error feedback — replaces `window.alert()`, and gives the
	// in-place ("no reload") bulk-action flow some visible confirmation
	// instead of nothing happening at all.
	var toastHideTimer = null;

	function showToast( message, type ) {
		var toast = document.querySelector( '[data-iftp-toast]' );

		if ( ! toast ) {
			return;
		}

		window.clearTimeout( toastHideTimer );

		toast.querySelector( '[data-iftp-toast-message]' ).textContent = message;
		toast.classList.remove( 'iftp-nf-toast--success', 'iftp-nf-toast--error' );
		toast.classList.add( 'iftp-nf-toast--' + ( 'error' === type ? 'error' : 'success' ) );
		toast.hidden = false;

		window.requestAnimationFrame( function () {
			toast.classList.add( 'is-visible' );
		} );

		toastHideTimer = window.setTimeout( function () {
			toast.classList.remove( 'is-visible' );

			window.setTimeout( function () {
				toast.hidden = true;
			}, 250 );
		}, 3200 );
	}

	function buildChevronSvg( className ) {
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'class', className );
		svg.setAttribute( 'width', '10' );
		svg.setAttribute( 'height', '6' );
		svg.setAttribute( 'viewBox', '0 0 10 6' );
		svg.setAttribute( 'aria-hidden', 'true' );

		var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		path.setAttribute( 'd', 'M1 1l4 4 4-4' );
		path.setAttribute( 'fill', 'none' );
		path.setAttribute( 'stroke', 'currentColor' );
		path.setAttribute( 'stroke-width', '1.6' );
		path.setAttribute( 'stroke-linecap', 'round' );
		path.setAttribute( 'stroke-linejoin', 'round' );
		svg.appendChild( path );

		return svg;
	}

	// Shared portal-dropdown behaviour behind every custom-built menu on this
	// screen (the bulk "Actions" menu, and the "All forms"/"per page" fake
	// selects below) — appended to <body> and positioned `fixed` so none of
	// them are clipped by `.iftp-nf-entries-card`'s `overflow: hidden`
	// (needed elsewhere for its rounded corners), the same technique the
	// ifthenpay-payments-for-contactform7 plugin uses for its own dropdowns.
	//
	// Position is computed exactly once, right when the menu opens — always
	// anchored to the bottom of whichever trigger it belongs to — and never
	// recalculated afterwards; earlier this instead re-measured on every
	// `scroll`/`resize` event to "follow" the trigger, which visibly
	// jittered (the JS-driven reposition lagging a frame behind the
	// browser's own instant repaint of `position: fixed` content during a
	// scroll). Simpler and steadier: leave it exactly where it opened, and
	// just close it if the page scrolls or resizes under it.
	function createPortalDropdown( trigger, wrapEl, buildItems ) {
		var menuEl = null;

		function position() {
			var rect = trigger.getBoundingClientRect();
			var menuWidth = menuEl.offsetWidth;
			var menuHeight = menuEl.offsetHeight;
			var spaceBelow = window.innerHeight - rect.bottom;
			// Opens below the trigger by default; flips above it when there
			// isn't room below (e.g. the bulk bar sitting near the bottom of
			// the viewport) but there's more room above than below — otherwise
			// most of the menu would render off-screen under the fold.
			var openUpward = spaceBelow < menuHeight + 6 && rect.top > spaceBelow;

			menuEl.classList.toggle( 'iftp-nf-dropdown-menu--upward', openUpward );
			menuEl.style.top = openUpward
				? Math.max( 8, rect.top - menuHeight - 6 ) + 'px'
				: ( rect.bottom + 6 ) + 'px';
			menuEl.style.left = Math.max( 8, Math.min( rect.right - menuWidth, window.innerWidth - menuWidth - 8 ) ) + 'px';
		}

		function close() {
			if ( ! menuEl ) {
				return;
			}

			menuEl.remove();
			menuEl = null;
			wrapEl.classList.remove( 'is-open' );
			trigger.setAttribute( 'aria-expanded', 'false' );
			document.removeEventListener( 'click', onDocumentClick, true );
			document.removeEventListener( 'keydown', onDocumentKeydown, true );
			window.removeEventListener( 'scroll', close, true );
			window.removeEventListener( 'resize', close );
		}

		function onDocumentClick( event ) {
			if ( menuEl && ! menuEl.contains( event.target ) && ! trigger.contains( event.target ) ) {
				close();
			}
		}

		function onDocumentKeydown( event ) {
			if ( 'Escape' === event.key ) {
				close();
				trigger.focus();
			}
		}

		function open() {
			menuEl = document.createElement( 'div' );
			menuEl.className = 'iftp-nf-dropdown-menu';
			menuEl.setAttribute( 'role', 'listbox' );

			buildItems( menuEl, close );

			document.body.appendChild( menuEl );
			position();

			wrapEl.classList.add( 'is-open' );
			trigger.setAttribute( 'aria-expanded', 'true' );

			document.addEventListener( 'click', onDocumentClick, true );
			document.addEventListener( 'keydown', onDocumentKeydown, true );
			window.addEventListener( 'scroll', close, true );
			window.addEventListener( 'resize', close );
		}

		return {
			toggle: function () {
				if ( menuEl ) {
					close();
				} else {
					open();
				}
			},
			close: close,
			isOpen: function () {
				return !! menuEl;
			}
		};
	}

	// Replaces the native "All forms" and "entries per page" <select>s with
	// the same custom-dropdown look as the bulk "Actions" menu — the real
	// <select> is kept (just visually hidden) so the surrounding <form>
	// still submits it exactly as before, and picking a fake option sets its
	// value and fires a real `change` event, so `bindPerPagePreference()`
	// below (which listens for that on the per-page select) keeps working
	// unmodified.
	function enhanceSelect( selectEl ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'iftp-nf-fake-select';

		var trigger = document.createElement( 'button' );
		trigger.type = 'button';
		trigger.className = 'iftp-nf-fake-select-trigger';
		trigger.setAttribute( 'aria-haspopup', 'listbox' );
		trigger.setAttribute( 'aria-expanded', 'false' );

		var labelEl = document.createElement( 'span' );
		labelEl.className = 'iftp-nf-fake-select-label';
		trigger.appendChild( labelEl );
		trigger.appendChild( buildChevronSvg( 'iftp-nf-fake-select-arrow' ) );

		selectEl.parentNode.insertBefore( wrap, selectEl );
		wrap.appendChild( trigger );
		wrap.appendChild( selectEl );
		selectEl.hidden = true;

		function syncLabel() {
			var option = selectEl.options[ selectEl.selectedIndex ];
			labelEl.textContent = option ? option.textContent : '';
		}

		var dropdown = createPortalDropdown( trigger, wrap, function ( menuEl, close ) {
			Array.prototype.forEach.call( selectEl.options, function ( option ) {
				var item = document.createElement( 'button' );
				item.type = 'button';
				item.setAttribute( 'role', 'option' );
				item.className = 'iftp-nf-dropdown-item' + ( option.selected ? ' is-active' : '' );

				var label = document.createElement( 'span' );
				label.textContent = option.textContent;
				item.appendChild( label );

				item.addEventListener( 'click', function () {
					close();

					if ( selectEl.value !== option.value ) {
						selectEl.value = option.value;
						selectEl.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
					}

					syncLabel();
				} );

				menuEl.appendChild( item );
			} );
		} );

		trigger.addEventListener( 'click', dropdown.toggle );

		syncLabel();
	}

	function bindCustomSelects() {
		document.querySelectorAll( '[data-iftp-enhance-select]' ).forEach( enhanceSelect );
	}

	// The static glyph shown in place of a drag handle for a position-locked
	// column in the "Columns" menu (see `bindColumnsControl()`) — signals
	// there's nothing to grab there, rather than just leaving empty space.
	function buildLockIconSvg() {
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'width', '12' );
		svg.setAttribute( 'height', '12' );
		svg.setAttribute( 'viewBox', '0 0 16 16' );

		var body = document.createElementNS( 'http://www.w3.org/2000/svg', 'rect' );
		body.setAttribute( 'x', '3' );
		body.setAttribute( 'y', '7' );
		body.setAttribute( 'width', '10' );
		body.setAttribute( 'height', '7' );
		body.setAttribute( 'rx', '1.5' );
		body.setAttribute( 'fill', 'none' );
		body.setAttribute( 'stroke', 'currentColor' );
		body.setAttribute( 'stroke-width', '1.3' );
		svg.appendChild( body );

		var shackle = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		shackle.setAttribute( 'd', 'M5 7V5a3 3 0 0 1 6 0v2' );
		shackle.setAttribute( 'fill', 'none' );
		shackle.setAttribute( 'stroke', 'currentColor' );
		shackle.setAttribute( 'stroke-width', '1.3' );
		shackle.setAttribute( 'stroke-linecap', 'round' );
		svg.appendChild( shackle );

		return svg;
	}

	function buildCalendarIconSvg() {
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'class', 'iftp-nf-date-icon' );
		svg.setAttribute( 'width', '14' );
		svg.setAttribute( 'height', '14' );
		svg.setAttribute( 'viewBox', '0 0 16 16' );
		svg.setAttribute( 'aria-hidden', 'true' );

		var rect = document.createElementNS( 'http://www.w3.org/2000/svg', 'rect' );
		rect.setAttribute( 'x', '1.5' );
		rect.setAttribute( 'y', '2.5' );
		rect.setAttribute( 'width', '13' );
		rect.setAttribute( 'height', '12' );
		rect.setAttribute( 'rx', '2' );
		rect.setAttribute( 'fill', 'none' );
		rect.setAttribute( 'stroke', 'currentColor' );
		rect.setAttribute( 'stroke-width', '1.3' );
		svg.appendChild( rect );

		var line = document.createElementNS( 'http://www.w3.org/2000/svg', 'line' );
		line.setAttribute( 'x1', '1.5' );
		line.setAttribute( 'y1', '6' );
		line.setAttribute( 'x2', '14.5' );
		line.setAttribute( 'y2', '6' );
		line.setAttribute( 'stroke', 'currentColor' );
		line.setAttribute( 'stroke-width', '1.3' );
		svg.appendChild( line );

		[ '4.5', '11.5' ].forEach( function ( x ) {
			var tick = document.createElementNS( 'http://www.w3.org/2000/svg', 'line' );
			tick.setAttribute( 'x1', x );
			tick.setAttribute( 'y1', '1' );
			tick.setAttribute( 'x2', x );
			tick.setAttribute( 'y2', '3.5' );
			tick.setAttribute( 'stroke', 'currentColor' );
			tick.setAttribute( 'stroke-width', '1.3' );
			tick.setAttribute( 'stroke-linecap', 'round' );
			svg.appendChild( tick );
		} );

		return svg;
	}

	// `YYYY-MM-DD` (the exact shape `input[type="date"]` stores/submits, and
	// what `EntriesPage::sanitize_date()` expects back) parsed as local
	// calendar-date components rather than `new Date(value)` — the native
	// parse treats that string as UTC midnight, which silently shifts a day
	// in any negative-offset timezone once read back via local getters.
	function parseIsoDate( value ) {
		var parts = ( value || '' ).split( '-' );

		if ( 3 !== parts.length ) {
			return null;
		}

		var date = new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) );

		return isNaN( date.getTime() ) ? null : date;
	}

	function formatIsoDate( date ) {
		var month = String( date.getMonth() + 1 ).padStart( 2, '0' );
		var day = String( date.getDate() ).padStart( 2, '0' );

		return date.getFullYear() + '-' + month + '-' + day;
	}

	function isSameDate( a, b ) {
		return !! a && !! b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
	}

	// Replaces the native `input[type="date"]`'s own browser/OS calendar
	// popup with a custom-built one matching this screen's own dropdown look
	// (see `createPortalDropdown()`) instead of looking like a foreign
	// widget dropped into an otherwise fully custom toolbar. The real input
	// is kept (just visually hidden) so the surrounding filters `<form>`
	// still submits a plain `date_from`/`date_to` value exactly as before.
	function enhanceDateInput( inputEl ) {
		var i18n = ( window.iftpNfEntries && window.iftpNfEntries.i18n ) || {};
		var wrap = document.createElement( 'div' );
		wrap.className = 'iftp-nf-date-field';

		var trigger = document.createElement( 'button' );
		trigger.type = 'button';
		trigger.className = 'iftp-nf-date-trigger';
		trigger.setAttribute( 'aria-haspopup', 'dialog' );
		trigger.setAttribute( 'aria-expanded', 'false' );

		var labelEl = document.createElement( 'span' );
		labelEl.className = 'iftp-nf-date-label';

		trigger.appendChild( buildCalendarIconSvg() );
		trigger.appendChild( labelEl );

		inputEl.parentNode.insertBefore( wrap, inputEl );
		wrap.appendChild( trigger );
		wrap.appendChild( inputEl );
		inputEl.hidden = true;

		var monthFormatter = ( 'undefined' !== typeof window.Intl )
			? new window.Intl.DateTimeFormat( undefined, { month: 'long', year: 'numeric' } )
			: null;
		var dayFormatter = ( 'undefined' !== typeof window.Intl )
			? new window.Intl.DateTimeFormat( undefined, { day: '2-digit', month: 'short', year: 'numeric' } )
			: null;
		var weekdayFormatter = ( 'undefined' !== typeof window.Intl )
			? new window.Intl.DateTimeFormat( undefined, { weekday: 'narrow' } )
			: null;

		function syncLabel() {
			var selected = parseIsoDate( inputEl.value );

			if ( ! selected ) {
				labelEl.textContent = inputEl.getAttribute( 'data-placeholder' ) || '';
				labelEl.classList.add( 'is-placeholder' );
				return;
			}

			labelEl.classList.remove( 'is-placeholder' );
			labelEl.textContent = dayFormatter ? dayFormatter.format( selected ) : inputEl.value;
		}

		function setValue( date ) {
			inputEl.value = date ? formatIsoDate( date ) : '';
			inputEl.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
			syncLabel();
		}

		var dropdown = createPortalDropdown( trigger, wrap, function ( menuEl, close ) {
			menuEl.classList.add( 'iftp-nf-calendar-menu' );
			menuEl.setAttribute( 'role', 'dialog' );

			var selected = parseIsoDate( inputEl.value );
			var today = new Date();
			var viewDate = selected
				? new Date( selected.getFullYear(), selected.getMonth(), 1 )
				: new Date( today.getFullYear(), today.getMonth(), 1 );

			function render() {
				menuEl.innerHTML = '';

				var header = document.createElement( 'div' );
				header.className = 'iftp-nf-calendar-header';

				var prevBtn = document.createElement( 'button' );
				prevBtn.type = 'button';
				prevBtn.className = 'iftp-nf-calendar-nav';
				prevBtn.setAttribute( 'aria-label', i18n.calendarPrevMonth || 'Previous month' );
				var prevArrow = buildChevronSvg( 'iftp-nf-calendar-nav-arrow' );
				prevArrow.style.transform = 'rotate(90deg)';
				prevBtn.appendChild( prevArrow );

				var monthLabel = document.createElement( 'span' );
				monthLabel.className = 'iftp-nf-calendar-month-label';
				monthLabel.textContent = monthFormatter ? monthFormatter.format( viewDate ) : ( viewDate.getMonth() + 1 ) + '/' + viewDate.getFullYear();

				var nextBtn = document.createElement( 'button' );
				nextBtn.type = 'button';
				nextBtn.className = 'iftp-nf-calendar-nav';
				nextBtn.setAttribute( 'aria-label', i18n.calendarNextMonth || 'Next month' );
				var nextArrow = buildChevronSvg( 'iftp-nf-calendar-nav-arrow' );
				nextArrow.style.transform = 'rotate(-90deg)';
				nextBtn.appendChild( nextArrow );

				header.appendChild( prevBtn );
				header.appendChild( monthLabel );
				header.appendChild( nextBtn );
				menuEl.appendChild( header );

				var weekdaysRow = document.createElement( 'div' );
				weekdaysRow.className = 'iftp-nf-calendar-weekdays';

				var weekStart = new Date( 2023, 0, 1 ); // A known Sunday, just to walk weekday labels in order.

				for ( var w = 0; w < 7; w++ ) {
					var weekdayCell = document.createElement( 'span' );
					weekdayCell.className = 'iftp-nf-calendar-weekday';
					var walking = new Date( weekStart );
					walking.setDate( weekStart.getDate() + w );
					weekdayCell.textContent = weekdayFormatter ? weekdayFormatter.format( walking ) : walking.toDateString().slice( 0, 1 );
					weekdaysRow.appendChild( weekdayCell );
				}

				menuEl.appendChild( weekdaysRow );

				var grid = document.createElement( 'div' );
				grid.className = 'iftp-nf-calendar-grid';

				var firstOfMonth = new Date( viewDate.getFullYear(), viewDate.getMonth(), 1 );
				var gridStart = new Date( firstOfMonth );
				gridStart.setDate( firstOfMonth.getDate() - firstOfMonth.getDay() );

				for ( var i = 0; i < 42; i++ ) {
					var cellDate = new Date( gridStart );
					cellDate.setDate( gridStart.getDate() + i );

					var dayBtn = document.createElement( 'button' );
					dayBtn.type = 'button';
					dayBtn.className = 'iftp-nf-calendar-day';
					dayBtn.textContent = String( cellDate.getDate() );

					if ( cellDate.getMonth() !== viewDate.getMonth() ) {
						dayBtn.classList.add( 'is-outside' );
					}

					if ( isSameDate( cellDate, today ) ) {
						dayBtn.classList.add( 'is-today' );
					}

					if ( isSameDate( cellDate, selected ) ) {
						dayBtn.classList.add( 'is-selected' );
					}

					( function ( pickedDate ) {
						dayBtn.addEventListener( 'click', function () {
							selected = pickedDate;
							setValue( pickedDate );
							close();
						} );
					} )( cellDate );

					grid.appendChild( dayBtn );
				}

				menuEl.appendChild( grid );

				var footer = document.createElement( 'div' );
				footer.className = 'iftp-nf-calendar-footer';

				var todayBtn = document.createElement( 'button' );
				todayBtn.type = 'button';
				todayBtn.className = 'iftp-nf-dropdown-footer-btn';
				todayBtn.textContent = i18n.calendarToday || 'Today';
				todayBtn.addEventListener( 'click', function () {
					selected = today;
					setValue( today );
					close();
				} );

				var clearBtn = document.createElement( 'button' );
				clearBtn.type = 'button';
				clearBtn.className = 'iftp-nf-dropdown-footer-btn iftp-nf-dropdown-footer-btn--clear';
				clearBtn.textContent = i18n.calendarClear || 'Clear';
				clearBtn.addEventListener( 'click', function () {
					selected = null;
					setValue( null );
					close();
				} );

				footer.appendChild( todayBtn );
				footer.appendChild( clearBtn );
				menuEl.appendChild( footer );

				prevBtn.addEventListener( 'click', function () {
					viewDate = new Date( viewDate.getFullYear(), viewDate.getMonth() - 1, 1 );
					render();
				} );

				nextBtn.addEventListener( 'click', function () {
					viewDate = new Date( viewDate.getFullYear(), viewDate.getMonth() + 1, 1 );
					render();
				} );
			}

			render();
		} );

		trigger.addEventListener( 'click', dropdown.toggle );

		syncLabel();
	}

	function bindCustomDateInputs() {
		document.querySelectorAll( '[data-iftp-enhance-date]' ).forEach( enhanceDateInput );
	}

	// "Columns" control: lets the admin reorder the table's columns (drag
	// handle) and hide the ones they don't care about (checkbox), persisted
	// to `localStorage` (not `sessionStorage` — this is meant to stick
	// around indefinitely on this browser, like the "entries per page"
	// preference above) so the chosen layout survives reloads and future
	// visits alike. Every page load re-renders the table from scratch on
	// the server (see `EntriesPage::render()`) with the full default column
	// set and order, so this always re-applies the saved layout client-side
	// afterwards rather than the server needing to know about it at all —
	// `EntriesPage::organizable_columns()` is only the source of the
	// available keys/labels and their default order.
	var COLUMNS_STORAGE_KEY = 'iftpNfEntriesColumns';

	function loadColumnLayout( defaultOrder ) {
		var layout = null;

		try {
			var raw = window.localStorage.getItem( COLUMNS_STORAGE_KEY );
			layout = raw ? JSON.parse( raw ) : null;
		} catch ( e ) {
			layout = null;
		}

		var savedOrder = ( layout && Array.isArray( layout.order ) ) ? layout.order : [];
		var hidden = ( layout && Array.isArray( layout.hidden ) ) ? layout.hidden : [];

		// Anything saved that no longer exists (a column removed in a plugin
		// update) is dropped, and anything new that isn't in the saved order
		// yet (a column added in a plugin update) is appended at the end —
		// keeps a stale saved layout from ever hiding a column entirely by
		// omission.
		var order = savedOrder.filter( function ( key ) {
			return -1 !== defaultOrder.indexOf( key );
		} );

		defaultOrder.forEach( function ( key ) {
			if ( -1 === order.indexOf( key ) ) {
				order.push( key );
			}
		} );

		hidden = hidden.filter( function ( key ) {
			return -1 !== defaultOrder.indexOf( key );
		} );

		return { order: order, hidden: hidden };
	}

	function persistColumnLayout( layout ) {
		try {
			window.localStorage.setItem( COLUMNS_STORAGE_KEY, JSON.stringify( layout ) );
		} catch ( e ) {
			// Private browsing / storage disabled — the chosen layout just won't persist.
		}
	}

	function bindColumnsControl() {
		var control = document.querySelector( '[data-iftp-columns]' );
		var trigger = document.querySelector( '[data-iftp-columns-trigger]' );
		var table = document.querySelector( '.iftp-nf-entries-table' );

		if ( ! control || ! trigger || ! table || 'undefined' === typeof window.iftpNfEntries ) {
			return;
		}

		var settings = window.iftpNfEntries;
		var columns = settings.columns || [];
		var i18n = settings.i18n || {};
		var defaultOrder = columns.map( function ( column ) {
			return column.key;
		} );
		var columnsByKey = {};

		columns.forEach( function ( column ) {
			columnsByKey[ column.key ] = column;
		} );

		// The keys locked to the front of the order (ID, Customer), in their
		// fixed relative sequence — derived from `defaultOrder` rather than
		// hardcoded, so `EntriesPage::organizable_columns()` stays the one
		// source of truth for which columns are locked.
		var lockedOrderKeys = defaultOrder.filter( function ( key ) {
			return columnsByKey[ key ].positionLocked;
		} );

		// Pins every `positionLocked` column back to its fixed front slot and
		// drops any hidden entry for a `visibilityLocked` column — run right
		// after loading (to self-heal a layout saved before these locks
		// existed) and after every mutation, so drag/keyboard reordering and
		// the checkbox handler below don't have to defend against ending up
		// in an invalid state themselves.
		function normalizeLayout( layout ) {
			var rest = layout.order.filter( function ( key ) {
				return -1 === lockedOrderKeys.indexOf( key );
			} );

			layout.order = lockedOrderKeys.concat( rest );
			layout.hidden = layout.hidden.filter( function ( key ) {
				return columnsByKey[ key ] && ! columnsByKey[ key ].visibilityLocked;
			} );

			return layout;
		}

		var headRow = table.querySelector( 'thead tr' );

		function cellsForColumn( key ) {
			return Array.prototype.slice.call( table.querySelectorAll( '[data-col="' + key + '"]' ) );
		}

		// Reorders every `<th>`/`<td>` sharing a `data-col` to match `order`
		// (run once per row, and once for the header row), and toggles
		// visibility for anything in `hidden`. Column count never changes,
		// so the `colspan="10"` on the empty-state/details rows keeps
		// spanning every visible column with no adjustment needed here.
		function applyLayout( layout ) {
			var rows = [ headRow ].concat( Array.prototype.slice.call( table.querySelectorAll( 'tbody tr.iftp-nf-entry-row' ) ) );

			rows.forEach( function ( row ) {
				if ( ! row ) {
					return;
				}

				layout.order.forEach( function ( key ) {
					var cell = row.querySelector( '[data-col="' + key + '"]' );

					if ( cell ) {
						row.appendChild( cell );
					}
				} );
			} );

			columns.forEach( function ( column ) {
				var isHidden = -1 !== layout.hidden.indexOf( column.key );

				cellsForColumn( column.key ).forEach( function ( cell ) {
					cell.classList.toggle( 'iftp-nf-col-hidden', isHidden );
				} );
			} );
		}

		var layout = normalizeLayout( loadColumnLayout( defaultOrder ) );
		applyLayout( layout );

		reapplyColumnLayout = function () {
			applyLayout( layout );
		};

		var draggingKey = null;

		var dropdown = createPortalDropdown( trigger, control, function ( menuEl, close ) {
			menuEl.classList.add( 'iftp-nf-columns-menu' );
			menuEl.setAttribute( 'role', 'dialog' );

			var list = document.createElement( 'div' );
			list.className = 'iftp-nf-columns-list';
			menuEl.appendChild( list );

			// The keyboard-driven equivalent of a drag-and-drop reorder (see
			// the drag handle's `keydown` listener below) — moves `key` one
			// spot in `direction` (-1 up, 1 down), then re-renders the list
			// and refocuses the same handle so repeated Arrow presses keep
			// working without the user's focus getting lost.
			function moveColumn( key, direction ) {
				var index = layout.order.indexOf( key );
				var target = index + direction;

				if ( -1 === index || target < 0 || target >= layout.order.length ) {
					return;
				}

				// The target slot itself is fine to land on unless it's
				// currently one of the locked columns pinned to the front —
				// `normalizeLayout()` always keeps those at indexes
				// `[0, lockedOrderKeys.length)`, so this is the only extra
				// check needed to stop a movable column from swapping past
				// that boundary (dragging past it is separately refused by
				// the `dragover`/`drop` handlers below, since a locked row
				// is never a valid drop target).
				if ( columnsByKey[ layout.order[ target ] ].positionLocked ) {
					return;
				}

				layout.order.splice( index, 1 );
				layout.order.splice( target, 0, key );

				persistColumnLayout( layout );
				applyLayout( layout );
				renderList();

				var movedHandle = list.querySelector( '[data-col-key="' + key + '"] .iftp-nf-column-drag-handle' );

				if ( movedHandle ) {
					movedHandle.focus();
				}
			}

			function renderList() {
				list.innerHTML = '';

				layout.order.forEach( function ( key ) {
					var col = columnsByKey[ key ];

					if ( ! col ) {
						return;
					}

					var label = col.label;
					var isHidden = -1 !== layout.hidden.indexOf( key );

					var item = document.createElement( 'div' );
					item.className = 'iftp-nf-column-item' + ( isHidden ? ' is-hidden' : '' );
					item.setAttribute( 'data-col-key', key );

					// Position-locked columns (ID, Customer, at Victor's
					// request) get no drag handle at all — native drag-and-drop
					// starts anywhere on a `draggable` element, so `draggable`
					// itself has to stay off too, or the row could still be
					// picked up despite having nothing to grab it by.
					// `data-locked-position` is what the `dragover`/`drop`
					// handlers below check to also refuse it as a drop
					// *target* — otherwise a movable column could still be
					// dropped onto/past it even though it can't be dragged
					// itself.
					if ( col.positionLocked ) {
						item.draggable = false;
						item.setAttribute( 'data-locked-position', 'true' );

						var lockIcon = document.createElement( 'span' );
						lockIcon.className = 'iftp-nf-column-lock-icon';
						lockIcon.setAttribute( 'aria-hidden', 'true' );
						lockIcon.title = ( i18n.columnsLockedPosition || '%s always stays in place' ).replace( '%s', label );
						lockIcon.appendChild( buildLockIconSvg() );
						item.appendChild( lockIcon );
					} else {
						item.draggable = true;

						// A real, focusable button rather than just a
						// decorative drag handle — native HTML5 drag-and-drop
						// (below) has no keyboard equivalent at all, so
						// without this the reorder half of this control would
						// be entirely mouse-only. ArrowUp/ArrowDown move the
						// column while this is focused.
						var handleLabel = ( i18n.columnsDragLabel || 'Drag to reorder %s' ).replace( '%s', label );
						var handle = document.createElement( 'button' );
						handle.type = 'button';
						handle.className = 'iftp-nf-column-drag-handle';
						handle.setAttribute( 'aria-label', handleLabel );
						handle.title = handleLabel;
						handle.textContent = '⋮⋮';

						handle.addEventListener( 'keydown', function ( event ) {
							if ( 'ArrowUp' === event.key ) {
								event.preventDefault();
								moveColumn( key, -1 );
							} else if ( 'ArrowDown' === event.key ) {
								event.preventDefault();
								moveColumn( key, 1 );
							}
						} );

						item.appendChild( handle );
					}

					var toggleLabel = document.createElement( 'label' );
					toggleLabel.className = 'iftp-nf-column-toggle';

					var checkbox = document.createElement( 'input' );
					checkbox.type = 'checkbox';

					if ( col.visibilityLocked ) {
						// Always shown, not just defaulted to checked — ID,
						// Customer, Amount and Status stay visible because
						// between them ID/Amount are the table's only two
						// ways to open a row's details, and Customer/Status
						// are what an admin scans the table for at a glance.
						checkbox.checked = true;
						checkbox.disabled = true;
						toggleLabel.classList.add( 'iftp-nf-column-toggle--locked' );
						checkbox.setAttribute( 'aria-label', ( i18n.columnsLockedVisibility || '%s is always shown' ).replace( '%s', label ) );
					} else {
						checkbox.checked = ! isHidden;
						checkbox.setAttribute( 'aria-label', ( i18n.columnsToggleLabel || 'Show or hide %s' ).replace( '%s', label ) );

						checkbox.addEventListener( 'change', function () {
							if ( checkbox.checked ) {
								layout.hidden = layout.hidden.filter( function ( hiddenKey ) {
									return hiddenKey !== key;
								} );
							} else {
								layout.hidden.push( key );
							}

							item.classList.toggle( 'is-hidden', ! checkbox.checked );
							persistColumnLayout( layout );
							applyLayout( layout );
						} );
					}

					var labelText = document.createElement( 'span' );
					labelText.textContent = label;

					toggleLabel.appendChild( checkbox );
					toggleLabel.appendChild( labelText );

					item.appendChild( toggleLabel );
					list.appendChild( item );
				} );
			}

			renderList();

			list.addEventListener( 'dragstart', function ( event ) {
				var item = event.target.closest( '.iftp-nf-column-item' );

				if ( ! item ) {
					return;
				}

				draggingKey = item.getAttribute( 'data-col-key' );
				item.classList.add( 'is-dragging' );
				event.dataTransfer.effectAllowed = 'move';
			} );

			list.addEventListener( 'dragend', function ( event ) {
				var item = event.target.closest( '.iftp-nf-column-item' );

				if ( item ) {
					item.classList.remove( 'is-dragging' );
				}

				draggingKey = null;

				Array.prototype.forEach.call( list.querySelectorAll( '.is-drop-target' ), function ( el ) {
					el.classList.remove( 'is-drop-target' );
				} );
			} );

			list.addEventListener( 'dragover', function ( event ) {
				var item = event.target.closest( '.iftp-nf-column-item' );

				// A locked row is never a valid drop target — without this,
				// a movable column could still be dropped onto/past ID or
				// Customer even though neither of those can be dragged
				// itself (see `renderList()`), displacing them anyway.
				if ( ! item || ! draggingKey || item.getAttribute( 'data-col-key' ) === draggingKey || item.hasAttribute( 'data-locked-position' ) ) {
					return;
				}

				event.preventDefault();
				event.dataTransfer.dropEffect = 'move';

				Array.prototype.forEach.call( list.querySelectorAll( '.is-drop-target' ), function ( el ) {
					el.classList.remove( 'is-drop-target' );
				} );

				item.classList.add( 'is-drop-target' );
			} );

			list.addEventListener( 'drop', function ( event ) {
				var item = event.target.closest( '.iftp-nf-column-item' );

				if ( ! item || ! draggingKey || item.hasAttribute( 'data-locked-position' ) ) {
					return;
				}

				event.preventDefault();

				var targetKey = item.getAttribute( 'data-col-key' );

				if ( targetKey === draggingKey ) {
					return;
				}

				var rect = item.getBoundingClientRect();
				var insertAfter = event.clientY > rect.top + ( rect.height / 2 );

				layout.order = layout.order.filter( function ( key ) {
					return key !== draggingKey;
				} );

				var targetIndex = layout.order.indexOf( targetKey );
				layout.order.splice( insertAfter ? targetIndex + 1 : targetIndex, 0, draggingKey );

				persistColumnLayout( layout );
				applyLayout( layout );
				renderList();
			} );

			var footer = document.createElement( 'div' );
			footer.className = 'iftp-nf-columns-footer';

			var resetBtn = document.createElement( 'button' );
			resetBtn.type = 'button';
			resetBtn.className = 'iftp-nf-dropdown-footer-btn';
			resetBtn.textContent = i18n.columnsReset || 'Reset to default';
			resetBtn.addEventListener( 'click', function () {
				layout.order = defaultOrder.slice();
				layout.hidden = [];
				persistColumnLayout( layout );
				applyLayout( layout );
				renderList();
			} );

			footer.appendChild( resetBtn );
			menuEl.appendChild( footer );
		} );

		trigger.addEventListener( 'click', dropdown.toggle );
	}

	// Bulk select + actions. A checkbox per row plus a "select all" in the
	// header drive a bulk-actions bar (hidden until something is checked)
	// sitting below the table. Its "Actions" button opens a custom-built
	// dropdown menu — not a native <select> — portaled onto <body> and
	// positioned from the trigger's own bounding box so it's never clipped
	// by the card's `overflow: hidden` (needed for its rounded corners);
	// same technique the ifthenpay-payments-for-contactform7 plugin uses
	// for its own bulk-actions dropdown.
	//
	// Deleting only clears this plugin's own payment-tracking record for
	// each selected entry (see `SubmissionStore::delete()`) — never the
	// real Ninja Forms submission — and fades/collapses the row out
	// instead of a full page reload. A status change updates in place too:
	// the row's status badge crossfades to the new value, and — since a
	// status change can move an entry in or out of the currently filtered
	// status tab — a row that no longer matches the active tab fades/
	// collapses out exactly like a delete would. The server posts back
	// freshly recomputed status-tab counts and footer total (against the
	// same filtered/searched view, see `iftpNfEntries.currentFilters`), so
	// those patch in too, without ever reloading the page.
	// Selection persists across pagination (and across full page reloads,
	// since every "page" is a normal server-rendered navigation, not an
	// SPA route) via sessionStorage — select 5 rows on page 1, jump to
	// page 200, select 3 more, and a bulk action still applies to all 8.
	// sessionStorage (not localStorage) so the selection only lives for
	// this browser tab's working session, not indefinitely across visits.
	var SELECTION_STORAGE_KEY = 'iftpNfEntriesSelection';

	function loadSelection() {
		try {
			var raw = window.sessionStorage.getItem( SELECTION_STORAGE_KEY );
			var parsed = raw ? JSON.parse( raw ) : [];

			return new window.Set( Array.isArray( parsed ) ? parsed : [] );
		} catch ( e ) {
			return new window.Set();
		}
	}

	function persistSelection( selection ) {
		try {
			window.sessionStorage.setItem( SELECTION_STORAGE_KEY, JSON.stringify( Array.from( selection ) ) );
		} catch ( e ) {
			// Private browsing / storage disabled — selection just won't survive a page change.
		}
	}

	function bindBulkActions() {
		var body = document.querySelector( '[data-iftp-entries-body]' );
		var selectAll = document.querySelector( '[data-iftp-select-all]' );
		var bulkBar = document.querySelector( '[data-iftp-bulk-bar]' );
		var bulkCount = document.querySelector( '[data-iftp-bulk-count]' );
		var bulkCancel = document.querySelector( '[data-iftp-bulk-cancel]' );
		var bulkActionsWrap = document.querySelector( '[data-iftp-bulk-actions]' );
		var bulkTrigger = document.querySelector( '[data-iftp-bulk-trigger]' );

		if ( ! body || ! selectAll || ! bulkBar || ! bulkCount || ! bulkCancel || ! bulkActionsWrap || ! bulkTrigger || 'undefined' === typeof window.iftpNfEntries ) {
			return;
		}

		var settings = window.iftpNfEntries;
		var ROW_REMOVE_DURATION = 220;
		var BULK_BAR_TRANSITION_DURATION = 180;
		var bulkBarHideTimer = null;
		var busy = false;
		var selection = loadSelection();

		var dropdown = createPortalDropdown( bulkTrigger, bulkActionsWrap, function ( menuEl, close ) {
			( settings.bulkActions || [] ).forEach( function ( action ) {
				if ( action.danger ) {
					var divider = document.createElement( 'div' );
					divider.className = 'iftp-nf-dropdown-divider';
					menuEl.appendChild( divider );
				}

				var item = document.createElement( 'button' );
				item.type = 'button';
				item.setAttribute( 'role', 'option' );
				item.className = 'iftp-nf-dropdown-item' + ( action.danger ? ' iftp-nf-dropdown-item--danger' : '' );

				if ( action.dot ) {
					var dot = document.createElement( 'span' );
					dot.className = 'iftp-nf-dropdown-dot';
					dot.style.background = action.dot;
					item.appendChild( dot );
				}

				var label = document.createElement( 'span' );
				label.textContent = action.label;
				item.appendChild( label );

				item.addEventListener( 'click', function () {
					close();
					runAction( action );
				} );

				menuEl.appendChild( item );
			} );
		} );

		function rowCheckboxes() {
			return Array.prototype.slice.call( body.querySelectorAll( '[data-iftp-row-check]' ) );
		}

		// Only the refs also present in the current page's DOM — used to
		// drive visible UI (row removal, badge swaps); the rest of a bulk
		// action's targets simply aren't rendered on this page.
		function checkboxesForRefs( refs ) {
			var refSet = new window.Set( refs );

			return rowCheckboxes().filter( function ( box ) {
				return refSet.has( box.value );
			} );
		}

		function syncCheckboxesFromSelection() {
			rowCheckboxes().forEach( function ( box ) {
				box.checked = selection.has( box.value );
			} );
		}

		function refreshBulkBar() {
			var all = rowCheckboxes();
			var visibleSelected = all.filter( function ( box ) {
				return selection.has( box.value );
			} );

			window.clearTimeout( bulkBarHideTimer );

			if ( selection.size > 0 ) {
				bulkBar.hidden = false;
				window.requestAnimationFrame( function () {
					bulkBar.classList.add( 'is-visible' );
				} );
			} else {
				bulkBar.classList.remove( 'is-visible' );
				dropdown.close();
				bulkBarHideTimer = window.setTimeout( function () {
					bulkBar.hidden = true;
				}, BULK_BAR_TRANSITION_DURATION );
			}

			bulkCount.textContent = selection.size + ' ' + ( 1 === selection.size ? settings.i18n.selectedOne : settings.i18n.selectedMany );

			selectAll.checked = all.length > 0 && visibleSelected.length === all.length;
			selectAll.indeterminate = visibleSelected.length > 0 && visibleSelected.length < all.length;
		}

		// "Select all" only ever covers the rows on the current page — the
		// persisted selection can span many pages, and there's no
		// affordance here (nor did the ask call for one) to select every
		// entry across the whole filtered result set at once.
		selectAll.addEventListener( 'change', function () {
			rowCheckboxes().forEach( function ( box ) {
				if ( selectAll.checked ) {
					selection.add( box.value );
				} else {
					selection.delete( box.value );
				}

				box.checked = selectAll.checked;
			} );

			persistSelection( selection );
			refreshBulkBar();
		} );

		body.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target.matches( '[data-iftp-row-check]' ) ) {
				if ( event.target.checked ) {
					selection.add( event.target.value );
				} else {
					selection.delete( event.target.value );
				}

				persistSelection( selection );
				refreshBulkBar();
			}
		} );

		bulkCancel.addEventListener( 'click', function () {
			selection.clear();
			persistSelection( selection );
			syncCheckboxesFromSelection();
			refreshBulkBar();
		} );

		function updateRowStatusBadge( row, status ) {
			var badge = row.querySelector( '.iftp-nf-status-badge' );

			if ( ! badge ) {
				return;
			}

			// Crossfades to the new status instead of snapping: fade the old
			// value out, swap class/text while invisible, then fade in.
			badge.classList.add( 'is-swapping' );

			window.setTimeout( function () {
				badge.className = 'iftp-nf-status-badge iftp-nf-status-badge--' + status + ' is-swapping';
				badge.textContent = status.charAt( 0 ).toUpperCase() + status.slice( 1 );

				window.requestAnimationFrame( function () {
					badge.classList.remove( 'is-swapping' );
				} );
			}, 140 );
		}

		function updateTabCounts( counts ) {
			Object.keys( counts ).forEach( function ( slug ) {
				var el = document.querySelector( '[data-iftp-count="' + ( '' === slug ? '_all' : slug ) + '"]' );

				if ( ! el ) {
					return;
				}

				el.textContent = counts[ slug ];
				el.classList.add( 'is-pulsing' );

				window.setTimeout( function () {
					el.classList.remove( 'is-pulsing' );
				}, 320 );
			} );
		}

		function updateTotalLabel( label ) {
			var el = document.querySelector( '[data-iftp-total-count]' );

			if ( el && label ) {
				el.textContent = label;
			}
		}

		function removeRows( checked, onDone ) {
			checked.forEach( function ( box ) {
				var row = box.closest( '.iftp-nf-entry-row' );

				if ( ! row ) {
					return;
				}

				var toggle = row.querySelector( '[data-details-target]' );
				var detailsRow = toggle ? document.getElementById( toggle.getAttribute( 'data-details-target' ) ) : null;

				row.classList.add( 'is-removing' );

				window.setTimeout( function () {
					row.remove();

					if ( detailsRow ) {
						detailsRow.remove();
					}
				}, ROW_REMOVE_DURATION );
			} );

			window.setTimeout( onDone, ROW_REMOVE_DURATION + 20 );
		}

		// Once a bulk action has actually been applied to every selected
		// ref — including the ones sitting on other pages, never touched
		// here — the whole persisted selection is done with, so it's
		// cleared outright rather than just the current page's checkboxes.
		function clearSelectionAfterAction() {
			selection.clear();
			persistSelection( selection );
			refreshBulkBar();
		}

		// Swaps in the current page's rows/pagination/counts/total exactly
		// as `ajax_delete_entries()` just recomputed them — refilling the
		// page with whatever shifted up to replace the deleted rows (or, if
		// the current page no longer exists, showing the new last page's
		// rows instead — the server already clamped `paged` for that) —
		// instead of leaving the page short until the next navigation.
		function applyFreshEntries( data ) {
			if ( 'string' === typeof data.rowsHtml ) {
				body.innerHTML = data.rowsHtml;
			}

			var paginationWrap = document.querySelector( '[data-iftp-pagination]' );

			if ( paginationWrap && 'string' === typeof data.paginationHtml ) {
				paginationWrap.innerHTML = data.paginationHtml;
			}

			if ( data.counts ) {
				updateTabCounts( data.counts );
			}

			updateTotalLabel( data.totalLabel );

			bindDetailsToggles();
			reapplyColumnLayout();
		}

		function runDelete( refs ) {
			openConfirmModal( {
				title: settings.i18n.confirmDeleteTitle,
				message: settings.i18n.confirmDeleteMessage,
				confirmLabel: settings.i18n.confirmDeleteButton,
				danger: true
			}, function () {
				busy = true;
				bulkTrigger.disabled = true;

				var filters = settings.currentFilters || {};
				var formData = new window.FormData();
				formData.append( 'action', 'iftp_nf_delete_entries' );
				formData.append( 'nonce', settings.nonce );
				formData.append( 's', filters.s || '' );
				formData.append( 'view_status', filters.status || '' );
				formData.append( 'form_id', filters.form_id || 0 );
				formData.append( 'date_from', filters.date_from || '' );
				formData.append( 'date_to', filters.date_to || '' );
				formData.append( 'per_page', filters.per_page || '' );
				formData.append( 'paged', filters.paged || 1 );
				formData.append( 'orderby', filters.orderby || '' );
				formData.append( 'order', filters.order || '' );
				refs.forEach( function ( ref ) {
					formData.append( 'refs[]', ref );
				} );

				window.fetch( settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							throw new Error( 'iftp_nf_delete_entries failed' );
						}

						removeRows( checkboxesForRefs( refs ), function () {
							applyFreshEntries( json.data || {} );
							clearSelectionAfterAction();
						} );
						showToast( settings.i18n.deleteToastSuccess, 'success' );
					} )
					.catch( function () {
						showToast( settings.i18n.deleteError, 'error' );
					} )
					.finally( function () {
						busy = false;
						bulkTrigger.disabled = false;
					} );
			} );
		}

		function runStatusChange( action, refs ) {
			var statusLabel = action.value.charAt( 0 ).toUpperCase() + action.value.slice( 1 );

			openConfirmModal( {
				title: settings.i18n.confirmStatusTitle,
				message: settings.i18n.confirmStatusMessage.replace( '%s', statusLabel ),
				confirmLabel: settings.i18n.confirmStatusButton
			}, function () {
				busy = true;
				bulkTrigger.disabled = true;

				var filters = settings.currentFilters || {};
				var formData = new window.FormData();
				formData.append( 'action', 'iftp_nf_update_status' );
				formData.append( 'nonce', settings.nonce );
				formData.append( 'status', action.value );
				formData.append( 's', filters.s || '' );
				formData.append( 'view_status', filters.status || '' );
				formData.append( 'form_id', filters.form_id || 0 );
				formData.append( 'date_from', filters.date_from || '' );
				formData.append( 'date_to', filters.date_to || '' );
				refs.forEach( function ( ref ) {
					formData.append( 'refs[]', ref );
				} );

				window.fetch( settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							throw new Error( 'iftp_nf_update_status failed' );
						}

						var data = json.data || {};
						var visibleBoxes = checkboxesForRefs( refs );

						if ( data.matchesView ) {
							visibleBoxes.forEach( function ( box ) {
								var row = box.closest( '.iftp-nf-entry-row' );

								if ( row ) {
									updateRowStatusBadge( row, action.value );
								}
							} );

							clearSelectionAfterAction();
						} else {
							removeRows( visibleBoxes, clearSelectionAfterAction );
						}

						if ( data.counts ) {
							updateTabCounts( data.counts );
						}

						updateTotalLabel( data.totalLabel );
						showToast( settings.i18n.statusToastSuccess, 'success' );
					} )
					.catch( function () {
						showToast( settings.i18n.updateStatusError, 'error' );
					} )
					.finally( function () {
						busy = false;
						bulkTrigger.disabled = false;
					} );
			} );
		}

		function runAction( action ) {
			var refs = Array.from( selection );

			if ( busy || 0 === refs.length ) {
				return;
			}

			if ( 'delete' === action.value ) {
				runDelete( refs );
			} else {
				runStatusChange( action, refs );
			}
		}

		syncCheckboxesFromSelection();
		refreshBulkBar();

		bulkTrigger.addEventListener( 'click', dropdown.toggle );
	}

	// Remembers the last "entries per page" choice in localStorage so it
	// carries over the next time this screen is opened fresh (e.g. from the
	// admin menu, with no `per_page` in the URL yet) instead of always
	// resetting back to the 20-per-page default.
	var PER_PAGE_STORAGE_KEY = 'iftpNfEntriesPerPage';

	function bindPerPagePreference() {
		var select = document.getElementById( 'iftp-nf-per-page' );
		var form = select ? select.closest( 'form' ) : null;

		if ( ! select || ! form ) {
			return;
		}

		// `requestSubmit()` (unlike `.submit()`) fires a real `submit` event,
		// which `bindTableLoadingSpinner()` listens for — but it's absent on
		// pre-2022 Safari, so this falls back to a plain `.submit()` there
		// (which still submits the form, just without the spinner).
		function submitForm() {
			if ( form.requestSubmit ) {
				form.requestSubmit();
			} else {
				form.submit();
			}
		}

		var params = new window.URLSearchParams( window.location.search );

		if ( ! params.has( 'per_page' ) ) {
			var stored = null;

			try {
				stored = window.localStorage.getItem( PER_PAGE_STORAGE_KEY );
			} catch ( e ) {
				stored = null;
			}

			var hasOption = stored && Array.prototype.some.call( select.options, function ( option ) {
				return option.value === stored;
			} );

			if ( hasOption && stored !== select.value ) {
				select.value = stored;
				submitForm();
				return;
			}
		}

		select.addEventListener( 'change', function () {
			try {
				window.localStorage.setItem( PER_PAGE_STORAGE_KEY, select.value );
			} catch ( e ) {
				// Private browsing / storage disabled — the form below still submits normally.
			}

			submitForm();
		} );
	}

	// Filtering/sorting/paging on this screen is a real server-rendered
	// `GET` navigation, not an in-page fetch (see `EntriesPage::render()`),
	// so nothing here otherwise tells the admin their click did anything
	// until the new page finishes loading — on a slow request the table
	// just sits there looking identical to before. This covers it with a
	// spinner overlay (`EntriesPage::render()`'s `[data-iftp-table-loading]`)
	// the instant a filter/sort/page action is triggered. `form.submit()`
	// calls elsewhere (`bindPerPagePreference()`) are `requestSubmit()`
	// instead specifically so they also fire the `submit` event this relies
	// on — plain `.submit()` bypasses it entirely.
	function bindTableLoadingSpinner() {
		var overlay = document.querySelector( '[data-iftp-table-loading]' );

		if ( ! overlay ) {
			return;
		}

		function showLoading() {
			overlay.hidden = false;
		}

		document.querySelectorAll( '.iftp-nf-entries-filters, .iftp-nf-page-jump' ).forEach( function ( form ) {
			form.addEventListener( 'submit', showLoading );
		} );

		document.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest( '.iftp-nf-sortable, .iftp-nf-page-number, .iftp-nf-page-btn, .iftp-nf-reset-filters' );

			if ( ! trigger || trigger.classList.contains( 'is-current' ) || trigger.classList.contains( 'is-disabled' ) ) {
				return;
			}

			showLoading();
		} );

		// Restores the overlay to hidden if the browser serves this page back
		// out of the back/forward cache (bfcache) — otherwise a page left
		// mid-navigation and then returned to via the back button would show
		// the spinner stuck on indefinitely, over a table that's actually
		// already fully loaded.
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				overlay.hidden = true;
			}
		} );
	}

	// Peeking ninja mascot: each hover shakes him in place, then advances
	// one step of a 4-step cycle — right, home, right, then a full exit
	// off-screen to the left, with a smoke-bomb puff right as he vanishes.
	// Once he's off-screen, he sits hidden for 3s, then automatically (no
	// hover needed) a smoke cloud blooms in — a "grenade falls, cloud
	// rises and holds, then clears" beat — and right as it starts blooming
	// he's teleported back to his home spot, still hidden below the page
	// edge behind the cloud; the CSS `nf-shy-ninja-rise` animation then
	// plays him popping his head back up into view as the cloud thins, so
	// he visibly gets up rather than just materializing once it clears.
	// The hover cycle then resets. Left untouched for 4s, he either
	// trembles in place (if at home) or — since he shouldn't be stranded
	// mid-cycle no matter which step he was left at — walks himself back
	// home first and trembles from there. `prefers-reduced-motion` zeroes
	// out the timed waits below (the CSS side already disables the actual
	// transitions/animations), so state still advances but without the
	// long, motion-free pauses that would otherwise remain.
	function bindPeekingNinja() {
		var wrapper = document.getElementById( 'nf-peeking-ninja-wrapper' );

		if ( ! wrapper ) {
			return;
		}

		var prefersReducedMotion = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

		// One entry per hover step; `null` means "no state class" (home).
		var HOVER_STEPS = [ 'nf-shy-ninja-right', null, 'nf-shy-ninja-right', 'nf-shy-ninja-exit-left' ];
		var STATE_CLASSES = [ 'nf-shy-ninja-right', 'nf-shy-ninja-exit-left' ];

		var SHAKE_DURATION = prefersReducedMotion ? 0 : 400;
		var EXIT_DURATION = prefersReducedMotion ? 0 : 600; // Time for the exit-left slide itself to finish.
		var OFFSCREEN_WAIT = prefersReducedMotion ? 0 : 3000; // How long he then stays gone before heading back.
		var SMOKE_DURATION = prefersReducedMotion ? 0 : 600; // Quick vanish puff.
		var SMOKE_COVER_DURATION = prefersReducedMotion ? 0 : 1800; // Slower "grenade falls, bloom, hold, clear" reveal.
		var RETURN_DURATION = prefersReducedMotion ? 0 : 450; // Walking back home when left idle mid-cycle (the "right" hop).
		var IDLE_DELAY = 4000;

		var busy = false;
		var idleTimer = null;
		var hoverStep = 0;

		function clearStateClasses() {
			STATE_CLASSES.forEach( function ( className ) {
				wrapper.classList.remove( className );
			} );
		}

		function armIdleTimer() {
			window.clearTimeout( idleTimer );
			wrapper.classList.remove( 'nf-shy-ninja-idle-tremble' );

			idleTimer = window.setTimeout( function () {
				if ( busy ) {
					return;
				}

				// Left mid-cycle at the "right" hop (1st or 3rd hover) —
				// he's not home and hovering has stopped, so walk him back
				// instead of leaving him stranded there indefinitely.
				if ( wrapper.classList.contains( 'nf-shy-ninja-right' ) ) {
					busy = true;
					wrapper.classList.remove( 'nf-shy-ninja-right' );
					hoverStep = 0;

					window.setTimeout( function () {
						busy = false;
						armIdleTimer();
					}, RETURN_DURATION );

					return;
				}

				if ( ! prefersReducedMotion ) {
					wrapper.classList.add( 'nf-shy-ninja-idle-tremble' );
				}
			}, IDLE_DELAY );
		}

		// Runs once he's fully exited off-screen to the left: wait, then
		// bloom the smoke-cover cloud and, right as it starts covering,
		// teleport him back to home (instant, still hidden below the page
		// edge behind the cloud) — the CSS `nf-shy-ninja-rise` animation
		// (keyed off `nf-shy-ninja-smoke-cover-active`) then plays him
		// getting back up into view as the cloud clears.
		function runVanishSequence() {
			window.setTimeout( function () {
				wrapper.classList.add( 'nf-shy-ninja-no-transition' );
				clearStateClasses(); // `left` snaps straight back to home, no visible slide.
				void wrapper.offsetHeight; // Flush the snap before transitions are re-enabled below.
				wrapper.classList.remove( 'nf-shy-ninja-no-transition' );

				wrapper.classList.add( 'nf-shy-ninja-smoke-cover-active' );

				window.setTimeout( function () {
					wrapper.classList.remove( 'nf-shy-ninja-smoke-cover-active' ); // Clears — he's already there.
					hoverStep = 0;
					busy = false;
					armIdleTimer();
				}, SMOKE_COVER_DURATION );
			}, OFFSCREEN_WAIT );
		}

		wrapper.addEventListener( 'mouseenter', function () {
			armIdleTimer();

			if ( busy ) {
				return;
			}

			busy = true;

			wrapper.classList.add( 'nf-shy-ninja-shake' );

			window.setTimeout( function () {
				wrapper.classList.remove( 'nf-shy-ninja-shake' );

				var targetClass = HOVER_STEPS[ hoverStep ];
				var isExiting = 'nf-shy-ninja-exit-left' === targetClass;

				clearStateClasses();

				if ( targetClass ) {
					wrapper.classList.add( targetClass );
				}

				if ( isExiting ) {
					// Smoke-bomb puff right as he vanishes off-screen.
					wrapper.classList.add( 'nf-shy-ninja-smoke-active' );

					window.setTimeout( function () {
						wrapper.classList.remove( 'nf-shy-ninja-smoke-active' );
					}, SMOKE_DURATION );

					window.setTimeout( runVanishSequence, EXIT_DURATION );
					return;
				}

				hoverStep = ( hoverStep + 1 ) % HOVER_STEPS.length;
				busy = false;
				armIdleTimer();
			}, SHAKE_DURATION );
		} );

		armIdleTimer();
	}

	// Easter egg: an almost-invisible dot button slipped into the WP admin
	// footer text (see `EntriesPage::inject_ninja_toggle()`), right where the
	// peeking ninja rests. He starts hidden on every page load (markup-level
	// `nf-shy-ninja-hidden` class - nothing persisted), and this just flips
	// that class on click, so a refresh always resets him back to hidden.
	function bindNinjaToggle() {
		var toggle = document.getElementById( 'iftp-nf-ninja-toggle' );
		var wrapper = document.getElementById( 'nf-peeking-ninja-wrapper' );

		if ( ! toggle || ! wrapper ) {
			return;
		}

		toggle.addEventListener( 'click', function () {
			var hidden = wrapper.classList.toggle( 'nf-shy-ninja-hidden' );

			toggle.setAttribute( 'aria-pressed', hidden ? 'false' : 'true' );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindDetailsToggles();
		bindCustomSelects();
		bindCustomDateInputs();
		bindColumnsControl();
		bindBulkActions();
		bindPerPagePreference();
		bindTableLoadingSpinner();
		bindPeekingNinja();
		bindNinjaToggle();
	} );
} )();
