import { h, icon, markdown, onColor, store } from './dom';

const MOBILE = '(max-width: 600px)';
const TOKEN_KEY = 'dev3sa_token';
const OPEN_KEY = 'dev3sa_open';

/**
 * Creates one chat (floating launcher + panel, or inline panel).
 *
 * @param {Object}      options           Options.
 * @param {Object}      options.config    Boot config (window.dev3saChat).
 * @param {Function}    options.request   Transport: request( route, { method, data } ).
 * @param {HTMLElement} options.mount     Where to render.
 * @param {string}      options.mode      floating|inline.
 * @param {boolean}     options.persist   Remember the token / open state (false in previews).
 * @return {Object} API { open, close, toggle, send, isOpen, root, destroy }.
 */
export function createChat( { config, request, mount, mode = 'floating', persist = true } ) {
	const t = config.i18n || {};
	const local = store( 'local' );
	const session = store( 'session' );
	const floating = mode === 'floating';
	const state = {
		open: ! floating,
		token: persist ? local.get( TOKEN_KEY ) || '' : '',
		loaded: false,
		busy: false,
		started: false,
		lockY: 0,
		pushed: false,
		lastFocus: null,
	};
	const uid = 'dev3sa-' + Math.random().toString( 36 ).slice( 2, 8 );
	const mq = window.matchMedia ? window.matchMedia( MOBILE ) : { matches: false, addEventListener() {} };
	const isMobile = () => floating && mq.matches;

	/* ---------- skeleton ---------- */
	const root = h( 'div', {
		class: `dev3sa-chat dev3sa-chat--${ mode } dev3sa-chat--${ config.position === 'left' ? 'left' : 'right' }`,
		dir: config.rtl ? 'rtl' : null,
	} );
	root.style.setProperty( '--dev3sa-accent', config.accent || '#4f46e5' );
	root.style.setProperty( '--dev3sa-on-accent', onColor( config.accent ) );
	if ( floating ) {
		root.style.setProperty( '--dev3sa-x', ( config.offsetX ?? 20 ) + 'px' );
		root.style.setProperty( '--dev3sa-y', ( config.offsetY ?? 20 ) + 'px' );
	}

	const avatar = () =>
		config.avatar
			? h( 'img', { class: 'dev3sa-avatar', src: config.avatar, alt: '', width: 36, height: 36, loading: 'lazy' } )
			: h( 'span', { class: 'dev3sa-avatar dev3sa-avatar--icon', 'aria-hidden': 'true' }, icon( 'chat', 18 ) );

	const log = h( 'div', { class: 'dev3sa-log', role: 'log', 'aria-live': 'polite', 'aria-label': t.chatLog || 'Chat messages', tabindex: '0' } );
	const chips = h( 'div', { class: 'dev3sa-chips', hidden: true } );
	const input = h( 'textarea', {
		class: 'dev3sa-input',
		id: uid + '-input',
		rows: '1',
		maxlength: '1000',
		placeholder: config.placeholder || '',
		'aria-label': t.message || 'Message',
		enterkeyhint: 'send',
		autocomplete: 'off',
	} );
	const sendBtn = h( 'button', { type: 'submit', class: 'dev3sa-send', 'aria-label': t.send || 'Send' }, icon( 'send', 20 ) );
	const form = h( 'form', { class: 'dev3sa-composer', onSubmit: ( e ) => {
		e.preventDefault();
		send( input.value );
	} }, input, sendBtn );
	const status = h( 'span', { class: 'dev3sa-status' }, h( 'span', { class: 'dev3sa-dot', 'aria-hidden': 'true' } ), config.online === false ? t.away || '' : t.online || 'Online' );
	const titleId = uid + '-title';
	const head = h(
		'div',
		{ class: 'dev3sa-head' },
		avatar(),
		h( 'div', { class: 'dev3sa-head__text' }, h( 'h2', { class: 'dev3sa-title', id: titleId, text: config.name || '' } ), config.subtitle ? h( 'p', { class: 'dev3sa-subtitle', text: config.subtitle } ) : null, status ),
		h( 'button', { type: 'button', class: 'dev3sa-icon-btn', 'aria-label': t.newChat || 'New chat', title: t.newChat || '', onClick: () => reset() }, icon( 'refresh', 18 ) ),
		floating ? h( 'button', { type: 'button', class: 'dev3sa-icon-btn dev3sa-close', 'aria-label': t.close || 'Close', onClick: () => close() }, icon( 'close', 20 ) ) : null
	);
	const consent = config.consent
		? h( 'p', { class: 'dev3sa-consent' }, config.consent + ' ', config.privacy ? h( 'a', { href: config.privacy, target: '_blank', rel: 'noopener', text: t.privacy || 'Privacy policy' } ) : null )
		: null;
	const panel = h(
		'section',
		{ class: 'dev3sa-panel', id: uid + '-panel', role: floating ? 'dialog' : 'region', 'aria-labelledby': titleId, hidden: floating ? true : null },
		head,
		log,
		chips,
		h( 'div', { class: 'dev3sa-foot' }, form, consent )
	);

	let launcher = null;
	let bubble = null;
	if ( floating ) {
		const iconOnly = config.launcher === 'icon' || ! config.label;
		launcher = h(
			'button',
			{
				type: 'button',
				class: 'dev3sa-launcher' + ( iconOnly ? ' dev3sa-launcher--icon' : '' ),
				'aria-expanded': 'false',
				'aria-controls': uid + '-panel',
				'aria-label': iconOnly ? t.open || 'Open chat' : null,
				onClick: () => toggle(),
			},
			icon( 'chat', 22 ),
			iconOnly ? null : h( 'span', { class: 'dev3sa-launcher__label', text: config.label } ),
			h( 'span', { class: 'dev3sa-unread', hidden: true, 'aria-hidden': 'true' } )
		);
		root.appendChild( panel );
		root.appendChild( launcher );
	} else {
		root.appendChild( panel );
	}
	mount.appendChild( root );

	/* ---------- rendering ---------- */
	const scrollDown = () => {
		log.scrollTop = log.scrollHeight;
	};

	function addRow( role, ...children ) {
		const row = h( 'div', { class: `dev3sa-row dev3sa-row--${ role }` }, ...children );
		log.appendChild( row );
		scrollDown();
		return row;
	}

	function starterChips( list ) {
		chips.textContent = '';
		( list || [] ).forEach( ( label ) => {
			chips.appendChild( h( 'button', { type: 'button', class: 'dev3sa-chip', text: label, onClick: () => send( label ) } ) );
		} );
		chips.hidden = ! chips.childNodes.length;
	}

	function stars( rating ) {
		if ( ! rating ) {
			return null;
		}
		const pct = Math.max( 0, Math.min( 100, ( rating / 5 ) * 100 ) );
		return h( 'span', { class: 'dev3sa-stars', role: 'img', 'aria-label': `${ rating } / 5` }, h( 'span', { class: 'dev3sa-stars__fill', style: `width:${ pct }%` } ) );
	}

	function productCard( p ) {
		const btn = p.can_add
			? h( 'button', { type: 'button', class: 'dev3sa-btn dev3sa-btn--primary', onClick: ( e ) => addToCart( p, e.currentTarget ) }, icon( 'cart', 16 ), h( 'span', { text: p.cta } ) )
			: h( 'a', { class: 'dev3sa-btn dev3sa-btn--primary', href: p.cta_url || p.url, text: p.cta || t.view } );
		return h(
			'article',
			{ class: 'dev3sa-card' },
			h( 'a', { class: 'dev3sa-card__media', href: p.url, tabindex: '-1', 'aria-hidden': 'true' }, h( 'img', { src: p.image, alt: '', width: 300, height: 300, loading: 'lazy', decoding: 'async' } ), p.on_sale ? h( 'span', { class: 'dev3sa-sale', text: '%' } ) : null ),
			h(
				'div',
				{ class: 'dev3sa-card__body' },
				h( 'a', { class: 'dev3sa-card__name', href: p.url, text: p.name } ),
				h( 'div', { class: 'dev3sa-card__price', html: p.price_html } ),
				h( 'div', { class: 'dev3sa-card__meta' }, stars( p.rating ), p.reviews ? h( 'span', { text: `(${ p.reviews })` } ) : null, h( 'span', { class: p.in_stock ? 'dev3sa-stock' : 'dev3sa-stock dev3sa-stock--out', text: p.stock_label } ) ),
				h( 'div', { class: 'dev3sa-card__actions' }, btn )
			)
		);
	}

	function cartLinks( cart ) {
		return h(
			'div',
			{ class: 'dev3sa-inline-actions' },
			cart && cart.cart_url ? h( 'a', { class: 'dev3sa-btn', href: cart.cart_url, text: t.viewCart || 'View cart' } ) : null,
			cart && cart.checkout_url ? h( 'a', { class: 'dev3sa-btn dev3sa-btn--primary', href: cart.checkout_url, text: t.checkout || 'Checkout' } ) : null
		);
	}

	function addedNotice( a ) {
		const box = h(
			'div',
			{ class: 'dev3sa-note dev3sa-note--ok', role: 'status' },
			h( 'p', {}, icon( 'check', 16 ), h( 'strong', { text: ( a.quantity > 1 ? a.quantity + ' × ' : '' ) + a.product } ), ' ', h( 'span', { text: t.added || 'Added to your cart' } ) )
		);
		const actions = cartLinks( a.cart );
		if ( a.key && persist ) {
			actions.appendChild(
				h( 'button', { type: 'button', class: 'dev3sa-btn dev3sa-btn--ghost', text: t.undo || 'Undo', onClick: ( e ) => {
					const b = e.currentTarget;
					b.disabled = true;
					request( 'cart', { method: 'POST', data: { token: state.token, key: a.key, remove: true } } )
						.then( () => {
							box.replaceChildren( h( 'p', { text: t.removed || 'Removed.' } ) );
							refreshFragments();
						} )
						.catch( ( err ) => {
							b.disabled = false;
							showError( err );
						} );
				} } )
			);
		}
		box.appendChild( actions );
		return box;
	}

	function orderCard( o ) {
		return h(
			'div',
			{ class: 'dev3sa-order' },
			h( 'div', { class: 'dev3sa-order__head' }, h( 'strong', { text: '#' + o.number } ), h( 'span', { class: `dev3sa-badge dev3sa-badge--${ o.status }`, text: o.status_label } ) ),
			o.date ? h( 'p', { class: 'dev3sa-muted', text: o.date } ) : null,
			h( 'ol', { class: 'dev3sa-steps' }, ( o.steps || [] ).map( ( s ) => h( 'li', { class: s.done ? 'is-done' : '' }, h( 'span', { class: 'dev3sa-steps__dot', 'aria-hidden': 'true' } ), h( 'span', { text: s.label } ) ) ) ),
			o.items && o.items.length ? h( 'ul', { class: 'dev3sa-order__items' }, o.items.map( ( i ) => h( 'li', { text: `${ i.qty } × ${ i.name }` } ) ), o.more_items ? h( 'li', { text: `+${ o.more_items }` } ) : null ) : null,
			h( 'p', { class: 'dev3sa-order__total' }, h( 'span', { text: ( t.total || 'Total' ) + ': ' } ), h( 'span', { html: o.total_html } ) ),
			( o.tracking || [] ).map( ( tr ) =>
				h( 'p', { class: 'dev3sa-order__track' }, h( 'span', { text: ( t.tracking || 'Tracking' ) + ': ' + ( tr.provider ? tr.provider + ' ' : '' ) } ), tr.url ? h( 'a', { href: tr.url, target: '_blank', rel: 'noopener', text: tr.number } ) : h( 'code', { text: tr.number } ) )
			),
			o.url ? h( 'a', { class: 'dev3sa-btn', href: o.url, text: t.viewOrder || 'View order' } ) : null
		);
	}

	function field( label, attrs ) {
		const id = uid + '-' + Math.random().toString( 36 ).slice( 2, 7 );
		const control = attrs.tag === 'textarea' ? h( 'textarea', { id, rows: 3, ...attrs, tag: null } ) : h( 'input', { id, ...attrs } );
		return h( 'div', { class: 'dev3sa-field' }, h( 'label', { for: id, text: label } ), control );
	}

	function miniForm( fields, submitLabel, onSubmit, keepNote = true ) {
		const err = h( 'p', { class: 'dev3sa-form-error', role: 'alert', hidden: true } );
		const submit = h( 'button', { type: 'submit', class: 'dev3sa-btn dev3sa-btn--primary dev3sa-btn--block', text: submitLabel } );
		const f = h( 'form', { class: 'dev3sa-form', novalidate: true }, ...fields, err, submit );
		f.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const values = {};
			let bad = '';
			f.querySelectorAll( 'input,textarea' ).forEach( ( el ) => {
				values[ el.name ] = el.value.trim();
				el.removeAttribute( 'aria-invalid' );
				if ( ! bad && el.required && ! values[ el.name ] ) {
					bad = t.required || 'Required';
					el.setAttribute( 'aria-invalid', 'true' );
					el.focus();
				} else if ( ! bad && el.type === 'email' && values[ el.name ] && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( values[ el.name ] ) ) {
					bad = t.badEmail || 'Invalid email';
					el.setAttribute( 'aria-invalid', 'true' );
					el.focus();
				}
			} );
			err.hidden = ! bad;
			err.textContent = bad;
			if ( bad ) {
				return;
			}
			submit.disabled = true;
			onSubmit( values )
				.then( () => {
					if ( keepNote ) {
						f.replaceChildren( h( 'p', { class: 'dev3sa-muted' }, icon( 'check', 16 ), ' ', t.sent || 'Sent' ) );
					} else {
						f.remove(); // The answer (order card) is rendered right below.
					}
				} )
				.catch( ( e2 ) => {
					submit.disabled = false;
					err.hidden = false;
					err.textContent = e2.message || t.error;
				} );
		} );
		return f;
	}

	function orderForm( a ) {
		return miniForm(
			[
				field( t.orderNumber || 'Order number', { name: 'order', required: true, inputmode: 'text', autocomplete: 'off', value: a.number || '', maxlength: 30 } ),
				config.loggedIn ? null : field( t.email || 'Email', { name: 'email', type: 'email', required: true, autocomplete: 'email', inputmode: 'email', maxlength: 190 } ),
			].filter( Boolean ),
			t.findOrder || 'Find my order',
			( v ) => post( 'order', { order: v.order, email: v.email || '' } ),
			false
		);
	}

	function handoffForm( a ) {
		const o = a.options || {};
		const contact = h(
			'div',
			{ class: 'dev3sa-inline-actions' },
			o.whatsapp ? h( 'a', { class: 'dev3sa-btn', href: o.whatsapp, target: '_blank', rel: 'noopener', text: t.whatsapp || 'WhatsApp' } ) : null,
			o.phone ? h( 'a', { class: 'dev3sa-btn', href: o.phone }, icon( 'phone', 16 ), h( 'span', { text: t.call || 'Call us' } ) ) : null
		);
		return h(
			'div',
			{ class: 'dev3sa-handoff' },
			miniForm(
				[
					field( t.name || 'Name', { name: 'name', autocomplete: 'name', maxlength: 120 } ),
					field( t.email || 'Email', { name: 'email', type: 'email', required: true, autocomplete: 'email', inputmode: 'email', maxlength: 190 } ),
					field( t.yourMessage || 'Message', { name: 'message', tag: 'textarea', required: true, maxlength: 2000 } ),
				],
				t.sendTeam || 'Send',
				( v ) => post( 'handoff', v )
			),
			contact.childNodes.length ? contact : null
		);
	}

	function action( a ) {
		switch ( a.type ) {
			case 'added':
				return addedNotice( a );
			case 'order':
				return orderCard( a.order );
			case 'order_form':
				return orderForm( a );
			case 'handoff_form':
				return handoffForm( a );
			case 'cart':
				return cartLinks( a.cart );
			case 'would_add':
				return h( 'p', { class: 'dev3sa-note', text: `→ add_to_cart: ${ a.quantity } × ${ a.product }` } );
		}
		return null;
	}

	function rating( m ) {
		if ( ! persist || ! m.id || ! [ 'ai', 'cache', 'rules', 'kb', 'fallback' ].includes( m.source ) ) {
			return null;
		}
		const wrap = h( 'div', { class: 'dev3sa-rate' } );
		const make = ( value, name, label ) => {
			const b = h( 'button', { type: 'button', class: 'dev3sa-rate__btn', 'aria-label': label, title: label, 'aria-pressed': m.rating === value ? 'true' : 'false' }, icon( name, 14 ) );
			b.addEventListener( 'click', () => {
				const next = m.rating === value ? 0 : value;
				m.rating = next;
				wrap.querySelectorAll( 'button' ).forEach( ( x ) => x.setAttribute( 'aria-pressed', 'false' ) );
				b.setAttribute( 'aria-pressed', next ? 'true' : 'false' );
				request( 'rate', { method: 'POST', data: { token: state.token, message_id: m.id, rating: next } } ).catch( () => {} );
			} );
			return b;
		};
		wrap.appendChild( make( 1, 'up', t.helpful || 'Helpful' ) );
		wrap.appendChild( make( -1, 'down', t.notHelpful || 'Not helpful' ) );
		return wrap;
	}

	function render( m, last = true ) {
		if ( m.role === 'user' ) {
			addRow( 'user', h( 'div', { class: 'dev3sa-msg dev3sa-msg--user' }, h( 'span', { class: 'dev3sa-sr', text: ( t.you || 'You' ) + ': ' } ), m.text ) );
			return;
		}
		const parts = [];
		if ( m.text ) {
			parts.push( h( 'div', { class: 'dev3sa-msg dev3sa-msg--' + ( m.role === 'agent' ? 'agent' : 'bot' ), html: ( m.role === 'agent' ? `<span class="dev3sa-msg__by">${ t.team || 'Store team' }</span>` : '' ) + markdown( m.text ) } ) );
		}
		if ( m.products && m.products.length ) {
			parts.push( h( 'div', { class: 'dev3sa-cards' + ( m.products.length === 1 ? ' dev3sa-cards--one' : '' ), role: 'list', 'aria-label': t.products || 'Products' }, m.products.map( ( p ) => h( 'div', { role: 'listitem' }, productCard( p ) ) ) ) );
		}
		( m.actions || [] ).forEach( ( a ) => {
			const el = action( a );
			if ( el ) {
				parts.push( el );
			}
		} );
		parts.push( rating( m ) );
		addRow( 'bot', ...parts );
		if ( last ) {
			starterChips( m.quick_replies || [] );
		}
	}

	function typing( on ) {
		const old = log.querySelector( '.dev3sa-typing' );
		if ( old ) {
			old.remove();
		}
		if ( on ) {
			addRow( 'bot dev3sa-typing', h( 'div', { class: 'dev3sa-msg dev3sa-msg--bot', 'aria-label': t.typing || 'Typing…' }, h( 'span', { class: 'dev3sa-dots', 'aria-hidden': 'true' }, h( 'i' ), h( 'i' ), h( 'i' ) ) ) );
		}
	}

	function errorText( err ) {
		if ( err && err.code === 'network' ) {
			return navigator.onLine === false ? t.offline : t.error;
		}
		if ( err && err.status === 429 ) {
			return t.busy;
		}
		return ( err && err.message ) || t.error;
	}

	function showError( err, retry ) {
		const row = addRow( 'bot', h( 'div', { class: 'dev3sa-msg dev3sa-msg--error', role: 'alert' }, h( 'span', { text: errorText( err ) } ), retry ? h( 'button', { type: 'button', class: 'dev3sa-link', text: t.retry || 'Retry', onClick: () => {
			row.remove();
			retry();
		} } ) : null ) );
	}

	function greet() {
		render( { role: 'bot', text: config.greeting, quick_replies: [] }, false );
		const list = [ ...( config.starters || [] ) ];
		if ( config.product && config.product.name ) {
			list.unshift( t.askProduct || 'Ask about this product' );
		}
		starterChips( list );
	}

	/* ---------- server calls ---------- */
	function remember( body ) {
		if ( body && body.token && body.token !== state.token ) {
			state.token = body.token;
			if ( persist ) {
				local.set( TOKEN_KEY, body.token );
			}
		}
	}

	function post( route, data ) {
		return request( route, { method: 'POST', data: { token: state.token, ...data } } ).then( ( body ) => {
			remember( body );
			( body.messages || [] ).forEach( ( m ) => render( m ) );
			return body;
		} );
	}

	function refreshFragments() {
		try {
			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
			}
			document.body.dispatchEvent( new window.Event( 'wc-blocks_added_to_cart', { bubbles: true } ) );
		} catch ( e ) {}
	}

	function addToCart( p, btn ) {
		const label = btn.querySelector( 'span' );
		const old = label ? label.textContent : '';
		btn.disabled = true;
		if ( label ) {
			label.textContent = t.adding || 'Adding…';
		}
		request( 'cart', { method: 'POST', data: { token: state.token, product_id: p.id, quantity: 1 } } )
			.then( ( body ) => {
				btn.closest( '.dev3sa-row' ).appendChild( addedNotice( { ...body, quantity: body.quantity || 1 } ) );
				scrollDown();
				refreshFragments();
				if ( label ) {
					label.textContent = old;
				}
				btn.disabled = false;
			} )
			.catch( ( err ) => {
				btn.disabled = false;
				if ( label ) {
					label.textContent = old;
				}
				showError( err );
			} );
	}

	function send( raw ) {
		let text = String( raw || '' ).trim();
		if ( ! text || state.busy ) {
			return Promise.resolve();
		}
		if ( config.product && text === ( t.askProduct || 'Ask about this product' ) ) {
			text = ( t.askAbout || 'Tell me more about %s' ).replace( '%s', config.product.name );
		}
		state.busy = true;
		state.started = true;
		input.value = '';
		autosize();
		chips.hidden = true;
		render( { role: 'user', text } );
		typing( true );
		sendBtn.disabled = true;
		const context = { url: window.location.href.slice( 0, 250 ), product_id: config.product ? config.product.id : 0 };
		return request( 'chat', { method: 'POST', data: { token: state.token, message: text, context, lang: config.lang } } )
			.then( ( body ) => {
				typing( false );
				remember( body );
				( body.messages || [] ).forEach( ( m ) => render( m ) );
			} )
			.catch( ( err ) => {
				typing( false );
				showError( err, () => send( text ) );
			} )
			.finally( () => {
				state.busy = false;
				sendBtn.disabled = false;
			} );
	}

	function load() {
		if ( state.loaded ) {
			return Promise.resolve();
		}
		state.loaded = true;
		if ( ! state.token ) {
			greet();
			return Promise.resolve();
		}
		log.setAttribute( 'aria-busy', 'true' );
		return request( 'history?token=' + encodeURIComponent( state.token ) )
			.then( ( body ) => {
				const msgs = body.messages || [];
				if ( ! body.token ) {
					state.token = '';
					local.del( TOKEN_KEY );
				}
				greet();
				msgs.forEach( ( m, i ) => render( m, i === msgs.length - 1 ) );
				if ( msgs.length ) {
					state.started = true;
				}
			} )
			.catch( () => greet() )
			.finally( () => log.removeAttribute( 'aria-busy' ) );
	}

	function reset() {
		state.token = '';
		state.started = false;
		if ( persist ) {
			local.del( TOKEN_KEY );
		}
		log.textContent = '';
		greet();
		input.focus();
	}

	/* ---------- composer ---------- */
	function autosize() {
		input.style.height = 'auto';
		input.style.height = Math.min( 120, input.scrollHeight || 0 ) + 'px';
	}
	input.addEventListener( 'input', autosize );
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' && ! e.shiftKey && ! e.isComposing ) {
			e.preventDefault();
			send( input.value );
		}
	} );

	/* ---------- phones: full-screen sheet, safe areas, keyboard ---------- */
	const vv = window.visualViewport;
	function fitViewport() {
		if ( ! isMobile() || ! state.open ) {
			panel.style.removeProperty( '--dev3sa-vvh' );
			panel.style.removeProperty( '--dev3sa-vvt' );
			return;
		}
		const height = vv ? vv.height : window.innerHeight;
		const top = vv ? vv.offsetTop : 0;
		panel.style.setProperty( '--dev3sa-vvh', Math.round( height ) + 'px' );
		panel.style.setProperty( '--dev3sa-vvt', Math.round( top ) + 'px' );
		// Keep the newest message visible above the keyboard.
		window.requestAnimationFrame( scrollDown );
	}
	if ( vv ) {
		vv.addEventListener( 'resize', fitViewport );
		vv.addEventListener( 'scroll', fitViewport );
	}
	window.addEventListener( 'orientationchange', fitViewport );

	function lockScroll( on ) {
		const html = document.documentElement;
		const body = document.body;
		if ( on ) {
			state.lockY = window.scrollY || 0;
			html.classList.add( 'dev3sa-locked' );
			body.style.top = -state.lockY + 'px';
		} else if ( html.classList.contains( 'dev3sa-locked' ) ) {
			html.classList.remove( 'dev3sa-locked' );
			body.style.top = '';
			window.scrollTo( 0, state.lockY );
		}
	}

	function onPop() {
		if ( state.open && state.pushed ) {
			state.pushed = false;
			close( true );
		}
	}
	window.addEventListener( 'popstate', onPop );

	function trap( e ) {
		if ( e.key === 'Escape' && floating ) {
			e.preventDefault();
			close();
			return;
		}
		if ( e.key !== 'Tab' || ! isMobile() ) {
			return;
		}
		const items = Array.from( panel.querySelectorAll( 'a[href],button:not([disabled]),textarea,input,[tabindex="0"]' ) ).filter( ( el ) => el.offsetParent !== null || el === document.activeElement );
		if ( ! items.length ) {
			return;
		}
		const first = items[ 0 ];
		const lastEl = items[ items.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			lastEl.focus();
		} else if ( ! e.shiftKey && document.activeElement === lastEl ) {
			e.preventDefault();
			first.focus();
		}
	}
	panel.addEventListener( 'keydown', trap );

	/* ---------- open / close ---------- */
	function open( message ) {
		if ( ! floating ) {
			load();
			if ( message ) {
				send( message );
			}
			return;
		}
		if ( ! state.open ) {
			state.open = true;
			state.lastFocus = document.activeElement;
			panel.hidden = false;
			root.classList.add( 'is-open' );
			launcher.setAttribute( 'aria-expanded', 'true' );
			const dot = launcher.querySelector( '.dev3sa-unread' );
			if ( dot ) {
				dot.hidden = true;
			}
			if ( bubble ) {
				bubble.remove();
				bubble = null;
			}
			if ( isMobile() ) {
				panel.setAttribute( 'aria-modal', 'true' );
				lockScroll( true );
				fitViewport();
				try {
					window.history.pushState( { dev3sa: true }, '' );
					state.pushed = true;
				} catch ( e ) {}
			} else {
				panel.removeAttribute( 'aria-modal' );
			}
			if ( persist ) {
				session.set( OPEN_KEY, '1' );
			}
		}
		load().then( () => {
			scrollDown();
			if ( message ) {
				send( message );
			}
		} );
		// On touch phones focusing the field would cover the starters with the keyboard.
		window.setTimeout( () => ( isMobile() ? panel.querySelector( '.dev3sa-close' ) : input ).focus(), 30 );
	}

	function close( fromHistory = false ) {
		if ( ! floating || ! state.open ) {
			return;
		}
		state.open = false;
		panel.hidden = true;
		root.classList.remove( 'is-open' );
		launcher.setAttribute( 'aria-expanded', 'false' );
		lockScroll( false );
		fitViewport();
		if ( state.pushed && ! fromHistory ) {
			state.pushed = false;
			try {
				window.history.back();
			} catch ( e ) {}
		}
		if ( persist ) {
			session.del( OPEN_KEY );
		}
		const back = state.lastFocus && document.body.contains( state.lastFocus ) && state.lastFocus !== document.body ? state.lastFocus : launcher;
		back.focus();
	}

	function toggle() {
		return state.open ? close() : open();
	}

	/* ---------- teaser bubble, auto open, restore ---------- */
	if ( floating ) {
		if ( persist && session.get( OPEN_KEY ) && ! mq.matches && ! document.querySelector( '.dev3sa-chat--inline' ) ) {
			window.setTimeout( () => open(), 0 );
		} else {
			if ( config.bubble && config.greeting && ! session.get( 'dev3sa_bubble' ) ) {
				window.setTimeout( () => {
					if ( state.open || ! document.body.contains( root ) ) {
						return;
					}
					bubble = h(
						'div',
						{ class: 'dev3sa-bubble', role: 'status' },
						h( 'button', { type: 'button', class: 'dev3sa-bubble__text', onClick: () => open() }, config.greeting.length > 110 ? config.greeting.slice( 0, 108 ) + '…' : config.greeting ),
						h( 'button', { type: 'button', class: 'dev3sa-bubble__close', 'aria-label': t.close || 'Close', onClick: () => {
							session.set( 'dev3sa_bubble', '1' );
							bubble.remove();
							bubble = null;
						} }, icon( 'close', 14 ) )
					);
					root.appendChild( bubble );
					const dot = launcher.querySelector( '.dev3sa-unread' );
					if ( dot ) {
						dot.hidden = false;
					}
				}, 2500 );
			}
			if ( config.autoOpen > 0 && persist && ! local.get( 'dev3sa_auto' ) ) {
				window.setTimeout( () => {
					if ( ! state.open ) {
						local.set( 'dev3sa_auto', '1' );
						open();
					}
				}, config.autoOpen * 1000 );
			}
		}
	} else {
		load();
	}

	return {
		open,
		close,
		toggle,
		send,
		isOpen: () => state.open,
		root,
		destroy() {
			window.removeEventListener( 'popstate', onPop );
			lockScroll( false );
			root.remove();
		},
	};
}
