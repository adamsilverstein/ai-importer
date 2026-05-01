/**
 * Dashboard data store.
 *
 * Centralizes fetching of sources and recent imports so dashboard-style
 * pages can subscribe via useSelect/useDispatch instead of duplicating
 * useState + useEffect with direct API calls.
 */

import { createReduxStore, register } from '@wordpress/data';

import { fetchSources, fetchImports } from '../api';

export const STORE_NAME = 'ai-importer/dashboard';

const DEFAULT_STATE = {
	sources: [],
	imports: [],
	sourcesLoading: false,
	importsLoading: false,
	error: null,
};

const actions = {
	setSources( sources ) {
		return { type: 'SET_SOURCES', sources };
	},
	setImports( imports ) {
		return { type: 'SET_IMPORTS', imports };
	},
	setSourcesLoading( loading ) {
		return { type: 'SET_SOURCES_LOADING', loading };
	},
	setImportsLoading( loading ) {
		return { type: 'SET_IMPORTS_LOADING', loading };
	},
	setError( error ) {
		return { type: 'SET_ERROR', error };
	},
	clearError() {
		return { type: 'SET_ERROR', error: null };
	},
};

/**
 * Reduce dispatched actions into the next state.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Action to apply.
 * @return {Object} Next state.
 */
function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'SET_SOURCES':
			return { ...state, sources: action.sources };
		case 'SET_IMPORTS':
			return { ...state, imports: action.imports };
		case 'SET_SOURCES_LOADING':
			return { ...state, sourcesLoading: action.loading };
		case 'SET_IMPORTS_LOADING':
			return { ...state, importsLoading: action.loading };
		case 'SET_ERROR':
			return { ...state, error: action.error };
		default:
			return state;
	}
}

const selectors = {
	getSources( state ) {
		return state.sources;
	},
	getConnectedSources( state ) {
		return state.sources.filter( ( s ) => s.is_authenticated );
	},
	getImports( state ) {
		return state.imports;
	},
	isLoading( state ) {
		return state.sourcesLoading || state.importsLoading;
	},
	getError( state ) {
		return state.error;
	},
};

const resolvers = {
	*getSources() {
		yield actions.setSourcesLoading( true );
		try {
			const sources = yield {
				type: 'API_FETCH_SOURCES',
			};
			yield actions.setSources( sources );
		} catch ( e ) {
			yield actions.setError( e.message || 'Failed to load sources.' );
			yield actions.setSources( [] );
		} finally {
			yield actions.setSourcesLoading( false );
		}
	},
	*getImports() {
		yield actions.setImportsLoading( true );
		try {
			const imports = yield {
				type: 'API_FETCH_IMPORTS',
				limit: 5,
			};
			yield actions.setImports( imports );
		} catch ( e ) {
			yield actions.setError( e.message || 'Failed to load imports.' );
			yield actions.setImports( [] );
		} finally {
			yield actions.setImportsLoading( false );
		}
	},
	*getConnectedSources() {
		yield resolvers.getSources();
	},
};

const controls = {
	API_FETCH_SOURCES() {
		return fetchSources();
	},
	API_FETCH_IMPORTS( { limit } ) {
		return fetchImports( limit );
	},
};

const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
	resolvers,
	controls,
} );

register( store );

export default store;
