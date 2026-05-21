import { __ } from '@wordpress/i18n';

import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';

/**
 * Renders a top-N list from a Leaderboard payload (rows or grouped).
 *
 * @param {Object}       props
 * @param {string}       props.title        Section heading.
 * @param {Object|Array} props.payload      Leaderboard rows or {rows:[…]} payload.
 * @param {string}       props.currency     ISO 4217 code.
 * @param {string}       [props.emptyLabel] Shown when there are no rows.
 * @return {JSX.Element} The rendered section.
 */
export default function TopListPanel( {
	title,
	payload,
	currency,
	emptyLabel,
} ) {
	let rows = [];
	if ( Array.isArray( payload?.rows ) ) {
		rows = payload.rows;
	} else if ( Array.isArray( payload ) ) {
		rows = payload;
	}

	return (
		<section
			className="giving-day-warroom__panel giving-day-warroom__panel--top"
			aria-label={ title }
		>
			<header className="giving-day-warroom__panel-header">
				<h2>{ title }</h2>
				<span className="giving-day-warroom__panel-meta">
					{ formatNumber( rows.length ) }
				</span>
			</header>
			{ rows.length === 0 ? (
				<p className="giving-day-warroom__empty">
					{ emptyLabel || __( 'No data yet.', 'giving-day-blocks' ) }
				</p>
			) : (
				<ol className="giving-day-warroom__top-list">
					{ rows.map( ( row, idx ) => (
						<li
							key={ row.id || idx }
							className="giving-day-warroom__top-row"
						>
							<span className="giving-day-warroom__top-rank">
								{ row.rank || idx + 1 }
							</span>
							<span className="giving-day-warroom__top-title">
								{ row.label ||
									row.title ||
									row.display_name ||
									__( 'Untitled', 'giving-day-blocks' ) }
							</span>
							<span className="giving-day-warroom__top-amount">
								{ formatCurrency(
									row.raised || row.amount || 0,
									currency
								) }
							</span>
						</li>
					) ) }
				</ol>
			) }
		</section>
	);
}
