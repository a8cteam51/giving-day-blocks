import { __, sprintf } from '@wordpress/i18n';

import { formatCurrency, formatNumber } from './formatCurrency';
import {
	MATCH_TYPE_DONOR_UNLOCK,
	MATCH_STATE_SCHEDULED,
	MATCH_STATE_ACTIVE,
	MATCH_STATE_EXHAUSTED,
	MATCH_STATE_UNLOCKED,
	MATCH_STATE_COMPLETED,
} from './matchProgress';

/**
 * Returns the type-aware copy strings for a match payload. The string
 * set must stay identical to MatchProgress::front_end_copy() in PHP so
 * SSR markup and hydration agree visually.
 *
 * @param {Object} match    Resolved progress payload from the REST API.
 * @param {string} currency 3-letter ISO code.
 * @return {{ typeLabel: string, headline: string, meta: string }}
 */
export function frontEndCopy( match, currency ) {
	if ( ! match ) {
		return { typeLabel: '', headline: '', meta: '' };
	}
	if ( match.type === MATCH_TYPE_DONOR_UNLOCK ) {
		return copyDonorUnlock( match, currency );
	}
	return copyDollarForDollar( match, currency );
}

function copyDonorUnlock( match, currency ) {
	const threshold = Number( match.donor_threshold ) || 0;
	const donors = Number( match.donors_so_far ) || 0;
	const remaining = Number( match.donors_remaining ) || 0;
	const unlock = Number( match.unlock_amount ) || 0;
	const state = match.state || MATCH_STATE_ACTIVE;

	const typeLabel = __( 'Donor unlock', 'giving-day-blocks' );
	let headline;
	switch ( state ) {
		case MATCH_STATE_SCHEDULED:
			headline = __(
				'Match starts soon — help unlock the bonus.',
				'giving-day-blocks'
			);
			break;
		case MATCH_STATE_UNLOCKED:
			headline = sprintf(
				/* translators: %s: amount (formatted). */
				__( 'Goal reached — %s unlocked!', 'giving-day-blocks' ),
				formatCurrency( unlock, currency )
			);
			break;
		case MATCH_STATE_COMPLETED:
			headline = __( 'Match ended.', 'giving-day-blocks' );
			break;
		default:
			headline = sprintf(
				/* translators: 1: donors needed, 2: amount (formatted). */
				__(
					'%1$s more donors unlock %2$s.',
					'giving-day-blocks'
				),
				formatNumber( remaining ),
				formatCurrency( unlock, currency )
			);
	}

	const meta = sprintf(
		/* translators: 1: donors so far, 2: donor threshold. */
		__( '%1$s of %2$s donors', 'giving-day-blocks' ),
		formatNumber( donors ),
		formatNumber( threshold )
	);

	return { typeLabel, headline, meta };
}

function copyDollarForDollar( match, currency ) {
	const cap = Number( match.cap ) || 0;
	const matched = Number( match.matched_so_far ) || 0;
	const remaining = Number( match.remaining ) || 0;
	const multiplierLabel = match.multiplier_label || '2×';
	const state = match.state || MATCH_STATE_ACTIVE;

	const typeLabel = __( 'Dollar-for-dollar', 'giving-day-blocks' );
	let headline;
	switch ( state ) {
		case MATCH_STATE_SCHEDULED:
			headline = __(
				'Match starts soon — your gift will go further.',
				'giving-day-blocks'
			);
			break;
		case MATCH_STATE_EXHAUSTED:
			headline = sprintf(
				/* translators: %s: cap (formatted). */
				__(
					'%s fully matched — thank you!',
					'giving-day-blocks'
				),
				formatCurrency( cap, currency )
			);
			break;
		case MATCH_STATE_COMPLETED:
			headline = __( 'Match ended.', 'giving-day-blocks' );
			break;
		default:
			headline = sprintf(
				/* translators: %s: multiplier label, e.g. "2×". */
				__(
					'Your gift goes %s further.',
					'giving-day-blocks'
				),
				multiplierLabel
			);
	}

	const meta = cap > 0
		? sprintf(
				/* translators: 1: matched amount, 2: cap, 3: remaining. */
				__(
					'%1$s of %2$s matched — %3$s still available.',
					'giving-day-blocks'
				),
				formatCurrency( matched, currency ),
				formatCurrency( cap, currency ),
				formatCurrency( remaining, currency )
		  )
		: sprintf(
				/* translators: %s: matched amount. */
				__( '%s matched so far.', 'giving-day-blocks' ),
				formatCurrency( matched, currency )
		  );

	return { typeLabel, headline, meta };
}
