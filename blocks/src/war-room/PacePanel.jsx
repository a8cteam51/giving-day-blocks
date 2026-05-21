import { __ } from '@wordpress/i18n';

import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';
import HourlyChart from './HourlyChart';

export default function PacePanel( { data } ) {
	const pace = data?.pace || {};
	const currency = data?.currency || 'USD';
	const lastHour = pace.last_hour || { raised: 0, count: 0 };

	return (
		<section
			className="giving-day-warroom__panel giving-day-warroom__panel--pace"
			aria-labelledby="gd-warroom-pace-heading"
		>
			<header className="giving-day-warroom__panel-header">
				<h2 id="gd-warroom-pace-heading">
					{ __( 'Pace', 'giving-day-blocks' ) }
				</h2>
			</header>
			<div className="giving-day-warroom__stat-row">
				<div className="giving-day-warroom__stat">
					<span className="giving-day-warroom__stat-label">
						{ __( 'Last 60 minutes', 'giving-day-blocks' ) }
					</span>
					<span className="giving-day-warroom__stat-value">
						{ formatCurrency( lastHour.raised || 0, currency ) }
					</span>
					<span className="giving-day-warroom__stat-meta">
						{ /* translators: %s: number of donations */ }
						{ `${ formatNumber( lastHour.count || 0 ) } ` }
						{ Number( lastHour.count ) === 1
							? __( 'donation', 'giving-day-blocks' )
							: __( 'donations', 'giving-day-blocks' ) }
					</span>
				</div>
			</div>
			<HourlyChart
				hourly={ pace.hourly || [] }
				currency={ currency }
				bucketSize={ pace.bucket_size || 'hour' }
			/>
		</section>
	);
}
