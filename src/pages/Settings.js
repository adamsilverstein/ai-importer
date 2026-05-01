/**
 * Settings page.
 *
 * AI Importer relies on the WordPress 7.0+ Connectors API for all AI
 * provider configuration. This page intentionally exposes no API-key
 * fields — it only points users to core's Connections settings.
 */

import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
} from '@wordpress/components';

/**
 * Settings page component.
 *
 * @return {JSX.Element} The component.
 */
export default function Settings() {
	const adminUrl = aiImporter?.adminUrl || '';
	const connectionsUrl = `${ adminUrl }options-general.php?page=connections`;

	return (
		<div className="ai-importer-settings">
			<Card>
				<CardHeader>
					<h2>{ __( 'AI Provider', 'ai-importer' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'AI Importer uses WordPress core AI features (introduced in WordPress 7.0). Configure your AI provider once in Settings → Connections and AI Importer will use it automatically.',
							'ai-importer'
						) }
					</p>
					<p>
						<Button variant="primary" href={ connectionsUrl }>
							{ __( 'Open Connections settings', 'ai-importer' ) }
						</Button>
					</p>
					<p>
						<ExternalLink href="https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/">
							{ __(
								'Learn about the Connectors API',
								'ai-importer'
							) }
						</ExternalLink>
					</p>
				</CardBody>
			</Card>
		</div>
	);
}
