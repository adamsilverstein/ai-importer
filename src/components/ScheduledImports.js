/**
 * Scheduled imports management (PRD F10.3).
 *
 * Lists configured schedules for connected sources and provides a form to
 * add new schedules, toggle them on/off, and delete them.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import {
	fetchSources,
	fetchSchedules,
	saveSchedule,
	deleteSchedule,
} from '../api';

const INTERVAL_OPTIONS = [
	{ label: __( 'Hourly', 'ai-importer' ), value: 'hourly' },
	{ label: __( 'Daily', 'ai-importer' ), value: 'daily' },
	{ label: __( 'Weekly', 'ai-importer' ), value: 'weekly' },
];

/**
 * Format an ISO date string for display.
 *
 * @param {?string} dateStr ISO 8601 date string.
 * @return {string} Localized date or a dash.
 */
function formatDate( dateStr ) {
	if ( ! dateStr ) {
		return '—';
	}
	return new Date( dateStr ).toLocaleString();
}

/**
 * Scheduled imports component.
 *
 * @return {JSX.Element} The component.
 */
export default function ScheduledImports() {
	const [ schedules, setSchedules ] = useState( [] );
	const [ sources, setSources ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );

	const [ sourceAdapter, setSourceAdapter ] = useState( '' );
	const [ interval, setInterval ] = useState( 'daily' );
	const [ updateExisting, setUpdateExisting ] = useState( true );

	const connectedSources = sources.filter(
		( source ) => source.is_authenticated
	);

	const reloadSchedules = useCallback( async () => {
		const data = await fetchSchedules();
		setSchedules( data );
	}, [] );

	useEffect( () => {
		const load = async () => {
			try {
				const [ sourceData, scheduleData ] = await Promise.all( [
					fetchSources(),
					fetchSchedules(),
				] );
				const sourceList = Array.isArray( sourceData )
					? sourceData
					: Object.values( sourceData || {} );
				setSources( sourceList );
				setSchedules( scheduleData );

				const firstConnected = sourceList.find(
					( source ) => source.is_authenticated
				);
				if ( firstConnected ) {
					setSourceAdapter( firstConnected.id );
				}
			} catch ( err ) {
				setError( err.message );
			} finally {
				setLoading( false );
			}
		};
		load();
	}, [] );

	const sourceLabel = useCallback(
		( id ) => {
			const match = sources.find( ( source ) => source.id === id );
			return match ? match.name : id;
		},
		[ sources ]
	);

	const handleAdd = async () => {
		if ( ! sourceAdapter ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			await saveSchedule( {
				sourceAdapter,
				interval,
				updateExisting,
				enabled: true,
			} );
			await reloadSchedules();
		} catch ( err ) {
			setError( err.message );
		} finally {
			setSaving( false );
		}
	};

	const handleToggle = async ( schedule, enabled ) => {
		setError( null );
		try {
			await saveSchedule( {
				id: schedule.id,
				sourceAdapter: schedule.source_adapter,
				interval: schedule.interval,
				updateExisting: schedule.update_existing,
				enabled,
			} );
			await reloadSchedules();
		} catch ( err ) {
			setError( err.message );
		}
	};

	const handleDelete = async ( schedule ) => {
		setError( null );
		try {
			await deleteSchedule( schedule.id );
			await reloadSchedules();
		} catch ( err ) {
			setError( err.message );
		}
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Scheduled Imports', 'ai-importer' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Automatically import new content from connected sources on a recurring schedule. Scheduled runs only import items that have not been imported before.',
						'ai-importer'
					) }
				</p>

				{ error && (
					<Notice
						status="error"
						isDismissible
						onDismiss={ () => setError( null ) }
					>
						{ error }
					</Notice>
				) }

				{ loading ? (
					<Spinner />
				) : (
					<>
						{ schedules.length > 0 ? (
							<table className="ai-importer-schedules__table wp-list-table widefat striped">
								<thead>
									<tr>
										<th scope="col">
											{ __( 'Source', 'ai-importer' ) }
										</th>
										<th scope="col">
											{ __( 'Interval', 'ai-importer' ) }
										</th>
										<th scope="col">
											{ __( 'Enabled', 'ai-importer' ) }
										</th>
										<th scope="col">
											{ __( 'Last run', 'ai-importer' ) }
										</th>
										<th scope="col">
											{ __( 'Next run', 'ai-importer' ) }
										</th>
										<th scope="col">
											{ __( 'Actions', 'ai-importer' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ schedules.map( ( schedule ) => (
										<tr key={ schedule.id }>
											<td>
												{ sourceLabel(
													schedule.source_adapter
												) }
											</td>
											<td>{ schedule.interval }</td>
											<td>
												<ToggleControl
													__nextHasNoMarginBottom
													label={ __(
														'Enabled',
														'ai-importer'
													) }
													hideLabelFromVision
													checked={ schedule.enabled }
													onChange={ ( value ) =>
														handleToggle(
															schedule,
															value
														)
													}
												/>
											</td>
											<td>
												{ formatDate(
													schedule.last_run
												) }
											</td>
											<td>
												{ formatDate(
													schedule.next_run
												) }
											</td>
											<td>
												<Button
													variant="link"
													isDestructive
													onClick={ () =>
														handleDelete( schedule )
													}
												>
													{ __(
														'Delete',
														'ai-importer'
													) }
												</Button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) : (
							<p>
								{ __(
									'No scheduled imports yet.',
									'ai-importer'
								) }
							</p>
						) }

						<hr />

						<h3>{ __( 'Add a schedule', 'ai-importer' ) }</h3>

						{ connectedSources.length === 0 ? (
							<p>
								{ __(
									'Connect a source to enable scheduled imports.',
									'ai-importer'
								) }
							</p>
						) : (
							<Flex
								align="flex-end"
								gap={ 4 }
								justify="flex-start"
								wrap
							>
								<FlexItem>
									<SelectControl
										__nextHasNoMarginBottom
										label={ __( 'Source', 'ai-importer' ) }
										value={ sourceAdapter }
										options={ connectedSources.map(
											( source ) => ( {
												label: source.name,
												value: source.id,
											} )
										) }
										onChange={ setSourceAdapter }
									/>
								</FlexItem>
								<FlexItem>
									<SelectControl
										__nextHasNoMarginBottom
										label={ __(
											'Interval',
											'ai-importer'
										) }
										value={ interval }
										options={ INTERVAL_OPTIONS }
										onChange={ setInterval }
									/>
								</FlexItem>
								<FlexItem>
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ __(
											'Update existing items',
											'ai-importer'
										) }
										checked={ updateExisting }
										onChange={ setUpdateExisting }
									/>
								</FlexItem>
								<FlexItem>
									<Button
										variant="primary"
										onClick={ handleAdd }
										isBusy={ saving }
										disabled={ saving || ! sourceAdapter }
									>
										{ __( 'Add schedule', 'ai-importer' ) }
									</Button>
								</FlexItem>
							</Flex>
						) }
					</>
				) }
			</CardBody>
		</Card>
	);
}
