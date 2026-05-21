import { __ } from '@wordpress/i18n';

import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';

export default function BreakdownPanel( { data } ) {
	const breakdown = data?.breakdown || {};
	const currency = data?.currency || 'USD';
	const bySource = Array.isArray( breakdown.by_source )
		? breakdown.by_source
		: [];
	const byType = Array.isArray( breakdown.by_type ) ? breakdown.by_type : [];

	return (
		<section
			className="giving-day-warroom__panel giving-day-warroom__panel--breakdown"
			aria-labelledby="gd-warroom-breakdown-heading"
		>
			<header className="giving-day-warroom__panel-header">
				<h2 id="gd-warroom-breakdown-heading">
					{ __( 'Breakdown', 'giving-day-blocks' ) }
				</h2>
			</header>
			<div className="giving-day-warroom__breakdown">
				<BreakdownGroup
					title={ __( 'By source', 'giving-day-blocks' ) }
					rows={ bySource }
					currency={ currency }
				/>
				<BreakdownGroup
					title={ __( 'By gift type', 'giving-day-blocks' ) }
					rows={ byType }
					currency={ currency }
				/>
			</div>
		</section>
	);
}

function BreakdownGroup( { title, rows, currency } ) {
	return (
		<div className="giving-day-warroom__breakdown-group">
			<h3>{ title }</h3>
			<ul>
				{ rows.map( ( row ) => {
					const pct = Math.max(
						0,
						Math.min( 100, Number( row.percent ) || 0 )
					);
					return (
						<li
							key={ row.key }
							className="giving-day-warroom__breakdown-row"
						>
							<div className="giving-day-warroom__breakdown-head">
								<span>{ row.label }</span>
								<span>
									{ formatCurrency(
										row.raised || 0,
										currency
									) }
									{ ' · ' }
									{ formatNumber( row.count || 0 ) }
								</span>
							</div>
							<div
								className="giving-day-warroom__breakdown-bar"
								role="progressbar"
								aria-valuenow={ pct }
								aria-valuemin={ 0 }
								aria-valuemax={ 100 }
								aria-valuetext={ `${ pct }%` }
							>
								<div
									className="giving-day-warroom__breakdown-bar-fill"
									style={ { width: `${ pct }%` } }
								/>
							</div>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}
