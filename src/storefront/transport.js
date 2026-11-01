import { joinUrl } from './dom';

/**
 * REST transport for dev3sa/v1/public/*. Same-origin cookies (WooCommerce cart session); logged-in
 * shoppers also send the REST nonce so WordPress knows who they are.
 *
 * @param {Object} config Boot config (rest, nonce).
 * @return {Function} request( route, { method, data } ) → Promise<Object>.
 */
export function createTransport( config ) {
	return function request( route, { method = 'GET', data } = {} ) {
		const headers = { Accept: 'application/json' };
		if ( data ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		if ( config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}
		return window
			.fetch( joinUrl( config.rest, route ), {
				method,
				headers,
				credentials: 'same-origin',
				body: data ? JSON.stringify( data ) : undefined,
			} )
			.then(
				( res ) =>
					res
						.json()
						.catch( () => ( {} ) )
						.then( ( body ) => {
							if ( ! res.ok ) {
								const err = new Error( ( body && body.message ) || 'Request failed' );
								err.code = ( body && body.code ) || 'http_' + res.status;
								err.status = res.status;
								throw err;
							}
							return body;
						} ),
				( e ) => {
					const err = new Error( 'Network error' );
					err.code = 'network';
					err.cause = e;
					throw err;
				}
			);
	};
}
