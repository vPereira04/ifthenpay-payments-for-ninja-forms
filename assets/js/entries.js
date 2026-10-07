( function () {
	'use strict';

	// bindColumnsControl() fills this in once it runs, so a full tbody swap
	// elsewhere can reapply the saved column layout to freshly rendered rows.
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

	// A reusable confirm modal standing in for window.confirm() so bulk
	// actions feel like part of the UI rather than a jarring OS popup.
	// opts is { title, message, confirmLabel, danger }; onConfirm only runs
	// if confirmed.
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
		void modal.offsetWidth; // Flushing the unhide before adding the class so the open transition plays.
		modal.classList.add( 'is-open' );
		confirmBtn.focus();
	}

	// A reusable toast for bulk-action feedback, replacing window.alert() so
	// the no-reload bulk-action flow gets some visible confirmation.
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

	// Shared dropdown behind every custom menu on this screen — portaled onto
	// <body> and positioned fixed so none of them get clipped by the card's
	// overflow: hidden. Position is computed once, when it opens, and never
	// recalculated afterwards; re-measuring on every scroll/resize used to
	// "follow" the trigger, but that visibly jittered, so I just close it
	// instead if the page scrolls or resizes under it.
	function createPortalDropdown( trigger, wrapEl, buildItems ) {
		var menuEl = null;

		function position() {
			var rect = trigger.getBoundingClientRect();
			var menuWidth = menuEl.offsetWidth;
			var menuHeight = menuEl.offsetHeight;
			var spaceBelow = window.innerHeight - rect.bottom;
			// Opens below by default, flips above when there's not enough
			// room below but more room above — otherwise it'd render off-screen.
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

	// Fills an option's icon (from its data-icon, e.g. a payment method's
	// logo or the Dinheiro cash.svg — see render_create_entry_modal()) plus
	// its label into el, shared by the trigger's current-value display and
	// each dropdown item so both show the same thing.
	function fillOptionContent( el, option ) {
		el.innerHTML = '';

		var icon = option.getAttribute( 'data-icon' );

		if ( icon ) {
			var img = document.createElement( 'img' );
			img.className = 'iftp-nf-fake-select-icon';
			img.src = icon;
			img.alt = '';
			img.loading = 'lazy';
			el.appendChild( img );
		}

		var label = document.createElement( 'span' );
		label.textContent = option.textContent;
		el.appendChild( label );
	}

	// Replaces the native <select> with a custom dropdown matching the bulk
	// actions menu look. I keep the real <select> (hidden) so the form still
	// submits normally, and fire a real change event when picking a fake
	// option. A select marked `.iftp-nf-modal-select` (the "+ New Payment"
	// popup's Form/Method/Status) gets its own larger, full-width variant —
	// its own style so it doesn't get confused with the compact toolbar
	// filter dropdowns, at Victor's request.
	function enhanceSelect( selectEl ) {
		var isModalSelect = selectEl.classList.contains( 'iftp-nf-modal-select' );

		var wrap = document.createElement( 'div' );
		wrap.className = 'iftp-nf-fake-select' + ( isModalSelect ? ' iftp-nf-fake-select--modal' : '' );

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

			if ( option ) {
				fillOptionContent( labelEl, option );
			} else {
				labelEl.textContent = '';
			}
		}

		var dropdown = createPortalDropdown( trigger, wrap, function ( menuEl, close ) {
			menuEl.classList.toggle( 'iftp-nf-dropdown-menu--modal', isModalSelect );

			Array.prototype.forEach.call( selectEl.options, function ( option ) {
				var item = document.createElement( 'button' );
				item.type = 'button';
				item.setAttribute( 'role', 'option' );
				item.className = 'iftp-nf-dropdown-item' + ( option.selected ? ' is-active' : '' );

				fillOptionContent( item, option );

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

	// Lock glyph shown for a position-locked column, so it's clear there's
	// nothing to grab instead of just empty space.
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

	// I parse YYYY-MM-DD as local date parts instead of new Date(value) —
	// the native parse treats it as UTC midnight, which can shift a day in
	// negative-offset timezones.
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

	// Swaps the native date input's browser calendar for one that matches
	// this screen's own dropdown look. The real input stays (hidden) so the
	// form still submits a plain date value.
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

	// Lets the admin reorder/hide columns, persisted to localStorage so it
	// survives reloads and future visits. The table always re-renders with
	// the default layout server-side, and I reapply the saved layout on top
	// of it client-side.
	var columnsStorageKey = 'iftpNfEntriesColumns';

	function loadColumnLayout( defaultOrder ) {
		var layout = null;

		try {
			var raw = window.localStorage.getItem( columnsStorageKey );
			layout = raw ? JSON.parse( raw ) : null;
		} catch ( e ) {
			layout = null;
		}

		var savedOrder = ( layout && Array.isArray( layout.order ) ) ? layout.order : [];
		var hidden = ( layout && Array.isArray( layout.hidden ) ) ? layout.hidden : [];

		// I drop anything saved that no longer exists and append anything new
		// that isn't in the saved order yet, so a stale layout never hides a
		// column by omission.
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
			window.localStorage.setItem( columnsStorageKey, JSON.stringify( layout ) );
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

		// Keys locked to the front of the order, in their fixed sequence —
		// derived from defaultOrder so there's one source of truth for what's locked.
		var lockedOrderKeys = defaultOrder.filter( function ( key ) {
			return columnsByKey[ key ].positionLocked;
		} );

		// Pins locked columns back to their fixed slots and drops any hidden
		// entry for a visibility-locked one, so drag/reorder and the checkbox
		// handler never have to guard against an invalid state themselves.
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

		// Reorders each row's cells to match order and toggles hidden columns.
		// Column count never changes, so colspan elsewhere doesn't need adjusting.
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

			// Keyboard equivalent of drag-and-drop reorder — moves a column one
			// spot up/down, then re-renders and refocuses its handle so repeated
			// arrow presses keep working.
			function moveColumn( key, direction ) {
				var index = layout.order.indexOf( key );
				var target = index + direction;

				if ( -1 === index || target < 0 || target >= layout.order.length ) {
					return;
				}

				// The target slot is fine to land on unless it's a locked
				// column pinned to the front — that's the only extra check
				// needed to stop a movable column swapping past that boundary.
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

					// Locked columns get no drag handle, and draggable has to be
					// off too, or the row could still be picked up with nothing
					// to grab it by. data-locked-position also marks it as an
					// invalid drop target below.
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

						// Native drag-and-drop has no keyboard equivalent, so I
						// give this handle a real focusable button — arrow keys
						// move the column while it's focused.
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
						// These stay visible always — ID/Amount are the only
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

				// A locked row can't be a drop target either, or a movable
				// column could still land on/past it.
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

	// Bulk select + actions bar. Its "Actions" button opens a custom dropdown
	// portaled onto <body>, same trick as the other custom dropdowns here, so
	// it isn't clipped by the card's overflow: hidden.
	//
	// Deleting only clears our own payment-tracking record, never the real
	// Ninja Forms submission, and animates the row out instead of reloading.
	// A status change crossfades the badge in place, and — since it can move
	// an entry out of the current filter tab — fades the row out too if it no
	// longer matches. The server sends back fresh counts/totals to patch in.
	//
	// Selection persists across pagination via sessionStorage (not
	// localStorage) so it survives page navigation but not indefinitely
	// across visits.
	var selectionStorageKey = 'iftpNfEntriesSelection';

	function loadSelection() {
		try {
			var raw = window.sessionStorage.getItem( selectionStorageKey );
			var parsed = raw ? JSON.parse( raw ) : [];

			return new window.Set( Array.isArray( parsed ) ? parsed : [] );
		} catch ( e ) {
			return new window.Set();
		}
	}

	function persistSelection( selection ) {
		try {
			window.sessionStorage.setItem( selectionStorageKey, JSON.stringify( Array.from( selection ) ) );
		} catch ( e ) {
			// Private browsing / storage disabled — selection just won't survive a page change.
		}
	}

	function bindBulkActions() {
		var body = document.querySelector( '[data-iftp-entries-body]' );
		var selectAll = document.querySelector( '[data-iftp-select-all]' );
		var footer = document.querySelector( '[data-iftp-entries-footer]' );
		var totalCount = document.querySelector( '[data-iftp-total-count]' );
		var bulkCount = document.querySelector( '[data-iftp-bulk-count]' );
		var bulkCancel = document.querySelector( '[data-iftp-bulk-cancel]' );
		var bulkActionsWrap = document.querySelector( '[data-iftp-bulk-actions]' );
		var bulkTrigger = document.querySelector( '[data-iftp-bulk-trigger]' );

		if ( ! body || ! selectAll || ! footer || ! totalCount || ! bulkCount || ! bulkCancel || ! bulkActionsWrap || ! bulkTrigger || 'undefined' === typeof window.iftpNfEntries ) {
			return;
		}

		var settings = window.iftpNfEntries;
		var rowRemoveDuration = 220;
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

		// Only the refs actually rendered on this page — the rest of a bulk
		// action's targets just aren't in the DOM here.
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

			if ( selection.size > 0 ) {
				footer.classList.add( 'is-selecting' );
				bulkActionsWrap.hidden = false;
				totalCount.hidden = true;
				bulkCount.hidden = false;
				bulkCancel.hidden = false;

				var total = window.parseInt( totalCount.getAttribute( 'data-total' ), 10 ) || 0;
				bulkCount.textContent = settings.i18n.selectedOfTotal
					.replace( '%1$s', String( selection.size ) )
					.replace( '%2$s', String( total ) );
			} else {
				footer.classList.remove( 'is-selecting' );
				bulkActionsWrap.hidden = true;
				totalCount.hidden = false;
				bulkCount.hidden = true;
				bulkCancel.hidden = true;
				dropdown.close();
			}

			selectAll.checked = all.length > 0 && visibleSelected.length === all.length;
			selectAll.indeterminate = visibleSelected.length > 0 && visibleSelected.length < all.length;
		}

		// "Select all" only covers the current page — there's no way here to
		// select every entry across the whole filtered result at once.
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

		function updateTotalLabel( label, total ) {
			var el = document.querySelector( '[data-iftp-total-count]' );

			if ( ! el ) {
				return;
			}

			if ( label ) {
				el.textContent = label;
			}

			if ( 'number' === typeof total ) {
				el.setAttribute( 'data-total', String( total ) );
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
				}, rowRemoveDuration );
			} );

			window.setTimeout( onDone, rowRemoveDuration + 20 );
		}

		// Once a bulk action's applied to every selected ref — including ones
		// on other pages — the whole persisted selection is done, so I clear
		// it outright.
		function clearSelectionAfterAction() {
			selection.clear();
			persistSelection( selection );
			refreshBulkBar();
		}

		// Swaps in the freshly rendered rows/pagination/counts the server just
		// recomputed, so the page doesn't stay short until the next navigation.
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

			updateTotalLabel( data.totalLabel, data.total );

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
				formData.append( 'per_page', filters.per_page || '' );
				formData.append( 'paged', filters.paged || 1 );
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

						updateTotalLabel( data.totalLabel, data.total );
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

		// Per-row "Delete" (the ID row action) — same confirm modal and
		// AJAX round-trip as the bulk action, just scoped to this one ref
		// regardless of what's currently selected.
		body.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest( '[data-iftp-row-delete]' );

			if ( ! trigger || busy ) {
				return;
			}

			runDelete( [ trigger.getAttribute( 'data-iftp-row-delete' ) ] );
		} );
	}

	// Remembers the last "entries per page" choice so it carries over next
	// time this screen opens fresh, instead of resetting to the 20-per-page default.
	var perPageStorageKey = 'iftpNfEntriesPerPage';

	function bindPerPagePreference() {
		var select = document.getElementById( 'iftp-nf-per-page' );
		var customInput = document.getElementById( 'iftp-nf-per-page-custom' );
		var form = select ? select.closest( 'form' ) : null;

		if ( ! select || ! form ) {
			return;
		}

		// requestSubmit() fires a real submit event (which the loading
		// spinner listens for) — falling back to .submit() on pre-2022
		// Safari, just without the spinner.
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
				stored = window.localStorage.getItem( perPageStorageKey );
			} catch ( e ) {
				stored = null;
			}

			var storedNum = stored ? parseInt( stored, 10 ) : NaN;

			if ( ! isNaN( storedNum ) && storedNum > 0 ) {
				var isPreset = Array.prototype.some.call( select.options, function ( option ) {
					return option.value === String( storedNum );
				} );

				if ( isPreset ) {
					select.value = String( storedNum );
				} else if ( customInput ) {
					// No preset option matches this stored value (it was a typed
					// custom number), so I hand it to customInput instead of
					// forcing it onto the select.
					select.value = 'custom';
					select.disabled = true;
					customInput.hidden = false;
					customInput.disabled = false;
					customInput.value = String( storedNum );
				} else {
					stored = null;
				}

				if ( stored ) {
					submitForm();
					return;
				}
			}
		}

		// One listener instead of splitting the mode-toggle and persist+submit
		// across two handlers, so they can't run out of order — submitForm()
		// needs the field's enabled/hidden state settled first.
		select.addEventListener( 'change', function () {
			// "Custom…" isn't a real value — picking it just reveals
			// customInput, which persists/submits once a number is typed.
			if ( 'custom' === select.value ) {
				select.disabled = true;

				if ( customInput ) {
					customInput.hidden = false;
					customInput.disabled = false;
					customInput.focus();
					customInput.select();
				}

				return;
			}

			select.disabled = false;

			if ( customInput ) {
				customInput.hidden = true;
				customInput.disabled = true;
			}

			try {
				window.localStorage.setItem( perPageStorageKey, select.value );
			} catch ( e ) {
				// Private browsing / storage disabled — the form below still submits normally.
			}

			submitForm();
		} );
	}

	// Applies a typed "Custom…" value — the select hands off to this input,
	// so this just needs to clamp/persist/submit it.
	function bindPerPageCustomInput() {
		var customInput = document.getElementById( 'iftp-nf-per-page-custom' );

		if ( ! customInput ) {
			return;
		}

		function applyCustomValue() {
			var max = parseInt( customInput.getAttribute( 'max' ), 10 ) || 250;
			var typed = parseInt( customInput.value, 10 );

			if ( isNaN( typed ) ) {
				return;
			}

			var value = Math.min( max, Math.max( 1, typed ) );
			customInput.value = String( value );

			try {
				window.localStorage.setItem( perPageStorageKey, String( value ) );
			} catch ( e ) {
				// Private browsing / storage disabled — the form below still submits normally.
			}

			var form = customInput.closest( 'form' );

			if ( ! form ) {
				return;
			}

			if ( form.requestSubmit ) {
				form.requestSubmit();
			} else {
				form.submit();
			}
		}

		// Enter-only, like the page-jump input — not change/blur too, which
		// risks a second redundant submit while the first is still
		// navigating away. Tabbing off still works via the "Filter" button.
		customInput.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				applyCustomValue();
			}
		} );
	}

	// Filtering/sorting/paging here is a real page navigation, not a fetch,
	// so nothing tells the admin their click did anything until it loads.
	// This shows a spinner the instant a filter/sort/page action fires.
	function bindTableLoadingSpinner() {
		var overlay = document.querySelector( '[data-iftp-table-loading]' );

		if ( ! overlay ) {
			return;
		}

		function showLoading() {
			overlay.hidden = false;
		}

		document.querySelectorAll( '.iftp-nf-entries-filters' ).forEach( function ( form ) {
			form.addEventListener( 'submit', showLoading );
		} );

		document.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest( '.iftp-nf-sortable, .iftp-nf-page-number, .iftp-nf-page-btn, .iftp-nf-reset-filters' );

			if ( ! trigger || trigger.classList.contains( 'is-current' ) || trigger.classList.contains( 'is-disabled' ) ) {
				return;
			}

			showLoading();
		} );

		// Resets the spinner if the browser restores this page from the
		// back/forward cache — otherwise going back mid-navigation would
		// leave it stuck on over an already-loaded table.
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				overlay.hidden = true;
			}
		} );
	}

	// The pagination's "…" is secretly a jump-to-page input. I delegate on
	// document since the pagination markup gets replaced wholesale after a
	// bulk action.
	function bindPageJumpInput() {
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' !== event.key || ! event.target.matches( '.iftp-nf-page-jump-input' ) ) {
				return;
			}

			event.preventDefault();

			var target = parseInt( event.target.value, 10 );
			var total = parseInt( event.target.getAttribute( 'data-total' ), 10 ) || 1;

			if ( isNaN( target ) || target < 1 ) {
				return;
			}

			target = Math.min( target, total );

			var baseUrl = event.target.getAttribute( 'data-base-url' ) || window.location.href;
			var url = new URL( baseUrl, window.location.href );

			if ( target > 1 ) {
				url.searchParams.set( 'paged', target );
			} else {
				url.searchParams.delete( 'paged' );
			}

			var overlay = document.querySelector( '[data-iftp-table-loading]' );

			if ( overlay ) {
				overlay.hidden = false;
			}

			window.location.href = url.toString();
		} );
	}

	// Sticky scroll-to-top button, hidden when there's little enough on the
	// page that scrolling up isn't tedious.
	function bindScrollToTopButton() {
		var btn = document.getElementById( 'iftp-nf-scroll-btn' );

		if ( ! btn ) {
			return;
		}

		var perPage = parseInt( btn.getAttribute( 'data-per-page' ) || '20', 10 );

		if ( perPage <= 10 ) {
			return;
		}

		function update() {
			if ( window.scrollY > 100 ) {
				btn.classList.add( 'is-visible' );
			} else {
				btn.classList.remove( 'is-visible' );
			}
		}

		window.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update, { passive: true } );
		update();

		btn.addEventListener( 'click', function () {
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		} );
	}

	// Peeking ninja easter egg: each hover shakes him and advances one step
	// of a hop-out-and-return cycle, finishing with a smoke-puff exit and,
	// after a pause, a smoke-bloom return. Left idle mid-cycle, he walks
	// himself back home. prefers-reduced-motion zeroes out the timed waits
	// so state still advances, just without the pauses.
	function bindPeekingNinja() {
		var wrapper = document.getElementById( 'nf-peeking-ninja-wrapper' );

		if ( ! wrapper ) {
			return;
		}

		var prefersReducedMotion = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

		// One entry per hover step; `null` means "no state class" (home).
		var hoverSteps = [ 'nf-shy-ninja-right', null, 'nf-shy-ninja-right', 'nf-shy-ninja-exit-left' ];
		var stateClasses = [ 'nf-shy-ninja-right', 'nf-shy-ninja-exit-left' ];

		var shakeDuration = prefersReducedMotion ? 0 : 400;
		var exitDuration = prefersReducedMotion ? 0 : 600; // Time for the exit-left slide to finish.
		var offscreenWait = prefersReducedMotion ? 0 : 3000; // How long he stays gone before heading back.
		var smokeDuration = prefersReducedMotion ? 0 : 600; // Quick vanish puff.
		var smokeCoverDuration = prefersReducedMotion ? 0 : 3700; // Slower "bloom, hold, clear" reveal — the last wave of cluster puffs starts .7s in (see admin.css) then runs its own 2.9s.
		var returnDuration = prefersReducedMotion ? 0 : 450; // Walking back home when left idle — same speed as hopping out, reversed.
		var idleDelay = 4000;

		var busy = false;
		var idleTimer = null;
		var hoverStep = 0;

		function clearStateClasses() {
			stateClasses.forEach( function ( className ) {
				wrapper.classList.remove( className );
			} );
		}

		function armIdleTimer() {
			window.clearTimeout( idleTimer );

			idleTimer = window.setTimeout( function () {
				if ( busy ) {
					return;
				}

				// Left mid-cycle at a "right" hop with hovering stopped —
				// walk him back instead of leaving him stranded.
				if ( wrapper.classList.contains( 'nf-shy-ninja-right' ) ) {
					busy = true;
					wrapper.classList.remove( 'nf-shy-ninja-right' );
					hoverStep = 0;

					window.setTimeout( function () {
						busy = false;
						armIdleTimer();
					}, returnDuration );
				}
			}, idleDelay );
		}

		// Once he's off-screen: wait, then bloom the smoke cloud and
		// teleport him back home while still hidden behind it — the CSS
		// rise animation then plays him popping back into view as it clears.
		function runVanishSequence() {
			window.setTimeout( function () {
				wrapper.classList.add( 'nf-shy-ninja-no-transition' );
				// Adding this alongside the position snap, not after, so he
				// stays invisible the instant he's back on-screen instead of
				// flashing visible for a frame.
				wrapper.classList.add( 'nf-shy-ninja-smoke-cover-active' );
				clearStateClasses(); // `left` snaps straight back to home, no visible slide.
				void wrapper.offsetHeight; // Flush the snap before transitions are re-enabled below.
				wrapper.classList.remove( 'nf-shy-ninja-no-transition' );

				window.setTimeout( function () {
					wrapper.classList.remove( 'nf-shy-ninja-smoke-cover-active' ); // Clears — he's already there.
					hoverStep = 0;
					busy = false;
					armIdleTimer();
				}, smokeCoverDuration );
			}, offscreenWait );
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

				var targetClass = hoverSteps[ hoverStep ];
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
					}, smokeDuration );

					window.setTimeout( runVanishSequence, exitDuration );
					return;
				}

				hoverStep = ( hoverStep + 1 ) % hoverSteps.length;
				busy = false;
				armIdleTimer();
			}, shakeDuration );
		} );

		armIdleTimer();
	}

	// Hidden easter-egg toggle button in the admin footer, next to the
	// peeking ninja. He starts hidden every load (nothing persisted) —
	// clicking just flips the class.
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

	// The "+ New Payment" popup (`render_create_entry_modal()`) — a
	// form-based modal, unlike the generic `openConfirmModal()` above, since
	// its fields are fixed rather than filled in per bulk action. Submits
	// via AJAX to `iftp_nf_create_entry`, then folds the response into the
	// table the same way a bulk action's does.
	function bindCreateEntry() {
		var trigger = document.querySelector( '[data-iftp-new-entry-trigger]' );
		var modal = document.querySelector( '[data-iftp-new-entry-modal]' );

		if ( ! trigger || ! modal || 'undefined' === typeof window.iftpNfEntries ) {
			return;
		}

		var settings = window.iftpNfEntries;
		var overlay = modal.querySelector( '[data-iftp-new-entry-overlay]' );
		var cancelBtn = modal.querySelector( '[data-iftp-new-entry-cancel]' );
		var form = modal.querySelector( '[data-iftp-new-entry-form]' );
		var submitBtn = modal.querySelector( '[data-iftp-new-entry-submit]' );
		var errorEl = modal.querySelector( '[data-iftp-new-entry-error]' );

		// A missing piece here would otherwise throw on the next line
		// (submitBtn.textContent) and silently abort the rest of this
		// DOMContentLoaded handler — every bind*() call still queued after
		// this one (bulk actions, columns, per-page, …) would never run.
		// Bailing out of just this one function is safer than taking
		// everything else down with it.
		if ( ! overlay || ! cancelBtn || ! form || ! submitBtn || ! errorEl ) {
			window.console && console.error( 'iftp-nf: "+ New Payment" modal is missing an expected element — not wiring it up.' );
			return;
		}

		var submitLabel = submitBtn.textContent;
		var busy = false;

		function showError( message ) {
			errorEl.textContent = message || '';
			errorEl.hidden = ! message;
		}

		function open() {
			showError( '' );
			modal.hidden = false;
			void modal.offsetWidth; // Flushing the unhide before adding the class so the open transition plays.
			modal.classList.add( 'is-open' );

			var firstField = form.querySelector( 'select, input' );

			if ( firstField ) {
				firstField.focus();
			}
		}

		function close() {
			modal.classList.remove( 'is-open' );

			window.setTimeout( function () {
				modal.hidden = true;
				form.reset();
				showError( '' );
			}, 200 );
		}

		function onKeydown( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				close();
			}
		}

		trigger.addEventListener( 'click', open );
		cancelBtn.addEventListener( 'click', close );
		overlay.addEventListener( 'click', close );
		document.addEventListener( 'keydown', onKeydown );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( busy ) {
				return;
			}

			showError( '' );

			busy = true;
			submitBtn.disabled = true;
			submitBtn.textContent = settings.i18n.newEntryCreating;

			var filters = settings.currentFilters || {};
			var formData = new window.FormData( form );
			formData.append( 'action', 'iftp_nf_create_entry' );
			formData.append( 'nonce', settings.nonce );
			formData.append( 'view_s', filters.s || '' );
			formData.append( 'view_status', filters.status || '' );
			formData.append( 'view_form_id', filters.form_id || 0 );
			formData.append( 'view_date_from', filters.date_from || '' );
			formData.append( 'view_date_to', filters.date_to || '' );
			formData.append( 'view_orderby', filters.orderby || '' );
			formData.append( 'view_order', filters.order || '' );
			formData.append( 'view_per_page', filters.per_page || '' );
			formData.append( 'view_paged', filters.paged || 1 );

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
						throw new Error( ( json && json.data && json.data.message ) || settings.i18n.newEntryError );
					}

					var data = json.data || {};
					var body = document.querySelector( '[data-iftp-entries-body]' );

					if ( body && 'string' === typeof data.rowsHtml ) {
						body.innerHTML = data.rowsHtml;
					}

					var paginationWrap = document.querySelector( '[data-iftp-pagination]' );

					if ( paginationWrap && 'string' === typeof data.paginationHtml ) {
						paginationWrap.innerHTML = data.paginationHtml;
					}

					if ( data.counts ) {
						Object.keys( data.counts ).forEach( function ( slug ) {
							var el = document.querySelector( '[data-iftp-count="' + ( '' === slug ? '_all' : slug ) + '"]' );

							if ( el ) {
								el.textContent = data.counts[ slug ];
							}
						} );
					}

					var totalEl = document.querySelector( '[data-iftp-total-count]' );

					if ( totalEl && 'string' === typeof data.totalLabel ) {
						totalEl.textContent = data.totalLabel;
					}

					if ( totalEl && 'number' === typeof data.total ) {
						totalEl.setAttribute( 'data-total', String( data.total ) );
					}

					bindDetailsToggles();
					reapplyColumnLayout();

					close();
					showToast( settings.i18n.newEntryToastSuccess, 'success' );
				} )
				.catch( function ( error ) {
					window.console && console.error( 'iftp-nf: create entry failed —', error );
					showError( error.message || settings.i18n.newEntryError );
				} )
				.finally( function () {
					busy = false;
					submitBtn.disabled = false;
					submitBtn.textContent = submitLabel;
				} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindDetailsToggles();
		bindCustomSelects();
		bindCustomDateInputs();
		bindColumnsControl();
		bindBulkActions();
		bindCreateEntry();
		bindPerPagePreference();
		bindPerPageCustomInput();
		bindTableLoadingSpinner();
		bindPageJumpInput();
		bindScrollToTopButton();
		bindPeekingNinja();
		bindNinjaToggle();
	} );
} )();
