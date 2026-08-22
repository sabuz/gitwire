import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

import App from './app';
import { toast } from './toast';
import {
	PROVIDER_LABELS,
	providerLabel,
	ProviderIcon,
} from './components/provider';
import { relativeTimeFromUnix, relativeTimeFromDate } from './relative-time';
import './style.scss';

if ( window.gitwire?.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( window.gitwire.nonce ) );
}

// Shared singletons reused by the Pro bundle.
if ( window.gitwire ) {
	window.gitwire.toast = toast;
	window.gitwire.ui = {
		PROVIDER_LABELS,
		providerLabel,
		ProviderIcon,
		relativeTimeFromUnix,
		relativeTimeFromDate,
	};
}

const container = document.getElementById( 'gitwire-app' );
if ( container ) {
	createRoot( container ).render(
		<App initialData={ window.gitwire || {} } />
	);
}
