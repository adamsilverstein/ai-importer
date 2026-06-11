/**
 * Import progress component.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
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
 * Format an ETA in seconds as a human-friendly string.
 *
 * @param {number} seconds Estimated seconds remaining.
 * @return {string} Human-readable time remaining.
 */
function formatEta( seconds ) {
	if ( seconds < 60 ) {
		return __( 'less than a minute remaining', 'ai-importer' );
	}

	if ( seconds < 3600 ) {
		const minutes = Math.round( seconds / 60 );
		return sprintf(
			/* translators: %d: number of minutes remaining. */
			_n(
				'about %d minute remaining',
				'about %d minutes remaining',
				minutes,
				'ai-importer'
			),
			minutes
		);
	}

	const hours = Math.round( seconds / 3600 );
	return sprintf(
		/* translators: %d: number of hours remaining. */
		_n(
			'about %d hour remaining',
			'about %d hours remaining',
			hours,
			'ai-importer'
		),
		hours
	);
}

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
	const pollingRef = useRef( false );
	const completedRef = useRef( false );

	const poll = useCallback( async () => {
		if ( pollingRef.current ) {
			return;
		}
		pollingRef.current = true;
		try {
			const data = await fetchImportProgress( batchId );
			setProgress( data );

			if (
				( data.state === 'completed' ||
					data.state === 'failed' ||
					data.state === 'rolled_back' ) &&
				! completedRef.current
			) {
				completedRef.current = true;
				onComplete?.( data );
			}
			if ( data.state === 'processing' ) {
				completedRef.current = false;
			}
		} catch ( err ) {
			setError(
				err.message || __( 'Failed to fetch progress.', 'ai-importer' )
			);
		} finally {
			pollingRef.current = false;
		}
	}, [ batchId, onComplete ] );

	useEffect( () => {
		completedRef.current = false;
		poll();
	}, [ batchId, poll ] );

	useEffect( () => {
		if ( progress?.state !== 'processing' ) {
			return;
		}
		const interval = setInterval( () => {
			poll();
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
				<p>{ __( 'Loading…', 'ai-importer' ) }</p>
			</div>
		);
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
						{ progress.skipped > 0 && (
							<span className="ai-importer-progress__skipped">
								{ progress.skipped }{ ' ' }
								{ __( 'skipped', 'ai-importer' ) }
							</span>
						) }
						{ progress.failed > 0 && (
							<span className="ai-importer-progress__failed">
								{ progress.failed }{ ' ' }
								{ __( 'failed', 'ai-importer' ) }
							</span>
						) }
						{ isActive &&
							Number.isFinite( progress.eta_seconds ) && (
								<span className="ai-importer-progress__eta">
									{ formatEta( progress.eta_seconds ) }
								</span>
							) }
						{ isActive &&
							Number.isFinite( progress.items_per_minute ) && (
								<span className="ai-importer-progress__rate">
									{ sprintf(
										/* translators: %s: number of items imported per minute. */
										__( '%s items/min', 'ai-importer' ),
										progress.items_per_minute
									) }
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
