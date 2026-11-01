import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, AiDraftReview, Button, Card, Checkbox, ColorField, ErrorNotice, FormGrid, Inline, NumberField, PageHeader, Select, Stack, Tabs, TextField, Textarea, Toggle, useToast } from '../core3/ui';
import Preview from './Preview';


export const KEYS = [ 'widget_enabled', 'bot_name', 'subtitle', 'avatar_id', 'accent_color', 'position', 'offset_x', 'offset_y', 'launcher_style', 'launcher_label', 'greeting', 'starters', 'placeholder', 'away_message', 'greeting_bubble', 'auto_open', 'show_on', 'pages', 'exclude_pages', 'hide_on_checkout', 'audience', 'product_search', 'add_to_cart', 'order_tracking', 'handoff', 'max_products', 'hide_out_of_stock', 'handoff_email', 'whatsapp', 'phone', 'hours_enabled', 'hours_start', 'hours_end', 'hours_days', 'ai_chat', 'ai_tone', 'answer_length', 'ai_instructions', 'ai_auto_add', 'max_ai_turns', 'chat_rate_limit', 'auto_index', 'store_transcripts', 'retention_days', 'show_consent', 'consent_text' ];

const SECTIONS = [
	{ id: 'look', label: __( 'Appearance', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ id: 'messages', label: __( 'Messages', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ id: 'display', label: __( 'Where it shows', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ id: 'features', label: __( 'Features & contact', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ id: 'ai', label: __( 'DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
	{ id: 'privacy', label: __( 'Privacy', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
];
const DAYS = [ __( 'Sun', 'dev3-ai-shopping-assistant-for-woocommerce' ), __( 'Mon', 'dev3-ai-shopping-assistant-for-woocommerce' ), __( 'Tue', 'dev3-ai-shopping-assistant-for-woocommerce' ), __( 'Wed', 'dev3-ai-shopping-assistant-for-woocommerce' ), __( 'Thu', 'dev3-ai-shopping-assistant-for-woocommerce' ), __( 'Fri', 'dev3-ai-shopping-assistant-for-woocommerce' ), __( 'Sat', 'dev3-ai-shopping-assistant-for-woocommerce' ) ];
const PERSONA = [
	{ key: 'bot_name', label: __( 'Assistant name', 'dev3-ai-shopping-assistant-for-woocommerce' ), maxLength: 40 },
	{ key: 'subtitle', label: __( 'Subtitle', 'dev3-ai-shopping-assistant-for-woocommerce' ), maxLength: 70 },
	{ key: 'greeting', label: __( 'Greeting', 'dev3-ai-shopping-assistant-for-woocommerce' ), multiline: true, maxLength: 400 },
	{ key: 'starters', label: __( 'Starter buttons (one per line)', 'dev3-ai-shopping-assistant-for-woocommerce' ), multiline: true, maxLength: 260 },
	{ key: 'away_message', label: __( 'Away message', 'dev3-ai-shopping-assistant-for-woocommerce' ), multiline: true, maxLength: 300 },
	{ key: 'ai_instructions', label: __( 'Assistant instructions', 'dev3-ai-shopping-assistant-for-woocommerce' ), multiline: true, maxLength: 1500 },
];

const pick = ( s ) => {
	const out = {};
	KEYS.forEach( ( k ) => ( out[ k ] = s[ k ] ) );
	out.starters = ( s.starters || [] ).join( '\n' );
	return out;
};

/**
 * Page checklist (multi-select that works on phones).
 *
 * @param {Object}   props          Props.
 * @param {string}   props.label    Legend.
 * @param {Array}    props.pages    Options.
 * @param {number[]} props.value    Selected ids.
 * @param {Function} props.onChange Change.
 * @return {Element} Fieldset.
 */
function PagePicker( { label, pages, value, onChange } ) {
	const set = new Set( ( value || [] ).map( Number ) );
	return (
		<fieldset className="dev3sa-pagepicker">
			<legend>{ label }</legend>
			<div className="dev3sa-pagepicker__list">
				{ pages.length === 0 && <p className="dev3sa-muted">{ __( 'No published pages.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</p> }
				{ pages.map( ( p ) => (
					<Checkbox
						key={ p.value }
						label={ p.label || `#${ p.value }` }
						checked={ set.has( p.value ) }
						onChange={ ( on ) => {
							const next = new Set( set );
							if ( on ) {
								next.add( p.value );
							} else {
								next.delete( p.value );
							}
							onChange( Array.from( next ) );
						} }
					/>
				) ) }
			</div>
		</fieldset>
	);
}

/**
 * Chat widget settings with a live preview and the DEV3 AI persona writer.
 *
 * @param {Object} props Props (settings, save, onUsage).
 * @return {Element} Screen.
 */
export default function WidgetTab( { settings, save, onUsage } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( () => pick( settings ) );
	const [ section, setSection ] = useState( 'look' );
	const [ device, setDevice ] = useState( 'desktop' );
	const [ busy, setBusy ] = useState( false );
	const [ pages, setPages ] = useState( [] );
	const [ avatarUrl, setAvatarUrl ] = useState( config.avatarUrl || '' );
	const [ brief, setBrief ] = useState( '' );
	const [ persona, setPersona ] = useState( null );
	const [ personaBusy, setPersonaBusy ] = useState( false );
	const [ personaError, setPersonaError ] = useState( null );

	useEffect( () => {
		request( 'lookup?type=pages' )
			.then( setPages )
			.catch( () => {} );
	}, [] );

	const set = ( key ) => ( value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );
	const base = pick( settings );
	const dirty = KEYS.some( ( k ) => JSON.stringify( form[ k ] ) !== JSON.stringify( base[ k ] ) );
	const payload = ( f ) => ( { ...f, starters: String( f.starters || '' ).split( '\n' ).map( ( x ) => x.trim() ).filter( Boolean ) } );

	const submit = ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setBusy( true );
		save( payload( form ) )
			.then( ( next ) => {
				setForm( pick( next ) );
				toast.success( __( 'Chat widget saved.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const chooseAvatar = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( { title: __( 'Assistant avatar', 'dev3-ai-shopping-assistant-for-woocommerce' ), library: { type: 'image' }, multiple: false } );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			set( 'avatar_id' )( att.id );
			setAvatarUrl( ( att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url ) || att.url );
		} );
		frame.open();
	};

	const writePersona = () => {
		setPersonaBusy( true );
		setPersonaError( null );
		request( 'ai/persona', { method: 'POST', data: { brief } } )
			.then( setPersona )
			.catch( setPersonaError )
			.finally( () => {
				setPersonaBusy( false );
				onUsage();
			} );
	};
	const acceptPersona = ( values ) => {
		const next = { ...form, ...values };
		setForm( next );
		setPersona( null );
		setBusy( true );
		return save( payload( next ) )
			.then( ( saved ) => {
				setForm( pick( saved ) );
				toast.success( __( 'New assistant copy saved.', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const panels = {
		look: (
			<Stack>
				<Toggle label={ __( 'Show the floating chat on the storefront', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( 'The inline block and [dev3sa_chat] shortcode work either way.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.widget_enabled } onChange={ set( 'widget_enabled' ) } />
				<FormGrid>
					<TextField label={ __( 'Assistant name', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.bot_name } onChange={ set( 'bot_name' ) } maxLength={ 40 } />
					<TextField label={ __( 'Subtitle', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.subtitle } onChange={ set( 'subtitle' ) } maxLength={ 70 } />
					<ColorField label={ __( 'Accent colour', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.accent_color } onChange={ set( 'accent_color' ) } help={ __( 'Text on the accent switches to dark automatically when needed for contrast.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
					<Select label={ __( 'Position', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.position } onChange={ set( 'position' ) } options={ [ { value: 'right', label: __( 'Bottom right', 'dev3-ai-shopping-assistant-for-woocommerce' ) }, { value: 'left', label: __( 'Bottom left', 'dev3-ai-shopping-assistant-for-woocommerce' ) } ] } />
					<Select label={ __( 'Launcher', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.launcher_style } onChange={ set( 'launcher_style' ) } options={ [ { value: 'label', label: __( 'Icon and label', 'dev3-ai-shopping-assistant-for-woocommerce' ) }, { value: 'icon', label: __( 'Icon only', 'dev3-ai-shopping-assistant-for-woocommerce' ) } ] } />
					<TextField label={ __( 'Launcher label', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.launcher_label } onChange={ set( 'launcher_label' ) } maxLength={ 30 } disabled={ form.launcher_style === 'icon' } />
					<NumberField label={ __( 'Side offset (px)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.offset_x } onChange={ set( 'offset_x' ) } min={ 0 } max={ 200 } />
					<NumberField label={ __( 'Bottom offset (px)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.offset_y } onChange={ set( 'offset_y' ) } min={ 0 } max={ 300 } help={ __( 'Raise it if a cookie bar or sticky cart covers the button.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
				</FormGrid>
				<Inline>
					{ avatarUrl ? <img src={ avatarUrl } alt="" width={ 40 } height={ 40 } className="dev3sa-avatar-thumb" /> : null }
					<Button onClick={ chooseAvatar }>{ form.avatar_id ? __( 'Change avatar', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( 'Choose avatar', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</Button>
					{ form.avatar_id > 0 && (
						<Button
							variant="ghost"
							onClick={ () => {
								set( 'avatar_id' )( 0 );
								setAvatarUrl( '' );
							} }
						>
							{ __( 'Remove', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						</Button>
					) }
				</Inline>
			</Stack>
		),
		messages: (
			<Stack>
				<Textarea label={ __( 'Greeting', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.greeting } onChange={ set( 'greeting' ) } rows={ 3 } maxLength={ 400 } />
				<Textarea label={ __( 'Starter buttons', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( 'One per line, up to 6. "Track my order" and "Talk to a person" are answered without AI.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.starters } onChange={ set( 'starters' ) } rows={ 4 } />
				<TextField label={ __( 'Message box placeholder', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.placeholder } onChange={ set( 'placeholder' ) } maxLength={ 80 } />
				<Textarea label={ __( 'Away message (outside business hours)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.away_message } onChange={ set( 'away_message' ) } rows={ 2 } maxLength={ 300 } />
				<FormGrid>
					<Toggle label={ __( 'Show the greeting as a bubble next to the launcher', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.greeting_bubble } onChange={ set( 'greeting_bubble' ) } />
					<NumberField label={ __( 'Open automatically after (seconds)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( '0 = never. Opens once per visitor.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.auto_open } onChange={ set( 'auto_open' ) } min={ 0 } max={ 600 } />
				</FormGrid>
			</Stack>
		),
		display: (
			<Stack>
				<FormGrid>
					<Select
						label={ __( 'Show the floating chat on', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						value={ form.show_on }
						onChange={ set( 'show_on' ) }
						options={ [
							{ value: 'all', label: __( 'Every page', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'woo', label: __( 'Shop, product, cart and account pages', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'pages', label: __( 'Only the pages I pick', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
						] }
					/>
					<Select
						label={ __( 'Show it to', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						value={ form.audience }
						onChange={ set( 'audience' ) }
						options={ [
							{ value: 'all', label: __( 'Everyone', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'guests', label: __( 'Guests only', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'customers', label: __( 'Logged-in customers only', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
						] }
					/>
				</FormGrid>
				<Toggle label={ __( 'Hide on the checkout page', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.hide_on_checkout } onChange={ set( 'hide_on_checkout' ) } />
				{ form.show_on === 'pages' && <PagePicker label={ __( 'Show on these pages', 'dev3-ai-shopping-assistant-for-woocommerce' ) } pages={ pages } value={ form.pages } onChange={ set( 'pages' ) } /> }
				<PagePicker label={ __( 'Never show on these pages', 'dev3-ai-shopping-assistant-for-woocommerce' ) } pages={ pages } value={ form.exclude_pages } onChange={ set( 'exclude_pages' ) } />
			</Stack>
		),
		features: (
			<Stack>
				<FormGrid>
					<Toggle label={ __( 'Product finder', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.product_search } onChange={ set( 'product_search' ) } />
					<Toggle label={ __( 'Add to cart from the chat', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.add_to_cart } onChange={ set( 'add_to_cart' ) } />
					<Toggle label={ __( 'Order tracking', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.order_tracking } onChange={ set( 'order_tracking' ) } />
					<Toggle label={ __( 'Talk to a person (handoff form)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.handoff } onChange={ set( 'handoff' ) } />
					<NumberField label={ __( 'Products per answer', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.max_products } onChange={ set( 'max_products' ) } min={ 1 } max={ 8 } />
					<Toggle label={ __( 'Never suggest out-of-stock products', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.hide_out_of_stock } onChange={ set( 'hide_out_of_stock' ) } />
				</FormGrid>
				<FormGrid>
					<TextField label={ __( 'Send handoff messages to', 'dev3-ai-shopping-assistant-for-woocommerce' ) } type="email" value={ form.handoff_email } onChange={ set( 'handoff_email' ) } placeholder={ __( 'Admin email', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
					<TextField label={ __( 'WhatsApp number', 'dev3-ai-shopping-assistant-for-woocommerce' ) } type="tel" value={ form.whatsapp } onChange={ set( 'whatsapp' ) } placeholder="+15551234567" help={ __( 'Optional. Shows a WhatsApp button with the handoff form.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
					<TextField label={ __( 'Phone number', 'dev3-ai-shopping-assistant-for-woocommerce' ) } type="tel" value={ form.phone } onChange={ set( 'phone' ) } />
				</FormGrid>
				<Toggle label={ __( 'Use business hours', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( 'Outside these hours the handoff form shows the away message. The assistant itself is always on.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.hours_enabled } onChange={ set( 'hours_enabled' ) } />
				{ form.hours_enabled && (
					<>
						<FormGrid>
							<TextField label={ __( 'Opens', 'dev3-ai-shopping-assistant-for-woocommerce' ) } type="time" value={ form.hours_start } onChange={ set( 'hours_start' ) } />
							<TextField label={ __( 'Closes', 'dev3-ai-shopping-assistant-for-woocommerce' ) } type="time" value={ form.hours_end } onChange={ set( 'hours_end' ) } />
						</FormGrid>
						<fieldset className="dev3sa-days">
							<legend>{ __( 'Open on', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</legend>
							{ DAYS.map( ( label, i ) => (
								<Checkbox
									key={ label }
									label={ label }
									checked={ ( form.hours_days || [] ).includes( i ) }
									onChange={ ( on ) => set( 'hours_days' )( on ? [ ...( form.hours_days || [] ), i ] : ( form.hours_days || [] ).filter( ( d ) => d !== i ) ) }
								/>
							) ) }
						</fieldset>
					</>
				) }
			</Stack>
		),
		ai: (
			<Stack>
				<Toggle label={ __( 'Answer with DEV3 AI when it is connected', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( 'Off = guided assistant only (keyword search, knowledge base, order tracking, handoff).', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.ai_chat } onChange={ set( 'ai_chat' ) } />
				<FormGrid>
					<Select
						label={ __( 'Tone', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						value={ form.ai_tone }
						onChange={ set( 'ai_tone' ) }
						options={ [
							{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'professional', label: __( 'Professional', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'playful', label: __( 'Playful', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'luxury', label: __( 'Luxury', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
							{ value: 'concise', label: __( 'Concise', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
						] }
					/>
					<Select label={ __( 'Answer length', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.answer_length } onChange={ set( 'answer_length' ) } options={ [ { value: 'short', label: __( 'Short (1–3 sentences)', 'dev3-ai-shopping-assistant-for-woocommerce' ) }, { value: 'medium', label: __( 'Medium (up to 120 words)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } ] } />
					<NumberField label={ __( 'AI answers per conversation', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( 'After this the guided assistant takes over (cost control).', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.max_ai_turns } onChange={ set( 'max_ai_turns' ) } min={ 1 } max={ 500 } />
					<NumberField label={ __( 'Messages per minute per visitor', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.chat_rate_limit } onChange={ set( 'chat_rate_limit' ) } min={ 1 } max={ 120 } />
				</FormGrid>
				<Textarea label={ __( 'Extra instructions for the assistant', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( 'e.g. "Always mention free returns within 60 days. Recommend gift wrap for gifts."', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.ai_instructions } onChange={ set( 'ai_instructions' ) } rows={ 4 } maxLength={ 1500 } />
				<Toggle label={ __( 'Add to cart when the shopper asks (“add the blue mug to my cart”)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( 'Off = DEV3 AI shows the product with an Add button instead.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.ai_auto_add } onChange={ set( 'ai_auto_add' ) } />
				<Toggle label={ __( 'Keep the smart search index up to date automatically', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.auto_index } onChange={ set( 'auto_index' ) } />
			</Stack>
		),
		privacy: (
			<Stack>
				<Toggle label={ __( 'Store chat transcripts', 'dev3-ai-shopping-assistant-for-woocommerce' ) } help={ __( 'Off = messages are deleted after one day (needed for the conversation to make sense); totals are kept.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.store_transcripts } onChange={ set( 'store_transcripts' ) } />
				<NumberField label={ __( 'Delete transcripts after (days)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.retention_days } onChange={ set( 'retention_days' ) } min={ 1 } max={ 3650 } />
				<Toggle label={ __( 'Show a privacy line under the message box', 'dev3-ai-shopping-assistant-for-woocommerce' ) } checked={ form.show_consent } onChange={ set( 'show_consent' ) } />
				<TextField label={ __( 'Privacy line', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ form.consent_text } onChange={ set( 'consent_text' ) } maxLength={ 160 } disabled={ ! form.show_consent } help={ __( 'Links to your privacy policy page automatically.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
			</Stack>
		),
	};

	return (
		<form onSubmit={ submit }>
			<PageHeader
				title={ __( 'Chat widget', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				description={ __( 'Look, messages, where it shows and what it can do. The preview updates as you type.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
				actions={
					<Button type="submit" variant="primary" icon="check" loading={ busy } disabled={ ! dirty }>
						{ __( 'Save changes', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
					</Button>
				}
			/>
			<div className="dev3sa-widget-layout">
				<div className="dev3sa-widget-layout__main">
					<Card>
						<Tabs tabs={ SECTIONS } value={ section } onChange={ setSection } label={ __( 'Widget settings', 'dev3-ai-shopping-assistant-for-woocommerce' ) } variant="pills" />
						<div className="dev3sa-section">{ panels[ section ] }</div>
					</Card>
					<Card tone="ai" title={ __( 'Write my assistant’s copy with DEV3 AI', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ __( 'Name, greeting, starter buttons, away message and voice instructions, written for your store. Review before saving.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
						<Textarea label={ __( 'Describe your brand voice (optional)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ brief } onChange={ setBrief } rows={ 2 } maxLength={ 600 } placeholder={ __( 'e.g. Handmade ceramics studio, warm and down to earth, a bit witty', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
						<div>
							<AiButton type="button" loading={ personaBusy && ! persona } onClick={ writePersona } />
						</div>
						<ErrorNotice error={ personaError } onRetry={ writePersona } onDismiss={ () => setPersonaError( null ) } />
						{ persona && (
							<AiDraftReview
								fields={ PERSONA }
								draft={ persona.draft }
								original={ { bot_name: form.bot_name, subtitle: form.subtitle, greeting: form.greeting, starters: form.starters, away_message: form.away_message, ai_instructions: form.ai_instructions } }
								meta={ persona.meta }
								acceptLabel={ __( 'Use and save', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
								accepting={ busy }
								onAccept={ acceptPersona }
								onReject={ () => setPersona( null ) }
								onRegenerate={ writePersona }
								regenerating={ personaBusy }
							/>
						) }
					</Card>
				</div>
				<aside className="dev3sa-widget-layout__side" aria-label={ __( 'Live preview', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
					<Card
						title={ __( 'Live preview', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
						actions={
							<Tabs
								tabs={ [
									{ id: 'desktop', label: __( 'Desktop', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
									{ id: 'phone', label: __( 'Phone', 'dev3-ai-shopping-assistant-for-woocommerce' ) },
								] }
								value={ device }
								onChange={ setDevice }
								label={ __( 'Preview device', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
								variant="pills"
							/>
						}
					>
						<Preview settings={ { ...payload( form ), avatar_url: avatarUrl } } device={ device } />
						<p className="dev3sa-muted">
							{ __( 'On phones the chat opens as a full-screen sheet that stays above the keyboard.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }{ ' ' }
							{ config.homeUrl && (
								<a href={ config.homeUrl + '#dev3sa-chat' } target="_blank" rel="noreferrer">
									{ __( 'Open your store', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
								</a>
							) }
						</p>
					</Card>
				</aside>
			</div>
		</form>
	);
}
