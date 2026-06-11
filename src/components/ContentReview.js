/**
 * Content review component for manifest items.
 */

import { useState, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';

import {
	computeHighEngagementThreshold,
	filterManifestItems,
	getEngagementCount,
	hasEngagementData,
} from '../utils/manifestFilters';

/**
 * ContentReview component displays manifest items for selection.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.manifest  Manifest data from the API.
 * @param {Function} props.onImport  Called with selected item IDs.
 * @param {boolean}  props.isLoading Whether import is starting.
 * @return {JSX.Element} The component.
 */
export default function ContentReview( { manifest, onImport, isLoading } ) {
	const [ selectedIds, setSelectedIds ] = useState( () =>
		manifest.items
			.filter(
				( item ) => item.type !== 'reply' && item.type !== 'repost'
			)
			.map( ( item ) => item.id )
	);
	const [ typeFilter, setTypeFilter ] = useState( '' );
	const [ dateFromFilter, setDateFromFilter ] = useState( '' );
	const [ dateToFilter, setDateToFilter ] = useState( '' );
	const [ engagementFilter, setEngagementFilter ] = useState( '' );

	const showEngagementFilter = useMemo(
		() => hasEngagementData( manifest.items ),
		[ manifest.items ]
	);

	const highEngagementThreshold = useMemo(
		() => computeHighEngagementThreshold( manifest.items ),
		[ manifest.items ]
	);

	const filteredItems = useMemo(
		() =>
			filterManifestItems( manifest.items, {
				type: typeFilter,
				dateFrom: dateFromFilter,
				dateTo: dateToFilter,
				engagement: engagementFilter,
				highEngagementThreshold,
			} ),
		[
			manifest.items,
			typeFilter,
			dateFromFilter,
			dateToFilter,
			engagementFilter,
			highEngagementThreshold,
		]
	);

	const typeOptions = useMemo( () => {
		const types = [
			...new Set( manifest.items.map( ( item ) => item.type ) ),
		];
		return [
			{ label: __( 'All types', 'ai-importer' ), value: '' },
			...types.map( ( type ) => ( {
				label: type.charAt( 0 ).toUpperCase() + type.slice( 1 ),
				value: type,
			} ) ),
		];
	}, [ manifest.items ] );

	const engagementOptions = useMemo(
		() => [
			{ label: __( 'All engagement', 'ai-importer' ), value: '' },
			{ label: __( 'Any engagement', 'ai-importer' ), value: 'any' },
			{
				label: highEngagementThreshold
					? sprintf(
							/* translators: %d: engagement count threshold. */
							__( 'High engagement (%d+)', 'ai-importer' ),
							highEngagementThreshold
					  )
					: __( 'High engagement', 'ai-importer' ),
				value: 'high',
			},
		],
		[ highEngagementThreshold ]
	);

	const mediaCount = useMemo(
		() =>
			manifest.items.filter( ( item ) => item.media_urls?.length > 0 )
				.length,
		[ manifest.items ]
	);

	const dateRange = useMemo( () => {
		const dates = manifest.items
			.map( ( item ) => item.created_at )
			.filter( Boolean )
			.sort();
		if ( dates.length === 0 ) {
			return null;
		}
		return { earliest: dates[ 0 ], latest: dates[ dates.length - 1 ] };
	}, [ manifest.items ] );

	const toggleItem = ( itemId ) => {
		setSelectedIds( ( prev ) =>
			prev.includes( itemId )
				? prev.filter( ( id ) => id !== itemId )
				: [ ...prev, itemId ]
		);
	};

	const toggleAll = ( checked ) => {
		const filteredIds = filteredItems.map( ( item ) => item.id );
		const filteredSet = new Set( filteredIds );

		setSelectedIds( ( prev ) => {
			if ( checked ) {
				return [ ...new Set( [ ...prev, ...filteredIds ] ) ];
			}
			return prev.filter( ( id ) => ! filteredSet.has( id ) );
		} );
	};

	const allSelected =
		filteredItems.length > 0 &&
		filteredItems.every( ( item ) => selectedIds.includes( item.id ) );

	const formatDate = ( dateStr ) => {
		if ( ! dateStr ) {
			return '';
		}
		return new Date( dateStr ).toLocaleDateString();
	};

	return (
		<div className="ai-importer-content-review">
			<Card>
				<CardHeader>
					<h2>{ __( 'Content Summary', 'ai-importer' ) }</h2>
				</CardHeader>
				<CardBody>
					<div className="ai-importer-content-review__stats">
						<div className="ai-importer-content-review__stat">
							<span className="ai-importer-content-review__stat-value">
								{ manifest.stats.total }
							</span>
							<span className="ai-importer-content-review__stat-label">
								{ __( 'Total Items', 'ai-importer' ) }
							</span>
						</div>
						{ mediaCount > 0 && (
							<div className="ai-importer-content-review__stat">
								<span className="ai-importer-content-review__stat-value">
									{ mediaCount }
								</span>
								<span className="ai-importer-content-review__stat-label">
									{ __( 'With Media', 'ai-importer' ) }
								</span>
							</div>
						) }
						{ dateRange && (
							<div className="ai-importer-content-review__stat">
								<span className="ai-importer-content-review__stat-value">
									{ formatDate( dateRange.earliest ) }
									{ ' - ' }
									{ formatDate( dateRange.latest ) }
								</span>
								<span className="ai-importer-content-review__stat-label">
									{ __( 'Date Range', 'ai-importer' ) }
								</span>
							</div>
						) }
					</div>

					{ manifest.stats.by_type && (
						<div className="ai-importer-content-review__types">
							{ Object.entries( manifest.stats.by_type ).map(
								( [ type, count ] ) => (
									<span
										key={ type }
										className="ai-importer-content-review__type-badge"
									>
										{ type }: { count }
									</span>
								)
							) }
						</div>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<div className="ai-importer-content-review__toolbar">
						<h2>
							{ __( 'Select Content', 'ai-importer' ) }
							<span className="ai-importer-content-review__count">
								({ selectedIds.length }{ ' ' }
								{ __( 'selected', 'ai-importer' ) })
							</span>
						</h2>
						<div className="ai-importer-content-review__filters">
							<SelectControl
								label={ __( 'Type', 'ai-importer' ) }
								value={ typeFilter }
								options={ typeOptions }
								onChange={ setTypeFilter }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'From date', 'ai-importer' ) }
								type="date"
								value={ dateFromFilter }
								onChange={ setDateFromFilter }
								max={ dateToFilter || undefined }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'To date', 'ai-importer' ) }
								type="date"
								value={ dateToFilter }
								onChange={ setDateToFilter }
								min={ dateFromFilter || undefined }
								__nextHasNoMarginBottom
							/>
							{ showEngagementFilter && (
								<SelectControl
									label={ __( 'Engagement', 'ai-importer' ) }
									value={ engagementFilter }
									options={ engagementOptions }
									onChange={ setEngagementFilter }
									__nextHasNoMarginBottom
								/>
							) }
						</div>
					</div>
				</CardHeader>
				<CardBody>
					<div className="ai-importer-content-review__list">
						<div className="ai-importer-content-review__list-header">
							<CheckboxControl
								checked={ allSelected }
								onChange={ toggleAll }
								label={ __( 'Select all', 'ai-importer' ) }
								__nextHasNoMarginBottom
							/>
						</div>
						{ filteredItems.slice( 0, 100 ).map( ( item ) => (
							<div
								key={ item.id }
								className="ai-importer-content-review__item"
							>
								<CheckboxControl
									checked={ selectedIds.includes( item.id ) }
									onChange={ () => toggleItem( item.id ) }
									label={
										item.title || item.excerpt || item.id
									}
									__nextHasNoMarginBottom
									hideLabelFromVision
								/>
								<div className="ai-importer-content-review__item-content">
									<span className="ai-importer-content-review__item-title">
										{ item.title ||
											item.excerpt ||
											item.id }
									</span>
									<span className="ai-importer-content-review__item-meta">
										<span className="ai-importer-content-review__item-type">
											{ item.type }
										</span>
										<span className="ai-importer-content-review__item-date">
											{ formatDate( item.created_at ) }
										</span>
										{ item.media_urls?.length > 0 && (
											<span className="ai-importer-content-review__item-media">
												{ item.media_urls.length }{ ' ' }
												{ __( 'media', 'ai-importer' ) }
											</span>
										) }
										{ getEngagementCount( item ) > 0 && (
											<span className="ai-importer-content-review__item-engagement">
												{ getEngagementCount( item ) }{ ' ' }
												{ __(
													'engagement',
													'ai-importer'
												) }
											</span>
										) }
									</span>
								</div>
							</div>
						) ) }
						{ filteredItems.length === 0 && (
							<p className="ai-importer-content-review__empty">
								{ __(
									'No items match the current filters.',
									'ai-importer'
								) }
							</p>
						) }
						{ filteredItems.length > 100 && (
							<p className="ai-importer-content-review__truncated">
								{ __(
									'Showing first 100 items.',
									'ai-importer'
								) }{ ' ' }
								{ filteredItems.length - 100 }{ ' ' }
								{ __(
									'more items will also be included if selected.',
									'ai-importer'
								) }
							</p>
						) }
					</div>
				</CardBody>
			</Card>

			<div className="ai-importer-content-review__actions">
				<Button
					variant="primary"
					onClick={ () => onImport( selectedIds ) }
					disabled={ selectedIds.length === 0 || isLoading }
					isBusy={ isLoading }
				>
					{ isLoading
						? __( 'Starting Import…', 'ai-importer' )
						: __( 'Import Selected', 'ai-importer' ) +
						  ` (${ selectedIds.length })` }
				</Button>
			</div>
		</div>
	);
}
