/**
 * Checks the Vega-Lite subset renderer against the shapes Wren AI emits.
 * Runs on a tiny DOM shim, so no browser and no dependencies:
 *
 *     node tests/test-chart-renderer.js
 */
'use strict';

/* --------------------------------------------------------------------------
 * Minimal DOM
 * ----------------------------------------------------------------------- */

function Node( tag, ns ) {
	this.tagName = tag;
	this.namespace = ns || null;
	this.children = [];
	this.attributes = {};
	this.style = {};
	this.dataset = {};
	this.textValue = '';
	this.className = '';
	this.hidden = false;
	this.classList = {
		list: [],
		add: function ( name ) {
			this.list.push( name );
		},
		remove: function () {},
		contains: function ( name ) {
			return this.list.indexOf( name ) !== -1;
		}
	};
}

Node.prototype.setAttribute = function ( key, value ) {
	this.attributes[ key ] = value;
};

Node.prototype.appendChild = function ( child ) {
	this.children.push( child );

	return child;
};

Node.prototype.insertBefore = function ( child ) {
	this.children.unshift( child );

	return child;
};

Node.prototype.addEventListener = function () {};

Node.prototype.remove = function () {};

Object.defineProperty( Node.prototype, 'textContent', {
	get: function () {
		return this.textValue;
	},
	set: function ( value ) {
		this.textValue = String( value );
	}
} );

Object.defineProperty( Node.prototype, 'firstChild', {
	get: function () {
		return this.children[ 0 ] || null;
	}
} );

/**
 * Every descendant with the given tag name.
 */
function findAll( node, tag, found ) {
	found = found || [];

	node.children.forEach( function ( child ) {
		if ( child.tagName === tag ) {
			found.push( child );
		}

		findAll( child, tag, found );
	} );

	return found;
}

/**
 * Every descendant carrying the given class name.
 */
function findByClass( node, className, found ) {
	found = found || [];

	node.children.forEach( function ( child ) {
		var classes = String( child.className || '' ).split( ' ' );

		if ( classes.indexOf( className ) !== -1 ) {
			found.push( child );
		}

		findByClass( child, className, found );
	} );

	return found;
}

global.document = {
	readyState: 'complete',
	createElement: function ( tag ) {
		return new Node( tag );
	},
	createElementNS: function ( ns, tag ) {
		return new Node( tag, ns );
	},
	createTextNode: function ( text ) {
		var node = new Node( '#text' );

		node.textContent = text;

		return node;
	},
	addEventListener: function () {},
	querySelectorAll: function () {
		return [];
	}
};

global.window = { WWD_CONFIG: { locale: 'it-IT' } };

require( '../assets/js/wwd-chart.js' );

var chart = global.window.WWDChart;

/* --------------------------------------------------------------------------
 * Checks
 * ----------------------------------------------------------------------- */

var failures = 0;
var checks = 0;

function check( label, passed, details ) {
	checks++;

	if ( passed ) {
		console.log( 'ok    ' + label );

		return;
	}

	failures++;
	console.log( 'FAIL  ' + label );

	if ( details ) {
		console.log( '      ' + details );
	}
}

console.log( 'Chart renderer\n--------------' );

// 1. Bar chart, the most common Wren answer.
var bar = chart.render(
	{
		title: 'Post per stato',
		mark: { type: 'bar' },
		encoding: {
			x: { field: 'post_status', type: 'nominal' },
			y: { field: 'total', type: 'quantitative' }
		}
	},
	[ 'post_status', 'total' ],
	[ [ 'publish', 128 ], [ 'draft', 12 ], [ 'trash', 3 ] ]
);

check( 'draws a bar per category', bar && findAll( bar, 'rect' ).length === 3, bar ? findAll( bar, 'rect' ).length : 'null' );
check( 'keeps the chart title', bar && findByClass( bar, 'wwd-chart__title' ).length === 1 );
check( 'labels every bar for hover', bar && findAll( bar, 'title' ).length === 3 );

// 2. Line chart over months.
var line = chart.render(
	{
		mark: { type: 'line' },
		encoding: {
			x: { field: 'm', type: 'temporal', timeUnit: 'yearmonth' },
			y: { field: 'total', type: 'quantitative' }
		}
	},
	[ 'm', 'total' ],
	[ [ '2026-01-01', 4 ], [ '2026-02-01', 9 ], [ '2026-03-01', 6 ] ]
);

check( 'draws one path for a single series', line && findAll( line, 'path' ).length === 1 );
check( 'truncates temporal x values to the requested unit', line && findAll( line, 'text' ).some( function ( node ) {
	return node.textContent === '2026-02';
} ) );

// 3. Grouped bar: two categorical fields.
var grouped = chart.render(
	{
		mark: { type: 'bar' },
		encoding: {
			x: { field: 'month', type: 'nominal' },
			y: { field: 'total', type: 'quantitative', stack: null },
			xOffset: { field: 'type', type: 'nominal' },
			color: { field: 'type', type: 'nominal' }
		}
	},
	[ 'month', 'type', 'total' ],
	[
		[ '2026-01', 'post', 10 ], [ '2026-01', 'page', 4 ],
		[ '2026-02', 'post', 14 ], [ '2026-02', 'page', 2 ]
	]
);

check( 'draws a bar per group and series', grouped && findAll( grouped, 'rect' ).length === 4 );
check( 'adds a legend for the series', grouped && findByClass( grouped, 'wwd-legend' ).length === 1 );

// 4. Stacked bar: same data without stack: null.
var stacked = chart.render(
	{
		mark: { type: 'bar' },
		encoding: {
			x: { field: 'month', type: 'nominal' },
			y: { field: 'total', type: 'quantitative' },
			color: { field: 'type', type: 'nominal' }
		}
	},
	[ 'month', 'type', 'total' ],
	[
		[ '2026-01', 'post', 10 ], [ '2026-01', 'page', 4 ],
		[ '2026-02', 'post', 14 ], [ '2026-02', 'page', 2 ]
	]
);

var stackedBars = stacked ? findAll( stacked, 'rect' ) : [];

check( 'stacks segments instead of overlapping them', stackedBars.length === 4 && stackedBars[ 0 ].attributes.x === stackedBars[ 1 ].attributes.x );

// 5. Pie chart.
var pie = chart.render(
	{
		mark: { type: 'arc' },
		encoding: {
			theta: { field: 'total', type: 'quantitative' },
			color: { field: 'category', type: 'nominal' }
		}
	},
	[ 'category', 'total' ],
	[ [ 'News', 40 ], [ 'Guide', 30 ], [ 'Case study', 30 ] ]
);

check( 'draws a slice per category', pie && findAll( pie, 'path' ).length === 3 );

// 6. Multi line via the fold transform.
var multi = chart.render(
	{
		mark: { type: 'line' },
		transform: [ { fold: [ 'posts', 'comments' ], as: [ 'metric', 'value' ] } ],
		encoding: {
			x: { field: 'month', type: 'ordinal' },
			y: { field: 'value', type: 'quantitative' },
			color: { field: 'metric', type: 'nominal' }
		}
	},
	[ 'month', 'posts', 'comments' ],
	[ [ '2026-01', 5, 20 ], [ '2026-02', 8, 31 ] ]
);

check( 'folds several metrics into separate lines', multi && findAll( multi, 'path' ).length === 2 );

// 7. A single number is a KPI, with or without a spec.
var kpi = chart.render( null, [ 'total' ], [ [ 1234567 ] ] );

check( 'renders a single value as a KPI', kpi && findByClass( kpi, 'wwd-kpi__value' ).length === 1 );
check(
	'formats the KPI for the locale',
	kpi && /^1\D234\D567$/.test( findByClass( kpi, 'wwd-kpi__value' )[ 0 ].textContent ),
	kpi ? findByClass( kpi, 'wwd-kpi__value' )[ 0 ].textContent : ''
);

// 8. Fallbacks.
check( 'falls back to the table when there is no spec', chart.render( null, [ 'a', 'b' ], [ [ 1, 2 ] ] ) === null );
check( 'falls back when the spec names unknown fields', chart.render(
	{ mark: 'bar', encoding: { x: { field: 'nope' }, y: { field: 'total' } } },
	[ 'month', 'total' ],
	[ [ '2026-01', 3 ] ]
) === null );
check( 'falls back on an empty result set', chart.render( { mark: 'bar', encoding: {} }, [ 'a' ], [] ) === null );

// 9. The table view.
var table = chart.table( [ 'a', 'b' ], [ [ 1, 'x' ], [ 2, 'y' ] ] );

check( 'builds a header per column', findAll( table, 'th' ).length === 2 );
check( 'builds a cell per value', findAll( table, 'td' ).length === 4 );
check( 'right aligns numbers', findAll( table, 'td' )[ 0 ].className === 'wwd-num' );
check( 'shows an em dash for NULL', ( function () {
	var withNull = chart.table( [ 'a' ], [ [ null ] ] );

	return findAll( withNull, 'td' )[ 0 ].textContent === '—';
}() ) );

console.log( '\n' + checks + ' checks, ' + failures + ' failures' );

process.exit( failures > 0 ? 1 : 0 );
