/**
 * Main App component with page routing.
 */

import { __ } from '@wordpress/i18n';

import Dashboard from './pages/Dashboard';
import ImportWizard from './pages/ImportWizard';
import Sources from './pages/Sources';
import History from './pages/History';

/**
 * Get the page component based on the current admin page slug.
 *
 * @return {JSX.Element} The page component.
 */
function App() {
	const page = aiImporter?.currentPage || 'ai-importer';

	const pages = {
		'ai-importer': Dashboard,
		'ai-importer-import': ImportWizard,
		'ai-importer-sources': Sources,
		'ai-importer-history': History,
	};

	const PageComponent = pages[ page ] || Dashboard;

	return (
		<div className="ai-importer-app">
			<h1>{ __( 'AI Importer', 'ai-importer' ) }</h1>
			<PageComponent />
		</div>
	);
}

export default App;
