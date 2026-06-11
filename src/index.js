import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

import App from './app';
import { toast } from './toast';
import './style.scss';

if ( window.Gitwire?.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( window.Gitwire.nonce ) );
}

// shared toast singleton — the Pro bundle renders into the same Toaster
if ( window.Gitwire ) {
	window.Gitwire.toast = toast;
}

const container = document.getElementById( 'gitwire-app' );
if ( container ) {
	createRoot( container ).render(
		<App initialData={ window.Gitwire || {} } />
	);
}
