/**
 * Dashboard page component.
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import { fetchSources, fetchImports } from '../api';

/**
 * Dashboard page showing overview and recent activity.
 *
 * @return {JSX.Element} The dashboard page.
 */
export default function Dashboard() {
	const [ sources, setSources ] = useState( [] );
	const [ imports, setImports ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		const load = async () => {
			const [ sourcesData, importsData ] = await Promise.all( [
				fetchSources().catch( () => [] ),
				fetchImports( 5 ).catch( () => [] ),
			] );
			setSources( sourcesData );
			setImports( importsData );
			setLoading( false );
		};
		load();
	}, [] );

	if ( loading ) {
		return <p>{ __( 'Loading…', 'ai-importer' ) }</p>;
	}

	const connectedSources = sources.filter( ( s ) => s.is_authenticated );
	const adminUrl = aiImporter?.adminUrl || '';

	return (
		<div className="ai-importer-dashboard">
			{ error && (
				<Notice
					status="error"
					isDismissible
					onDismiss={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			<div className="ai-importer-dashboard__cards">
				<Card>
					<CardHeader>
						<h2>{ __( 'Quick Actions', 'ai-importer' ) }</h2>
					</CardHeader>
					<CardBody>
						<div className="ai-importer-dashboard__actions">
							<Button
								variant="primary"
								href={ `${ adminUrl }admin.php?page=ai-importer-import` }
							>
								{ __( 'Start New Import', 'ai-importer' ) }
							</Button>
							<Button
								variant="secondary"
								href={ `${ adminUrl }admin.php?page=ai-importer-sources` }
							>
								{ __( 'Manage Sources', 'ai-importer' ) }
							</Button>
						</div>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h2>{ __( 'Connected Sources', 'ai-importer' ) }</h2>
					</CardHeader>
					<CardBody>
						{ connectedSources.length > 0 ? (
							<ul className="ai-importer-dashboard__source-list">
								{ connectedSources.map( ( source ) => (
									<li key={ source.id }>
										<strong>{ source.name }</strong>
									</li>
								) ) }
							</ul>
						) : (
							<p>
								{ __(
									'No sources connected yet.',
									'ai-importer'
								) }{ ' ' }
								<a
									href={ `${ adminUrl }admin.php?page=ai-importer-import` }
								>
									{ __(
										'Connect your first source',
										'ai-importer'
									) }
								</a>
							</p>
						) }
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h2>{ __( 'Recent Imports', 'ai-importer' ) }</h2>
					</CardHeader>
					<CardBody>
						{ imports.length > 0 ? (
							<table className="ai-importer-dashboard__imports-table">
								<thead>
									<tr>
										<th>
											{ __( 'Source', 'ai-importer' ) }
										</th>
										<th>
											{ __( 'Status', 'ai-importer' ) }
										</th>
										<th>
											{ __( 'Items', 'ai-importer' ) }
										</th>
										<th>{ __( 'Date', 'ai-importer' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ imports.map( ( batch ) => (
										<tr key={ batch.id }>
											<td>{ batch.source_adapter }</td>
											<td>
												<span
													className={ `ai-importer-status ai-importer-status--${ batch.state }` }
												>
													{ batch.state_label }
												</span>
											</td>
											<td>
												{ batch.processed } /{ ' ' }
												{ batch.total }
											</td>
											<td>
												{ batch.created_at
													? new Date(
															batch.created_at
													  ).toLocaleDateString()
													: '' }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) : (
							<p>
								{ __(
									'No imports yet. Start your first import to see activity here.',
									'ai-importer'
								) }
							</p>
						) }
						{ imports.length > 0 && (
							<Button
								variant="link"
								href={ `${ adminUrl }admin.php?page=ai-importer-history` }
							>
								{ __( 'View all imports', 'ai-importer' ) }
							</Button>
						) }
					</CardBody>
				</Card>
			</div>
		</div>
	);
}
