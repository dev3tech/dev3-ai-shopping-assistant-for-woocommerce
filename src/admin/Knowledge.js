import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, EmptyState, ErrorNotice, FormGrid, Inline, Modal, NumberField, PageHeader, ResponsiveTable, Select, TextField, Textarea, useToast } from '../core3/ui';
import DraftList from './DraftList';
import IndexCard from './IndexCard';


export const TYPES = [
	{ value: 'faq', label: __( 'FAQ', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ value: 'shipping', label: __( 'Shipping', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ value: 'returns', label: __( 'Returns', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ value: 'payment', label: __( 'Payment', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ value: 'policy', label: __( 'Policy', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ value: 'product', label: __( 'Product info', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ value: 'other', label: __( 'Other', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
];
const typeLabel = ( v ) => ( TYPES.find( ( t ) => t.value === v ) || { label: v } ).label;
const EMPTY = { title: '', content: '', type: 'faq', keywords: '', status: 'publish' };

/**
 * Knowledge base: entries, editor, import from the store, DEV3 AI builder and the search index.
 *
 * @param {Object} props Props (onUsage).
 * @return {Element} Screen.
 */
export default function Knowledge( { onUsage } ) {
	const toast = useToast();
	const [ entries, setEntries ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ edit, setEdit ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ importing, setImporting ] = useState( false );
	const [ confirm, setConfirm ] = useState( null );
	const [ count, setCount ] = useState( 8 );
	const [ ai, setAi ] = useState( null );
	const [ aiBusy, setAiBusy ] = useState( false );
	const [ aiError, setAiError ] = useState( null );
	const [ index, setIndex ] = useState( null );

	const load = () => {
		setError( null );
		request( 'knowledge' )
			.then( ( r ) => setEntries( r.entries ) )
			.catch( setError );
		request( 'index' )
			.then( setIndex )
			.catch( () => {} );
	};
	useEffect( load, [] );

	const save = ( e ) => {
		e.preventDefault();
		setSaving( true );
		request( 'knowledge', { method: 'POST', data: edit } )
			.then( () => {
				setEdit( null );
				load();
				toast.success( __( 'Entry saved.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSaving( false ) );
	};
	const remove = () =>
		request( `knowledge/${ confirm.id }`, { method: 'DELETE' } )
			.then( () => {
				setConfirm( null );
				load();
				toast.success( __( 'Entry deleted.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) );
	const importFacts = () => {
		setImporting( true );
		request( 'knowledge/import', { method: 'POST' } )
			.then( ( r ) => {
				setEntries( r.entries );
				toast.success( sprintf( /* translators: 1: added, 2: skipped */ __( 'Imported %1$d entries (%2$d already existed).', 'dev3-ai-shopping-assistant-for-woocommerce' ), r.added, r.skipped ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setImporting( false ) );
	};
	const generate = () => {
		setAiBusy( true );
		setAiError( null );
		request( 'ai/knowledge', { method: 'POST', data: { count } } )
			.then( setAi )
			.catch( setAiError )
			.finally( () => {
				setAiBusy( false );
				onUsage();
			} );
	};

	const columns = [
		{ key: 'title', label: __( 'Question / title', 'dev3-ai-shopping-assistant-for-woocommerce' ), primary: true, sortable: true },
		{ key: 'type', label: __( 'Type', 'dev3-ai-shopping-assistant-for-woocommerce' ), render: ( r ) => <Badge>{ typeLabel( r.type ) }</Badge>, sortable: true, nowrap: true },
		{ key: 'content', label: __( 'Answer', 'dev3-ai-shopping-assistant-for-woocommerce' ), render: ( r ) => <span className="dev3sa-clamp">{ r.content }</span> },
		{
			key: 'status',
			label: __( 'Status', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			render: ( r ) => (
				<Inline gap={ 4 }>
					<Badge tone={ r.status === 'publish' ? 'success' : 'neutral' }>{ r.status === 'publish' ? __( 'Live', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( 'Draft', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge>
					{ r.source === 'ai' && <Badge tone="brand">{ __( 'DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> }
					{ r.source === 'import' && <Badge tone="info">{ __( 'Imported', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> }
				</Inline>
			),
		},
	];

	return (
		<>
			<PageHeader
				title={ __( 'Knowledge base', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				description={ __( 'Answers about shipping, returns, payment and your store. The assistant quotes them in rule mode and uses them as facts with DEV3 AI.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				actions={
					<>
						<Button icon="refresh" loading={ importing } onClick={ importFacts }>{ __( 'Import from store', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
						<Button variant="primary" icon="plus" onClick={ () => setEdit( { ...EMPTY } ) }>{ __( 'New entry', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
					</>
				}
			/>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card flush>
				<ResponsiveTable
					columns={ columns }
					rows={ entries || [] }
					loading={ ! entries && ! error }
					pageSize={ 20 }
					defaultSort={ { key: 'type', dir: 'asc' } }
					onRowClick={ ( r ) => setEdit( { ...r } ) }
					rowActions={ ( r ) => (
						<span onClick={ ( e ) => e.stopPropagation() } onKeyDown={ ( e ) => e.stopPropagation() } role="presentation">
							<Button size="sm" variant="ghost" icon="trash" label={ __( 'Delete', 'dev3-ai-shopping-assistant-for-woocommerce' ) } onClick={ () => setConfirm( r ) } />
						</span>
					) }
					empty={ {
						icon: 'file',
						title: __( 'No answers yet', 'dev3-ai-shopping-assistant-for-woocommerce' ),
						description: __( 'Import your refund, shipping and payment details from WooCommerce, or let DEV3 AI draft them.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
						action: <Button variant="primary" icon="refresh" onClick={ importFacts }>{ __( 'Import from store', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>,
					} }
				/>
			</Card>

			<Card tone="ai" title={ __( 'Build my knowledge base with DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ __( 'DEV3 AI reads your policy pages, shipping zones and payment methods and drafts the answers shoppers ask most. Review each draft: accept, edit or reject. Facts it could not find are marked [CONFIRM: …].', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
				<Inline>
					<NumberField label={ __( 'Entries', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ count } onChange={ ( v ) => setCount( v === '' ? 8 : v ) } min={ 3 } max={ 12 } />
					<AiButton loading={ aiBusy } onClick={ generate } />
				</Inline>
				<ErrorNotice error={ aiError } onRetry={ generate } onDismiss={ () => setAiError( null ) } />
				{ ai && ai.drafts && ! ai.drafts.length && <EmptyState compact icon="sparkles" title={ __( 'No new entries suggested', 'dev3-ai-shopping-assistant-for-woocommerce' ) } /> }
				{ ai && ai.drafts && ai.drafts.length > 0 && <DraftList drafts={ ai.drafts } meta={ ai.meta } onSaved={ load } onRegenerate={ generate } regenerating={ aiBusy } /> }
			</Card>

			{ index && <IndexCard status={ index } onUsage={ onUsage } onDone={ load } /> }

			<Modal
				open={ !! edit }
				onClose={ () => setEdit( null ) }
				size="lg"
				title={ edit && edit.id ? __( 'Edit entry', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( 'New entry', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setEdit( null ) }>{ __( 'Cancel', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
						<Button variant="primary" type="submit" form="dev3sa-kb-form" loading={ saving }>{ __( 'Save entry', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
					</>
				}
			>
				{ edit && (
					<form id="dev3sa-kb-form" onSubmit={ save } className="dev3sa-stack">
						<TextField label={ __( 'Question or title', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ edit.title } onChange={ ( title ) => setEdit( { ...edit, title } ) } required maxLength={ 200 } data-autofocus />
						<Textarea label={ __( 'Answer', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ edit.content } onChange={ ( content ) => setEdit( { ...edit, content } ) } required rows={ 6 } maxLength={ 4000 } />
						<FormGrid>
							<Select label={ __( 'Type', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ edit.type } onChange={ ( type ) => setEdit( { ...edit, type } ) } options={ TYPES } />
							<Select
								label={ __( 'Status', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
								value={ edit.status }
								onChange={ ( status ) => setEdit( { ...edit, status } ) }
								options={ [
									{ value: 'publish', label: __( 'Live (used by the assistant)', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
									{ value: 'draft', label: __( 'Draft', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
								] }
							/>
						</FormGrid>
						<TextField label={ __( 'Keywords', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( 'Comma-separated words shoppers might use, e.g. delivery, postage, courier.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ edit.keywords } onChange={ ( keywords ) => setEdit( { ...edit, keywords } ) } maxLength={ 255 } />
					</form>
				) }
			</Modal>
			<Modal
				open={ !! confirm }
				onClose={ () => setConfirm( null ) }
				size="sm"
				title={ __( 'Delete this entry?', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				description={ confirm ? confirm.title : '' }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( null ) }>{ __( 'Cancel', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
						<Button variant="danger" onClick={ remove }>{ __( 'Delete', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
					</>
				}
			/>
		</>
	);
}
