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

	// XHR, not fetch: other plugins wrap window.fetch and a broken wrapper
	// would break this screen too.
	function request( path, options ) {
		options = options || {};

		return new Promise( function ( resolve, reject ) {
			var xhr = new XMLHttpRequest();

			xhr.open( options.method || 'GET', config.root + path, true );
			xhr.setRequestHeader( 'Content-Type', 'application/json' );
			xhr.setRequestHeader( 'X-WP-Nonce', config.nonce );
			xhr.withCredentials = true;

			xhr.onload = function () {
				var data = {};

				try {
					data = JSON.parse( xhr.responseText );
				} catch ( e ) {
					data = {};
				}

				if ( xhr.status >= 200 && xhr.status < 300 ) {
					resolve( data );

					return;
				}

				reject( new Error( data && data.message ? data.message : t( 'failed' ) ) );
			};

			xhr.onerror = function () {
				reject( new Error( t( 'failed' ) ) );
			};

			xhr.send( options.body ? JSON.stringify( options.body ) : null );
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

	// The command is handed to the user to paste into a root shell, so nothing
	// that is not an API key gets to travel inside it.
	function safeKey( value ) {
		return String( value || '' ).replace( /[^A-Za-z0-9._-]/g, '' );
	}

	function pairCommand( data, key ) {
		var lines = [
			'curl -fsSL ' + config.bootstrap + ' | sudo bash -s -- \\',
			'    --pair-url ' + data.pair_url + ' \\',
			'    --pair-code ' + data.code
		];

		if ( key ) {
			lines[ lines.length - 1 ] += ' \\';
			lines.push( '    --llm google --llm-api-key ' + key );
		}

		return lines.join( '\n' );
	}

	function bindPairing() {
		var openButton = document.getElementById( 'wwd-pair-open' );
		var closeButton = document.getElementById( 'wwd-pair-close' );
		var output = document.getElementById( 'wwd-pair-output' );
		var command = document.getElementById( 'wwd-pair-command' );
		var keyInput = document.getElementById( 'wwd-pair-key' );
		var refreshInput = document.getElementById( 'wwd-pair-refresh' );
		var statusNode = document.getElementById( 'wwd-pair-status' );
		var timer = null;
		var since = 0;

		if ( ! openButton ) {
			return;
		}

		function stop() {
			if ( timer ) {
				window.clearTimeout( timer );
				timer = null;
			}
		}

		function poll() {
			request( '/pair/status' ).then( function ( data ) {
				if ( data.paired_at && data.paired_at > since ) {
					stop();
					status( statusNode, t( 'paired' ), 'ok' );

					// Endpoint and key are in the database now; the form above
					// still shows the old ones.
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );

					return;
				}

				if ( ! data.open ) {
					stop();
					status( statusNode, t( 'expired' ), 'warn' );

					return;
				}

				timer = window.setTimeout( poll, 4000 );
			} ).catch( function () {
				timer = window.setTimeout( poll, 8000 );
			} );
		}

		function watch() {
			stop();
			status( statusNode, t( 'waiting' ) );
			poll();
		}

		openButton.addEventListener( 'click', function () {
			openButton.disabled = true;

			request( '/pair/open', {
				method: 'POST',
				body: { refresh: refreshInput ? refreshInput.checked : true }
			} ).then( function ( data ) {
				since = 0;
				command.value = pairCommand( data, safeKey( keyInput && keyInput.value ) );
				output.hidden = false;
				closeButton.hidden = false;
				command.focus();
				command.select();
				watch();
			} ).catch( function ( error ) {
				status( statusNode, error.message, 'bad' );
			} ).then( function () {
				openButton.disabled = false;
			} );
		} );

		closeButton.addEventListener( 'click', function () {
			request( '/pair/close', { method: 'POST' } ).then( function () {
				stop();
				output.hidden = true;
				closeButton.hidden = true;
				status( statusNode, t( 'pairOff' ) );
			} ).catch( function ( error ) {
				status( statusNode, error.message, 'bad' );
			} );
		} );

		// A pairing opened before this page was loaded is still worth
		// watching: the command may be running in another window right now.
		request( '/pair/status' ).then( function ( data ) {
			if ( ! data.open ) {
				return;
			}

			since = data.paired_at || 0;
			closeButton.hidden = false;
			watch();
		} ).catch( function () {} );
	}

	// Nobody should have to guess a model id, and no list written into a
	// release stays right: ask the provider what it has.
	function bindModelList() {
		var button = document.getElementById( 'wwd-list-models' );
		var field = document.getElementById( 'wwd-model-name' );
		var list = document.getElementById( 'wwd-model-list' );
		var choices = document.getElementById( 'wwd-model-choices' );
		var output = document.getElementById( 'wwd-model-status' );

		if ( ! button || ! field ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			choices.hidden = true;
			choices.textContent = '';
			status( output, t( 'asking' ) );

			request( '/models' ).then( function ( data ) {
				var models = data.models || [];

				list.textContent = '';

				models.forEach( function ( model ) {
					var option = document.createElement( 'option' );

					option.value = model;
					list.appendChild( option );

					var pick = document.createElement( 'button' );

					pick.type = 'button';
					pick.className = 'button button-small';
					pick.textContent = model;

					pick.addEventListener( 'click', function () {
						field.value = model;
						status( output, t( 'picked' ), 'ok' );
					} );

					choices.appendChild( pick );
				} );

				choices.hidden = ! models.length;
				status( output, models.length + ' ' + t( 'models' ), 'ok' );
			} ).catch( function ( error ) {
				status( output, error.message, 'bad' );
			} ).then( function () {
				button.disabled = false;
			} );
		} );
	}

	// The settings form carries both engines; only the chosen one is shown.
	function bindEngine() {
		var radios = document.querySelectorAll( '[data-wwd-engine]' );
		var panes = document.querySelectorAll( '[data-wwd-pane]' );

		if ( ! radios.length ) {
			return;
		}

		function show( engine ) {
			Array.prototype.forEach.call( panes, function ( pane ) {
				pane.hidden = pane.getAttribute( 'data-wwd-pane' ) !== engine;
			} );
		}

		Array.prototype.forEach.call( radios, function ( radio ) {
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					show( radio.value );
				}
			} );
		} );
	}

	function boot() {
		bindHealth();
		bindSync();
		bindEngine();
		bindModelList();
		bindPairing();
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
