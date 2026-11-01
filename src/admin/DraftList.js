import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiDraftReview, Badge, Button, Inline, Notice, useToast } from '../core3/ui';

const FIELDS = [
	{ key: 'title', label: __( 'Question / title', 'dev3-ai-shopping-assistant-for-woocommerce' ), maxLength: 200 },
	{ key: 'content', label: __( 'Answer', 'dev3-ai-shopping-assistant-for-woocommerce' ), multiline: true, maxLength: 4000 },
	{ key: 'keywords', label: __( 'Keywords', 'dev3-ai-shopping-assistant-for-woocommerce' ), maxLength: 255 },
];

/**
 * Review list of AI-drafted knowledge entries: accept / edit / reject each, or accept all.
 *
 * @param {Object}   props              Props.
 * @param {Array}    props.drafts       Drafts { key, title, content, type, keywords, confirm, asked?, examples? }.
 * @param {Object}   props.meta         { model, tokens, cached }.
 * @param {Function} props.onSaved      After saving.
 * @param {Function} props.onRegenerate Regenerate all.
 * @param {boolean}  props.regenerating Busy.
 * @return {Element} List.
 */
export default function DraftList( { drafts, meta, onSaved, onRegenerate, regenerating } ) {
	const toast = useToast();
	const [ left, setLeft ] = useState( drafts );
	const [ saving, setSaving ] = useState( '' );
	useEffect( () => setLeft( drafts ), [ drafts ] );

	const drop = ( key ) => setLeft( ( l ) => l.filter( ( d ) => d.key !== key ) );
	const accept = ( draft, values ) => {
		setSaving( draft.key );
		return request( 'knowledge', { method: 'POST', data: { ...values, type: draft.type, source: 'ai', status: 'publish' } } )
			.then( () => {
				drop( draft.key );
				onSaved();
				toast.success( __( 'Entry added to the knowledge base.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSaving( '' ) );
	};
	const acceptAll = () => {
		const ready = left.filter( ( d ) => ! d.confirm );
		setSaving( 'all' );
		request( 'knowledge/bulk', { method: 'POST', data: { entries: ready.map( ( d ) => ( { title: d.title, content: d.content, type: d.type, keywords: d.keywords } ) ) } } )
			.then( ( r ) => {
				setLeft( ( l ) => l.filter( ( d ) => d.confirm ) );
				onSaved();
				toast.success( sprintf( /* translators: %d: count */ _n( '%d entry added.', '%d entries added.', r.saved, 'dev3-ai-shopping-assistant-for-woocommerce' ), r.saved ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSaving( '' ) );
	};

	if ( ! left.length ) {
		return <Notice tone="success">{ __( 'All drafts reviewed.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Notice>;
	}
	const ready = left.filter( ( d ) => ! d.confirm ).length;
	return (
		<div className="dev3sa-drafts">
			<Inline align="between">
				<p className="dev3sa-muted">
					{ sprintf( /* translators: %d: drafts */ _n( '%d draft to review. Nothing is saved until you accept it.', '%d drafts to review. Nothing is saved until you accept them.', left.length, 'dev3-ai-shopping-assistant-for-woocommerce' ), left.length ) }
				</p>
				{ ready > 0 && (
					<Button size="sm" variant="primary" icon="check" loading={ saving === 'all' } onClick={ acceptAll }>
						{ sprintf( /* translators: %d: count */ __( 'Accept %d without [CONFIRM]', 'dev3-ai-shopping-assistant-for-woocommerce' ), ready ) }
					</Button>
				) }
			</Inline>
			{ left.map( ( d ) => (
				<div key={ d.key } className="dev3sa-draft">
					<Inline gap={ 4 }>
						<Badge>{ d.type }</Badge>
						{ d.confirm && <Badge tone="warning">{ __( 'Needs your facts: replace [CONFIRM: …]', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Badge> }
						{ d.asked > 0 && <Badge tone="info">{ sprintf( /* translators: %d: times */ _n( 'Asked %d time', 'Asked %d times', d.asked, 'dev3-ai-shopping-assistant-for-woocommerce' ), d.asked ) }</Badge> }
					</Inline>
					{ d.examples && d.examples.length > 0 && <p className="dev3sa-muted">{ __( 'For example:', 'dev3-ai-shopping-assistant-for-woocommerce' ) } “{ d.examples.join( '”, “' ) }”</p> }
					<AiDraftReview
						fields={ FIELDS }
						draft={ { title: d.title, content: d.content, keywords: d.keywords } }
						meta={ meta }
						acceptLabel={ __( 'Add to knowledge base', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						accepting={ saving === d.key }
						onAccept={ ( values ) => accept( d, values ) }
						onReject={ () => drop( d.key ) }
						onRegenerate={ onRegenerate }
						regenerating={ regenerating }
					/>
				</div>
			) ) }
		</div>
	);
}
