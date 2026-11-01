import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import Conversations from './Conversations';
import Knowledge from './Knowledge';
import WidgetTab from './WidgetTab';
import TestConsole from './TestConsole';
import Insights from './Insights';
import './admin.css';


export const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-shopping-assistant-for-woocommerce' ), icon: 'dashboard' },
	{ id: 'conversations', label: __( 'Conversations', 'dev3-ai-shopping-assistant-for-woocommerce' ), icon: 'inbox' },
	{ id: 'knowledge', label: __( 'Knowledge base', 'dev3-ai-shopping-assistant-for-woocommerce' ), icon: 'file' },
	{ id: 'widget', label: __( 'Chat widget', 'dev3-ai-shopping-assistant-for-woocommerce' ), icon: 'settings' },
	{ id: 'test', label: __( 'Test console', 'dev3-ai-shopping-assistant-for-woocommerce' ), icon: 'bolt' },
	{ id: 'insights', label: __( 'AI insights', 'dev3-ai-shopping-assistant-for-woocommerce' ), icon: 'sparkles' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-shopping-assistant-for-woocommerce' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-shopping-assistant-for-woocommerce' ), icon: 'chart' },
];

export default function App() {
	const [ route, navigate ] = useHashRoute( NAV.map( ( n ) => n.id ), 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ badge, setBadge ] = useState( 0 );

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] );
	useEffect( () => {
		request( 'stats?days=30' )
			.then( ( s ) => setBadge( s.stats.needs_reply ) )
			.catch( () => {} );
	}, [ meter ] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );
	const onUsage = () => setMeter( ( n ) => n + 1 );
	const props = { settings, save, onUsage, navigate, params: route.params };

	const screens = {
		dashboard: () => <Dashboard { ...props } />,
		conversations: () => <Conversations { ...props } />,
		knowledge: () => <Knowledge { ...props } />,
		widget: () => <WidgetTab { ...props } />,
		test: () => <TestConsole { ...props } />,
		insights: () => <Insights { ...props } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget. The storefront AI switch turns the shopper chat AI off while keeping the guided assistant.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-shopping-assistant-for-woocommerce' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-shopping-assistant-for-woocommerce' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Shopping Assistant & Chatbot for WooCommerce' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ NAV.map( ( n ) => ( n.id === 'conversations' && badge ? { ...n, badge } : n ) ) }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-shopping-assistant-for-woocommerce' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
