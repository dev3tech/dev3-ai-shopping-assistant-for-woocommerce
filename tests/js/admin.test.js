/**
 * Admin screens: dashboard, knowledge drafts (review before save), widget settings, test console.
 */
import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import Dashboard from '../../src/admin/Dashboard';
import Knowledge from '../../src/admin/Knowledge';
import WidgetTab from '../../src/admin/WidgetTab';
import TestConsole from '../../src/admin/TestConsole';
import Conversations from '../../src/admin/Conversations';
import { previewConfig } from '../../src/admin/Preview';
import { change, click, render } from './render';

const STATS = {
	stats: { days: 30, conversations: 12, messages: 80, ai_messages: 30, ai_rate: 75, answered_rate: 91.5, unanswered: 2, carts: 5, orders: 2, revenue: 120.5, satisfaction: 80, ratings: 5, needs_reply: 1, series: [ { day: '2026-10-24', conversations: 3, carts: 1 }, { day: '2026-10-25', conversations: 9, carts: 4 } ] },
	top: [ { question: 'do you ship to canada', count: 4 } ],
	unanswered: [ { question: 'do you sell gift cards', count: 2 } ],
	index: { products: 10, products_indexed: 0, kb: 3, kb_indexed: 0, job: null, ai: false },
	currency: 'USD',
	checklist: { widget: true, knowledge: true, ai: false, index: false },
};
const SETTINGS = { api_key_set: false, widget_enabled: true, bot_name: 'Helper', subtitle: 'Sub', avatar_id: 0, accent_color: '#4f46e5', position: 'right', offset_x: 20, offset_y: 20, launcher_style: 'label', launcher_label: 'Chat', greeting: 'Hi', starters: [ 'Track my order' ], placeholder: 'Ask', away_message: 'Away', greeting_bubble: true, auto_open: 0, show_on: 'all', pages: [], exclude_pages: [], hide_on_checkout: true, audience: 'all', product_search: true, add_to_cart: true, order_tracking: true, handoff: true, max_products: 4, hide_out_of_stock: false, handoff_email: '', whatsapp: '', phone: '', hours_enabled: false, hours_start: '09:00', hours_end: '17:00', hours_days: [ 1, 2 ], ai_chat: true, ai_tone: 'friendly', answer_length: 'short', ai_instructions: '', ai_auto_add: true, max_ai_turns: 40, chat_rate_limit: 12, auto_index: true, store_transcripts: true, retention_days: 90, show_consent: true, consent_text: 'Stored' };

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;
const route = ( map ) =>
	apiFetch.mockImplementation( ( { url, method = 'GET', data } ) => {
		const path = url.replace( /^.*dev3sa\/v1\//, '' );
		const key = Object.keys( map ).find( ( k ) => ( k.includes( ' ' ) ? k === `${ method } ${ path.split( '?' )[ 0 ] }` : method === 'GET' && path.split( '?' )[ 0 ] === k ) );
		if ( ! key ) {
			return new Promise( () => {} );
		}
		const v = map[ key ];
		return Promise.resolve( typeof v === 'function' ? v( data, path ) : v );
	} );

afterEach( () => {
	apiFetch.mockReset();
	document.body.innerHTML = '';
} );

test( 'dashboard shows KPIs, questions and the setup checklist', async () => {
	route( { stats: STATS } );
	const view = await render( wrap( <Dashboard settings={ SETTINGS } navigate={ jest.fn() } onUsage={ jest.fn() } /> ) );
	await view.flush();
	expect( view.getByText( '91.5%' ) ).toBeTruthy();
	expect( view.queryByText( 'do you ship to canada' ) ).toBeTruthy();
	expect( view.queryByText( 'do you sell gift cards' ) ).toBeTruthy();
	expect( view.queryByText( 'Connect DEV3 AI' ) ).toBeTruthy();
	expect( view.$$( '.dev3sa-daychart__col' ) ).toHaveLength( 2 );
	expect( view.queryByText( '2 to do' ) ).toBeTruthy();
	view.unmount();
} );

test( 'knowledge: AI drafts are saved only when accepted', async () => {
	const saved = jest.fn( () => ( { id: 9 } ) );
	route( {
		knowledge: { entries: [ { id: 1, title: 'Shipping?', content: 'We ship.', type: 'shipping', keywords: '', status: 'publish', source: 'import' } ], facts: 2 },
		index: STATS.index,
		'POST ai/knowledge': { drafts: [ { key: 'd0', title: 'Can I return?', content: 'Yes within 30 days.', type: 'returns', keywords: 'returns', confirm: false } ], meta: { model: 'gemini-2.5-flash', tokens: 100 } },
		'POST knowledge': saved,
	} );
	const view = await render( wrap( <Knowledge onUsage={ jest.fn() } /> ) );
	await view.flush();
	expect( view.queryByText( 'Shipping?' ) ).toBeTruthy();
	await click( view.$( '.dev3sa-ui-card.is-ai button.dev3sa-ui-btn--ai, .dev3sa-ui-card button[class*="ai"]' ) || view.getByText( 'Generate with DEV3 AI' ) );
	await view.flush();
	expect( saved ).not.toHaveBeenCalled();
	await click( view.getByText( 'Add to knowledge base' ) );
	await view.flush();
	expect( saved ).toHaveBeenCalledWith( expect.objectContaining( { title: 'Can I return?', type: 'returns', source: 'ai' } ), expect.anything() );
	view.unmount();
} );

test( 'widget settings save starters as a list', async () => {
	const save = jest.fn( ( v ) => Promise.resolve( { ...SETTINGS, ...v } ) );
	route( { lookup: [ { value: 3, label: 'About' } ] } );
	const view = await render( wrap( <WidgetTab settings={ SETTINGS } save={ save } onUsage={ jest.fn() } /> ) );
	await view.flush();
	const name = view.$$( 'input' ).find( ( i ) => i.value === 'Helper' );
	await change( name, 'Mia' );
	await click( view.getByText( 'Messages' ) );
	const starters = view.$$( 'textarea' ).find( ( t ) => t.value === 'Track my order' );
	await change( starters, 'Track my order\nBest sellers' );
	await click( view.getByText( 'Save changes' ) );
	await view.flush();
	expect( save ).toHaveBeenCalledWith( expect.objectContaining( { bot_name: 'Mia', starters: [ 'Track my order', 'Best sellers' ] } ) );
	// The live preview renders the storefront chat with the unsaved name.
	expect( view.$( '.dev3sa-preview .dev3sa-title' ).textContent ).toBe( 'Mia' );
	view.unmount();
} );

test( 'preview config splits starters', () => {
	expect( previewConfig( { ...SETTINGS, starters: 'a\nb\n' } ).starters ).toEqual( [ 'a', 'b' ] );
} );

test( 'test console shows the answer source and debug details', async () => {
	route( {
		lookup: [],
		'POST test-chat': { text: 'We ship worldwide.', intent: 'policy', source: 'kb', products: [], actions: [], quick_replies: [], unanswered: false, debug: { reason: 'ai_unavailable', ms: 12 } },
	} );
	const view = await render( wrap( <TestConsole onUsage={ jest.fn() } /> ) );
	await view.flush();
	await click( view.getByText( 'What is your return policy?' ) );
	await view.flush();
	expect( view.queryByText( 'We ship worldwide.' ) ).toBeTruthy();
	expect( view.$$( '.dev3sa-debug code' ).map( ( c ) => c.textContent ) ).toContain( 'ai_unavailable' );
	view.unmount();
} );

test( 'conversations list loads with the status filter from the URL', async () => {
	const list = jest.fn( () => ( { rows: [ { id: 4, first_question: 'need help', name: 'Jo', email: 'jo@x.test', status: 'needs_reply', messages: 3, carts: 1, order_id: 0, rating_up: 0, rating_down: 0, updated_at: '2026-10-25 10:00:00' } ], total: 1 } ) );
	route( { conversations: ( d, path ) => list( path ) } );
	const view = await render( wrap( <Conversations params={ [ 'needs_reply' ] } navigate={ jest.fn() } onUsage={ jest.fn() } /> ) );
	await view.flush();
	expect( list.mock.calls[ 0 ][ 0 ] ).toContain( 'status=needs_reply' );
	expect( view.queryByText( 'need help' ) ).toBeTruthy();
	view.unmount();
} );
