/**
 * Client-side filtering helpers for manifest items.
 *
 * Manifest items are plain objects from the REST API with the shape
 * produced by ManifestItem::to_array(): id, type, title, excerpt,
 * created_at (ISO 8601 string), media_urls, metadata, etc.
 */

/**
 * Metadata keys that represent engagement counts on the source platform.
 *
 * Adapters store platform-specific engagement numbers in item metadata,
 * e.g. the Twitter adapter provides `favorite_count` and `retweet_count`.
 *
 * @type {string[]}
 */
const ENGAGEMENT_KEYS = [
	'favorite_count',
	'retweet_count',
	'like_count',
	'likes',
	'reply_count',
	'replies',
	'reblog_count',
	'comment_count',
	'claps',
	'note_count',
];

/**
 * Get the total engagement count for a manifest item.
 *
 * Sums all known engagement metadata keys. Returns null when the item
 * carries no engagement metadata at all, which distinguishes "no data"
 * from an item with zero engagement.
 *
 * @param {Object} item Manifest item.
 * @return {?number} Total engagement count, or null if no engagement data.
 */
export function getEngagementCount( item ) {
	const metadata = item?.metadata;

	if ( ! metadata || typeof metadata !== 'object' ) {
		return null;
	}

	let found = false;
	let total = 0;

	for ( const key of ENGAGEMENT_KEYS ) {
		const value = metadata[ key ];

		if ( typeof value === 'number' && isFinite( value ) ) {
			found = true;
			total += value;
		}
	}

	return found ? total : null;
}

/**
 * Check whether any item in a list carries engagement metadata.
 *
 * @param {Object[]} items Manifest items.
 * @return {boolean} True if at least one item has engagement data.
 */
export function hasEngagementData( items ) {
	return ( items || [] ).some(
		( item ) => getEngagementCount( item ) !== null
	);
}

/**
 * Compute the "high engagement" threshold for a set of items.
 *
 * Uses the 75th percentile of engagement counts across items that carry
 * engagement data, with a minimum threshold of 1.
 *
 * @param {Object[]} items Manifest items.
 * @return {?number} Threshold, or null when no items have engagement data.
 */
export function computeHighEngagementThreshold( items ) {
	const counts = ( items || [] )
		.map( getEngagementCount )
		.filter( ( count ) => count !== null )
		.sort( ( a, b ) => a - b );

	if ( counts.length === 0 ) {
		return null;
	}

	const index = Math.min(
		Math.floor( counts.length * 0.75 ),
		counts.length - 1
	);

	return Math.max( 1, counts[ index ] );
}

/**
 * Filter manifest items by type, date range, and engagement level.
 *
 * All provided filters are combined with AND logic. Date comparisons use
 * the date portion of the item's ISO 8601 created_at string, so they match
 * the date as recorded by the source platform.
 *
 * @param {Object[]} items                             Manifest items.
 * @param {Object}   filters                           Active filters.
 * @param {string}   [filters.type]                    Content type, or '' for all.
 * @param {string}   [filters.dateFrom]                Inclusive start date (YYYY-MM-DD), or ''.
 * @param {string}   [filters.dateTo]                  Inclusive end date (YYYY-MM-DD), or ''.
 * @param {string}   [filters.engagement]              '' (all), 'any', or 'high'.
 * @param {?number}  [filters.highEngagementThreshold] Threshold for 'high'.
 * @return {Object[]} Filtered items.
 */
export function filterManifestItems( items, filters = {} ) {
	const {
		type = '',
		dateFrom = '',
		dateTo = '',
		engagement = '',
		highEngagementThreshold = null,
	} = filters;

	return ( items || [] ).filter( ( item ) => {
		if ( type && item.type !== type ) {
			return false;
		}

		if ( dateFrom || dateTo ) {
			const itemDate = ( item.created_at || '' ).slice( 0, 10 );

			if ( ! itemDate ) {
				return false;
			}

			if ( dateFrom && itemDate < dateFrom ) {
				return false;
			}

			if ( dateTo && itemDate > dateTo ) {
				return false;
			}
		}

		if ( engagement ) {
			const count = getEngagementCount( item );

			if ( 'any' === engagement && ! ( count > 0 ) ) {
				return false;
			}

			if ( 'high' === engagement ) {
				const threshold = highEngagementThreshold ?? 1;

				if ( null === count || count < threshold ) {
					return false;
				}
			}
		}

		return true;
	} );
}
