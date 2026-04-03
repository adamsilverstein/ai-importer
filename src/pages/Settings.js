/**
 * Settings page placeholder.
 */

import { __ } from '@wordpress/i18n';
import { Card, CardBody, CardHeader } from '@wordpress/components';

/**
 * Settings page component.
 *
 * @return {JSX.Element} The component.
 */
export default function Settings() {
	return (
		<div className="ai-importer-settings">
			<Card>
				<CardHeader>
					<h2>{ __( 'Settings', 'ai-importer' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Settings will be available in a future update.',
							'ai-importer'
						) }
					</p>
				</CardBody>
			</Card>
		</div>
	);
}
