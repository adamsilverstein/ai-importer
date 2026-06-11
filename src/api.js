/**
 * API integration layer for AI Importer REST endpoints.
 */

import apiFetch from '@wordpress/api-fetch';

const API_BASE = 'ai-importer/v1';

/**
 * Fetch all available source adapters.
 *
 * @return {Promise<Array>} List of source adapters.
 */
export function fetchSources() {
	return apiFetch( { path: `${ API_BASE }/sources` } );
}

/**
 * Fetch a single source adapter with its settings schema.
 *
 * @param {string} sourceId The adapter ID.
 * @return {Promise<Object>} Source adapter details.
 */
export function fetchSource( sourceId ) {
	return apiFetch( { path: `${ API_BASE }/sources/${ sourceId }` } );
}

/**
 * Connect (authenticate) a source adapter.
 *
 * @param {string}   sourceId    The adapter ID.
 * @param {Object}   credentials Credentials or settings.
 * @param {FormData} formData    Optional FormData for file uploads.
 * @return {Promise<Object>} Connection result.
 */
export function connectSource( sourceId, credentials = {}, formData = null ) {
	if ( formData ) {
		return apiFetch( {
			path: `${ API_BASE }/sources/${ sourceId }/connect`,
			method: 'POST',
			body: formData,
		} );
	}

	return apiFetch( {
		path: `${ API_BASE }/sources/${ sourceId }/connect`,
		method: 'POST',
		data: credentials,
	} );
}

/**
 * Disconnect a source adapter.
 *
 * @param {string} sourceId The adapter ID.
 * @return {Promise<Object>} Disconnection result.
 */
export function disconnectSource( sourceId ) {
	return apiFetch( {
		path: `${ API_BASE }/sources/${ sourceId }/disconnect`,
		method: 'POST',
	} );
}

/**
 * Fetch the content manifest for a connected source.
 *
 * @param {string} sourceId The adapter ID.
 * @return {Promise<Object>} Manifest data with items and stats.
 */
export function fetchManifest( sourceId ) {
	return apiFetch( { path: `${ API_BASE }/sources/${ sourceId }/manifest` } );
}

/**
 * Fetch AI mapping suggestions for a connected source.
 *
 * @param {string} sourceId The adapter ID.
 * @return {Promise<Object>} Suggestions, analysis, and site schema.
 */
export function fetchMappingSuggestions( sourceId ) {
	return apiFetch( {
		path: `${ API_BASE }/sources/${ sourceId }/mapping-suggestions`,
	} );
}

/**
 * Fetch the saved mapping configuration for a source.
 *
 * @param {string} sourceId The adapter ID.
 * @return {Promise<Object>} Saved mapping (mapping is null when none saved).
 */
export function fetchSavedMapping( sourceId ) {
	return apiFetch( {
		path: `${ API_BASE }/sources/${ sourceId }/mappings`,
	} );
}

/**
 * Save a mapping configuration for reuse.
 *
 * @param {string} sourceId The adapter ID.
 * @param {Object} mapping  Mapping configuration to persist.
 * @return {Promise<Object>} Saved mapping data.
 */
export function saveMapping( sourceId, mapping ) {
	return apiFetch( {
		path: `${ API_BASE }/sources/${ sourceId }/mappings`,
		method: 'POST',
		data: { mapping },
	} );
}

/**
 * Start a new import batch.
 *
 * @param {string}   sourceAdapter The adapter ID.
 * @param {string[]} itemIds       Manifest item IDs to import.
 * @param {?Object}  mapping       Optional mapping configuration to apply.
 * @return {Promise<Object>} Created batch data.
 */
export function startImport( sourceAdapter, itemIds, mapping = null ) {
	const data = {
		source_adapter: sourceAdapter,
		item_ids: itemIds,
	};

	if ( mapping ) {
		data.mapping = mapping;
	}

	return apiFetch( {
		path: `${ API_BASE }/imports`,
		method: 'POST',
		data,
	} );
}

/**
 * Get import progress/status.
 *
 * @param {string} batchId The batch UUID.
 * @return {Promise<Object>} Progress data.
 */
export function fetchImportProgress( batchId ) {
	return apiFetch( { path: `${ API_BASE }/imports/${ batchId }` } );
}

/**
 * Fetch recent import batches.
 *
 * @param {number} limit Maximum number of batches to return.
 * @return {Promise<Array>} List of import batches.
 */
export function fetchImports( limit = 10 ) {
	return apiFetch( {
		path: `${ API_BASE }/imports?limit=${ limit }`,
	} );
}

/**
 * Pause a running import.
 *
 * @param {string} batchId The batch UUID.
 * @return {Promise<Object>} Updated progress data.
 */
export function pauseImport( batchId ) {
	return apiFetch( {
		path: `${ API_BASE }/imports/${ batchId }/pause`,
		method: 'POST',
	} );
}

/**
 * Resume a paused import.
 *
 * @param {string} batchId The batch UUID.
 * @return {Promise<Object>} Updated progress data.
 */
export function resumeImport( batchId ) {
	return apiFetch( {
		path: `${ API_BASE }/imports/${ batchId }/resume`,
		method: 'POST',
	} );
}

/**
 * Rollback an import (delete all created posts).
 *
 * @param {string} batchId The batch UUID.
 * @return {Promise<Object>} Rollback result.
 */
export function rollbackImport( batchId ) {
	return apiFetch( {
		path: `${ API_BASE }/imports/${ batchId }`,
		method: 'DELETE',
	} );
}
