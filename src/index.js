import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

import App from './app';
import './style.scss';

if ( window.Gitwire?.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( window.Gitwire.nonce ) );
}

const container = document.getElementById( 'gitwire-app' );
if ( container ) {
	createRoot( container ).render( <App initialData={ window.Gitwire || {} } /> );
}
