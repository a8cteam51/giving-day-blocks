import { __ } from '@wordpress/i18n';

import { useWarRoom } from '../_shared/hooks/useWarRoom';

import SummaryPanel from './SummaryPanel';
import PacePanel from './PacePanel';
import ActiveMatchesPanel from './ActiveMatchesPanel';
import BreakdownPanel from './BreakdownPanel';
import TopListPanel from './TopListPanel';
import RecentDonationsPanel from './RecentDonationsPanel';

export const ALL_PANELS = [
	'summary',
	'pace',
	'matches',
	'breakdown',
	'top_teams',
	'top_beneficiaries',
	'recent',
];

export const DEFAULT_PANELS = ALL_PANELS;

export const PANEL_LABELS = {
	summary: __( 'Summary', 'giving-day-blocks' ),
	pace: __( 'Pace + hourly chart', 'giving-day-blocks' ),
	matches: __( 'Active matches', 'giving-day-blocks' ),
	breakdown: __( 'Breakdown', 'giving-day-blocks' ),
	top_teams: __( 'Top teams', 'giving-day-blocks' ),
	top_beneficiaries: __( 'Top beneficiaries', 'giving-day-blocks' ),
	recent: __( 'Recent donations', 'giving-day-blocks' ),
};

/**
 * Composite "command center" view for organizers. Reads /warroom and
 * renders the enabled panels.
 *
 * @param {Object}   props
 * @param {number}   props.campaignId
 * @param {string[]} props.panels        Which panels to render.
 * @param {Object}   [props.initialData] Server-rendered payload for hydration.
 * @param {number}   [props.intervalMs]
 */
export default function WarRoom( {
	campaignId,
	panels = DEFAULT_PANELS,
	initialData = null,
	intervalMs = 10000,
} ) {
	const { data, error, loading } = useWarRoom( campaignId, {
		intervalMs,
		initialData,
	} );

	if ( ! campaignId ) {
		return (
			<p className="giving-day-warroom__empty">
				{ __(
					'Pick a campaign to populate the war room.',
					'giving-day-blocks'
				) }
			</p>
		);
	}

	if ( loading && ! data ) {
		return (
			<p className="giving-day-warroom__loading">
				{ __( 'Loading…', 'giving-day-blocks' ) }
			</p>
		);
	}

	if ( error && ! data ) {
		return (
			<p className="giving-day-warroom__error" role="alert">
				{ /* translators: %s: error message */ }
				{ error.message ||
					__( 'Unable to load war room.', 'giving-day-blocks' ) }
			</p>
		);
	}

	const enabled = new Set( panels );
	return (
		<div className="giving-day-warroom__grid">
			{ enabled.has( 'summary' ) && (
				<div className="giving-day-warroom__cell giving-day-warroom__cell--full">
					<SummaryPanel data={ data } />
				</div>
			) }
			{ enabled.has( 'pace' ) && (
				<div className="giving-day-warroom__cell giving-day-warroom__cell--full">
					<PacePanel data={ data } />
				</div>
			) }
			{ enabled.has( 'matches' ) && (
				<div className="giving-day-warroom__cell giving-day-warroom__cell--half">
					<ActiveMatchesPanel data={ data } />
				</div>
			) }
			{ enabled.has( 'breakdown' ) && (
				<div className="giving-day-warroom__cell giving-day-warroom__cell--half">
					<BreakdownPanel data={ data } />
				</div>
			) }
			{ enabled.has( 'top_teams' ) && (
				<div className="giving-day-warroom__cell giving-day-warroom__cell--half">
					<TopListPanel
						title={ __( 'Top teams', 'giving-day-blocks' ) }
						payload={ data?.top_teams }
						currency={ data?.currency }
						emptyLabel={ __(
							'No teams have raised yet.',
							'giving-day-blocks'
						) }
					/>
				</div>
			) }
			{ enabled.has( 'top_beneficiaries' ) && (
				<div className="giving-day-warroom__cell giving-day-warroom__cell--half">
					<TopListPanel
						title={ __( 'Top beneficiaries', 'giving-day-blocks' ) }
						payload={ data?.top_beneficiaries }
						currency={ data?.currency }
						emptyLabel={ __(
							'No beneficiaries have raised yet.',
							'giving-day-blocks'
						) }
					/>
				</div>
			) }
			{ enabled.has( 'recent' ) && (
				<div className="giving-day-warroom__cell giving-day-warroom__cell--full">
					<RecentDonationsPanel data={ data } />
				</div>
			) }
		</div>
	);
}
