import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, Drawer, ErrorNotice, Inline, Modal, PageHeader, ResponsiveTable, Select, Skeleton, Stack, TextField, Textarea, formatMoney, useToast } from '../core3/ui';


export const STATUS = {
	open: { label: __( 'Open', 'dev3-ai-shopping-assistant-for-woocommerce' ), tone: 'info' },
	needs_reply: { label: __( 'Needs reply', 'dev3-ai-shopping-assistant-for-woocommerce' ), tone: 'warning' },
	resolved: { label: __( 'Resolved', 'dev3-ai-shopping-assistant-for-woocommerce' ), tone: 'success' },
};

const SOURCE = {
	ai: __( 'DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	cache: __( 'DEV3 AI (cached)', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	rules: __( 'Guided', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	kb: __( 'Knowledge base', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	fallback: __( 'Fallback', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	agent: __( 'Team reply', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	form: __( 'Form', 'dev3-ai-shopping-assistant-for-woocommerce' ),
};

function when( mysql ) {
	if ( ! mysql ) {
		return '';
	}
	const d = new Date( mysql.replace( ' ', 'T' ) + 'Z' );
	return isNaN( d ) ? mysql : d.toLocaleString( undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } );
}

/**
 * Conversation transcript + actions (drawer).
 *
 * @param {Object}   props         Props.
 * @param {number}   props.id      Conversation id.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onChange Reload the list.
 * @param {Function} props.onUsage Usage refresh.
 * @return {Element} Drawer.
 */
function Transcript( { id, onClose, onChange, onUsage } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ reply, setReply ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ summary, setSummary ] = useState( null );
	const [ summarising, setSummarising ] = useState( false );
	const [ confirm, setConfirm ] = useState( false );

	const load = () => request( `conversations/${ id }` ).then( setData ).catch( setError );
	useEffect( () => {
		setData( null );
		setSummary( null );
		load();
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const conv = data ? data.conversation : null;
	const setStatus = ( status ) =>
		request( `conversations/${ id }`, { method: 'POST', data: { status } } )
			.then( () => {
				load();
				onChange();
				toast.success( __( 'Status updated.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
			} )
			.catch( ( e ) => toast.error( e ) );
	const sendReply = () => {
		setSending( true );
		request( `conversations/${ id }/reply`, { method: 'POST', data: { reply, resolve: true } } )
			.then( ( next ) => {
				setData( next );
				setReply( '' );
				onChange();
				toast.success( __( 'Reply emailed to the shopper.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSending( false ) );
	};
	const summarise = () => {
		setSummarising( true );
		request( `conversations/${ id }/summary`, { method: 'POST' } )
			.then( setSummary )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => {
				setSummarising( false );
				onUsage();
			} );
	};
	const saveSummary = ( text ) =>
		request( `conversations/${ id }`, { method: 'POST', data: { summary: text, sentiment: summary.sentiment, ...( summary.needs_followup && conv.status === 'open' ? { status: 'needs_reply' } : {} ) } } )
			.then( () => {
				setSummary( null );
				load();
				onChange();
				toast.success( __( 'Summary saved.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
			} )
			.catch( ( e ) => toast.error( e ) );
	const remove = () =>
		request( `conversations/${ id }`, { method: 'DELETE' } )
			.then( () => {
				setConfirm( false );
				onChange();
				onClose();
				toast.success( __( 'Conversation deleted.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
			} )
			.catch( ( e ) => toast.error( e ) );

	return (
		<Drawer
			open
			onClose={ onClose }
			size="lg"
			title={ conv ? sprintf( /* translators: %d: id */ __( 'Conversation #%d', 'dev3-ai-shopping-assistant-for-woocommerce' ), conv.id ) : __( 'Conversation', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
			description={ conv ? [ conv.name || conv.email || __( 'Guest shopper', 'dev3-ai-shopping-assistant-for-woocommerce' ), when( conv.created_at ) ].filter( Boolean ).join( ' · ' ) : '' }
			footer={
				conv && (
					<Button variant="danger" icon="trash" onClick={ () => setConfirm( true ) }>
						{ __( 'Delete', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
					</Button>
				)
			}
		>
			<ErrorNotice error={ error } />
			{ ! data && ! error && <Skeleton lines={ 8 } /> }
			{ conv && (
				<Stack gap={ 16 }>
					<Inline align="between">
						<Select
							label={ __( 'Status', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
							value={ conv.status }
							onChange={ setStatus }
							options={ Object.keys( STATUS ).map( ( k ) => ( { value: k, label: STATUS[ k ].label } ) ) }
						/>
						<Inline>
							{ conv.carts > 0 && <Badge tone="brand">{ sprintf( /* translators: %d: count */ __( '%d added to cart', 'dev3-ai-shopping-assistant-for-woocommerce' ), conv.carts ) }</Badge> }
							{ conv.order_id > 0 && (
								<Button size="sm" variant="link" href={ conv.order_url } target="_blank">
									{ sprintf( /* translators: 1: order id, 2: revenue */ __( 'Order #%1$d · %2$s', 'dev3-ai-shopping-assistant-for-woocommerce' ), conv.order_id, formatMoney( conv.revenue, window.dev3saConfig && window.dev3saConfig.currency ) ) }
								</Button>
							) }
							{ conv.sentiment && <Badge tone={ conv.sentiment === 'negative' ? 'danger' : conv.sentiment === 'positive' ? 'success' : 'neutral' }>{ conv.sentiment }</Badge> }
						</Inline>
					</Inline>

					{ conv.summary && ! summary && (
						<Card tone="ai" title={ __( 'Summary', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
							<p>{ conv.summary }</p>
						</Card>
					) }
					<div>
						{ ! summary && <AiButton size="sm" loading={ summarising } onClick={ summarise }>{ __( 'Summarise with DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</AiButton> }
						{ summary && (
							<AiDraftReview
								title={ __( 'DEV3 AI · Conversation summary', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
								draft={ summary.summary }
								original={ conv.summary || undefined }
								meta={ summary.meta }
								acceptLabel={ __( 'Save summary', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
								onAccept={ saveSummary }
								onReject={ () => setSummary( null ) }
								onRegenerate={ summarise }
								regenerating={ summarising }
							/>
						) }
					</div>

					<ol className="dev3sa-transcript" aria-label={ __( 'Transcript', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
						{ data.messages.map( ( m ) => (
							<li key={ m.id } className={ `dev3sa-transcript__msg is-${ m.role }` }>
								<div className="dev3sa-transcript__meta">
									<strong>{ m.role === 'user' ? __( 'Shopper', 'dev3-ai-shopping-assistant-for-woocommerce' ) : m.role === 'agent' ? __( 'Team', 'dev3-ai-shopping-assistant-for-woocommerce' ) : m.role === 'system' ? __( 'System', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( 'Assistant', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</strong>
									<span>{ when( m.created_at ) }</span>
									{ m.role === 'bot' && m.source && <Badge tone={ m.source === 'ai' || m.source === 'cache' ? 'brand' : 'neutral' }>{ SOURCE[ m.source ] || m.source }</Badge> }
									{ m.intent && m.role === 'user' && <Badge tone={ m.intent === 'unanswered' ? 'warning' : 'neutral' }>{ m.intent.replace( '_', ' ' ) }</Badge> }
									{ m.rating === 1 && <Badge tone="success">{ __( 'Helpful', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> }
									{ m.rating === -1 && <Badge tone="danger">{ __( 'Not helpful', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> }
								</div>
								<p className="dev3sa-transcript__text">{ m.content }</p>
								{ m.product_cards && m.product_cards.length > 0 && (
									<p className="dev3sa-transcript__products">
										{ __( 'Products shown:', 'dev3-ai-shopping-assistant-for-woocommerce' ) }{ ' ' }
										{ m.product_cards.map( ( p, i ) => (
											<span key={ p.id }>
												{ i > 0 && ', ' }
												<a href={ p.url }>{ p.name }</a>
											</span>
										) ) }
									</p>
								) }
								{ m.meta && m.meta.actions && m.meta.actions.length > 0 && (
									<p className="dev3sa-transcript__products">
										{ m.meta.actions.map( ( a ) => a.type.replace( '_', ' ' ) + ( a.product ? `: ${ a.product }` : '' ) + ( a.order ? ` #${ a.order.number }` : '' ) ).join( ' · ' ) }
									</p>
								) }
							</li>
						) ) }
					</ol>

					<Card title={ __( 'Reply by email', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ conv.email ? sprintf( /* translators: %s: email */ __( 'Sent to %s and saved in the transcript. The conversation is marked resolved.', 'dev3-ai-shopping-assistant-for-woocommerce' ), conv.email ) : __( 'This shopper has not left an email address yet.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
						<Textarea label={ __( 'Your reply', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ reply } onChange={ setReply } rows={ 4 } maxLength={ 4000 } disabled={ ! conv.email } />
						<div>
							<Button variant="primary" icon="check" loading={ sending } disabled={ ! conv.email || ! reply.trim() } onClick={ sendReply }>
								{ __( 'Send reply', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
							</Button>
						</div>
					</Card>
				</Stack>
			) }
			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				size="sm"
				title={ __( 'Delete this conversation?', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				description={ __( 'The transcript is removed for good. Attributed revenue stays on the order.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( false ) }>{ __( 'Cancel', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
						<Button variant="danger" onClick={ remove }>{ __( 'Delete', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
					</>
				}
			/>
		</Drawer>
	);
}

/**
 * Inbox: filters, server-paginated table, transcript drawer, CSV export.
 *
 * @param {Object} props Props (params, navigate, onUsage).
 * @return {Element} Screen.
 */
export default function Conversations( { params = [], navigate, onUsage } ) {
	const toast = useToast();
	const openId = params[ 0 ] && /^\d+$/.test( params[ 0 ] ) ? Number( params[ 0 ] ) : null;
	const [ status, setStatus ] = useState( params[ 0 ] && STATUS[ params[ 0 ] ] ? params[ 0 ] : '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ rating, setRating ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( { rows: [], total: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ exporting, setExporting ] = useState( false );

	const load = () => {
		setLoading( true );
		setError( null );
		const q = new URLSearchParams( { page, per_page: 20, status, search: query, rating } ).toString();
		request( `conversations?${ q }` )
			.then( setData )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	useEffect( load, [ page, status, query, rating ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const exportCsv = () => {
		setExporting( true );
		request( 'conversations/export' )
			.then( ( res ) => {
				const blob = new window.Blob( [ res.csv ], { type: 'text/csv;charset=utf-8' } );
				const a = document.createElement( 'a' );
				a.href = window.URL.createObjectURL( blob );
				a.download = res.filename;
				document.body.appendChild( a );
				a.click();
				a.remove();
				toast.success( sprintf( /* translators: %d: rows */ __( 'Exported %d conversations.', 'dev3-ai-shopping-assistant-for-woocommerce' ), res.count ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setExporting( false ) );
	};

	const columns = [
		{
			key: 'first_question',
			label: __( 'First question', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3sa-clamp">
					{ r.first_question || <em>{ __( '(no message)', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</em> }
				</span>
			),
		},
		{ key: 'who', label: __( 'Shopper', 'dev3-ai-shopping-assistant-for-woocommerce' ), render: ( r ) => r.name || r.email || __( 'Guest', 'dev3-ai-shopping-assistant-for-woocommerce' ), hideOnMobile: false },
		{ key: 'status', label: __( 'Status', 'dev3-ai-shopping-assistant-for-woocommerce' ), render: ( r ) => <Badge tone={ ( STATUS[ r.status ] || {} ).tone }>{ ( STATUS[ r.status ] || {} ).label || r.status }</Badge>, nowrap: true },
		{ key: 'messages', label: __( 'Messages', 'dev3-ai-shopping-assistant-for-woocommerce' ), align: 'end', width: 90 },
		{
			key: 'outcome',
			label: __( 'Outcome', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			render: ( r ) => (
				<Inline gap={ 4 }>
					{ r.carts > 0 && <Badge tone="brand">{ sprintf( /* translators: %d: count */ __( '%d in cart', 'dev3-ai-shopping-assistant-for-woocommerce' ), r.carts ) }</Badge> }
					{ r.order_id > 0 && <Badge tone="success">{ __( 'Order', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> }
					{ r.rating_down > 0 && <Badge tone="danger">{ sprintf( /* translators: %d: count */ __( '%d not helpful', 'dev3-ai-shopping-assistant-for-woocommerce' ), r.rating_down ) }</Badge> }
					{ r.rating_up > 0 && <Badge tone="success">{ sprintf( /* translators: %d: count */ __( '%d helpful', 'dev3-ai-shopping-assistant-for-woocommerce' ), r.rating_up ) }</Badge> }
				</Inline>
			),
		},
		{ key: 'updated_at', label: __( 'Last message', 'dev3-ai-shopping-assistant-for-woocommerce' ), render: ( r ) => when( r.updated_at ), nowrap: true },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Conversations', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				description={ __( 'Every chat with transcript, outcome and rating. Reply by email to shoppers who asked for a person.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				actions={ <Button icon="file" loading={ exporting } onClick={ exportCsv }>{ __( 'Export CSV', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button> }
			/>
			<Card>
				<form
					className="dev3sa-filters"
					onSubmit={ ( e ) => {
						e.preventDefault();
						setPage( 1 );
						setQuery( search );
					} }
				>
					<TextField label={ __( 'Search', 'dev3-ai-shopping-assistant-for-woocommerce' ) } type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Email, name or message text', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
					<Select
						label={ __( 'Status', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						value={ status }
						onChange={ ( v ) => {
							setPage( 1 );
							setStatus( v );
						} }
						options={ [ { value: '', label: __( 'All statuses', 'dev3-ai-shopping-assistant-for-woocommerce' ) }, ...Object.keys( STATUS ).map( ( k ) => ( { value: k, label: STATUS[ k ].label } ) ) ] }
					/>
					<Select
						label={ __( 'Rating', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						value={ rating }
						onChange={ ( v ) => {
							setPage( 1 );
							setRating( v );
						} }
						options={ [
							{ value: '', label: __( 'Any rating', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'up', label: __( 'Rated helpful', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'down', label: __( 'Rated not helpful', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
						] }
					/>
					<Button type="submit" icon="search">{ __( 'Search', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
				</form>
			</Card>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card flush>
				<ResponsiveTable
					columns={ columns }
					rows={ data.rows }
					loading={ loading }
					onRowClick={ ( r ) => navigate( `conversations/${ r.id }` ) }
					pagination={ { page, perPage: 20, total: data.total, onPageChange: setPage } }
					empty={ { icon: 'inbox', title: __( 'No conversations found', 'dev3-ai-shopping-assistant-for-woocommerce' ), description: query || status || rating ? __( 'Try other filters.', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( 'Conversations appear here when shoppers use the chat.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } }
				/>
			</Card>
			{ openId && <Transcript id={ openId } onClose={ () => navigate( 'conversations' ) } onChange={ load } onUsage={ onUsage } /> }
		</>
	);
}
