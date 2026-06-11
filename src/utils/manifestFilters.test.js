/**
 * Tests for manifest filtering helpers.
 */

import {
	computeHighEngagementThreshold,
	filterManifestItems,
	getEngagementCount,
	hasEngagementData,
} from './manifestFilters';

const makeItem = ( overrides = {} ) => ( {
	id: 'item-1',
	type: 'post',
	title: 'Test item',
	created_at: '2024-01-15T10:30:00+00:00',
	media_urls: [],
	metadata: {},
	...overrides,
} );

describe( 'getEngagementCount', () => {
	it( 'returns null when the item has no engagement metadata', () => {
		expect( getEngagementCount( makeItem() ) ).toBeNull();
		expect(
			getEngagementCount(
				makeItem( { metadata: { tags: [ 'a' ], is_draft: false } } )
			)
		).toBeNull();
	} );

	it( 'returns null when metadata is missing entirely', () => {
		expect( getEngagementCount( { id: 'x' } ) ).toBeNull();
		expect(
			getEngagementCount( makeItem( { metadata: null } ) )
		).toBeNull();
	} );

	it( 'sums known engagement keys', () => {
		const item = makeItem( {
			metadata: { favorite_count: 10, retweet_count: 5 },
		} );
		expect( getEngagementCount( item ) ).toBe( 15 );
	} );

	it( 'returns zero (not null) when engagement keys exist with zero values', () => {
		const item = makeItem( {
			metadata: { favorite_count: 0, retweet_count: 0 },
		} );
		expect( getEngagementCount( item ) ).toBe( 0 );
	} );

	it( 'ignores non-numeric values', () => {
		const item = makeItem( { metadata: { likes: 'lots' } } );
		expect( getEngagementCount( item ) ).toBeNull();
	} );
} );

describe( 'hasEngagementData', () => {
	it( 'returns false for items without engagement metadata', () => {
		expect( hasEngagementData( [ makeItem(), makeItem() ] ) ).toBe( false );
		expect( hasEngagementData( [] ) ).toBe( false );
	} );

	it( 'returns true when at least one item has engagement metadata', () => {
		const items = [
			makeItem(),
			makeItem( { metadata: { favorite_count: 0 } } ),
		];
		expect( hasEngagementData( items ) ).toBe( true );
	} );
} );

describe( 'computeHighEngagementThreshold', () => {
	it( 'returns null when no items carry engagement data', () => {
		expect( computeHighEngagementThreshold( [ makeItem() ] ) ).toBeNull();
		expect( computeHighEngagementThreshold( [] ) ).toBeNull();
	} );

	it( 'returns the 75th percentile of engagement counts', () => {
		const items = [ 1, 2, 3, 4 ].map( ( count, i ) =>
			makeItem( {
				id: `item-${ i }`,
				metadata: { favorite_count: count },
			} )
		);
		expect( computeHighEngagementThreshold( items ) ).toBe( 4 );
	} );

	it( 'enforces a minimum threshold of 1', () => {
		const items = [
			makeItem( { metadata: { favorite_count: 0 } } ),
			makeItem( { id: 'item-2', metadata: { favorite_count: 0 } } ),
		];
		expect( computeHighEngagementThreshold( items ) ).toBe( 1 );
	} );
} );

describe( 'filterManifestItems', () => {
	const items = [
		makeItem( {
			id: 'tweet-1',
			type: 'post',
			created_at: '2023-06-01T08:00:00+00:00',
			metadata: { favorite_count: 2, retweet_count: 0 },
		} ),
		makeItem( {
			id: 'tweet-2',
			type: 'reply',
			created_at: '2024-01-15T10:30:00+00:00',
			metadata: { favorite_count: 0, retweet_count: 0 },
		} ),
		makeItem( {
			id: 'tweet-3',
			type: 'post',
			created_at: '2024-03-20T23:59:00+00:00',
			metadata: { favorite_count: 90, retweet_count: 10 },
		} ),
		makeItem( {
			id: 'blog-1',
			type: 'article',
			created_at: '2025-02-10T12:00:00+00:00',
		} ),
	];

	it( 'returns all items when no filters are set', () => {
		expect( filterManifestItems( items ) ).toHaveLength( 4 );
		expect( filterManifestItems( items, {} ) ).toHaveLength( 4 );
	} );

	it( 'filters by type', () => {
		const result = filterManifestItems( items, { type: 'post' } );
		expect( result.map( ( i ) => i.id ) ).toEqual( [
			'tweet-1',
			'tweet-3',
		] );
	} );

	it( 'filters by from date inclusively', () => {
		const result = filterManifestItems( items, {
			dateFrom: '2024-01-15',
		} );
		expect( result.map( ( i ) => i.id ) ).toEqual( [
			'tweet-2',
			'tweet-3',
			'blog-1',
		] );
	} );

	it( 'filters by to date inclusively', () => {
		const result = filterManifestItems( items, { dateTo: '2024-01-15' } );
		expect( result.map( ( i ) => i.id ) ).toEqual( [
			'tweet-1',
			'tweet-2',
		] );
	} );

	it( 'filters by a combined date range', () => {
		const result = filterManifestItems( items, {
			dateFrom: '2024-01-01',
			dateTo: '2024-12-31',
		} );
		expect( result.map( ( i ) => i.id ) ).toEqual( [
			'tweet-2',
			'tweet-3',
		] );
	} );

	it( 'excludes items without a created_at date when a date filter is set', () => {
		const undated = makeItem( { id: 'undated', created_at: '' } );
		const result = filterManifestItems( [ ...items, undated ], {
			dateFrom: '2000-01-01',
		} );
		expect( result.map( ( i ) => i.id ) ).not.toContain( 'undated' );
	} );

	it( 'filters by any engagement', () => {
		const result = filterManifestItems( items, { engagement: 'any' } );
		expect( result.map( ( i ) => i.id ) ).toEqual( [
			'tweet-1',
			'tweet-3',
		] );
	} );

	it( 'filters by high engagement using the threshold', () => {
		const result = filterManifestItems( items, {
			engagement: 'high',
			highEngagementThreshold: 50,
		} );
		expect( result.map( ( i ) => i.id ) ).toEqual( [ 'tweet-3' ] );
	} );

	it( 'excludes items without engagement data from engagement filters', () => {
		const any = filterManifestItems( items, { engagement: 'any' } );
		const high = filterManifestItems( items, {
			engagement: 'high',
			highEngagementThreshold: 1,
		} );
		expect( any.map( ( i ) => i.id ) ).not.toContain( 'blog-1' );
		expect( high.map( ( i ) => i.id ) ).not.toContain( 'blog-1' );
	} );

	it( 'combines type, date, and engagement filters with AND logic', () => {
		const result = filterManifestItems( items, {
			type: 'post',
			dateFrom: '2024-01-01',
			dateTo: '2024-12-31',
			engagement: 'any',
		} );
		expect( result.map( ( i ) => i.id ) ).toEqual( [ 'tweet-3' ] );
	} );
} );
