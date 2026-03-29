/**
 * Import progress component.
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
import {
	fetchImportProgress,
	pauseImport,
	resumeImport,
	rollbackImport,
} from '../api';

/**
 * ImportProgress component shows real-time import status.
 *
 * @param {Object}   props            Component props.
 * @param {string}   props.batchId    The import batch ID.
 * @param {Function} props.onComplete Called when import finishes.
 * @return {JSX.Element} The component.
 */
export default function ImportProgress( { batchId, onComplete } ) {
	const [ progress, setProgress ] = useState( null );
	const [ error, setError ] = useState( null );

	const poll = useCallback( async () => {
		try {
			const data = await fetchImportProgress( batchId );
			setProgress( data );

			if (
				data.state === 'completed' ||
				data.state === 'failed' ||
				data.state === 'rolled_back'
			) {
				onComplete?.( data );
			}
		} catch ( err ) {
			setError(
				err.message || __( 'Failed to fetch progress.', 'ai-importer' )
			);
		}
	}, [ batchId, onComplete ] );

	useEffect( () => {
		poll();
		const interval = setInterval( () => {
			if ( progress?.state === 'processing' ) {
				poll();
			}
		}, 3000 );

		return () => clearInterval( interval );
	}, [ poll, progress?.state ] );

	const handlePause = async () => {
		try {
			const data = await pauseImport( batchId );
			setProgress( data );
		} catch ( err ) {
			setError( err.message );
		}
	};

	const handleResume = async () => {
		try {
			const data = await resumeImport( batchId );
			setProgress( data );
		} catch ( err ) {
			setError( err.message );
		}
	};

	const handleRollback = async () => {
		/* eslint-disable no-alert */
		if (
			! window.confirm(
				__(
					'Are you sure? This will delete all imported content from this batch.',
					'ai-importer'
				)
			)
		) {
			return;
		}
		/* eslint-enable no-alert */
		try {
			await rollbackImport( batchId );
			// Re-fetch progress to get the actual state from the server.
			const data = await fetchImportProgress( batchId );
			setProgress( data );
		} catch ( err ) {
			setError( err.message );
		}
	};

	if ( ! progress ) {
		return <p>{ __( 'Loading…', 'ai-importer' ) }</p>;
	}

	const isActive = progress.state === 'processing';
	const isPaused = progress.state === 'paused';
	const isDone =
		progress.state === 'completed' ||
		progress.state === 'failed' ||
		progress.state === 'rolled_back';

	return (
		<div className="ai-importer-progress">
			{ error && (
				<Notice
					status="error"
					isDismissible
					onDismiss={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h2>
						{ __( 'Import Progress', 'ai-importer' ) }
						<span
							className={ `ai-importer-progress__state ai-importer-progress__state--${ progress.state }` }
						>
							{ progress.state_label }
						</span>
					</h2>
				</CardHeader>
				<CardBody>
					<div className="ai-importer-progress__bar-container">
						<div
							className="ai-importer-progress__bar"
							style={ { width: `${ progress.percentage }%` } }
						/>
					</div>
					<div className="ai-importer-progress__stats">
						<span>{ progress.percentage }%</span>
						<span>
							{ progress.processed } / { progress.total }{ ' ' }
							{ __( 'processed', 'ai-importer' ) }
						</span>
						{ progress.failed > 0 && (
							<span className="ai-importer-progress__failed">
								{ progress.failed }{ ' ' }
								{ __( 'failed', 'ai-importer' ) }
							</span>
						) }
					</div>

					{ progress.errors?.length > 0 && (
						<details className="ai-importer-progress__errors">
							<summary>
								{ progress.errors.length }{ ' ' }
								{ __( 'errors', 'ai-importer' ) }
							</summary>
							<ul>
								{ progress.errors
									.slice( -10 )
									.map( ( err, i ) => (
										<li key={ i }>
											<code>{ err.item_id }</code>:{ ' ' }
											{ err.message }
										</li>
									) ) }
							</ul>
						</details>
					) }

					<div className="ai-importer-progress__actions">
						{ isActive && (
							<Button variant="secondary" onClick={ handlePause }>
								{ __( 'Pause', 'ai-importer' ) }
							</Button>
						) }
						{ isPaused && (
							<Button variant="primary" onClick={ handleResume }>
								{ __( 'Resume', 'ai-importer' ) }
							</Button>
						) }
						{ isDone && progress.state !== 'rolled_back' && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ handleRollback }
							>
								{ __( 'Rollback Import', 'ai-importer' ) }
							</Button>
						) }
					</div>
				</CardBody>
			</Card>
		</div>
	);
}
