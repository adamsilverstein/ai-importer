/**
 * File upload component for archive-based adapters.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, DropZone, Notice } from '@wordpress/components';

/**
 * FileUpload component for uploading archive files.
 *
 * @param {Object}   props           Component props.
 * @param {string}   props.accept    Accepted file types (e.g., '.zip').
 * @param {Function} props.onUpload  Called with the selected File object.
 * @param {boolean}  props.isLoading Whether upload is in progress.
 * @return {JSX.Element} The component.
 */
export default function FileUpload( { accept = '.zip', onUpload, isLoading } ) {
	const [ file, setFile ] = useState( null );
	const [ error, setError ] = useState( null );

	const handleFileChange = ( event ) => {
		const selectedFile = event.target.files?.[ 0 ];
		if ( selectedFile ) {
			setFile( selectedFile );
			setError( null );
		}
	};

	const handleDrop = ( files ) => {
		if ( files?.length > 0 ) {
			setFile( files[ 0 ] );
			setError( null );
		}
	};

	const handleUpload = () => {
		if ( ! file ) {
			setError( __( 'Please select a file first.', 'ai-importer' ) );
			return;
		}
		onUpload( file );
	};

	const formatFileSize = ( bytes ) => {
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		if ( bytes < 1024 * 1024 ) {
			return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		}
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	};

	return (
		<div className="ai-importer-file-upload">
			{ error && (
				<Notice
					status="error"
					isDismissible
					onDismiss={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			<div className="ai-importer-file-upload__dropzone">
				<DropZone onFilesDrop={ handleDrop } />
				{ file ? (
					<div className="ai-importer-file-upload__selected">
						<p>
							<strong>{ file.name }</strong>
							<span className="ai-importer-file-upload__size">
								{ formatFileSize( file.size ) }
							</span>
						</p>
						<Button
							variant="link"
							isDestructive
							onClick={ () => setFile( null ) }
						>
							{ __( 'Remove', 'ai-importer' ) }
						</Button>
					</div>
				) : (
					<div className="ai-importer-file-upload__prompt">
						<p>
							{ __(
								'Drop your archive file here, or click to browse.',
								'ai-importer'
							) }
						</p>
						<input
							type="file"
							accept={ accept }
							onChange={ handleFileChange }
							className="ai-importer-file-upload__input"
						/>
					</div>
				) }
			</div>

			<Button
				variant="primary"
				onClick={ handleUpload }
				disabled={ ! file || isLoading }
				isBusy={ isLoading }
			>
				{ isLoading
					? __( 'Uploading…', 'ai-importer' )
					: __( 'Upload & Connect', 'ai-importer' ) }
			</Button>
		</div>
	);
}
