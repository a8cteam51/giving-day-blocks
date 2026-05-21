import { __, sprintf } from '@wordpress/i18n';

import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';

export default function ActiveMatchesPanel( { data } ) {
	const matches = Array.isArray( data?.active_matches )
		? data.active_matches
		: [];
	const currency = data?.currency || 'USD';

	return (
		<section
			className="giving-day-warroom__panel giving-day-warroom__panel--matches"
			aria-labelledby="gd-warroom-matches-heading"
		>
			<header className="giving-day-warroom__panel-header">
				<h2 id="gd-warroom-matches-heading">
					{ __( 'Active matches', 'giving-day-blocks' ) }
				</h2>
				<span className="giving-day-warroom__panel-meta">
					{ formatNumber( matches.length ) }
				</span>
			</header>
			{ matches.length === 0 ? (
				<p className="giving-day-warroom__empty">
					{ __(
						'No matches running right now.',
						'giving-day-blocks'
					) }
				</p>
			) : (
				<ul className="giving-day-warroom__match-list">
					{ matches.map( ( match ) => (
						<MatchRow
							key={ match.match_id || match.id }
							match={ match }
							currency={ currency }
						/>
					) ) }
				</ul>
			) }
		</section>
	);
}

function MatchRow( { match, currency } ) {
	const isDfd = match.type === 'dollar_for_dollar';
	const pct = Math.max( 0, Math.min( 100, Number( match.pct ) || 0 ) );
	let primary;
	let secondary;
	if ( isDfd ) {
		primary = sprintf(
			/* translators: 1: matched so far, 2: cap */
			__( '%1$s of %2$s matched', 'giving-day-blocks' ),
			formatCurrency( match.matched_so_far || 0, currency ),
			formatCurrency( match.cap || 0, currency )
		);
		secondary = match.multiplier
			? sprintf(
					/* translators: %s: multiplier (e.g. 2) */
					__( '%s× multiplier', 'giving-day-blocks' ),
					formatNumber( match.multiplier )
			  )
			: '';
	} else {
		primary = sprintf(
			/* translators: 1: donors so far, 2: threshold */
			__( '%1$s of %2$s donors', 'giving-day-blocks' ),
			formatNumber( match.donors_so_far || 0 ),
			formatNumber( match.donor_threshold || 0 )
		);
		secondary = sprintf(
			/* translators: %s: unlock amount */
			__( 'Unlocks %s', 'giving-day-blocks' ),
			formatCurrency( match.unlock_amount || 0, currency )
		);
	}

	return (
		<li className="giving-day-warroom__match">
			<div className="giving-day-warroom__match-head">
				<strong>
					{ match.sponsor_name ||
						__( 'Sponsor', 'giving-day-blocks' ) }
				</strong>
				<span className="giving-day-warroom__match-secondary">
					{ secondary }
				</span>
			</div>
			<div
				className="giving-day-warroom__summary-bar"
				role="progressbar"
				aria-valuenow={ pct }
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuetext={ primary }
			>
				<div
					className="giving-day-warroom__summary-bar-fill"
					style={ { width: `${ pct }%` } }
				/>
			</div>
			<div className="giving-day-warroom__match-foot">{ primary }</div>
		</li>
	);
}
