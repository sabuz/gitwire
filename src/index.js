import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

import App from './app';
import './style.scss';

if ( window.GWP?.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( window.GWP.nonce ) );
}

const container = document.getElementById( 'gwp-app' );
if ( container ) {
	createRoot( container ).render( <App initialData={ window.GWP || {} } /> );
}
