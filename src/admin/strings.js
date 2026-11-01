import config from '../core3/config';

/**
 * Storefront chat strings (translated on the server, printed in the admin boot config).
 *
 * @return {Object} Strings.
 */
export function strings() {
	return config.chatStrings || {};
}
