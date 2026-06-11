/**
 * Mapping configuration component for the import wizard.
 *
 * Fetches AI mapping suggestions for the selected source, pre-fills the
 * controls from a previously saved mapping when one exists, and lets the
 * user accept, modify, or reject the suggested mapping before importing.
 */

import { useState, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import {
	fetchMappingSuggestions,
	fetchSavedMapping,
	saveMapping,
} from '../api';

const DEFAULT_POST_TYPE = 'post';
const DEFAULT_POST_STATUS = 'draft';
const NO_AUTHOR = '';
const NO_POST_FORMAT = '';

const POST_STATUS_OPTIONS = [
	{ label: __( 'Draft', 'ai-importer' ), value: 'draft' },
	{ label: __( 'Published', 'ai-importer' ), value: 'publish' },
	{ label: __( 'Pending Review', 'ai-importer' ), value: 'pending' },
	{ label: __( 'Private', 'ai-importer' ), value: 'private' },
];

/**
 * Build editable mapping state from a saved mapping or AI suggestions.
 *
 * @param {?Object} saved       Saved mapping config (or null).
 * @param {?Object} suggestions AI suggestions (or null).
 * @return {Object} Editable mapping state.
 */
function buildInitialMapping( saved, suggestions ) {
	if ( saved ) {
		return {
			postType: saved.post_type || DEFAULT_POST_TYPE,
			postStatus: saved.post_status || DEFAULT_POST_STATUS,
			postTypeMappings: ( saved.post_type_mappings || [] ).map(
				( entry ) => ( { ...entry, reasoning: '' } )
			),
			taxonomyMappings: ( saved.taxonomy_mappings || [] ).map(
				( entry ) => ( {
					...entry,
					destination_terms: entry.destination_terms || [],
					create_if_missing: !! entry.create_if_missing,
					reasoning: '',
				} )
			),
			authorMappings: ( saved.author_mappings || [] ).map(
				( entry ) => ( {
					source_author: entry.source_author,
					destination_user_id: String(
						entry.destination_user_id || ''
					),
				} )
			),
			defaultAuthorId: saved.default_author_id
				? String( saved.default_author_id )
				: NO_AUTHOR,
			postFormatMappings: ( saved.post_format_mappings || [] ).map(
				( entry ) => ( {
					source_content_type: entry.source_content_type,
					post_format: entry.post_format,
				} )
			),
			defaultPostFormat: saved.default_post_format || NO_POST_FORMAT,
			metaFieldMappings: ( saved.meta_field_mappings || [] ).map(
				( entry ) => ( {
					source_field: entry.source_field,
					destination_meta_key: entry.destination_meta_key,
				} )
			),
		};
	}

	if ( suggestions ) {
		return {
			postType: DEFAULT_POST_TYPE,
			postStatus: DEFAULT_POST_STATUS,
			postTypeMappings: ( suggestions.post_type_mappings || [] ).map(
				( entry ) => ( {
					source_content_type: entry.source_content_type,
					destination_post_type: entry.destination_post_type,
					reasoning: entry.reasoning || '',
				} )
			),
			taxonomyMappings: ( suggestions.taxonomy_mappings || [] ).map(
				( entry ) => ( {
					source_signal: entry.source_signal,
					destination_taxonomy: entry.destination_taxonomy,
					destination_terms: entry.destination_terms || [],
					create_if_missing: !! entry.create_if_missing,
					reasoning: entry.reasoning || '',
				} )
			),
			authorMappings: ( suggestions.detected_authors || [] ).map(
				( name ) => ( {
					source_author: name,
					destination_user_id: NO_AUTHOR,
				} )
			),
			defaultAuthorId: NO_AUTHOR,
			postFormatMappings: [],
			defaultPostFormat: NO_POST_FORMAT,
			metaFieldMappings: [],
		};
	}

	return {
		postType: DEFAULT_POST_TYPE,
		postStatus: DEFAULT_POST_STATUS,
		postTypeMappings: [],
		taxonomyMappings: [],
		authorMappings: [],
		defaultAuthorId: NO_AUTHOR,
		postFormatMappings: [],
		defaultPostFormat: NO_POST_FORMAT,
		metaFieldMappings: [],
	};
}

/**
 * Convert editable mapping state to the REST mapping payload.
 *
 * Rows without a destination are treated as rejected and dropped.
 *
 * @param {Object} mapping Editable mapping state.
 * @return {Object} Mapping payload for the REST API.
 */
function toMappingPayload( mapping ) {
	const payload = {
		post_type: mapping.postType || DEFAULT_POST_TYPE,
		post_status: mapping.postStatus || DEFAULT_POST_STATUS,
		post_type_mappings: mapping.postTypeMappings
			.filter( ( entry ) => entry.destination_post_type )
			.map( ( entry ) => ( {
				source_content_type: entry.source_content_type,
				destination_post_type: entry.destination_post_type,
			} ) ),
		taxonomy_mappings: mapping.taxonomyMappings
			.filter( ( entry ) => entry.destination_taxonomy )
			.map( ( entry ) => {
				const out = {
					source_signal: entry.source_signal,
					destination_taxonomy: entry.destination_taxonomy,
					destination_terms: entry.destination_terms,
				};
				if ( entry.create_if_missing ) {
					out.create_if_missing = true;
				}
				return out;
			} ),
		author_mappings: ( mapping.authorMappings || [] )
			.filter(
				( entry ) => entry.source_author && entry.destination_user_id
			)
			.map( ( entry ) => ( {
				source_author: entry.source_author,
				destination_user_id: parseInt( entry.destination_user_id, 10 ),
			} ) ),
		post_format_mappings: ( mapping.postFormatMappings || [] )
			.filter(
				( entry ) => entry.source_content_type && entry.post_format
			)
			.map( ( entry ) => ( {
				source_content_type: entry.source_content_type,
				post_format: entry.post_format,
			} ) ),
		meta_field_mappings: ( mapping.metaFieldMappings || [] )
			.filter(
				( entry ) => entry.source_field && entry.destination_meta_key
			)
			.map( ( entry ) => ( {
				source_field: entry.source_field,
				destination_meta_key: entry.destination_meta_key,
			} ) ),
	};

	if ( mapping.defaultAuthorId ) {
		payload.default_author_id = parseInt( mapping.defaultAuthorId, 10 );
	}

	if ( mapping.defaultPostFormat ) {
		payload.default_post_format = mapping.defaultPostFormat;
	}

	return payload;
}

/**
 * MappingConfiguration component.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.sourceId      The selected source adapter ID.
 * @param {Function} props.onStartImport Called with the mapping payload (or null for defaults).
 * @param {Function} props.onBack        Called to go back to the review step.
 * @param {boolean}  props.isLoading     Whether the import is starting.
 * @return {JSX.Element} The component.
 */
export default function MappingConfiguration( {
	sourceId,
	onStartImport,
	onBack,
	isLoading,
} ) {
	const [ loading, setLoading ] = useState( true );
	const [ loadError, setLoadError ] = useState( null );
	const [ suggestions, setSuggestions ] = useState( null );
	const [ siteSchema, setSiteSchema ] = useState( null );
	const [ usedSavedMapping, setUsedSavedMapping ] = useState( false );
	const [ mapping, setMapping ] = useState( () =>
		buildInitialMapping( null, null )
	);
	const [ saving, setSaving ] = useState( false );
	const [ saveNotice, setSaveNotice ] = useState( null );

	useEffect( () => {
		let cancelled = false;

		const load = async () => {
			setLoading( true );
			setLoadError( null );

			let saved = null;
			try {
				const savedResponse = await fetchSavedMapping( sourceId );
				saved = savedResponse?.mapping || null;
			} catch ( err ) {
				// A missing saved mapping is not fatal; suggestions still load.
				saved = null;
			}

			try {
				const data = await fetchMappingSuggestions( sourceId );
				if ( cancelled ) {
					return;
				}
				setSuggestions( data.suggestions );
				setSiteSchema( data.site_schema );
				setMapping( buildInitialMapping( saved, data.suggestions ) );
				setUsedSavedMapping( !! saved );
			} catch ( err ) {
				if ( cancelled ) {
					return;
				}
				setLoadError(
					err.message ||
						__(
							'Failed to fetch mapping suggestions.',
							'ai-importer'
						)
				);
			} finally {
				if ( ! cancelled ) {
					setLoading( false );
				}
			}
		};

		load();

		return () => {
			cancelled = true;
		};
	}, [ sourceId ] );

	const postTypeOptions = useMemo( () => {
		const types = siteSchema?.post_types || [];
		const options = types.map( ( type ) => ( {
			label: type.name,
			value: type.slug,
		} ) );

		if ( ! options.some( ( option ) => option.value === 'post' ) ) {
			options.unshift( {
				label: __( 'Posts', 'ai-importer' ),
				value: 'post',
			} );
		}

		return options;
	}, [ siteSchema ] );

	const taxonomyOptions = useMemo( () => {
		const taxonomies = siteSchema?.taxonomies || [];
		return [
			{ label: __( 'Do not map', 'ai-importer' ), value: '' },
			...taxonomies.map( ( taxonomy ) => ( {
				label: taxonomy.name,
				value: taxonomy.slug,
			} ) ),
		];
	}, [ siteSchema ] );

	const userOptions = useMemo( () => {
		const users = siteSchema?.users || [];
		return [
			{
				label: __( 'Use default (current user)', 'ai-importer' ),
				value: NO_AUTHOR,
			},
			...users.map( ( user ) => ( {
				label: user.display_name,
				value: String( user.id ),
			} ) ),
		];
	}, [ siteSchema ] );

	const contentTypeOptions = useMemo( () => {
		// Source content types are surfaced by the post-type mapping
		// suggestions; fall back to nothing when none were detected.
		const seen = new Set();
		const options = [];

		( mapping.postTypeMappings || [] ).forEach( ( entry ) => {
			if (
				entry.source_content_type &&
				! seen.has( entry.source_content_type )
			) {
				seen.add( entry.source_content_type );
				options.push( {
					label: entry.source_content_type,
					value: entry.source_content_type,
				} );
			}
		} );

		return options;
	}, [ mapping.postTypeMappings ] );

	const postFormatOptions = useMemo( () => {
		const formats = siteSchema?.post_formats || [];
		const options = formats.map( ( format ) => ( {
			label: format.name,
			value: format.slug,
		} ) );

		return [
			{
				label: __( 'Do not assign', 'ai-importer' ),
				value: NO_POST_FORMAT,
			},
			...options,
		];
	}, [ siteSchema ] );

	const handleReset = () => {
		setMapping( buildInitialMapping( null, null ) );
		setSaveNotice( null );
	};

	const handleAcceptSuggestions = () => {
		setMapping( buildInitialMapping( null, suggestions ) );
		setSaveNotice( null );
	};

	const handleSaveMapping = async () => {
		setSaving( true );
		setSaveNotice( null );
		try {
			await saveMapping( sourceId, toMappingPayload( mapping ) );
			setSaveNotice( {
				status: 'success',
				message: __(
					'Mapping saved. It will be pre-filled next time you import from this source.',
					'ai-importer'
				),
			} );
		} catch ( err ) {
			setSaveNotice( {
				status: 'error',
				message:
					err.message ||
					__( 'Failed to save mapping.', 'ai-importer' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	const updatePostTypeMapping = ( index, destination ) => {
		setMapping( ( current ) => ( {
			...current,
			postTypeMappings: current.postTypeMappings.map( ( entry, i ) =>
				i === index
					? { ...entry, destination_post_type: destination }
					: entry
			),
		} ) );
	};

	const updateTaxonomyMapping = ( index, changes ) => {
		setMapping( ( current ) => ( {
			...current,
			taxonomyMappings: current.taxonomyMappings.map( ( entry, i ) =>
				i === index ? { ...entry, ...changes } : entry
			),
		} ) );
	};

	const updateAuthorMapping = ( index, destinationUserId ) => {
		setMapping( ( current ) => ( {
			...current,
			authorMappings: current.authorMappings.map( ( entry, i ) =>
				i === index
					? { ...entry, destination_user_id: destinationUserId }
					: entry
			),
		} ) );
	};

	const updatePostFormatMapping = ( index, postFormat ) => {
		setMapping( ( current ) => ( {
			...current,
			postFormatMappings: current.postFormatMappings.map( ( entry, i ) =>
				i === index ? { ...entry, post_format: postFormat } : entry
			),
		} ) );
	};

	const addPostFormatMapping = () => {
		const used = new Set(
			mapping.postFormatMappings.map(
				( entry ) => entry.source_content_type
			)
		);
		const available = ( contentTypeOptions || [] ).find(
			( option ) => ! used.has( option.value )
		);

		setMapping( ( current ) => ( {
			...current,
			postFormatMappings: [
				...current.postFormatMappings,
				{
					source_content_type: available ? available.value : '',
					post_format: NO_POST_FORMAT,
				},
			],
		} ) );
	};

	const updatePostFormatSource = ( index, sourceContentType ) => {
		setMapping( ( current ) => ( {
			...current,
			postFormatMappings: current.postFormatMappings.map( ( entry, i ) =>
				i === index
					? { ...entry, source_content_type: sourceContentType }
					: entry
			),
		} ) );
	};

	const removePostFormatMapping = ( index ) => {
		setMapping( ( current ) => ( {
			...current,
			postFormatMappings: current.postFormatMappings.filter(
				( entry, i ) => i !== index
			),
		} ) );
	};

	const addMetaFieldMapping = () => {
		setMapping( ( current ) => ( {
			...current,
			metaFieldMappings: [
				...current.metaFieldMappings,
				{ source_field: '', destination_meta_key: '' },
			],
		} ) );
	};

	const updateMetaFieldMapping = ( index, changes ) => {
		setMapping( ( current ) => ( {
			...current,
			metaFieldMappings: current.metaFieldMappings.map( ( entry, i ) =>
				i === index ? { ...entry, ...changes } : entry
			),
		} ) );
	};

	const removeMetaFieldMapping = ( index ) => {
		setMapping( ( current ) => ( {
			...current,
			metaFieldMappings: current.metaFieldMappings.filter(
				( entry, i ) => i !== index
			),
		} ) );
	};

	if ( loading ) {
		return (
			<Card>
				<CardBody>
					<div className="ai-importer-mapping__loading">
						<Spinner />
						<p>
							{ __(
								'Analyzing your content and site structure…',
								'ai-importer'
							) }
						</p>
					</div>
				</CardBody>
			</Card>
		);
	}

	if ( loadError ) {
		return (
			<Card>
				<CardBody>
					<Notice status="error" isDismissible={ false }>
						{ loadError }
					</Notice>
					<p>
						{ __(
							'You can continue without AI suggestions. Imported items will be created as draft posts.',
							'ai-importer'
						) }
					</p>
					<div className="ai-importer-mapping__actions">
						<Button variant="secondary" onClick={ onBack }>
							{ __( 'Back', 'ai-importer' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => onStartImport( null ) }
							isBusy={ isLoading }
							disabled={ isLoading }
						>
							{ __( 'Continue with defaults', 'ai-importer' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		);
	}

	return (
		<div className="ai-importer-mapping">
			{ usedSavedMapping && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'A previously saved mapping for this source was loaded. Review or adjust it below.',
						'ai-importer'
					) }
				</Notice>
			) }

			{ saveNotice && (
				<Notice
					status={ saveNotice.status }
					isDismissible
					onDismiss={ () => setSaveNotice( null ) }
				>
					{ saveNotice.message }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'Configure Mapping', 'ai-importer' ) }</h2>
				</CardHeader>
				<CardBody>
					{ suggestions?.summary && (
						<div className="ai-importer-mapping__summary">
							<h3>
								{ __( 'AI Recommendation', 'ai-importer' ) }
							</h3>
							<p>{ suggestions.summary }</p>
						</div>
					) }

					<div className="ai-importer-mapping__controls">
						<SelectControl
							label={ __( 'Default post type', 'ai-importer' ) }
							value={ mapping.postType }
							options={ postTypeOptions }
							onChange={ ( value ) =>
								setMapping( ( current ) => ( {
									...current,
									postType: value,
								} ) )
							}
							help={ __(
								'Used for content without a more specific mapping below.',
								'ai-importer'
							) }
							__nextHasNoMarginBottom
						/>

						<SelectControl
							label={ __( 'Post status', 'ai-importer' ) }
							value={ mapping.postStatus }
							options={ POST_STATUS_OPTIONS }
							onChange={ ( value ) =>
								setMapping( ( current ) => ( {
									...current,
									postStatus: value,
								} ) )
							}
							help={ __(
								'Status assigned to every imported item.',
								'ai-importer'
							) }
							__nextHasNoMarginBottom
						/>
					</div>

					{ mapping.postTypeMappings.length > 0 && (
						<div className="ai-importer-mapping__section">
							<h3>
								{ __( 'Content type mappings', 'ai-importer' ) }
							</h3>
							{ mapping.postTypeMappings.map(
								( entry, index ) => (
									<div
										key={ `${ entry.source_content_type }-${ index }` }
										className="ai-importer-mapping__row"
									>
										<SelectControl
											label={ sprintf(
												/* translators: %s: source content type slug. */
												__(
													'"%s" content becomes',
													'ai-importer'
												),
												entry.source_content_type
											) }
											value={
												entry.destination_post_type
											}
											options={ postTypeOptions }
											onChange={ ( value ) =>
												updatePostTypeMapping(
													index,
													value
												)
											}
											__nextHasNoMarginBottom
										/>
										{ entry.reasoning && (
											<p className="ai-importer-mapping__reasoning">
												{ entry.reasoning }
											</p>
										) }
									</div>
								)
							) }
						</div>
					) }

					{ mapping.taxonomyMappings.length > 0 && (
						<div className="ai-importer-mapping__section">
							<h3>
								{ __( 'Taxonomy mappings', 'ai-importer' ) }
							</h3>
							{ mapping.taxonomyMappings.map(
								( entry, index ) => (
									<div
										key={ `${ entry.source_signal }-${ index }` }
										className="ai-importer-mapping__row"
									>
										<SelectControl
											label={ sprintf(
												/* translators: %s: source signal, e.g. hashtags. */
												__(
													'Map "%s" to',
													'ai-importer'
												),
												entry.source_signal
											) }
											value={ entry.destination_taxonomy }
											options={ taxonomyOptions }
											onChange={ ( value ) =>
												updateTaxonomyMapping( index, {
													destination_taxonomy: value,
												} )
											}
											__nextHasNoMarginBottom
										/>
										{ entry.source_signal === 'hashtags' ? (
											<p className="ai-importer-mapping__help">
												{ __(
													'Each item’s hashtags become terms in the selected taxonomy.',
													'ai-importer'
												) }
											</p>
										) : (
											<TextControl
												label={ __(
													'Terms (comma-separated)',
													'ai-importer'
												) }
												value={ entry.destination_terms.join(
													', '
												) }
												onChange={ ( value ) =>
													updateTaxonomyMapping(
														index,
														{
															destination_terms:
																value
																	.split(
																		','
																	)
																	.map(
																		(
																			term
																		) =>
																			term.trim()
																	)
																	.filter(
																		Boolean
																	),
														}
													)
												}
												__nextHasNoMarginBottom
											/>
										) }
										{ entry.destination_taxonomy && (
											<ToggleControl
												label={ __(
													'Create this taxonomy if it does not exist',
													'ai-importer'
												) }
												checked={
													!! entry.create_if_missing
												}
												onChange={ ( value ) =>
													updateTaxonomyMapping(
														index,
														{
															create_if_missing:
																value,
														}
													)
												}
												help={ __(
													'Registers a new custom taxonomy during import and on subsequent page loads.',
													'ai-importer'
												) }
												__nextHasNoMarginBottom
											/>
										) }
										{ entry.reasoning && (
											<p className="ai-importer-mapping__reasoning">
												{ entry.reasoning }
											</p>
										) }
									</div>
								)
							) }
						</div>
					) }

					{ ( mapping.authorMappings.length > 0 ||
						userOptions.length > 1 ) && (
						<div className="ai-importer-mapping__section">
							<h3>{ __( 'Author mapping', 'ai-importer' ) }</h3>
							{ mapping.authorMappings.map( ( entry, index ) => (
								<div
									key={ `${ entry.source_author }-${ index }` }
									className="ai-importer-mapping__row"
								>
									<SelectControl
										label={ sprintf(
											/* translators: %s: source author name. */
											__(
												'Posts by "%s" become posts by',
												'ai-importer'
											),
											entry.source_author
										) }
										value={ entry.destination_user_id }
										options={ userOptions }
										onChange={ ( value ) =>
											updateAuthorMapping( index, value )
										}
										__nextHasNoMarginBottom
									/>
								</div>
							) ) }
							<SelectControl
								label={ __(
									'Default author for imported posts',
									'ai-importer'
								) }
								value={ mapping.defaultAuthorId }
								options={ userOptions }
								onChange={ ( value ) =>
									setMapping( ( current ) => ( {
										...current,
										defaultAuthorId: value,
									} ) )
								}
								help={ __(
									'Applied to imported posts without a matching author mapping above.',
									'ai-importer'
								) }
								__nextHasNoMarginBottom
							/>
						</div>
					) }

					<div className="ai-importer-mapping__section">
						<h3>{ __( 'Post formats', 'ai-importer' ) }</h3>
						<SelectControl
							label={ __( 'Default post format', 'ai-importer' ) }
							value={ mapping.defaultPostFormat }
							options={ postFormatOptions }
							onChange={ ( value ) =>
								setMapping( ( current ) => ( {
									...current,
									defaultPostFormat: value,
								} ) )
							}
							help={ __(
								'Assigned to imported posts when the destination post type supports post formats.',
								'ai-importer'
							) }
							__nextHasNoMarginBottom
						/>
						{ mapping.postFormatMappings.map( ( entry, index ) => (
							<div
								key={ `post-format-${ index }` }
								className="ai-importer-mapping__row"
							>
								<SelectControl
									label={ __(
										'Content type',
										'ai-importer'
									) }
									value={ entry.source_content_type }
									options={ contentTypeOptions }
									onChange={ ( value ) =>
										updatePostFormatSource( index, value )
									}
									__nextHasNoMarginBottom
								/>
								<SelectControl
									label={ __( 'Post format', 'ai-importer' ) }
									value={ entry.post_format }
									options={ postFormatOptions }
									onChange={ ( value ) =>
										updatePostFormatMapping( index, value )
									}
									__nextHasNoMarginBottom
								/>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										removePostFormatMapping( index )
									}
								>
									{ __( 'Remove', 'ai-importer' ) }
								</Button>
							</div>
						) ) }
						{ contentTypeOptions.length > 0 && (
							<Button
								variant="secondary"
								onClick={ addPostFormatMapping }
							>
								{ __(
									'Add content-type format',
									'ai-importer'
								) }
							</Button>
						) }
					</div>

					<div className="ai-importer-mapping__section">
						<h3>{ __( 'Custom field mapping', 'ai-importer' ) }</h3>
						<p className="ai-importer-mapping__help">
							{ __(
								'Copy source metadata into post meta. ACF and Meta Box fields are stored as post meta, so use the field name as the destination key.',
								'ai-importer'
							) }
						</p>
						{ mapping.metaFieldMappings.map( ( entry, index ) => (
							<div
								key={ `meta-field-${ index }` }
								className="ai-importer-mapping__row"
							>
								<TextControl
									label={ __(
										'Source field',
										'ai-importer'
									) }
									value={ entry.source_field }
									onChange={ ( value ) =>
										updateMetaFieldMapping( index, {
											source_field: value,
										} )
									}
									__nextHasNoMarginBottom
								/>
								<TextControl
									label={ __(
										'Destination meta key',
										'ai-importer'
									) }
									value={ entry.destination_meta_key }
									onChange={ ( value ) =>
										updateMetaFieldMapping( index, {
											destination_meta_key: value,
										} )
									}
									__nextHasNoMarginBottom
								/>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										removeMetaFieldMapping( index )
									}
								>
									{ __( 'Remove', 'ai-importer' ) }
								</Button>
							</div>
						) ) }
						<Button
							variant="secondary"
							onClick={ addMetaFieldMapping }
						>
							{ __( 'Add field mapping', 'ai-importer' ) }
						</Button>
					</div>

					<div className="ai-importer-mapping__secondary-actions">
						<Button
							variant="tertiary"
							onClick={ handleAcceptSuggestions }
							disabled={ ! suggestions }
						>
							{ __( 'Reset to AI suggestions', 'ai-importer' ) }
						</Button>
						<Button variant="tertiary" onClick={ handleReset }>
							{ __(
								'Reject suggestions (use defaults)',
								'ai-importer'
							) }
						</Button>
						<Button
							variant="secondary"
							onClick={ handleSaveMapping }
							isBusy={ saving }
							disabled={ saving }
						>
							{ __( 'Save mapping for reuse', 'ai-importer' ) }
						</Button>
					</div>

					<div className="ai-importer-mapping__actions">
						<Button variant="secondary" onClick={ onBack }>
							{ __( 'Back', 'ai-importer' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () =>
								onStartImport( toMappingPayload( mapping ) )
							}
							isBusy={ isLoading }
							disabled={ isLoading }
						>
							{ __( 'Start Import', 'ai-importer' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		</div>
	);
}
