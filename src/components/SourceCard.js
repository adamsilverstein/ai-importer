/**
 * Source adapter card component.
 */

import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Icon,
} from '@wordpress/components';
import { upload, check, close } from '@wordpress/icons';

/**
 * SourceCard component displays a single source adapter.
 *
 * @param {Object}   props              Component props.
 * @param {Object}   props.source       Source adapter data.
 * @param {Function} props.onSelect     Called when user selects source for import.
 * @param {Function} props.onDisconnect Called when user disconnects source.
 * @return {JSX.Element} The component.
 */
export default function SourceCard( { source, onSelect, onDisconnect } ) {
	const authIcon = source.auth_type === 'file_upload' ? upload : null;

	return (
		<Card className="ai-importer-source-card">
			<CardHeader>
				<div className="ai-importer-source-card__header">
					{ authIcon && <Icon icon={ authIcon } /> }
					<h3>{ source.name }</h3>
					{ source.is_authenticated && (
						<span className="ai-importer-source-card__status ai-importer-source-card__status--connected">
							<Icon icon={ check } />
							{ __( 'Connected', 'ai-importer' ) }
						</span>
					) }
				</div>
			</CardHeader>
			<CardBody>
				<p>{ source.description }</p>
				<div className="ai-importer-source-card__actions">
					{ source.is_authenticated ? (
						<>
							<Button
								variant="primary"
								onClick={ () => onSelect( source ) }
							>
								{ __( 'Import Content', 'ai-importer' ) }
							</Button>
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => onDisconnect( source.id ) }
							>
								<Icon icon={ close } />
								{ __( 'Disconnect', 'ai-importer' ) }
							</Button>
						</>
					) : (
						<Button
							variant="primary"
							onClick={ () => onSelect( source ) }
						>
							{ __( 'Connect & Import', 'ai-importer' ) }
						</Button>
					) }
				</div>
			</CardBody>
		</Card>
	);
}
