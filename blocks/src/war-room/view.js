/**
 * Front-end hydration for giving-day/war-room.
 *
 * Server-side render already permission-gated the markup; we just bootstrap
 * the React tree against the initial payload and let the poll loop take
 * over.
 */
import { createRoot } from '@wordpress/element';

import WarRoom, { ALL_PANELS } from './WarRoom';

function parseJSON( raw, fallback ) {
	if ( ! raw ) {
		return fallback;
	}
	try {
		const parsed = JSON.parse( raw );
		return parsed ?? fallback;
	} catch ( e ) {
		return fallback;
	}
}

function bootstrap( root ) {
	const campaignId = Number( root.dataset.campaignId );
	if ( ! campaignId ) {
		return;
	}
	const parsedPanels = parseJSON( root.dataset.panels, ALL_PANELS );
	const panels = Array.isArray( parsedPanels ) ? parsedPanels : ALL_PANELS;
	const initialData = parseJSON( root.dataset.initial, null );
	const parsedInterval = Number.parseInt(
		root.dataset.refreshMs || '10000',
		10
	);
	const intervalMs =
		Number.isFinite( parsedInterval ) && parsedInterval >= 1000
			? parsedInterval
			: 10000;

	const reactRoot = createRoot( root );
	reactRoot.render(
		<WarRoom
			campaignId={ campaignId }
			panels={ panels }
			intervalMs={ intervalMs }
			initialData={ initialData }
		/>
	);
}

document
	.querySelectorAll( '[data-giving-day-warroom]' )
	.forEach( ( el ) => bootstrap( el ) );
