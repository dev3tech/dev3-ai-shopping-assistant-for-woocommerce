/**
 * Storefront chat (vanilla JS): rendering, safety, cart/order flows, phone sheet behaviour.
 */
import { createChat } from '../../src/storefront/chat';
import { escapeHtml, joinUrl, markdown, onColor } from '../../src/storefront/dom';
import { init } from '../../src/storefront/index';

const tick = () => new Promise( ( r ) => setTimeout( r, 0 ) );
const config = ( extra = {} ) => ( {
	rest: '/wp-json/dev3sa/v1/public/',
	floating: true,
	name: 'Shop helper',
	subtitle: 'Ask me',
	accent: '#4f46e5',
	position: 'right',
	launcher: 'label',
	label: 'Chat with us',
	greeting: 'Hi there!',
	bubble: false,
	starters: [ 'Track my order', 'Show me best sellers' ],
	placeholder: 'Ask…',
	consent: 'Chats are stored.',
	privacy: '/privacy',
	online: true,
	i18n: { send: 'Send', added: 'Added to your cart', undo: 'Undo', findOrder: 'Find my order', required: 'Please fill in this field.', badEmail: 'Enter a valid email address.', askProduct: 'Ask about this product', askAbout: 'Tell me more about %s' },
	...extra,
} );
const card = { id: 7, name: 'Sage mug', url: '/p/mug', image: '/m.jpg', price_html: '<span>$24.00</span>', rating: 4.5, reviews: 3, in_stock: true, stock_label: 'In stock', can_add: true, cta: 'Add to cart', cta_url: '/p/mug' };

function setMobile( on ) {
	window.matchMedia = jest.fn( () => ( { matches: on, addEventListener() {}, removeEventListener() {} } ) );
}

beforeEach( () => {
	document.body.innerHTML = '';
	document.documentElement.className = '';
	window.localStorage.clear();
	window.sessionStorage.clear();
	setMobile( false );
	window.scrollTo = jest.fn();
	delete window.dev3saChatApi;
} );

describe( 'helpers', () => {
	test( 'markdown escapes HTML and renders bold, lists and safe links', () => {
		const html = markdown( 'Hi <script>x</script> **bold**\n- one\n- two\nSee [the shop](https://shop.test/a) or javascript:alert(1)' );
		expect( html ).not.toContain( '<script>' );
		expect( html ).toContain( '&lt;script&gt;' );
		expect( html ).toContain( '<strong>bold</strong>' );
		expect( html ).toContain( '<ul><li>one</li><li>two</li></ul>' );
		expect( html ).toContain( '<a href="https://shop.test/a" target="_blank" rel="noopener nofollow">the shop</a>' );
		expect( html ).not.toContain( 'href="javascript' );
	} );
	test( 'markdown does not turn [x](javascript:) into a link', () => {
		expect( markdown( '[click](javascript:alert(1))' ) ).not.toContain( '<a' );
	} );
	test( 'escapeHtml and joinUrl', () => {
		expect( escapeHtml( '"a"&<b>' ) ).toBe( '&quot;a&quot;&amp;&lt;b&gt;' );
		expect( joinUrl( '/wp-json/x/', 'history?token=1' ) ).toBe( '/wp-json/x/history?token=1' );
		expect( joinUrl( '/?rest_route=/x/', 'history?token=1' ) ).toBe( '/?rest_route=/x/history&token=1' );
	} );
	test( 'onColor picks readable text for the accent', () => {
		expect( onColor( '#4f46e5' ) ).toBe( '#fff' );
		expect( onColor( '#fde047' ) ).toBe( '#111827' );
		expect( onColor( 'not-a-colour' ) ).toBe( '#fff' );
	} );
} );

describe( 'inline chat', () => {
	test( 'shows greeting, starters, consent and privacy link', async () => {
		const request = jest.fn();
		createChat( { config: config( { floating: false } ), request, mount: document.body, mode: 'inline' } );
		await tick();
		expect( document.querySelector( '.dev3sa-msg--bot' ).textContent ).toContain( 'Hi there!' );
		expect( Array.from( document.querySelectorAll( '.dev3sa-chip' ) ).map( ( b ) => b.textContent ) ).toEqual( [ 'Track my order', 'Show me best sellers' ] );
		expect( document.querySelector( '.dev3sa-consent a' ).getAttribute( 'href' ) ).toBe( '/privacy' );
		expect( request ).not.toHaveBeenCalled();
	} );

	test( 'sending renders the user message, a product card and stores the token', async () => {
		const request = jest.fn( () => Promise.resolve( { token: 'a'.repeat( 32 ), messages: [ { id: 5, role: 'bot', text: 'Try this', products: [ card ], actions: [], quick_replies: [ 'Cheaper?' ], source: 'ai' } ] } ) );
		const chat = createChat( { config: config( { floating: false } ), request, mount: document.body, mode: 'inline' } );
		await chat.send( 'a mug' );
		expect( request ).toHaveBeenCalledWith( 'chat', expect.objectContaining( { method: 'POST', data: expect.objectContaining( { message: 'a mug', token: '' } ) } ) );
		expect( document.querySelector( '.dev3sa-msg--user' ).textContent ).toContain( 'a mug' );
		expect( document.querySelector( '.dev3sa-card__name' ).textContent ).toBe( 'Sage mug' );
		expect( document.querySelector( '.dev3sa-chip' ).textContent ).toBe( 'Cheaper?' );
		expect( window.localStorage.getItem( 'dev3sa_token' ) ).toBe( 'a'.repeat( 32 ) );
		expect( document.querySelectorAll( '.dev3sa-rate__btn' ) ).toHaveLength( 2 );
	} );

	test( 'add to cart posts the product and shows the confirmation with undo', async () => {
		const request = jest.fn( ( route ) =>
			route === 'chat'
				? Promise.resolve( { token: 'b'.repeat( 32 ), messages: [ { id: 1, role: 'bot', text: 'Here', products: [ card ], actions: [], source: 'rules' } ] } )
				: Promise.resolve( { key: 'abc123', product: 'Sage mug', quantity: 1, cart: { cart_url: '/cart', checkout_url: '/checkout', count: 1 } } )
		);
		const chat = createChat( { config: config( { floating: false } ), request, mount: document.body, mode: 'inline' } );
		await chat.send( 'mug' );
		document.querySelector( '.dev3sa-card .dev3sa-btn--primary' ).click();
		await tick();
		await tick();
		expect( request ).toHaveBeenLastCalledWith( 'cart', { method: 'POST', data: { token: 'b'.repeat( 32 ), product_id: 7, quantity: 1 } } );
		const note = document.querySelector( '.dev3sa-note--ok' );
		expect( note.textContent ).toContain( 'Added to your cart' );
		expect( note.querySelector( 'a[href="/checkout"]' ) ).not.toBeNull();
		expect( Array.from( note.querySelectorAll( 'button' ) ).map( ( b ) => b.textContent ) ).toContain( 'Undo' );
	} );

	test( 'order form validates the email before calling the server', async () => {
		const request = jest.fn( ( route ) =>
			route === 'chat' ? Promise.resolve( { token: 'c'.repeat( 32 ), messages: [ { id: 2, role: 'bot', text: 'Enter it', actions: [ { type: 'order_form', number: '1234' } ], source: 'rules' } ] } ) : Promise.resolve( { messages: [ { id: 3, role: 'bot', text: 'Shipped', actions: [ { type: 'order', order: { number: '1234', status: 'completed', status_label: 'Completed', steps: [ { label: 'Order placed', done: true } ], items: [ { name: 'Mug', qty: 2 } ], total_html: '$48', tracking: [ { provider: 'UPS', number: '1Z', url: '' } ] } } ] } ] } )
		);
		const chat = createChat( { config: config( { floating: false } ), request, mount: document.body, mode: 'inline' } );
		await chat.send( 'where is my order' );
		const form = document.querySelector( '.dev3sa-form' );
		expect( form.querySelector( 'input[name="order"]' ).value ).toBe( '1234' );
		form.querySelector( 'input[name="email"]' ).value = 'not-an-email';
		form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
		expect( form.querySelector( '.dev3sa-form-error' ).textContent ).toBe( 'Enter a valid email address.' );
		expect( request ).toHaveBeenCalledTimes( 1 );
		form.querySelector( 'input[name="email"]' ).value = 'jo@example.com';
		form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
		await tick();
		await tick();
		expect( request ).toHaveBeenLastCalledWith( 'order', { method: 'POST', data: { token: 'c'.repeat( 32 ), order: '1234', email: 'jo@example.com' } } );
		expect( document.querySelector( '.dev3sa-order' ).textContent ).toContain( 'Completed' );
		expect( document.querySelector( '.dev3sa-order code' ).textContent ).toBe( '1Z' );
	} );

	test( 'server errors show a retry button and never throw', async () => {
		const err = Object.assign( new Error( 'Too many requests. Please wait a moment.' ), { status: 429 } );
		const request = jest.fn().mockRejectedValueOnce( err ).mockResolvedValueOnce( { messages: [ { id: 9, role: 'bot', text: 'ok', source: 'rules' } ] } );
		const chat = createChat( { config: config( { floating: false, i18n: { busy: 'Slow down' } } ), request, mount: document.body, mode: 'inline' } );
		await chat.send( 'hello?' );
		const alert = document.querySelector( '.dev3sa-msg--error' );
		expect( alert.textContent ).toContain( 'Slow down' );
		alert.querySelector( 'button' ).click();
		await tick();
		await tick();
		expect( request ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'restores history for a saved token', async () => {
		window.localStorage.setItem( 'dev3sa_token', 'd'.repeat( 32 ) );
		const request = jest.fn( () => Promise.resolve( { token: 'd'.repeat( 32 ), messages: [ { id: 1, role: 'user', text: 'old question' }, { id: 2, role: 'bot', text: 'old answer', source: 'ai' } ] } ) );
		createChat( { config: config( { floating: false } ), request, mount: document.body, mode: 'inline' } );
		await tick();
		await tick();
		expect( request ).toHaveBeenCalledWith( 'history?token=' + 'd'.repeat( 32 ) );
		expect( document.body.textContent ).toContain( 'old question' );
		expect( document.body.textContent ).toContain( 'old answer' );
	} );

	test( '"Ask about this product" sends a message naming the product', async () => {
		const request = jest.fn( () => Promise.resolve( { messages: [] } ) );
		createChat( { config: config( { floating: false, product: { id: 7, name: 'Sage mug' } } ), request, mount: document.body, mode: 'inline' } );
		await tick();
		const chip = document.querySelector( '.dev3sa-chip' );
		expect( chip.textContent ).toBe( 'Ask about this product' );
		chip.click();
		await tick();
		expect( request ).toHaveBeenCalledWith( 'chat', expect.objectContaining( { data: expect.objectContaining( { message: 'Tell me more about Sage mug', context: expect.objectContaining( { product_id: 7 } ) } ) } ) );
	} );
} );

describe( 'floating chat', () => {
	test( 'launcher toggles the panel, Escape closes and focus returns', async () => {
		const chat = createChat( { config: config(), request: jest.fn( () => Promise.resolve( {} ) ), mount: document.body, mode: 'floating' } );
		const launcher = document.querySelector( '.dev3sa-launcher' );
		const panel = document.querySelector( '.dev3sa-panel' );
		expect( panel.hidden ).toBe( true );
		launcher.click();
		expect( chat.isOpen() ).toBe( true );
		expect( panel.hidden ).toBe( false );
		expect( launcher.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( panel.getAttribute( 'aria-modal' ) ).toBeNull();
		panel.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		expect( chat.isOpen() ).toBe( false );
		expect( document.activeElement ).toBe( launcher );
		expect( window.sessionStorage.getItem( 'dev3sa_open' ) ).toBeNull();
	} );

	test( 'on phones it opens as a modal full-screen sheet with scroll lock and a history entry', () => {
		setMobile( true );
		const push = jest.spyOn( window.history, 'pushState' );
		const chat = createChat( { config: config(), request: jest.fn( () => Promise.resolve( {} ) ), mount: document.body, mode: 'floating' } );
		chat.open();
		const panel = document.querySelector( '.dev3sa-panel' );
		expect( panel.getAttribute( 'aria-modal' ) ).toBe( 'true' );
		expect( document.documentElement.classList.contains( 'dev3sa-locked' ) ).toBe( true );
		expect( panel.style.getPropertyValue( '--dev3sa-vvh' ) ).toMatch( /px$/ );
		expect( push ).toHaveBeenCalled();
		window.dispatchEvent( new window.PopStateEvent( 'popstate', { state: null } ) );
		expect( chat.isOpen() ).toBe( false );
		expect( document.documentElement.classList.contains( 'dev3sa-locked' ) ).toBe( false );
		push.mockRestore();
	} );

	test( 'icon-only launcher has an accessible name; textarea Enter sends', async () => {
		const request = jest.fn( () => Promise.resolve( { messages: [] } ) );
		const chat = createChat( { config: config( { launcher: 'icon', i18n: { open: 'Open chat' } } ), request, mount: document.body, mode: 'floating' } );
		expect( document.querySelector( '.dev3sa-launcher' ).getAttribute( 'aria-label' ) ).toBe( 'Open chat' );
		chat.open();
		const input = document.querySelector( '.dev3sa-input' );
		input.value = 'hello';
		input.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) );
		await tick();
		expect( request ).toHaveBeenCalledWith( 'chat', expect.anything() );
	} );
} );

describe( 'init', () => {
	test( 'mounts inline chats and wires [data-dev3sa-open] buttons', () => {
		document.body.innerHTML = '<div data-dev3sa-inline></div><button data-dev3sa-open data-dev3sa-message="Hi">Chat</button>';
		window.fetch = jest.fn( () => new Promise( () => {} ) );
		window.dev3saChat = config();
		const api = init( window );
		expect( document.querySelectorAll( '.dev3sa-chat--inline' ) ).toHaveLength( 1 );
		expect( document.querySelectorAll( '.dev3sa-chat--floating' ) ).toHaveLength( 1 );
		document.querySelector( '[data-dev3sa-open]' ).click();
		expect( api.isOpen() ).toBe( true );
		expect( init( window ) ).toBe( api );
	} );
} );
