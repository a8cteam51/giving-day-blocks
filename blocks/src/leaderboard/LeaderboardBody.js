/**
 * Shared leaderboard list markup for editor and front-end.
 */

import { __ } from '@wordpress/i18n';

import { formatCurrency } from '../_shared/utils/formatCurrency';

function Row( { row, showAmount, showAvatar, currency } ) {
	const avatar = row.avatar_url;
	return (
		<li className="giving-day-leaderboard__row">
			<span className="giving-day-leaderboard__rank" aria-hidden="true">
				{ row.rank }
			</span>
			{ showAvatar && (
				<span className="giving-day-leaderboard__avatar-wrap">
					{ avatar ? (
						<img
							src={ avatar }
							alt=""
							className="giving-day-leaderboard__avatar"
							width="40"
							height="40"
							loading="lazy"
							decoding="async"
						/>
					) : (
						<span
							className="giving-day-leaderboard__avatar giving-day-leaderboard__avatar--placeholder"
							aria-hidden="true"
						/>
					) }
				</span>
			) }
			<span className="giving-day-leaderboard__label">{ row.label }</span>
			{ showAmount && (
				<span className="giving-day-leaderboard__amount">
					{ formatCurrency( row.amount || 0, currency ) }
				</span>
			) }
		</li>
	);
}

function SkeletonRows( { count, showAvatar } ) {
	const rows = [];
	for ( let i = 0; i < count; i += 1 ) {
		rows.push(
			<li
				key={ `sk-${ i }` }
				className="giving-day-leaderboard__row giving-day-leaderboard__skeleton-row"
				aria-hidden="true"
			>
				<span className="giving-day-leaderboard__rank giving-day-leaderboard__skel" />
				{ showAvatar && (
					<span className="giving-day-leaderboard__avatar-wrap">
						<span className="giving-day-leaderboard__skel giving-day-leaderboard__skel--round" />
					</span>
				) }
				<span className="giving-day-leaderboard__skel giving-day-leaderboard__skel--grow" />
				<span className="giving-day-leaderboard__skel giving-day-leaderboard__skel--amount" />
			</li>
		);
	}
	return rows;
}

export default function LeaderboardBody( {
	rows = [],
	groups = null,
	total = 0,
	currency = 'USD',
	showAmount = true,
	showAvatar = true,
	loading = false,
	skeletonCount = 5,
	expanded = false,
	onToggleExpand = null,
} ) {
	const listClass =
		'giving-day-leaderboard__list' +
		( showAvatar ? '' : ' giving-day-leaderboard__list--no-avatar' );

	if ( loading && ( ! rows || rows.length === 0 ) && ! groups ) {
		return (
			<ol className={ `${ listClass }` } aria-busy="true">
				<SkeletonRows
					count={ skeletonCount }
					showAvatar={ showAvatar }
				/>
			</ol>
		);
	}

	const flatRowCount = Array.isArray( rows ) ? rows.length : 0;
	const flatTotal = Number.isFinite( total ) ? Number( total ) : flatRowCount;
	const groupHasMore = Array.isArray( groups )
		? groups.some( ( g ) => {
				const groupTotal = Number.isFinite( g?.total )
					? Number( g.total )
					: Array.isArray( g?.rows )
						? g.rows.length
						: 0;
				const groupCount = Array.isArray( g?.rows ) ? g.rows.length : 0;
				return groupTotal > groupCount;
			} )
		: false;
	const canExpand =
		onToggleExpand !== null &&
		( flatTotal > flatRowCount || groupHasMore );

	const expandButton = canExpand ? (
		<button
			type="button"
			className="giving-day-leaderboard__expand"
			onClick={ onToggleExpand }
			aria-expanded={ expanded ? 'true' : 'false' }
		>
			{ expanded
				? __( 'Show less', 'giving-day-blocks' )
				: __( 'Show all', 'giving-day-blocks' ) }
		</button>
	) : null;

	if ( groups && Array.isArray( groups ) && groups.length > 0 ) {
		return (
			<>
				<div className="giving-day-leaderboard__groups">
					{ groups.map( ( group ) => (
						<section
							key={ group.term?.id || group.term?.slug }
							className="giving-day-leaderboard__group"
						>
							{ group.term?.name && (
								<h3 className="giving-day-leaderboard__group-title">
									{ group.term.name }
								</h3>
							) }
							{ group.rows && group.rows.length > 0 ? (
								<ol className={ listClass }>
									{ group.rows.map( ( row ) => (
										<Row
											key={ `${ group.term?.id }-${ row.rank }-${ row.id }` }
											row={ row }
											showAmount={ showAmount }
											showAvatar={ showAvatar }
											currency={ currency }
										/>
									) ) }
								</ol>
							) : (
								<p className="giving-day-leaderboard__empty">
									{ __( 'No entries yet.', 'giving-day-blocks' ) }
								</p>
							) }
						</section>
					) ) }
				</div>
				{ expandButton }
			</>
		);
	}

	if ( ! rows || rows.length === 0 ) {
		return (
			<p className="giving-day-leaderboard__empty">
				{ __( 'No leaderboard data yet.', 'giving-day-blocks' ) }
			</p>
		);
	}

	return (
		<>
			<ol className={ `${ listClass }` } aria-live="polite">
				{ rows.map( ( row ) => (
					<Row
						key={ `${ row.rank }-${ row.id }` }
						row={ row }
						showAmount={ showAmount }
						showAvatar={ showAvatar }
						currency={ currency }
					/>
				) ) }
			</ol>
			{ expandButton }
		</>
	);
}
