import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

import App from './app';
import './style.scss';

if (window.GHWP?.nonce) {
	apiFetch.use(apiFetch.createNonceMiddleware(window.GHWP.nonce));
}

const container = document.getElementById('ghwp-app');
if (container) {
	createRoot(container).render(<App initialData={window.GHWP || {}} />);
}
