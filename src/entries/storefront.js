import { init } from '../storefront/index';
import '../storefront/storefront.css';

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => init( window ) );
} else {
	init( window );
}
