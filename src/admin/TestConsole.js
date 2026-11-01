import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, EmptyState, ErrorNotice, Inline, PageHeader, Select, Textarea } from '../core3/ui';
import { markdown } from '../storefront/dom';

const SAMPLES = [ __( 'Do you have a gift under 40?', 'dev3-ai-shopping-assistant-for-woocommerce' ), __( 'What is your return policy?', 'dev3-ai-shopping-assistant-for-woocommerce' ), __( 'Track my order', 'dev3-ai-shopping-assistant-for-woocommerce' ), __( 'Add the cheapest mug to my cart', 'dev3-ai-shopping-assistant-for-woocommerce' ) ];
const SOURCE = { ai: __( 'DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ), cache: __( 'DEV3 AI · cached', 'dev3-ai-shopping-assistant-for-woocommerce' ), rules: __( 'Guided rules', 'dev3-ai-shopping-assistant-for-woocommerce' ), kb: __( 'Knowledge base', 'dev3-ai-shopping-assistant-for-woocommerce' ), fallback: __( 'Fallback (AI error)', 'dev3-ai-shopping-assistant-for-woocommerce' ) };

/**
 * Admin test console: chat as a shopper and see why the assistant answered as it did.
 * Nothing is stored and nothing is added to a cart.
 *
 * @param {Object} props Props (onUsage).
 * @return {Element} Screen.
 */
export default function TestConsole( { onUsage } ) {
	const [ turns, setTurns ] = useState( [] );
	const [ text, setText ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ products, setProducts ] = useState( [] );
	const [ productId, setProductId ] = useState( '' );
	const [ selected, setSelected ] = useState( null );
	const end = useRef( null );

	useEffect( () => {
		request( 'lookup?type=products' )
			.then( setProducts )
			.catch( () => {} );
	}, [] );
	useEffect( () => {
		if ( end.current && end.current.scrollIntoView ) {
			end.current.scrollIntoView( { block: 'nearest' } );
		}
	}, [ turns ] );

	const send = ( message ) => {
		const msg = String( message || '' ).trim();
		if ( ! msg || busy ) {
			return;
		}
		setBusy( true );
		setError( null );
		setText( '' );
		const history = turns.map( ( t ) => ( { role: t.role, text: t.text } ) );
		const last = [ ...turns ].reverse().find( ( t ) => t.role === 'bot' && t.products && t.products.length );
		setTurns( ( t ) => [ ...t, { role: 'user', text: msg } ] );
		request( 'test-chat', { method: 'POST', data: { message: msg, history, product_id: productId || 0, recent_products: last ? last.products.map( ( p ) => p.id ) : [] } } )
			.then( ( reply ) => {
				setTurns( ( t ) => [ ...t, { role: 'bot', ...reply } ] );
				setSelected( reply );
			} )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};

	return (
		<>
			<PageHeader
				title={ __( 'Test console', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				description={ __( 'Chat as a shopper and see the intent, sources and tokens behind every answer. Nothing is stored and no cart is changed.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				actions={ turns.length > 0 && <Button variant="ghost" icon="refresh" onClick={ () => setTurns( [] ) }>{ __( 'Clear', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button> }
			/>
			<div className="dev3sa-console">
				<Card className="dev3sa-console__chat">
					<Select
						label={ __( 'Pretend the shopper is on this product page', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						value={ String( productId ) }
						onChange={ setProductId }
						options={ [ { value: '', label: __( 'No product page', 'dev3-ai-shopping-assistant-for-woocommerce' ) }, ...products.map( ( p ) => ( { value: String( p.value ), label: p.label } ) ) ] }
					/>
					<div className="dev3sa-console__log" aria-live="polite">
						{ ! turns.length && (
							<EmptyState
								compact
								icon="bolt"
								title={ __( 'Ask anything a shopper would', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
								action={
									<Inline>
										{ SAMPLES.map( ( s ) => (
											<Button key={ s } size="sm" onClick={ () => send( s ) }>
												{ s }
											</Button>
										) ) }
									</Inline>
								}
							/>
						) }
						{ turns.map( ( t, i ) =>
							t.role === 'user' ? (
								<div key={ i } className="dev3sa-console__msg is-user">
									{ t.text }
								</div>
							) : (
								<button type="button" key={ i } className={ 'dev3sa-console__msg is-bot' + ( selected === t ? ' is-selected' : '' ) } onClick={ () => setSelected( t ) }>
									<span className="dev3sa-console__badges">
										<Badge tone={ t.source === 'ai' || t.source === 'cache' ? 'brand' : t.source === 'fallback' ? 'warning' : 'neutral' }>{ SOURCE[ t.source ] || t.source }</Badge>
										<Badge>{ t.intent }</Badge>
										{ t.unanswered && <Badge tone="warning">{ __( 'unanswered', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> }
									</span>
									<span className="dev3sa-console__text" dangerouslySetInnerHTML={ { __html: markdown( t.text ) } } />
									{ t.products && t.products.length > 0 && (
										<span className="dev3sa-console__products">
											{ t.products.map( ( p ) => (
												<span key={ p.id } className="dev3sa-console__product">
													<img src={ p.image } alt="" width={ 32 } height={ 32 } />
													{ p.name }
												</span>
											) ) }
										</span>
									) }
									{ ( t.actions || [] ).map( ( a, j ) => (
										<span key={ j } className="dev3sa-console__action">
											{ a.type === 'order' ? sprintf( /* translators: 1: order, 2: status */ __( 'Order #%1$s · %2$s', 'dev3-ai-shopping-assistant-for-woocommerce' ), a.order.number, a.order.status_label ) : a.type === 'would_add' ? sprintf( /* translators: 1: qty, 2: product */ __( 'Would add %1$d × %2$s to the cart', 'dev3-ai-shopping-assistant-for-woocommerce' ), a.quantity, a.product ) : a.type.replace( '_', ' ' ) }
										</span>
									) ) }
								</button>
							)
						) }
						{ busy && <div className="dev3sa-console__msg is-bot is-typing">{ __( 'Thinking…', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</div> }
						<div ref={ end } />
					</div>
					<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
					<form
						className="dev3sa-console__composer"
						onSubmit={ ( e ) => {
							e.preventDefault();
							send( text );
						} }
					>
						<Textarea
							label={ __( 'Message', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
							hideLabel
							rows={ 2 }
							value={ text }
							onChange={ setText }
							placeholder={ __( 'Type as a shopper…', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' && ! e.shiftKey ) {
									e.preventDefault();
									send( text );
								}
							} }
						/>
						<Button type="submit" variant="primary" loading={ busy } disabled={ ! text.trim() }>
							{ __( 'Send', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						</Button>
					</form>
				</Card>
				<Card className="dev3sa-console__debug" title={ __( 'Why this answer', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
					{ ! selected && <p className="dev3sa-muted">{ __( 'Send a message, then select an answer to see its details.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</p> }
					{ selected && (
						<dl className="dev3sa-debug">
							<dt>{ __( 'Answered by', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</dt>
							<dd>{ SOURCE[ selected.source ] || selected.source }</dd>
							<dt>{ __( 'Intent', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</dt>
							<dd>{ selected.intent }</dd>
							{ selected.debug && selected.debug.reason && (
								<>
									<dt>{ __( 'Why rules answered', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</dt>
									<dd>
										<code>{ selected.debug.reason }</code>
									</dd>
								</>
							) }
							{ selected.debug && selected.debug.model && (
								<>
									<dt>{ __( 'Model · tokens', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</dt>
									<dd>
										{ selected.debug.model } · { selected.debug.tokens }
										{ selected.debug.cached ? ` · ${ __( 'cached', 'dev3-ai-shopping-assistant-for-woocommerce' ) }` : '' }
									</dd>
								</>
							) }
							{ selected.debug && selected.debug.knowledge && selected.debug.knowledge.length > 0 && (
								<>
									<dt>{ __( 'Knowledge used', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</dt>
									<dd>{ selected.debug.knowledge.join( ' · ' ) }</dd>
								</>
							) }
							{ selected.debug && selected.debug.ms !== undefined && (
								<>
									<dt>{ __( 'Time', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</dt>
									<dd>{ selected.debug.ms } ms</dd>
								</>
							) }
							<dt>{ __( 'Raw details', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</dt>
							<dd>
								<pre className="dev3sa-pre">{ JSON.stringify( selected.debug, null, 2 ) }</pre>
							</dd>
						</dl>
					) }
				</Card>
			</div>
		</>
	);
}
