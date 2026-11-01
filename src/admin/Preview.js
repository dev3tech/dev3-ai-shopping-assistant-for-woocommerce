import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { createChat } from '../storefront/chat';
import { onColor } from '../storefront/dom';
import { strings } from './strings';

const IMG =
	'data:image/svg+xml,' +
	encodeURIComponent( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#eef2ff"/><rect x="130" y="80" width="140" height="150" rx="18" fill="#c7d2fe"/><circle cx="300" cy="150" r="34" fill="none" stroke="#c7d2fe" stroke-width="18"/></svg>' );

/**
 * Boot config for the preview from (unsaved) settings.
 *
 * @param {Object} s Settings.
 * @return {Object} Config.
 */
export function previewConfig( s ) {
	return {
		rest: '',
		floating: false,
		name: s.bot_name,
		subtitle: s.subtitle,
		avatar: s.avatar_url || '',
		accent: s.accent_color,
		position: s.position,
		launcher: s.launcher_style,
		label: s.launcher_label,
		greeting: s.greeting,
		starters: Array.isArray( s.starters ) ? s.starters : String( s.starters || '' ).split( '\n' ).filter( Boolean ),
		placeholder: s.placeholder,
		consent: s.show_consent ? s.consent_text : '',
		online: true,
		features: { cart: s.add_to_cart, orders: s.order_tracking, handoff: s.handoff },
		i18n: strings(),
	};
}

/**
 * Fake transport: shows how a product answer looks without calling the server.
 *
 * @return {Function} request.
 */
function fakeRequest() {
	return ( route ) => {
		if ( route.indexOf( 'chat' ) === 0 ) {
			return new Promise( ( resolve ) =>
				setTimeout(
					() =>
						resolve( {
							token: '',
							messages: [
								{
									id: 0,
									role: 'bot',
									text: __( 'This is a preview reply. On your store the assistant answers from your catalog, orders and knowledge base, like this:', 'dev3-ai-shopping-assistant-for-woocommerce' ),
									products: [
										{ id: 1, name: __( 'Stoneware mug, sage', 'dev3-ai-shopping-assistant-for-woocommerce' ), url: '#', image: IMG, price_html: '<span>$24.00</span>', rating: 4.6, reviews: 18, in_stock: true, stock_label: __( 'In stock', 'dev3-ai-shopping-assistant-for-woocommerce' ), can_add: false, cta: __( 'View', 'dev3-ai-shopping-assistant-for-woocommerce' ), cta_url: '#' },
										{ id: 2, name: __( 'Pour-over coffee set', 'dev3-ai-shopping-assistant-for-woocommerce' ), url: '#', image: IMG, price_html: '<del>$58.00</del> <ins>$46.00</ins>', on_sale: true, rating: 4.9, reviews: 7, in_stock: true, stock_label: __( 'In stock', 'dev3-ai-shopping-assistant-for-woocommerce' ), can_add: false, cta: __( 'View', 'dev3-ai-shopping-assistant-for-woocommerce' ), cta_url: '#' },
									],
									actions: [],
									quick_replies: [ __( 'Track my order', 'dev3-ai-shopping-assistant-for-woocommerce' ) ],
									source: 'preview',
								},
							],
						} ),
					500
				)
			);
		}
		return Promise.resolve( { messages: [] } );
	};
}

/**
 * Live, interactive preview of the storefront chat (desktop card or phone sheet).
 *
 * @param {Object} props          Props.
 * @param {Object} props.settings Current (unsaved) settings.
 * @param {string} props.device   desktop|phone.
 * @return {Element} Preview.
 */
export default function Preview( { settings, device = 'desktop' } ) {
	const ref = useRef( null );
	const key = JSON.stringify( previewConfig( settings ) );
	useEffect( () => {
		if ( ! ref.current ) {
			return undefined;
		}
		const chat = createChat( { config: JSON.parse( key ), request: fakeRequest(), mount: ref.current, mode: 'inline', persist: false } );
		return () => chat.destroy();
	}, [ key ] );
	return (
		<div className={ `dev3sa-preview is-${ device }` }>
			<div className="dev3sa-preview__screen" ref={ ref } style={ { '--dev3sa-inline-h': device === 'phone' ? '620px' : '600px' } } />
			<div className={ `dev3sa-preview__launcher is-${ settings.position }` } aria-hidden="true" style={ { background: settings.accent_color, color: onColor( settings.accent_color ) } }>
				<svg viewBox="0 0 24 24" width="20" height="20"><path d="M21 12c0 4.4-4 8-9 8-1.4 0-2.7-.3-3.9-.8L3 20l1.3-3.9C3.5 14.9 3 13.5 3 12c0-4.4 4-8 9-8s9 3.6 9 8z" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" /></svg>
				{ settings.launcher_style !== 'icon' && settings.launcher_label && <span>{ settings.launcher_label }</span> }
			</div>
		</div>
	);
}
