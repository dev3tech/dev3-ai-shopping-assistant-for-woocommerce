import { createChat } from './chat';
import { createTransport } from './transport';

/**
 * Boots the storefront chat: one floating chat (when enabled for the page) and any inline chats
 * from the block / [dev3sa_chat]. Buttons with [data-dev3sa-open] or links to #dev3sa-chat open it.
 *
 * Public API: window.dev3saChatApi.open( 'optional message' ), .close(), .toggle().
 *
 * @param {Window} win Window.
 * @return {Object|null} API of the floating chat (or the first inline chat).
 */
export function init( win ) {
	const config = win.dev3saChat;
	if ( ! config || ! config.rest || win.dev3saChatApi ) {
		return win.dev3saChatApi || null;
	}
	const doc = win.document;
	const request = createTransport( config );
	const chats = [];

	doc.querySelectorAll( '[data-dev3sa-inline]' ).forEach( ( node ) => {
		node.textContent = '';
		chats.push( createChat( { config, request, mount: node, mode: 'inline' } ) );
	} );

	let floating = null;
	if ( config.floating ) {
		floating = createChat( { config, request, mount: doc.body, mode: 'floating' } );
	}
	const api = floating || chats[ 0 ] || null;

	doc.addEventListener( 'click', ( e ) => {
		const trigger = e.target.closest && e.target.closest( '[data-dev3sa-open], a[href="#dev3sa-chat"]' );
		if ( ! trigger || ! api ) {
			return;
		}
		e.preventDefault();
		if ( floating ) {
			floating.open( trigger.getAttribute( 'data-dev3sa-message' ) || undefined );
		} else {
			api.root.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			const field = api.root.querySelector( 'textarea' );
			if ( field ) {
				field.focus();
			}
		}
	} );

	if ( floating && /(^|#)dev3sa-chat$/.test( win.location.hash ) ) {
		floating.open();
	}
	win.dev3saChatApi = api;
	return api;
}
