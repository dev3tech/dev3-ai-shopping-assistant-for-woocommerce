/**
 * Tiny DOM + text helpers for the storefront chat (no framework, small bundle).
 */

/**
 * Creates an element.
 *
 * @param {string}        tag      Tag name.
 * @param {Object}        attrs    Attributes; `class`, `text`, `html`, `on*` handlers, booleans.
 * @param {...(Node|string|null|false)} children Children.
 * @return {HTMLElement} Element.
 */
export function h( tag, attrs = {}, ...children ) {
	const el = document.createElement( tag );
	Object.keys( attrs || {} ).forEach( ( key ) => {
		const value = attrs[ key ];
		if ( value === null || value === undefined || value === false ) {
			return;
		}
		if ( key === 'class' ) {
			el.className = value;
		} else if ( key === 'text' ) {
			el.textContent = value;
		} else if ( key === 'html' ) {
			el.innerHTML = value;
		} else if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
			el.addEventListener( key.slice( 2 ).toLowerCase(), value );
		} else if ( value === true ) {
			el.setAttribute( key, '' );
		} else {
			el.setAttribute( key, String( value ) );
		}
	} );
	children.flat().forEach( ( child ) => {
		if ( child === null || child === undefined || child === false ) {
			return;
		}
		el.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
	} );
	return el;
}

/**
 * HTML-escapes text.
 *
 * @param {string} text Text.
 * @return {string} Escaped.
 */
export function escapeHtml( text ) {
	return String( text ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
}

/**
 * Inline markdown: **bold**, [label](https://…) and bare https links. Input is escaped first.
 *
 * @param {string} line Escaped line.
 * @return {string} HTML.
 */
function inline( line ) {
	return line
		.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
		.replace( /\[([^\]]{1,120})\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener nofollow">$1</a>' )
		.replace( /(^|[\s(])(https?:\/\/[^\s<)]+[^\s<).,;!?])/g, '$1<a href="$2" target="_blank" rel="noopener nofollow">$2</a>' );
}

/**
 * Safe light markdown → HTML (paragraphs, "- " lists, bold, links).
 *
 * @param {string} text Raw text from the server.
 * @return {string} HTML.
 */
export function markdown( text ) {
	const lines = escapeHtml( text || '' ).split( /\n/ );
	let html = '';
	let list = false;
	let para = [];
	const flush = () => {
		if ( para.length ) {
			html += '<p>' + para.map( inline ).join( '<br>' ) + '</p>';
			para = [];
		}
	};
	lines.forEach( ( raw ) => {
		const line = raw.trim();
		const item = line.match( /^[-*•]\s+(.*)$/ ) || line.match( /^\d+[.)]\s+(.*)$/ );
		if ( item ) {
			flush();
			if ( ! list ) {
				html += '<ul>';
				list = true;
			}
			html += '<li>' + inline( item[ 1 ] ) + '</li>';
			return;
		}
		if ( list ) {
			html += '</ul>';
			list = false;
		}
		if ( line === '' ) {
			flush();
		} else {
			para.push( line );
		}
	} );
	flush();
	if ( list ) {
		html += '</ul>';
	}
	return html;
}

/**
 * Safe storage (private mode / blocked storage never throws).
 *
 * @param {string} type local|session.
 * @return {{get: Function, set: Function, del: Function}} Store.
 */
export function store( type = 'local' ) {
	const s = () => {
		try {
			return type === 'session' ? window.sessionStorage : window.localStorage;
		} catch ( e ) {
			return null;
		}
	};
	return {
		get( key ) {
			try {
				const st = s();
				return st ? st.getItem( key ) : null;
			} catch ( e ) {
				return null;
			}
		},
		set( key, value ) {
			try {
				const st = s();
				if ( st ) {
					st.setItem( key, value );
				}
			} catch ( e ) {}
		},
		del( key ) {
			try {
				const st = s();
				if ( st ) {
					st.removeItem( key );
				}
			} catch ( e ) {}
		},
	};
}

/**
 * Joins a REST base and route (handles ?rest_route= bases).
 *
 * @param {string} base  Base URL.
 * @param {string} route Route with optional query.
 * @return {string} URL.
 */
export function joinUrl( base, route ) {
	const [ path, query ] = String( route ).split( /\?(.*)/s );
	const url = base + path;
	return query ? url + ( url.includes( '?' ) ? '&' : '?' ) + query : url;
}

/**
 * Picks readable text (#fff or near-black) for a background colour.
 *
 * @param {string} hex Colour.
 * @return {string} Colour.
 */
export function onColor( hex ) {
	const m = String( hex || '' ).replace( '#', '' );
	const full = m.length === 3 ? m.split( '' ).map( ( c ) => c + c ).join( '' ) : m;
	if ( ! /^[0-9a-f]{6}$/i.test( full ) ) {
		return '#fff';
	}
	const [ r, g, b ] = [ 0, 2, 4 ].map( ( i ) => {
		const c = parseInt( full.slice( i, i + 2 ), 16 ) / 255;
		return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	} );
	const lum = 0.2126 * r + 0.7152 * g + 0.0722 * b;
	// Contrast with white vs. near-black; keep white unless it fails AA.
	return ( 1.05 / ( lum + 0.05 ) ) >= 4.5 ? '#fff' : '#111827';
}

export const ICONS = {
	chat: 'M21 12c0 4.4-4 8-9 8-1.4 0-2.7-.3-3.9-.8L3 20l1.3-3.9C3.5 14.9 3 13.5 3 12c0-4.4 4-8 9-8s9 3.6 9 8z',
	close: 'M18 6L6 18M6 6l12 12',
	send: 'M5 12h13M13 6l6 6-6 6',
	refresh: 'M4 4v6h6M20 20v-6h-6M5.6 15A8 8 0 0019 13M18.4 9A8 8 0 005 11',
	up: 'M7 10v11M2 12h3v9H2zM7 10l4-8a3 3 0 013 3v4h5.5a2 2 0 012 2.3l-1.2 7A2 2 0 0118.3 21H7',
	down: 'M17 14V3M22 12h-3V3h3zM17 14l-4 8a3 3 0 01-3-3v-4H4.5a2 2 0 01-2-2.3l1.2-7A2 2 0 015.7 3H17',
	check: 'M20 6L9 17l-5-5',
	star: 'M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z',
	box: 'M21 16V8l-9-5-9 5v8l9 5 9-5zM3.3 7L12 12l8.7-5M12 22V12',
	user: 'M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z',
	cart: 'M3 3h2l2.4 12.2a2 2 0 002 1.8h8.2a2 2 0 002-1.6L21 8H6M10 21a1 1 0 100-2 1 1 0 000 2zM18 21a1 1 0 100-2 1 1 0 000 2z',
	phone: 'M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8 9.9a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.5c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z',
};

/**
 * Inline SVG icon.
 *
 * @param {string} name Icon name.
 * @param {number} size Pixels.
 * @return {SVGElement} Icon.
 */
export function icon( name, size = 20 ) {
	const ns = 'http://www.w3.org/2000/svg';
	const svg = document.createElementNS( ns, 'svg' );
	svg.setAttribute( 'viewBox', '0 0 24 24' );
	svg.setAttribute( 'width', size );
	svg.setAttribute( 'height', size );
	svg.setAttribute( 'aria-hidden', 'true' );
	svg.setAttribute( 'focusable', 'false' );
	const path = document.createElementNS( ns, 'path' );
	path.setAttribute( 'd', ICONS[ name ] || '' );
	path.setAttribute( 'fill', name === 'star' ? 'currentColor' : 'none' );
	path.setAttribute( 'stroke', 'currentColor' );
	path.setAttribute( 'stroke-width', '2' );
	path.setAttribute( 'stroke-linecap', 'round' );
	path.setAttribute( 'stroke-linejoin', 'round' );
	svg.appendChild( path );
	return svg;
}
