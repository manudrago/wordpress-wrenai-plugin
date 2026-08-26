/**
 * Admin helpers: connection test, schema deploy, table bulk selection.
 *
 * @package WP_Wren_Dashboards
 */
( function ( window, document ) {
	'use strict';

	var config = window.WWD_ADMIN || {};
	var i18n = config.i18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback || key;
	}

	function request( path, options ) {
		options = options || {};

		return window.fetch( config.root + path, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			}
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( ( data && data.message ) || t( 'failed' ) );
				}

				return data;
			} );
		} );
	}

	function status( node, message, tone ) {
		if ( ! node ) {
			return;
		}

		node.textContent = message;
		node.className = 'wwd-status' + ( tone ? ' is-' + tone : '' );
	}

	function checkHealth( output ) {
		status( output, t( 'checking' ) );

		return request( '/health' ).then( function () {
			status( output, t( 'connected' ), 'ok' );
		} ).catch( function ( error ) {
			status( output, error.message, 'bad' );
		} );
	}

	function bindHealth() {
		var button = document.getElementById( 'wwd-check-health' );
		var output = document.getElementById( 'wwd-health' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			checkHealth( output );
		} );

		// Nobody wants to press a button to find out whether the thing they
		// just configured works.
		checkHealth( output );
	}

	function pollSchema( output, attempt ) {
		attempt = attempt || 0;

		if ( attempt > 60 ) {
			status( output, t( 'indexing' ), 'warn' );

			return;
		}

		request( '/schema/status' ).then( function ( data ) {
			if ( 'finished' === data.status ) {
				status( output, t( 'synced' ), 'ok' );

				return;
			}

			if ( 'failed' === data.status ) {
				status( output, ( data.error && data.error.message ) || t( 'failed' ), 'bad' );

				return;
			}

			status( output, t( 'indexing' ) );

			window.setTimeout( function () {
				pollSchema( output, attempt + 1 );
			}, 2000 );
		} ).catch( function ( error ) {
			status( output, error.message, 'bad' );
		} );
	}

	function bindSync() {
		var button = document.getElementById( 'wwd-sync' );
		var output = document.getElementById( 'wwd-sync-status' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			status( output, t( 'syncing' ) );

			request( '/schema/sync', { method: 'POST' } ).then( function ( data ) {
				status(
					output,
					t( 'indexing' ) + ' (' + data.models + ' tables, ' + data.columns + ' columns, ' + data.joins + ' joins)'
				);

				pollSchema( output, 0 );
			} ).catch( function ( error ) {
				status( output, error.message, 'bad' );
			} ).then( function () {
				button.disabled = false;
			} );
		} );
	}

	function bindPreview() {
		var button = document.getElementById( 'wwd-preview-mdl' );
		var block = document.getElementById( 'wwd-mdl' );

		if ( ! button || ! block ) {
			return;
		}

		button.addEventListener( 'click', function () {
			block.hidden = ! block.hidden;
		} );
	}

	function bindBulkSelect() {
		var buttons = document.querySelectorAll( '[data-wwd-select]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var mode = button.getAttribute( 'data-wwd-select' );
				var labels = document.querySelectorAll( '.wwd-table-pick' );

				Array.prototype.forEach.call( labels, function ( label ) {
					var checkbox = label.querySelector( 'input[type="checkbox"]' );

					if ( ! checkbox ) {
						return;
					}

					if ( 'all' === mode ) {
						checkbox.checked = true;
					} else if ( 'none' === mode ) {
						checkbox.checked = false;
					} else if ( 'core' === mode ) {
						checkbox.checked = label.classList.contains( 'is-core' );
					}
				} );
			} );
		} );
	}

	function bindCopy() {
		Array.prototype.forEach.call( document.querySelectorAll( '.wwd-copy' ), function ( input ) {
			input.addEventListener( 'focus', function () {
				input.select();
			} );
		} );
	}

	function boot() {
		bindHealth();
		bindSync();
		bindPreview();
		bindBulkSelect();
		bindCopy();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( window, document ) );
