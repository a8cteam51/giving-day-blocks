import { __ } from '@wordpress/i18n';

import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';

const STATUS_LABEL = {
	scheduled: __( 'Scheduled', 'giving-day-blocks' ),
	live: __( 'Live', 'giving-day-blocks' ),
	ended: __( 'Ended', 'giving-day-blocks' ),
};

export default function SummaryPanel( { data } ) {
	const summary = data?.summary || {};
	const currency = data?.currency || 'USD';
	const status = data?.status || 'scheduled';
	const pct = Math.max( 0, Math.min( 100, Number( summary.percent ) || 0 ) );

	return (
		<section
			className="giving-day-warroom__panel giving-day-warroom__panel--summary"
			aria-labelledby="gd-warroom-summary-heading"
		>
			<header className="giving-day-warroom__panel-header">
				<h2 id="gd-warroom-summary-heading">
					{ __( 'Campaign summary', 'giving-day-blocks' ) }
				</h2>
				<span
					className={ `giving-day-warroom__status giving-day-warroom__status--${ status }` }
				>
					{ STATUS_LABEL[ status ] || status }
				</span>
			</header>
			<div className="giving-day-warroom__summary-grid">
				<div className="giving-day-warroom__summary-headline">
					<span
						className="giving-day-warroom__summary-amount"
						aria-live="polite"
					>
						{ formatCurrency( summary.raised || 0, currency ) }
					</span>
					{ summary.goal > 0 && (
						<span className="giving-day-warroom__summary-goal">
							{ /* translators: %s: formatted goal amount */ }
							{ __( 'of', 'giving-day-blocks' ) }{ ' ' }
							{ formatCurrency( summary.goal, currency ) }
						</span>
					) }
				</div>
				<div
					className="giving-day-warroom__summary-bar"
					role="progressbar"
					aria-valuenow={ pct }
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
					aria-valuetext={ `${ pct }%` }
				>
					<div
						className="giving-day-warroom__summary-bar-fill"
						style={ { width: `${ pct }%` } }
					/>
				</div>
				<dl className="giving-day-warroom__summary-stats">
					<div>
						<dt>{ __( 'Donations', 'giving-day-blocks' ) }</dt>
						<dd>{ formatNumber( summary.count || 0 ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Unique donors', 'giving-day-blocks' ) }</dt>
						<dd>{ formatNumber( summary.unique_donors || 0 ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Average gift', 'giving-day-blocks' ) }</dt>
						<dd>
							{ formatCurrency( summary.avg || 0, currency ) }
						</dd>
					</div>
				</dl>
			</div>
		</section>
	);
}
