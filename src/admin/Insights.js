import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Card, Columns, EmptyState, ErrorNotice, Inline, PageHeader, ProgressBar, Select, SkeletonCard } from '../core3/ui';
import DraftList from './DraftList';


/**
 * DEV3 AI insights: what shoppers ask (themes, demand, sentiment, actions) and knowledge gaps.
 *
 * @param {Object} props Props (onUsage).
 * @return {Element} Screen.
 */
export default function Insights( { onUsage } ) {
	const [ days, setDays ] = useState( '30' );
	const [ report, setReport ] = useState( undefined );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ gaps, setGaps ] = useState( null );
	const [ gapBusy, setGapBusy ] = useState( false );
	const [ gapError, setGapError ] = useState( null );

	useEffect( () => {
		request( 'ai/insights' )
			.then( ( r ) => setReport( r.report ) )
			.catch( () => setReport( null ) );
	}, [] );

	const run = () => {
		setBusy( true );
		setError( null );
		request( 'ai/insights', { method: 'POST', data: { days: Number( days ) } } )
			.then( ( r ) => setReport( r.report ) )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};
	const findGaps = () => {
		setGapBusy( true );
		setGapError( null );
		request( 'ai/gaps', { method: 'POST', data: { days: Number( days ) } } )
			.then( setGaps )
			.catch( setGapError )
			.finally( () => {
				setGapBusy( false );
				onUsage();
			} );
	};
	const total = report && report.sentiment ? report.sentiment.positive + report.sentiment.neutral + report.sentiment.negative : 0;
	const pct = ( n ) => ( total ? Math.round( ( 100 * n ) / total ) : 0 );

	return (
		<>
			<PageHeader
				title={ __( 'AI insights', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				description={ __( 'DEV3 AI reads what shoppers ask (personal data masked) and tells you what they want, what they could not find and which answers are missing.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				actions={
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
				}
			/>

			<Card tone="ai" title={ __( 'Knowledge gaps', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ __( 'Questions the assistant could not answer or that shoppers rated “not helpful”, grouped, with draft answers you can review and add.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
				<div>
					<AiButton loading={ gapBusy } onClick={ findGaps }>{ __( 'Find gaps with DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</AiButton>
				</div>
				<ErrorNotice error={ gapError } onRetry={ findGaps } onDismiss={ () => setGapError( null ) } />
				{ gaps && ! gaps.drafts.length && <EmptyState compact icon="success" title={ __( 'No gaps found', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ __( 'The assistant answered everything in this period.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } /> }
				{ gaps && gaps.drafts.length > 0 && <DraftList drafts={ gaps.drafts } meta={ gaps.meta } onSaved={ () => {} } onRegenerate={ findGaps } regenerating={ gapBusy } /> }
			</Card>

			<Card
				tone="ai"
				title={ __( 'Conversation report', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				description={ report && report.generated_at ? sprintf( /* translators: 1: date, 2: messages */ __( 'Generated %1$s from %2$d shopper messages.', 'dev3-ai-shopping-assistant-for-woocommerce' ), new Date( report.generated_at ).toLocaleString(), report.messages ) : __( 'Themes, demand, sentiment and recommended actions.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				actions={ <AiButton loading={ busy } onClick={ run }>{ report ? __( 'Refresh report', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( 'Generate report', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</AiButton> }
			>
				<ErrorNotice error={ error } onRetry={ run } onDismiss={ () => setError( null ) } />
				{ report === undefined && <SkeletonCard lines={ 3 } /> }
				{ report === null && ! busy && <EmptyState compact icon="sparkles" title={ __( 'No report yet', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ __( 'Generate one when you have a few conversations.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } /> }
				{ report && (
					<div className="dev3sa-report">
						<p className="dev3sa-report__headline">{ report.headline }</p>
						<Columns min={ 260 }>
							{ report.themes.map( ( t ) => (
								<div key={ t.title } className="dev3sa-theme">
									<Inline align="between">
										<h3>{ t.title }</h3>
										<Badge tone="brand">{ t.share }%</Badge>
									</Inline>
									<p>{ t.summary }</p>
									{ t.suggestion && (
										<p className="dev3sa-theme__tip">
											<strong>{ __( 'Do this:', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</strong> { t.suggestion }
										</p>
									) }
								</div>
							) ) }
						</Columns>
						<Columns min={ 260 }>
							<div>
								<h3>{ __( 'Shoppers asked for, but you may not sell', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</h3>
								{ report.missing_products.length ? (
									<Inline gap={ 6 }>
										{ report.missing_products.map( ( p ) => (
											<Badge key={ p }>{ p }</Badge>
										) ) }
									</Inline>
								) : (
									<p className="dev3sa-muted">{ __( 'Nothing stood out.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</p>
								) }
							</div>
							<div>
								<h3>{ __( 'Sentiment', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</h3>
								<ProgressBar value={ pct( report.sentiment.positive ) } tone="success" label={ sprintf( /* translators: %d: percent */ __( 'Positive %d%%', 'dev3-ai-shopping-assistant-for-woocommerce' ), pct( report.sentiment.positive ) ) } />
								<ProgressBar value={ pct( report.sentiment.neutral ) } label={ sprintf( /* translators: %d: percent */ __( 'Neutral %d%%', 'dev3-ai-shopping-assistant-for-woocommerce' ), pct( report.sentiment.neutral ) ) } />
								<ProgressBar value={ pct( report.sentiment.negative ) } tone="danger" label={ sprintf( /* translators: %d: percent */ __( 'Negative %d%%', 'dev3-ai-shopping-assistant-for-woocommerce' ), pct( report.sentiment.negative ) ) } />
							</div>
						</Columns>
						{ report.actions.length > 0 && (
							<div>
								<h3>{ __( 'Recommended actions', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</h3>
								<ol className="dev3sa-actions">
									{ report.actions.map( ( a ) => (
										<li key={ a }>{ a }</li>
									) ) }
								</ol>
							</div>
						) }
					</div>
				) }
			</Card>
		</>
	);
}
