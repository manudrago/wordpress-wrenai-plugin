/**
 * Front-end app: the ask form, the answer cards and the saved dashboards.
 *
 * @package WP_Wren_Dashboards
 */
( function ( window, document ) {
	'use strict';

	var config = window.WWD_CONFIG || {};
	var i18n = config.i18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback || key;
	}

	function sprintf( template, value ) {
		return String( template ).replace( /%[sd]/, value );
	}

	/*
	 * XMLHttpRequest rather than fetch on purpose. Plenty of plugins replace
	 * window.fetch with an instrumented version, and a broken wrapper takes
	 * every caller down with it - including this one. XHR keeps us out of that
	 * fight.
	 */
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

				var error = new Error( data && data.message ? data.message : t( 'error' ) );

				error.data = data;
				reject( error );
			};

			xhr.onerror = function () {
				reject( new Error( t( 'error' ) ) );
			};

			xhr.send( options.body ? JSON.stringify( options.body ) : null );
		} );
	}

	function element( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}

		return node;
	}

	function toCsv( columns, rows ) {
		var escapeCell = function ( value ) {
			var text = value === null || value === undefined ? '' : String( value );

			return /[",\n]/.test( text ) ? '"' + text.replace( /"/g, '""' ) + '"' : text;
		};

		var lines = [ columns.map( escapeCell ).join( ',' ) ];

		rows.forEach( function ( row ) {
			lines.push( row.map( escapeCell ).join( ',' ) );
		} );

		return lines.join( '\n' );
	}

	function downloadCsv( name, columns, rows ) {
		var blob = new window.Blob( [ '﻿' + toCsv( columns, rows ) ], { type: 'text/csv;charset=utf-8;' } );
		var url = window.URL.createObjectURL( blob );
		var link = document.createElement( 'a' );

		link.href = url;
		link.download = ( name || 'wren-data' ).replace( /[^a-z0-9\-_]+/gi, '-' ).slice( 0, 60 ) + '.csv';
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
		window.URL.revokeObjectURL( url );
	}

	/**
	 * Build the body of an answer: chart, table, and the switch between them.
	 */
	function renderResult( target, answer, options ) {
		options = options || {};
		target.innerHTML = '';

		if ( ! answer.rows || ! answer.rows.length ) {
			target.appendChild( element( 'p', 'wwd-empty', t( 'noData' ) ) );

			return;
		}

		var chart = null;

		try {
			chart = window.WWDChart.render( answer.chart, answer.columns, answer.rows, { height: options.height || 340 } );
		} catch ( e ) {
			chart = null;
		}

		var table = window.WWDChart.table( answer.columns, answer.rows, 200 );
		var views = element( 'div', 'wwd-views' );

		if ( chart ) {
			var tabs = element( 'div', 'wwd-tabs' );
			var chartTab = element( 'button', 'wwd-tab is-active', t( 'showChart' ) );
			var tableTab = element( 'button', 'wwd-tab', t( 'showTable' ) );

			chartTab.type = 'button';
			tableTab.type = 'button';

			table.hidden = true;

			chartTab.addEventListener( 'click', function () {
				chartTab.classList.add( 'is-active' );
				tableTab.classList.remove( 'is-active' );
				chart.hidden = false;
				table.hidden = true;
			} );

			tableTab.addEventListener( 'click', function () {
				tableTab.classList.add( 'is-active' );
				chartTab.classList.remove( 'is-active' );
				chart.hidden = true;
				table.hidden = false;
			} );

			tabs.appendChild( chartTab );
			tabs.appendChild( tableTab );
			views.appendChild( tabs );
			views.appendChild( chart );
		}

		views.appendChild( table );
		target.appendChild( views );

		var meta = element( 'p', 'wwd-meta' );

		meta.appendChild( document.createTextNode( answer.row_count + ' ' + t( 'rows' ) ) );

		if ( answer.duration ) {
			meta.appendChild( document.createTextNode( ' · ' + answer.duration + ' ms' ) );
		}

		if ( answer.cached ) {
			meta.appendChild( document.createTextNode( ' · ' + t( 'cached' ) ) );
		}

		if ( answer.truncated ) {
			meta.appendChild( document.createTextNode( ' · ' + sprintf( t( 'truncated' ), answer.rows.length ) ) );
		}

		var csv = element( 'button', 'wwd-linkish', t( 'csv' ) );

		csv.type = 'button';
		csv.addEventListener( 'click', function () {
			downloadCsv( answer.question || answer.title, answer.columns, answer.rows );
		} );

		meta.appendChild( document.createTextNode( ' · ' ) );
		meta.appendChild( csv );

		target.appendChild( meta );
	}

	/**
	 * The "save this answer to a dashboard" control.
	 */
	function buildSaveControl( card, answer, defaultDashboard ) {
		var wrap = element( 'div', 'wwd-save' );
		var button = element( 'button', 'wwd-btn wwd-btn--ghost', t( 'save' ) );

		button.type = 'button';
		wrap.appendChild( button );

		button.addEventListener( 'click', function () {
			button.disabled = true;

			request( '/dashboards' ).then( function ( data ) {
				button.disabled = false;
				button.hidden = true;

				var form = element( 'div', 'wwd-save__form' );
				var boards = ( data && data.dashboards ) || [];

				if ( ! boards.length ) {
					form.appendChild( element( 'p', 'wwd-empty', t( 'noBoards' ) ) );
					wrap.appendChild( form );

					return;
				}

				var select = document.createElement( 'select' );

				select.className = 'wwd-select';

				boards.forEach( function ( board ) {
					var option = document.createElement( 'option' );

					option.value = board.id;
					option.textContent = board.title;

					if ( String( board.id ) === String( defaultDashboard ) ) {
						option.selected = true;
					}

					select.appendChild( option );
				} );

				var title = document.createElement( 'input' );

				title.type = 'text';
				title.className = 'wwd-input';
				title.placeholder = t( 'panelTitle' );
				title.value = answer.question || '';

				var width = document.createElement( 'select' );

				width.className = 'wwd-select wwd-select--small';

				[
					[ 'half', t( 'widthHalf' ) ],
					[ 'full', t( 'widthFull' ) ],
					[ 'third', t( 'widthThird' ) ]
				].forEach( function ( pair ) {
					var option = document.createElement( 'option' );

					option.value = pair[ 0 ];
					option.textContent = pair[ 1 ];
					width.appendChild( option );
				} );

				var confirm = element( 'button', 'wwd-btn wwd-btn--primary', t( 'save' ) );
				var cancel = element( 'button', 'wwd-btn wwd-btn--ghost', t( 'cancel' ) );

				confirm.type = 'button';
				cancel.type = 'button';

				cancel.addEventListener( 'click', function () {
					form.remove();
					button.hidden = false;
				} );

				confirm.addEventListener( 'click', function () {
					confirm.disabled = true;
					confirm.textContent = t( 'saving' );

					request( '/dashboards/' + encodeURIComponent( select.value ) + '/panels', {
						method: 'POST',
						body: {
							session_id: answer.id,
							title: title.value,
							width: width.value
						}
					} ).then( function ( saved ) {
						form.remove();

						var done = element( 'p', 'wwd-saved' );
						var label = ( saved && saved.dashboard && saved.dashboard.title ) || '';

						done.textContent = sprintf( t( 'saved' ), label );
						wrap.appendChild( done );
					} ).catch( function ( error ) {
						confirm.disabled = false;
						confirm.textContent = t( 'save' );

						var problem = element( 'p', 'wwd-error', error.message );

						form.appendChild( problem );
					} );
				} );

				form.appendChild( select );
				form.appendChild( title );
				form.appendChild( width );
				form.appendChild( confirm );
				form.appendChild( cancel );
				wrap.appendChild( form );
			} ).catch( function () {
				button.disabled = false;
			} );
		} );

		card.appendChild( wrap );
	}

	function buildSqlToggle( card, sql ) {
		if ( ! sql ) {
			return;
		}

		var toggle = element( 'button', 'wwd-linkish wwd-sql-toggle', t( 'showSql' ) );
		var code = element( 'pre', 'wwd-sql-block' );

		toggle.type = 'button';
		code.textContent = sql;
		code.hidden = true;

		toggle.addEventListener( 'click', function () {
			code.hidden = ! code.hidden;
			toggle.textContent = code.hidden ? t( 'showSql' ) : t( 'hideSql' );
		} );

		card.appendChild( toggle );
		card.appendChild( code );
	}

	function AskApp( root ) {
		this.root = root;
		this.form = root.querySelector( '.wwd-ask' );
		this.input = root.querySelector( '.wwd-ask__input' );
		this.answers = root.querySelector( '.wwd-answers' );
		this.dashboard = root.getAttribute( 'data-dashboard' ) || '';
		this.height = parseInt( root.getAttribute( 'data-height' ), 10 ) || 340;
		this.timer = null;

		this.bind();
	}

	AskApp.prototype.bind = function () {
		var self = this;

		this.form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			self.ask( self.input.value );
		} );

		this.input.addEventListener( 'keydown', function ( event ) {
			if ( ( event.metaKey || event.ctrlKey ) && 'Enter' === event.key ) {
				event.preventDefault();
				self.ask( self.input.value );
			}
		} );

		Array.prototype.forEach.call( this.root.querySelectorAll( '.wwd-chip' ), function ( chip ) {
			chip.addEventListener( 'click', function () {
				self.input.value = chip.textContent;
				self.ask( chip.textContent );
			} );
		} );

		var reset = this.root.querySelector( '.wwd-ask__reset' );

		if ( reset ) {
			reset.addEventListener( 'click', function () {
				self.resetThread = true;
				self.answers.innerHTML = '';
				self.input.value = '';
				self.input.focus();
			} );
		}
	};

	AskApp.prototype.card = function ( question ) {
		var card = element( 'article', 'wwd-card' );
		var head = element( 'div', 'wwd-card__head' );

		head.appendChild( element( 'h3', 'wwd-card__question', question ) );
		card.appendChild( head );

		var stage = element( 'p', 'wwd-stage' );
		var spinner = element( 'span', 'wwd-spinner' );

		stage.appendChild( spinner );
		stage.appendChild( element( 'span', 'wwd-stage__text', t( 'thinking' ) ) );
		card.appendChild( stage );

		var body = element( 'div', 'wwd-card__body' );

		card.appendChild( body );

		this.answers.insertBefore( card, this.answers.firstChild );

		return { card: card, stage: stage, body: body };
	};

	AskApp.prototype.ask = function ( question ) {
		var self = this;

		question = String( question || '' ).trim();

		if ( ! question ) {
			return;
		}

		if ( this.timer ) {
			window.clearTimeout( this.timer );
			this.timer = null;
		}

		var view = this.card( question );

		request( '/ask', {
			method: 'POST',
			body: { question: question, reset: !! this.resetThread }
		} ).then( function ( answer ) {
			self.resetThread = false;
			self.poll( answer.id, view );
		} ).catch( function ( error ) {
			self.fail( view, error.message );
		} );
	};

	AskApp.prototype.poll = function ( id, view ) {
		var self = this;

		request( '/ask/' + encodeURIComponent( id ) ).then( function ( answer ) {
			var text = view.stage.querySelector( '.wwd-stage__text' );

			if ( text ) {
				text.textContent = answer.stage || t( 'thinking' );
			}

			if ( 'failed' === answer.status ) {
				self.fail( view, answer.error );

				return;
			}

			if ( 'done' === answer.status ) {
				self.finish( view, answer );

				return;
			}

			self.timer = window.setTimeout( function () {
				self.poll( id, view );
			}, config.pollMs || 1200 );
		} ).catch( function ( error ) {
			self.fail( view, error.message );
		} );
	};

	AskApp.prototype.finish = function ( view, answer ) {
		view.stage.remove();

		renderResult( view.body, answer, { height: this.height } );

		if ( answer.chart_note ) {
			view.card.appendChild( element( 'p', 'wwd-note', answer.chart_note ) );
		}

		buildSqlToggle( view.card, answer.sql );

		if ( config.canSave && answer.rows && answer.rows.length ) {
			buildSaveControl( view.card, answer, this.dashboard );
		}
	};

	AskApp.prototype.fail = function ( view, message ) {
		view.stage.remove();
		view.body.innerHTML = '';
		view.body.appendChild( element( 'p', 'wwd-error', message || t( 'error' ) ) );
	};

	function Board( root ) {
		this.root = root;
		this.id = root.getAttribute( 'data-dashboard' );
		this.refresh = parseInt( root.getAttribute( 'data-refresh' ), 10 ) || 0;

		this.load();

		if ( this.refresh > 0 ) {
			var self = this;

			window.setInterval( function () {
				self.load();
			}, Math.max( this.refresh, 15 ) * 1000 );
		}
	}

	Board.prototype.load = function () {
		var self = this;

		Array.prototype.forEach.call( this.root.querySelectorAll( '.wwd-panel' ), function ( panel ) {
			self.loadPanel( panel );
		} );
	};

	Board.prototype.loadPanel = function ( panel ) {
		var body = panel.querySelector( '.wwd-panel__body' );
		var button = panel.querySelector( '.wwd-panel__refresh' );
		var id = panel.getAttribute( 'data-panel' );
		var self = this;

		if ( button && ! button.dataset.bound ) {
			button.dataset.bound = '1';
			button.addEventListener( 'click', function () {
				self.loadPanel( panel );
			} );
		}

		request( '/dashboards/' + encodeURIComponent( this.id ) + '/panels/' + encodeURIComponent( id ) + '/data' )
			.then( function ( data ) {
				renderResult( body, data, { height: 300 } );
			} )
			.catch( function ( error ) {
				body.innerHTML = '';
				body.appendChild( element( 'p', 'wwd-error', error.message ) );
			} );
	};

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.wwd-app' ), function ( root ) {
			return new AskApp( root );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '.wwd-board' ), function ( root ) {
			return new Board( root );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( window, document ) );
