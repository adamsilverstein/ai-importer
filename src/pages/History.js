/**
 * Import history page.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import ImportProgress from '../components/ImportProgress';
import { fetchImports } from '../api';

/**
 * History page showing past imports with status.
 *
 * @return {JSX.Element} The history page.
 */
export default function History() {
	const [ imports, setImports ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ expandedBatch, setExpandedBatch ] = useState( null );

	const handleImportComplete = useCallback( () => {
		// Refresh the imports list when an import completes.
		fetchImports( 50 )
			.then( setImports )
			.catch( () => {} );
	}, [] );

	useEffect( () => {
		const load = async () => {
			try {
				const data = await fetchImports( 50 );
				setImports( data );
			} catch ( err ) {
				setError( err.message );
			} finally {
				setLoading( false );
			}
		};
		load();
	}, [] );

	const formatDate = ( dateStr ) => {
		if ( ! dateStr ) {
			return '-';
		}
		return new Date( dateStr ).toLocaleString();
	};

	if ( loading ) {
		return <p>{ __( 'Loading import history…', 'ai-importer' ) }</p>;
	}

	return (
		<div className="ai-importer-history">
			{ error && (
				<Notice
					status="error"
					isDismissible
					onDismiss={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			{ expandedBatch && (
				<>
					<Button
						variant="tertiary"
						onClick={ () => setExpandedBatch( null ) }
						className="ai-importer-history__back"
					>
						{ __( 'Back to history', 'ai-importer' ) }
					</Button>
					<ImportProgress
						batchId={ expandedBatch }
						onComplete={ handleImportComplete }
					/>
				</>
			) }

			{ ! expandedBatch && (
				<Card>
					<CardHeader>
						<h2>{ __( 'Import History', 'ai-importer' ) }</h2>
					</CardHeader>
					<CardBody>
						{ imports.length > 0 ? (
							<table className="ai-importer-history__table wp-list-table widefat striped">
								<thead>
									<tr>
										<th>
											{ __( 'Source', 'ai-importer' ) }
										</th>
										<th>
											{ __( 'Status', 'ai-importer' ) }
										</th>
										<th>
											{ __( 'Progress', 'ai-importer' ) }
										</th>
										<th>
											{ __( 'Items', 'ai-importer' ) }
										</th>
										<th>
											{ __( 'Started', 'ai-importer' ) }
										</th>
										<th>
											{ __( 'Actions', 'ai-importer' ) }
										</th>
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
											<td>{ batch.percentage }%</td>
											<td>
												{ batch.processed } /{ ' ' }
												{ batch.total }
												{ batch.failed > 0 && (
													<span className="ai-importer-history__failed">
														{ ' ' }
														({ batch.failed }{ ' ' }
														{ __(
															'failed',
															'ai-importer'
														) }
														)
													</span>
												) }
											</td>
											<td>
												{ formatDate(
													batch.started_at ||
														batch.created_at
												) }
											</td>
											<td>
												<Button
													variant="link"
													onClick={ () =>
														setExpandedBatch(
															batch.id
														)
													}
												>
													{ __(
														'Details',
														'ai-importer'
													) }
												</Button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) : (
							<p>{ __( 'No imports found.', 'ai-importer' ) }</p>
						) }
					</CardBody>
				</Card>
			) }
		</div>
	);
}
