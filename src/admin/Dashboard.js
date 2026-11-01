import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, ResponsiveTable, Select, Stat, StatGrid, formatMoney, formatNumber, SkeletonCard } from '../core3/ui';
import BarChart from './BarChart';
import IndexCard from './IndexCard';


/**
 * Dashboard: KPIs, conversations chart, top and unanswered questions, setup checklist, index.
 *
 * @param {Object} props Props (settings, navigate, onUsage).
 * @return {Element} Screen.
 */
export default function Dashboard( { settings, navigate, onUsage } ) {
	const [ days, setDays ] = useState( '30' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = () => {
		setError( null );
		request( `stats?days=${ days }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ days ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const s = data ? data.stats : null;
	const check = data ? data.checklist : {};
	const steps = [
		{ done: check.widget, label: __( 'Chat widget is switched on', 'dev3-ai-shopping-assistant-for-woocommerce' ), href: '#/widget' },
		{ done: check.knowledge, label: __( 'Knowledge base has answers (import your policies)', 'dev3-ai-shopping-assistant-for-woocommerce' ), href: '#/knowledge' },
		{ done: check.ai, label: __( 'DEV3 AI connected (Gemini API key)', 'dev3-ai-shopping-assistant-for-woocommerce' ), href: '#/api' },
		{ done: check.index, label: __( 'Smart product search index built', 'dev3-ai-shopping-assistant-for-woocommerce' ), href: '#/knowledge' },
	];
	const todo = steps.filter( ( x ) => ! x.done ).length;

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				description={ __( 'How the shopping assistant is helping your shoppers and your sales.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				actions={
					<>
						<Select
							label={ __( 'Period', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
							hideLabel
							value={ days }
							onChange={ setDays }
							options={ [
								{ value: '7', label: __( 'Last 7 days', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
								{ value: '30', label: __( 'Last 30 days', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
								{ value: '90', label: __( 'Last 90 days', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							] }
						/>
						<Button icon="bolt" href="#/test">{ __( 'Test the assistant', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
					</>
				}
			/>
			<ErrorNotice error={ error } onRetry={ load } />

			{ ! settings.api_key_set && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ) } actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button> }>
					{ __( 'Without a key the assistant runs in guided mode: keyword product search, order tracking, knowledge-base answers and handoff. Add a Google Gemini key for natural conversations, smart search and recommendations.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				</Notice>
			) }

			{ ! data && ! error && <SkeletonCard lines={ 4 } /> }
			{ s && (
				<>
					<StatGrid min={ 150 }>
						<Stat label={ __( 'Conversations', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ formatNumber( s.conversations ) } icon="inbox" tone="brand" hint={ sprintf( /* translators: %s: messages */ __( '%s messages', 'dev3-ai-shopping-assistant-for-woocommerce' ), formatNumber( s.messages ) ) } />
						<Stat label={ __( 'Answered', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ `${ s.answered_rate }%` } icon="success" tone="success" hint={ sprintf( /* translators: %s: AI answer share */ __( '%s%% by DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ), s.ai_rate ) } />
						<Stat label={ __( 'Added to cart', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ formatNumber( s.carts ) } icon="box" hint={ __( 'From chat product cards', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
						<Stat label={ __( 'Revenue from chat', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ formatMoney( s.revenue, data.currency ) } icon="chart" tone="success" hint={ sprintf( /* translators: %s: orders */ __( '%s paid orders', 'dev3-ai-shopping-assistant-for-woocommerce' ), formatNumber( s.orders ) ) } />
						<Stat label={ __( 'Satisfaction', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ s.satisfaction === null ? '–' : `${ s.satisfaction }%` } icon="users" hint={ sprintf( /* translators: %s: ratings */ __( '%s ratings', 'dev3-ai-shopping-assistant-for-woocommerce' ), formatNumber( s.ratings ) ) } />
						<Stat label={ __( 'Needs reply', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ formatNumber( s.needs_reply ) } icon="alert" tone={ s.needs_reply ? 'warning' : 'neutral' } hint={ s.needs_reply ? <Button size="sm" variant="link" href="#/conversations/needs_reply">{ __( 'Open inbox', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button> : __( 'All caught up', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
					</StatGrid>

					<Columns min={ 340 }>
						<Card title={ __( 'Conversations per day', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ sprintf( /* translators: %d: days */ __( 'Last %d days', 'dev3-ai-shopping-assistant-for-woocommerce' ), s.days ) }>
							{ s.conversations ? <BarChart data={ s.series } label={ __( 'Conversations', 'dev3-ai-shopping-assistant-for-woocommerce' ) } /> : <EmptyState compact icon="chart" title={ __( 'No conversations yet', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ __( 'They appear here as soon as shoppers start chatting.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } /> }
						</Card>
						<Card
							title={ __( 'Setup checklist', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
							actions={ todo ? <Badge tone="warning">{ sprintf( /* translators: %d: steps left */ __( '%d to do', 'dev3-ai-shopping-assistant-for-woocommerce' ), todo ) }</Badge> : <Badge tone="success">{ __( 'Done', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> }
						>
							<ul className="dev3sa-checklist">
								{ steps.map( ( step ) => (
									<li key={ step.label } className={ step.done ? 'is-done' : '' }>
										<span className="dev3sa-checklist__mark" aria-hidden="true">{ step.done ? '✓' : '' }</span>
										<a href={ step.href }>{ step.label }</a>
										<span className="screen-reader-text">{ step.done ? __( '(done)', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( '(to do)', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</span>
									</li>
								) ) }
							</ul>
						</Card>
					</Columns>

					<Columns min={ 340 }>
						<Card title={ __( 'Top questions', 'dev3-ai-shopping-assistant-for-woocommerce' ) } flush>
							<ResponsiveTable
								rowKey="question"
								rows={ data.top }
								columns={ [
									{ key: 'question', label: __( 'Question', 'dev3-ai-shopping-assistant-for-woocommerce' ), primary: true },
									{ key: 'count', label: __( 'Asked', 'dev3-ai-shopping-assistant-for-woocommerce' ), align: 'end', width: 80 },
								] }
								empty={ { icon: 'help', title: __( 'No questions yet', 'dev3-ai-shopping-assistant-for-woocommerce' ) } }
							/>
						</Card>
						<Card
							title={ __( 'Unanswered questions', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
							flush
							actions={ data.unanswered.length ? <Button size="sm" variant="ai" icon="sparkles" onClick={ () => navigate( 'insights' ) }>{ __( 'Fix with DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button> : null }
						>
							<ResponsiveTable
								rowKey="question"
								rows={ data.unanswered }
								columns={ [
									{ key: 'question', label: __( 'Question', 'dev3-ai-shopping-assistant-for-woocommerce' ), primary: true },
									{ key: 'count', label: __( 'Asked', 'dev3-ai-shopping-assistant-for-woocommerce' ), align: 'end', width: 80 },
								] }
								empty={ { icon: 'success', title: __( 'Nothing unanswered', 'dev3-ai-shopping-assistant-for-woocommerce' ), description: __( 'Questions the assistant could not answer show up here.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } }
							/>
						</Card>
					</Columns>

					<IndexCard status={ data.index } onUsage={ onUsage } onDone={ load } />
				</>
			) }
		</>
	);
}
