import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, Inline, JobProgress, ProgressBar, useToast } from '../core3/ui';

/**
 * Smart search index (embeddings of products + knowledge entries) with a background job.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.status  Index status from the server.
 * @param {Function} props.onUsage Refresh the usage pill.
 * @param {Function} props.onDone  Reload after a job finished.
 * @return {Element} Card.
 */
export default function IndexCard( { status, onUsage, onDone } ) {
	const toast = useToast();
	const [ job, setJob ] = useState( status && status.job ? status.job : null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	if ( ! status ) {
		return null;
	}
	const pct = status.products ? Math.round( ( 100 * status.products_indexed ) / status.products ) : 0;
	const start = ( force = false ) => {
		setBusy( true );
		setError( null );
		request( 'index', { method: 'POST', data: { force } } )
			.then( ( res ) => {
				if ( res && res.status === 'fresh' ) {
					toast.success( __( 'The index is already up to date.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
				} else {
					setJob( res );
				}
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card
			tone="ai"
			title={ __( 'Smart search index', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
			description={ __( 'DEV3 AI understands what shoppers mean ("a warm gift for my dad") by comparing embeddings of your products and answers. New and edited products are re-indexed automatically.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
			actions={ status.ai ? <Badge tone="success" dot>{ __( 'AI connected', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> : <Badge>{ __( 'AI not connected', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> }
		>
			<ProgressBar
				value={ pct }
				label={ sprintf(
					/* translators: 1: indexed products, 2: products, 3: indexed entries, 4: entries */
					__( '%1$d of %2$d products · %3$d of %4$d knowledge entries', 'dev3-ai-shopping-assistant-for-woocommerce' ),
					status.products_indexed,
					status.products,
					status.kb_indexed,
					status.kb
				) }
			/>
			<ErrorNotice error={ error } onRetry={ () => start() } onDismiss={ () => setError( null ) } />
			{ job && job.id && (
				<JobProgress
					jobId={ job.id }
					job={ job }
					title={ __( 'Building the index', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
					onDone={ ( j ) => {
						onUsage();
						if ( j.failed ) {
							toast.warning( __( 'Indexing finished with some failures. Retry them from the job card.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
						} else {
							toast.success( __( 'Smart search index is ready.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
						}
						onDone();
					} }
				/>
			) }
			<Inline>
				<Button variant="ai" icon="sparkles" loading={ busy } disabled={ ! status.ai || ( job && [ 'queued', 'running' ].includes( job.status ) ) } onClick={ () => start( false ) }>
					{ status.products_indexed ? __( 'Update index', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( 'Build index with DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				</Button>
				{ status.products_indexed > 0 && (
					<Button variant="ghost" disabled={ ! status.ai || busy } onClick={ () => start( true ) }>
						{ __( 'Rebuild everything', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
					</Button>
				) }
				{ ! status.ai && <Button variant="link" href="#/api">{ __( 'Add your Gemini API key', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button> }
			</Inline>
		</Card>
	);
}
