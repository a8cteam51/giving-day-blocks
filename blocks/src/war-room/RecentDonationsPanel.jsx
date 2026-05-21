import { __ } from '@wordpress/i18n';

import { formatCurrency } from '../_shared/utils/formatCurrency';
import { relativeTime } from '../_shared/utils/relativeTime';

export default function RecentDonationsPanel( { data } ) {
	const donations = Array.isArray( data?.recent_donations )
		? data.recent_donations
		: [];
	const currency = data?.currency || 'USD';

	return (
		<section
			className="giving-day-warroom__panel giving-day-warroom__panel--recent"
			aria-labelledby="gd-warroom-recent-heading"
		>
			<header className="giving-day-warroom__panel-header">
				<h2 id="gd-warroom-recent-heading">
					{ __( 'Recent donations', 'giving-day-blocks' ) }
				</h2>
			</header>
			{ donations.length === 0 ? (
				<p className="giving-day-warroom__empty">
					{ __( 'No donations yet.', 'giving-day-blocks' ) }
				</p>
			) : (
				<ul className="giving-day-warroom__recent" aria-live="polite">
					{ donations.map( ( donation ) => (
						<li
							key={ donation.order_id }
							className="giving-day-warroom__recent-row"
						>
							<span className="giving-day-warroom__recent-donor">
								{ donation.display_name }
								{ donation.offline && (
									<span className="giving-day-warroom__badge giving-day-warroom__badge--offline">
										{ __( 'Offline', 'giving-day-blocks' ) }
									</span>
								) }
								{ donation.recurring && (
									<span className="giving-day-warroom__badge giving-day-warroom__badge--recurring">
										{ __(
											'Recurring',
											'giving-day-blocks'
										) }
									</span>
								) }
							</span>
							<span className="giving-day-warroom__recent-amount">
								{ formatCurrency(
									donation.amount || 0,
									currency
								) }
							</span>
							<span className="giving-day-warroom__recent-meta">
								<DesignationLabel donation={ donation } />
								<span className="giving-day-warroom__recent-time">
									{ relativeTime( donation.order_date ) }
								</span>
							</span>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}

function DesignationLabel( { donation } ) {
	const parts = [];
	if ( donation.beneficiary?.title ) {
		parts.push( donation.beneficiary.title );
	}
	if ( donation.team?.title ) {
		parts.push( donation.team.title );
	}
	if ( parts.length === 0 ) {
		return null;
	}
	return (
		<span className="giving-day-warroom__recent-designation">
			{ parts.join( ' · ' ) }
		</span>
	);
}
