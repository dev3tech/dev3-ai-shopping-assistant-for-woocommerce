/* Editor side of the server-rendered blocks dev3sa/chat (inline chat) and dev3sa/chat-button. */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const icon = (
	<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<path fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" d="M21 12c0 4.4-4 8-9 8-1.4 0-2.7-.3-3.9-.8L3 20l1.3-3.9C3.5 14.9 3 13.5 3 12c0-4.4 4-8 9-8s9 3.6 9 8z" />
	</svg>
);

registerBlockType( 'dev3sa/chat', {
	apiVersion: 2,
	title: __( 'Shopping assistant', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	description: __( 'An inline chat that finds products, tracks orders and answers store questions.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	category: 'woocommerce',
	icon,
	keywords: [ 'chat', 'assistant', 'bot', 'dev3' ],
	supports: { html: false, align: [ 'wide', 'full' ] },
	attributes: { height: { type: 'number', default: 560 } },
	edit: function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps( { className: 'dev3sa-block-preview' } );
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Chat', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
						<RangeControl label={ __( 'Height (px)', 'dev3-ai-shopping-assistant-for-woocommerce' ) } min={ 360 } max={ 1200 } step={ 20 } value={ attributes.height } onChange={ ( height ) => setAttributes( { height } ) } />
					</PanelBody>
				</InspectorControls>
				<div style={ { height: attributes.height, border: '1px solid #e5e7eb', borderRadius: 16, overflow: 'hidden', display: 'flex', flexDirection: 'column', fontFamily: 'inherit' } }>
					<div style={ { background: '#4f46e5', color: '#fff', padding: '14px 16px', fontWeight: 700 } }>{ __( 'Shopping assistant', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</div>
					<div style={ { flex: 1, padding: 16, color: '#4b5563', background: '#fff' } }>
						<p style={ { background: '#f3f4f6', borderRadius: 16, padding: '10px 13px', maxWidth: '80%', margin: 0 } }>{ __( 'The live chat appears here on the front end.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</p>
					</div>
					<div style={ { borderTop: '1px solid #e5e7eb', padding: 12, color: '#6b7280', background: '#fff' } }>{ __( 'Ask about products, orders or shipping…', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</div>
				</div>
			</div>
		);
	},
	save: () => null,
} );

registerBlockType( 'dev3sa/chat-button', {
	apiVersion: 2,
	title: __( 'Open chat button', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	description: __( 'A button that opens the shopping assistant.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
	category: 'woocommerce',
	icon,
	keywords: [ 'chat', 'button', 'dev3' ],
	supports: { html: false, align: [ 'left', 'center', 'right' ] },
	attributes: { label: { type: 'string', default: '' } },
	edit: function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps();
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Button', 'dev3-ai-shopping-assistant-for-woocommerce' ) }>
						<TextControl label={ __( 'Label', 'dev3-ai-shopping-assistant-for-woocommerce' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } help={ __( 'Empty = the launcher label from the plugin settings.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
					</PanelBody>
				</InspectorControls>
				<span className="wp-element-button">{ attributes.label || __( 'Chat with us', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</span>
			</div>
		);
	},
	save: () => null,
} );
