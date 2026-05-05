/**
 * Front-end hydration for giving-day/causes-browser.
 *
 * State machine driving the visible "view":
 *
 *   - At root (stack empty), empty query  → causes grid (top-level)
 *   - At root, non-empty query           → beneficiaries (global search)
 *   - Inside a non-leaf cause            → causes grid (children)
 *   - Inside a leaf cause                → beneficiaries (scoped),
 *                                          optionally narrowed by query
 *
 * "Leaf" is determined by the /cause-areas?parent=N response: if that
 * returns zero children, the cause is treated as a leaf and the view flips
 * to beneficiaries. We mark the leaf flag on the breadcrumb stack so the
 * next render avoids a redundant children fetch.
 */
import {
	createRoot,
	useEffect,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const DEBOUNCE_MS = 250;
const PER_PAGE_DEFAULT = 50;

function parseConfig( root ) {
	try {
		return JSON.parse( root.dataset.config || '{}' ) || {};
	} catch ( e ) {
		return {};
	}
}

function useDebouncedValue( value, delayMs ) {
	const [ debounced, setDebounced ] = useState( value );
	useEffect( () => {
		const id = window.setTimeout( () => setDebounced( value ), delayMs );
		return () => window.clearTimeout( id );
	}, [ value, delayMs ] );
	return debounced;
}

function CauseCard( { cause, showCounts, onClick, labels } ) {
	const count = Number( cause.beneficiary_count || 0 );
	const countLabel =
		count === 1
			? labels.beneficiaryLabel || __( 'beneficiary', 'giving-day-blocks' )
			: labels.beneficiariesLabel ||
			  __( 'beneficiaries', 'giving-day-blocks' );
	return (
		<button
			type="button"
			className="giving-day-cause-areas__card"
			onClick={ () => onClick( cause ) }
			data-cause-id={ cause.id }
		>
			<span className="giving-day-cause-areas__card-image">
				{ cause.image_url ? (
					<img src={ cause.image_url } alt="" loading="lazy" />
				) : (
					<span
						className="giving-day-cause-areas__card-image-fallback"
						aria-hidden="true"
					/>
				) }
			</span>
			<span className="giving-day-cause-areas__card-body">
				<span className="giving-day-cause-areas__card-name">
					{ cause.name }
				</span>
				{ showCounts && (
					<span className="giving-day-cause-areas__card-count">
						{ String( count ) } { countLabel }
					</span>
				) }
			</span>
		</button>
	);
}

function BeneficiaryCard( { item } ) {
	return (
		<a
			className="giving-day-cause-areas__beneficiary"
			href={ item.permalink }
		>
			<span className="giving-day-cause-areas__beneficiary-image">
				{ item.thumbnail_url ? (
					<img
						src={ item.thumbnail_url }
						alt=""
						loading="lazy"
					/>
				) : (
					<span
						className="giving-day-cause-areas__beneficiary-image-fallback"
						aria-hidden="true"
					/>
				) }
			</span>
			<span className="giving-day-cause-areas__beneficiary-body">
				<span className="giving-day-cause-areas__beneficiary-title">
					{ item.title }
				</span>
				{ item.parent_org_label && (
					<span className="giving-day-cause-areas__beneficiary-org">
						{ item.parent_org_label }
					</span>
				) }
				{ item.excerpt && (
					<span className="giving-day-cause-areas__beneficiary-excerpt">
						{ item.excerpt }
					</span>
				) }
			</span>
		</a>
	);
}

function Breadcrumbs( { stack, rootLabel, onJump } ) {
	if ( stack.length === 0 ) {
		return null;
	}
	return (
		<nav
			className="giving-day-cause-areas__breadcrumbs"
			aria-label={ __(
				'Cause Areas breadcrumbs',
				'giving-day-blocks'
			) }
		>
			<ol className="giving-day-cause-areas__breadcrumb-list">
				<li>
					<button type="button" onClick={ () => onJump( -1 ) }>
						{ rootLabel }
					</button>
				</li>
				{ stack.map( ( crumb, idx ) => {
					const isLast = idx === stack.length - 1;
					return (
						<li key={ crumb.id }>
							{ isLast ? (
								<span aria-current="page">
									{ crumb.name }
								</span>
							) : (
								<button
									type="button"
									onClick={ () => onJump( idx ) }
								>
									{ crumb.name }
								</button>
							) }
						</li>
					);
				} ) }
			</ol>
		</nav>
	);
}

function App( { config } ) {
	const labels = config.labels || {};
	const showCounts = !! config.showCounts;

	const [ stack, setStack ] = useState( [] );
	const [ searchInput, setSearchInput ] = useState( '' );
	const debouncedSearch = useDebouncedValue(
		searchInput.trim(),
		DEBOUNCE_MS
	);

	const currentCauseId = stack.length
		? stack[ stack.length - 1 ].id
		: 0;
	const currentCauseIsLeaf = stack.length
		? !! stack[ stack.length - 1 ].isLeaf
		: false;

	const view =
		debouncedSearch || currentCauseIsLeaf ? 'beneficiaries' : 'causes';

	const [ causes, setCauses ] = useState( [] );
	const [ beneficiaries, setBeneficiaries ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let cancelled = false;
		setError( null );
		setLoading( true );

		async function load() {
			try {
				if ( view === 'causes' ) {
					const data = await apiFetch( {
						path: `/giving-day/v1/cause-areas?parent=${ currentCauseId }`,
					} );
					if ( cancelled ) {
						return;
					}
					const terms = Array.isArray( data?.terms )
						? data.terms
						: [];
					setCauses( terms );
					setBeneficiaries( [] );

					// Drilled into a non-leaf that returned no children:
					// promote it to leaf so the next render flips to
					// beneficiaries without a redundant fetch.
					if ( currentCauseId > 0 && terms.length === 0 ) {
						setStack( ( prev ) =>
							prev.map( ( c, i, arr ) =>
								i === arr.length - 1
									? { ...c, isLeaf: true }
									: c
							)
						);
					}
				} else {
					const params = new URLSearchParams();
					params.set( 'cause_id', String( currentCauseId ) );
					if ( debouncedSearch ) {
						params.set( 'search', debouncedSearch );
					}
					params.set(
						'per_page',
						String( PER_PAGE_DEFAULT )
					);
					const data = await apiFetch( {
						path: `/giving-day/v1/cause-areas/beneficiaries?${ params.toString() }`,
					} );
					if ( cancelled ) {
						return;
					}
					setBeneficiaries(
						Array.isArray( data?.items ) ? data.items : []
					);
					setCauses( [] );
				}
			} catch ( err ) {
				if ( cancelled ) {
					return;
				}
				setError(
					err instanceof Error ? err.message : String( err )
				);
			} finally {
				if ( ! cancelled ) {
					setLoading( false );
				}
			}
		}

		load();
		return () => {
			cancelled = true;
		};
	}, [ view, currentCauseId, debouncedSearch ] );

	const onCauseClick = ( cause ) => {
		setStack( ( prev ) => [
			...prev,
			{ id: cause.id, name: cause.name, isLeaf: ! cause.has_children },
		] );
		setSearchInput( '' );
	};

	const onJump = ( idx ) => {
		setStack( ( prev ) => ( idx < 0 ? [] : prev.slice( 0, idx + 1 ) ) );
		setSearchInput( '' );
	};

	const placeholder =
		labels.searchAllPlaceholder ||
		__( 'Search beneficiaries', 'giving-day-blocks' );

	return (
		<>
			<div className="giving-day-cause-areas__search">
				<label
					htmlFor="giving-day-cause-areas__search-input"
					className="screen-reader-text"
				>
					{ placeholder }
				</label>
				<input
					id="giving-day-cause-areas__search-input"
					className="giving-day-cause-areas__search-input"
					type="search"
					autoComplete="off"
					value={ searchInput }
					onChange={ ( e ) => setSearchInput( e.target.value ) }
					placeholder={ placeholder }
				/>
			</div>

			<Breadcrumbs
				stack={ stack }
				rootLabel={
					labels.rootBreadcrumb ||
					__( 'Cause Areas', 'giving-day-blocks' )
				}
				onJump={ onJump }
			/>

			{ error && (
				<p
					className="giving-day-cause-areas__error"
					role="alert"
				>
					{ labels.errorLoading ||
						__(
							'Could not load results. Please try again.',
							'giving-day-blocks'
						) }
				</p>
			) }

			{ loading && ! error && (
				<p className="giving-day-cause-areas__loading">
					{ labels.loading ||
						__( 'Loading…', 'giving-day-blocks' ) }
				</p>
			) }

			{ ! loading && ! error && view === 'causes' && (
				<div className="giving-day-cause-areas__grid">
					{ causes.length === 0 ? (
						<p className="giving-day-cause-areas__empty">
							{ labels.noResults ||
								__(
									'No results found.',
									'giving-day-blocks'
								) }
						</p>
					) : (
						causes.map( ( cause ) => (
							<CauseCard
								key={ cause.id }
								cause={ cause }
								showCounts={ showCounts }
								onClick={ onCauseClick }
								labels={ labels }
							/>
						) )
					) }
				</div>
			) }

			{ ! loading && ! error && view === 'beneficiaries' && (
				<div className="giving-day-cause-areas__beneficiaries">
					{ beneficiaries.length === 0 ? (
						<p className="giving-day-cause-areas__empty">
							{ labels.noResults ||
								__(
									'No results found.',
									'giving-day-blocks'
								) }
						</p>
					) : (
						beneficiaries.map( ( item ) => (
							<BeneficiaryCard
								key={ item.id }
								item={ item }
							/>
						) )
					) }
				</div>
			) }
		</>
	);
}

function hydrate( root ) {
	if ( root.dataset.gdHydrated === '1' ) {
		return;
	}
	root.dataset.gdHydrated = '1';

	const config = parseConfig( root );

	// Keep the SSR heading visible while the rest of the body is replaced
	// by the interactive React app. Anything else from render.php is
	// discarded so we don't double-render the search/grid.
	const heading = root.querySelector(
		'.giving-day-cause-areas__heading-row'
	);
	Array.from( root.children ).forEach( ( child ) => {
		if ( child !== heading ) {
			child.remove();
		}
	} );

	const appHost = document.createElement( 'div' );
	appHost.className = 'giving-day-cause-areas__app';
	root.appendChild( appHost );

	createRoot( appHost ).render( <App config={ config } /> );
}

function boot() {
	document
		.querySelectorAll(
			'.giving-day-cause-areas[data-cause-areas-root="1"]'
		)
		.forEach( hydrate );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
