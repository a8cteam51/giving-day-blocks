import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

import { formatCurrency } from '../_shared/utils/formatCurrency';

const W = 720;
const H = 180;
const PAD_X = 32;
const PAD_TOP = 16;
const PAD_BOTTOM = 32;

/**
 * Tiny dependency-free SVG line chart for per-bucket donations.
 *
 * @param {Object} props
 * @param {Array}  props.hourly       Aggregator finalize_pace_buckets() output.
 * @param {string} props.currency     ISO 4217 code.
 * @param {string} [props.bucketSize] 'hour' (default) or 'day'.
 * @return {JSX.Element} The rendered chart.
 */
export default function HourlyChart( {
	hourly,
	currency = 'USD',
	bucketSize = 'hour',
} ) {
	const series = useMemo(
		() => ( Array.isArray( hourly ) ? hourly : [] ),
		[ hourly ]
	);

	const { points, maxValue, gridY, ticks } = useMemo( () => {
		if ( series.length === 0 ) {
			return { points: '', maxValue: 0, gridY: [], ticks: [] };
		}
		const max = Math.max(
			...series.map( ( s ) => Number( s.raised || 0 ) )
		);
		const safeMax = max > 0 ? max : 1;
		const stepX =
			series.length > 1 ? ( W - PAD_X * 2 ) / ( series.length - 1 ) : 0;
		const plottableH = H - PAD_TOP - PAD_BOTTOM;

		const pts = series.map( ( point, i ) => {
			const x = PAD_X + stepX * i;
			const ratio = Number( point.raised || 0 ) / safeMax;
			const y = PAD_TOP + plottableH * ( 1 - ratio );
			return {
				x,
				y,
				raised: point.raised,
				count: point.count,
				t: point.hour_start,
			};
		} );

		const yLines = [ 0, 0.25, 0.5, 0.75, 1 ].map( ( r ) => ( {
			y: PAD_TOP + plottableH * ( 1 - r ),
			value: safeMax * r,
		} ) );

		// X-axis ticks: first, middle, last hour label.
		const tickIdx =
			series.length > 2
				? [ 0, Math.floor( series.length / 2 ), series.length - 1 ]
				: series.map( ( _, i ) => i );
		const xTicks = tickIdx.map( ( i ) => ( {
			x: pts[ i ].x,
			label: formatBucketLabel( series[ i ].hour_start, bucketSize ),
		} ) );

		return { points: pts, maxValue: safeMax, gridY: yLines, ticks: xTicks };
	}, [ series, bucketSize ] );

	if ( series.length === 0 ) {
		return (
			<p className="giving-day-warroom__empty">
				{ __(
					'No hourly data yet — the chart populates once donations start coming in.',
					'giving-day-blocks'
				) }
			</p>
		);
	}

	const path = points
		.map(
			( p, i ) =>
				`${ i === 0 ? 'M' : 'L' } ${ p.x.toFixed( 1 ) } ${ p.y.toFixed(
					1
				) }`
		)
		.join( ' ' );
	const areaPath =
		`${ path } L ${ points[ points.length - 1 ].x.toFixed( 1 ) } ${
			H - PAD_BOTTOM
		} ` + `L ${ points[ 0 ].x.toFixed( 1 ) } ${ H - PAD_BOTTOM } Z`;

	return (
		<figure className="giving-day-warroom__chart">
			<svg
				viewBox={ `0 0 ${ W } ${ H }` }
				role="img"
				aria-label={ __(
					'Donations per hour over the campaign window',
					'giving-day-blocks'
				) }
				preserveAspectRatio="xMidYMid meet"
				className="giving-day-warroom__chart-svg"
			>
				{ gridY.map( ( g, i ) => (
					<g key={ i }>
						<line
							x1={ PAD_X }
							x2={ W - PAD_X }
							y1={ g.y }
							y2={ g.y }
							className="giving-day-warroom__chart-grid"
						/>
						<text
							x={ PAD_X - 6 }
							y={ g.y + 3 }
							textAnchor="end"
							className="giving-day-warroom__chart-axis"
						>
							{ formatCurrencyAbbrev( g.value, currency ) }
						</text>
					</g>
				) ) }
				<path
					d={ areaPath }
					className="giving-day-warroom__chart-area"
				/>
				<path d={ path } className="giving-day-warroom__chart-line" />
				{ points.map( ( p, i ) => (
					<circle
						key={ i }
						cx={ p.x }
						cy={ p.y }
						r="3"
						className="giving-day-warroom__chart-point"
					>
						<title>
							{ `${ formatBucketLabel(
								p.t,
								bucketSize
							) } — ${ formatCurrency( p.raised, currency ) } / ${
								p.count
							} ${ p.count === 1 ? 'gift' : 'gifts' }` }
						</title>
					</circle>
				) ) }
				{ ticks.map( ( t, i ) => (
					<text
						key={ i }
						x={ t.x }
						y={ H - 10 }
						textAnchor="middle"
						className="giving-day-warroom__chart-axis"
					>
						{ t.label }
					</text>
				) ) }
			</svg>
			<figcaption className="screen-reader-text">
				{ __(
					'Donations per hour over the campaign window. Peak hour:',
					'giving-day-blocks'
				) }{ ' ' }
				{ formatCurrency( maxValue, currency ) }
			</figcaption>
		</figure>
	);
}

function formatBucketLabel( iso, bucketSize ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return '';
	}
	const locale = document.documentElement.lang || undefined;
	try {
		if ( bucketSize === 'day' ) {
			return d.toLocaleDateString( locale, {
				month: 'short',
				day: 'numeric',
			} );
		}
		return d.toLocaleTimeString( locale, {
			hour: 'numeric',
			hour12: true,
		} );
	} catch ( e ) {
		return d
			.toISOString()
			.slice(
				bucketSize === 'day' ? 0 : 11,
				bucketSize === 'day' ? 10 : 16
			);
	}
}

function formatCurrencyAbbrev( amount, currency ) {
	const n = Number( amount );
	if ( ! Number.isFinite( n ) ) {
		return '';
	}
	if ( n >= 1000 ) {
		return `${ ( n / 1000 ).toFixed( n >= 10000 ? 0 : 1 ) }k`;
	}
	return formatCurrency( n, currency );
}
