/**
 * AI Importer Admin UI
 *
 * Main entry point for the React admin interface.
 */

import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

// Wait for DOM to be ready.
document.addEventListener( 'DOMContentLoaded', () => {
	const rootElement = document.getElementById( 'ai-importer-root' );

	if ( rootElement ) {
		const root = createRoot( rootElement );
		root.render( <App /> );
	}
} );
