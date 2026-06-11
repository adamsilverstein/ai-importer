/**
 * Import wizard page - multi-step import flow.
 */

import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import SourceCard from '../components/SourceCard';
import FileUpload from '../components/FileUpload';
import ContentReview from '../components/ContentReview';
import MappingConfiguration from '../components/MappingConfiguration';
import ImportProgress from '../components/ImportProgress';
import {
	fetchSources,
	connectSource,
	fetchManifest,
	startImport,
} from '../api';

const STEP_SELECT_SOURCE = 'select-source';
const STEP_CONNECT = 'connect';
const STEP_REVIEW = 'review';
const STEP_MAPPING = 'mapping';
const STEP_IMPORT = 'import';
const STEP_COMPLETE = 'complete';

/**
 * ImportWizard component provides a multi-step import flow.
 *
 * @return {JSX.Element} The wizard component.
 */
export default function ImportWizard() {
	const [ step, setStep ] = useState( STEP_SELECT_SOURCE );
	const [ sources, setSources ] = useState( [] );
	const [ selectedSource, setSelectedSource ] = useState( null );
	const [ manifest, setManifest ] = useState( null );
	const [ pendingItemIds, setPendingItemIds ] = useState( [] );
	const [ batchId, setBatchId ] = useState( null );
	const [ completedData, setCompletedData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ sourcesLoaded, setSourcesLoaded ] = useState( false );

	const loadSources = useCallback( async () => {
		if ( sourcesLoaded ) {
			return;
		}
		setLoading( true );
		try {
			const data = await fetchSources();
			setSources( data );
			setSourcesLoaded( true );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setLoading( false );
		}
	}, [ sourcesLoaded ] );

	// Load sources on first render.
	if ( ! sourcesLoaded && ! loading ) {
		loadSources();
	}

	const handleSelectSource = ( source ) => {
		setSelectedSource( source );
		if ( source.is_authenticated ) {
			loadManifest( source.id );
		} else {
			setStep( STEP_CONNECT );
		}
	};

	const handleUpload = async ( file ) => {
		setLoading( true );
		setError( null );
		try {
			const formData = new FormData();
			formData.append( 'archive_file', file );

			await connectSource( selectedSource.id, {}, formData );
			await loadManifest( selectedSource.id );
		} catch ( err ) {
			setError(
				err.message || __( 'Failed to connect source.', 'ai-importer' )
			);
			setLoading( false );
		}
	};

	const loadManifest = async ( sourceId ) => {
		setLoading( true );
		setError( null );
		try {
			const data = await fetchManifest( sourceId );
			setManifest( data );
			setStep( STEP_REVIEW );
		} catch ( err ) {
			setError(
				err.message || __( 'Failed to fetch content.', 'ai-importer' )
			);
		} finally {
			setLoading( false );
		}
	};

	const handleReviewContinue = ( itemIds ) => {
		setPendingItemIds( itemIds );
		setError( null );
		setStep( STEP_MAPPING );
	};

	const handleStartImport = async ( mapping ) => {
		setLoading( true );
		setError( null );
		try {
			const batch = await startImport(
				selectedSource.id,
				pendingItemIds,
				mapping
			);
			setBatchId( batch.id );
			setStep( STEP_IMPORT );
		} catch ( err ) {
			setError(
				err.message || __( 'Failed to start import.', 'ai-importer' )
			);
		} finally {
			setLoading( false );
		}
	};

	const handleImportComplete = ( data ) => {
		setCompletedData( data );
		setStep( STEP_COMPLETE );
	};

	const handleStartOver = () => {
		setStep( STEP_SELECT_SOURCE );
		setSelectedSource( null );
		setManifest( null );
		setPendingItemIds( [] );
		setBatchId( null );
		setCompletedData( null );
		setError( null );
	};

	return (
		<div className="ai-importer-wizard">
			{ error && (
				<Notice
					status="error"
					isDismissible
					onDismiss={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			<div className="ai-importer-wizard__steps">
				<StepIndicator
					steps={ [
						{
							key: STEP_SELECT_SOURCE,
							label: __( 'Select Source', 'ai-importer' ),
						},
						{
							key: STEP_CONNECT,
							label: __( 'Connect', 'ai-importer' ),
						},
						{
							key: STEP_REVIEW,
							label: __( 'Review', 'ai-importer' ),
						},
						{
							key: STEP_MAPPING,
							label: __( 'Configure Mapping', 'ai-importer' ),
						},
						{
							key: STEP_IMPORT,
							label: __( 'Import', 'ai-importer' ),
						},
					] }
					current={ step }
				/>
			</div>

			{ step === STEP_SELECT_SOURCE && (
				<div className="ai-importer-wizard__source-grid">
					{ loading && (
						<p>{ __( 'Loading sources…', 'ai-importer' ) }</p>
					) }
					{ sources.map( ( source ) => (
						<SourceCard
							key={ source.id }
							source={ source }
							onSelect={ handleSelectSource }
							onDisconnect={ () => {} }
						/>
					) ) }
					{ ! loading && sources.length === 0 && (
						<p>
							{ __(
								'No source adapters available.',
								'ai-importer'
							) }
						</p>
					) }
				</div>
			) }

			{ step === STEP_CONNECT && selectedSource && (
				<Card>
					<CardHeader>
						<h2>
							{ /* translators: %s: source name */ }
							{ __( 'Connect to', 'ai-importer' ) }{ ' ' }
							{ selectedSource.name }
						</h2>
					</CardHeader>
					<CardBody>
						{ selectedSource.auth_type === 'file_upload' ? (
							<FileUpload
								accept=".zip"
								onUpload={ handleUpload }
								isLoading={ loading }
							/>
						) : (
							<p>
								{ __(
									'OAuth and API key connections are coming soon.',
									'ai-importer'
								) }
							</p>
						) }
						<Button
							variant="tertiary"
							onClick={ handleStartOver }
							className="ai-importer-wizard__back"
						>
							{ __( 'Back to sources', 'ai-importer' ) }
						</Button>
					</CardBody>
				</Card>
			) }

			{ step === STEP_REVIEW && manifest && (
				<>
					<ContentReview
						manifest={ manifest }
						onImport={ handleReviewContinue }
						isLoading={ loading }
					/>
					<Button
						variant="tertiary"
						onClick={ handleStartOver }
						className="ai-importer-wizard__back"
					>
						{ __( 'Start over', 'ai-importer' ) }
					</Button>
				</>
			) }

			{ step === STEP_MAPPING && selectedSource && (
				<MappingConfiguration
					sourceId={ selectedSource.id }
					onStartImport={ handleStartImport }
					onBack={ () => setStep( STEP_REVIEW ) }
					isLoading={ loading }
				/>
			) }

			{ step === STEP_IMPORT && batchId && (
				<ImportProgress
					batchId={ batchId }
					onComplete={ handleImportComplete }
				/>
			) }

			{ step === STEP_COMPLETE && completedData && (
				<Card>
					<CardHeader>
						<h2>
							{ completedData.state === 'completed'
								? __( 'Import Complete', 'ai-importer' )
								: __( 'Import Finished', 'ai-importer' ) }
						</h2>
					</CardHeader>
					<CardBody>
						<div className="ai-importer-wizard__summary">
							<p>
								<strong>{ completedData.processed }</strong>{ ' ' }
								{ __(
									'items imported successfully.',
									'ai-importer'
								) }
							</p>
							{ completedData.failed > 0 && (
								<p className="ai-importer-wizard__summary-failed">
									<strong>{ completedData.failed }</strong>{ ' ' }
									{ __( 'items failed.', 'ai-importer' ) }
								</p>
							) }
						</div>
						<div className="ai-importer-wizard__complete-actions">
							<Button
								variant="primary"
								onClick={ handleStartOver }
							>
								{ __( 'Import More Content', 'ai-importer' ) }
							</Button>
							<Button
								variant="secondary"
								href={ `${
									window.aiImporter?.adminUrl || ''
								}edit.php?post_status=draft` }
							>
								{ __(
									'Review Imported Drafts',
									'ai-importer'
								) }
							</Button>
						</div>
					</CardBody>
				</Card>
			) }
		</div>
	);
}

/**
 * Step indicator showing progress through the wizard.
 *
 * @param {Object} props         Component props.
 * @param {Array}  props.steps   Array of step objects with key and label.
 * @param {string} props.current Current step key.
 * @return {JSX.Element} The step indicator.
 */
function StepIndicator( { steps, current } ) {
	const currentIndex = steps.findIndex( ( s ) => s.key === current );

	return (
		<div className="ai-importer-step-indicator">
			{ steps.map( ( s, index ) => (
				<div
					key={ s.key }
					className={ `ai-importer-step-indicator__step${
						index === currentIndex
							? ' ai-importer-step-indicator__step--active'
							: ''
					}${
						index < currentIndex
							? ' ai-importer-step-indicator__step--completed'
							: ''
					}` }
				>
					<span className="ai-importer-step-indicator__number">
						{ index + 1 }
					</span>
					<span className="ai-importer-step-indicator__label">
						{ s.label }
					</span>
				</div>
			) ) }
		</div>
	);
}
