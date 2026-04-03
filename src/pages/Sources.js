/**
 * Sources management page.
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import SourceCard from '../components/SourceCard';
import { fetchSources, disconnectSource } from '../api';

/**
 * Sources page for managing connected adapters.
 *
 * @return {JSX.Element} The sources page.
 */
export default function Sources() {
	const [ sources, setSources ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		const load = async () => {
			try {
				const data = await fetchSources();
				setSources( data );
			} catch ( err ) {
				setError( err.message );
			} finally {
				setLoading( false );
			}
		};
		load();
	}, [] );

	const handleDisconnect = async ( sourceId ) => {
		try {
			await disconnectSource( sourceId );
			setSources( ( prev ) =>
				prev.map( ( s ) =>
					s.id === sourceId ? { ...s, is_authenticated: false } : s
				)
			);
			setNotice( __( 'Source disconnected.', 'ai-importer' ) );
		} catch ( err ) {
			setError( err.message );
		}
	};

	const handleSelect = () => {
		const adminUrl = aiImporter?.adminUrl || '';
		window.location.href = `${ adminUrl }admin.php?page=ai-importer-import`;
	};

	if ( loading ) {
		return <p>{ __( 'Loading sources…', 'ai-importer' ) }</p>;
	}

	return (
		<div className="ai-importer-sources">
			{ error && (
				<Notice
					status="error"
					isDismissible
					onDismiss={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }
			{ notice && (
				<Notice
					status="success"
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice }
				</Notice>
			) }

			<div className="ai-importer-sources__grid">
				{ sources.map( ( source ) => (
					<SourceCard
						key={ source.id }
						source={ source }
						onSelect={ handleSelect }
						onDisconnect={ handleDisconnect }
					/>
				) ) }
			</div>
		</div>
	);
}
