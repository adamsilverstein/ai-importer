/**
 * Main App component.
 */

import { __ } from '@wordpress/i18n';
import { Card, CardBody, CardHeader } from '@wordpress/components';

/**
 * App component.
 *
 * @return {JSX.Element} The App component.
 */
function App() {
	return (
		<div className="ai-importer-app">
			<h1>{ __( 'AI Importer', 'ai-importer' ) }</h1>
			<Card>
				<CardHeader>
					<h2>{ __( 'Welcome to AI Importer', 'ai-importer' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Import your content from social media platforms into WordPress using AI-powered analysis and mapping.',
							'ai-importer'
						) }
					</p>
					<p>
						{ __(
							'Get started by connecting a source or uploading an archive file.',
							'ai-importer'
						) }
					</p>
				</CardBody>
			</Card>
		</div>
	);
}

export default App;
