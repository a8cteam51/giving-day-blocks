import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	Placeholder,
	Spinner,
	Notice,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';

import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';
import { campaignColorStyle } from '../_shared/utils/campaignColorStyle';
import { clampPercent, computePercent } from '../_shared/utils/goalProgress';

import './editor.scss';

// Editor-only fallback fill when the campaign hasn't accumulated any
// "raised" yet. PLAN.md § 5.1: "shows mocked 42% in editor with 'Using
// campaign: X' notice." Picked deliberately distinct from a likely real
// post-launch value so editors can tell preview from production.
const PREVIEW_PERCENT = 42;

export default function Edit( { attributes, setAttributes } ) {
	const {
		campaignId,
		orientation,
		showPercent,
		showRaised,
		showGoal,
		showDonorCount,
		animateBar,
		animateNumbers,
	} = attributes;

	const { campaigns, campaign, isResolving } = useSelect(
		( select ) => {
			const core = select( 'core' );
			return {
				campaigns:
					core.getEntityRecords( 'postType', 'giving_campaign', {
						per_page: 50,
						orderby: 'date',
						order: 'desc',
					} ) || [],
				campaign: campaignId
					? core.getEntityRecord(
							'postType',
							'giving_campaign',
							campaignId
					  )
					: null,
				isResolving: campaignId
					? core.isResolving( 'getEntityRecord', [
							'postType',
							'giving_campaign',
							campaignId,
					  ] )
					: false,
			};
		},
		[ campaignId ]
	);

	const campaignOptions = useMemo( () => {
		const opts = [
			{
				label: __( '— Select a campaign —', 'giving-day-blocks' ),
				value: 0,
			},
		];
		( campaigns || [] ).forEach( ( c ) => {
			opts.push( {
				value: c.id,
				label: c.title?.rendered || c.title?.raw || `#${ c.id }`,
			} );
		} );
		return opts;
	}, [ campaigns ] );

	const meta = campaign?.meta || {};
	const goal = Number( meta._giving_goal_amount || 0 );
	// Editor preview only — real raised/donor numbers come from the
	// server-side Aggregator at frontend render time.
	const raised = 0;
	const donors = 0;
	const currency = meta._giving_currency || 'USD';

	const realPercent = computePercent( raised, goal );
	const isPreviewFill = raised <= 0;
	const displayPercent = isPreviewFill
		? PREVIEW_PERCENT
		: clampPercent( realPercent );

	const blockProps = useBlockProps( {
		className: `giving-day-goal-progress giving-day-goal-progress--${ orientation }`,
		'data-orientation': orientation,
		style: campaignColorStyle( meta ),
	} );

	const campaignTitle =
		campaign?.title?.rendered || campaign?.title?.raw || '';

	let body;
	if ( ! campaignId ) {
		body = (
			<Placeholder
				icon="chart-bar"
				label={ __(
					'Giving Day: Goal + Progress',
					'giving-day-blocks'
				) }
				instructions={ __(
					'Pick a campaign in the block sidebar to preview the progress bar.',
					'giving-day-blocks'
				) }
			>
				<SelectControl
					label={ __( 'Campaign', 'giving-day-blocks' ) }
					value={ 0 }
					options={ campaignOptions }
					onChange={ ( value ) =>
						setAttributes( {
							campaignId: parseInt( value, 10 ) || undefined,
						} )
					}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</Placeholder>
		);
	} else if ( isResolving ) {
		body = <Spinner />;
	} else {
		const previewPercentLabel = `${ PREVIEW_PERCENT }%`;
		const previewMessage = campaignTitle
			? sprintf(
					/* translators: 1: percent (e.g. "42%"), 2: campaign title. */
					__(
						'Editor preview: showing %1$s bar for "%2$s". The front end will render the real raised amount.',
						'giving-day-blocks'
					),
					previewPercentLabel,
					campaignTitle
			  )
			: sprintf(
					/* translators: %s: percent (e.g. "42%"). */
					__(
						'Editor preview: showing %s bar. The front end will render the real raised amount.',
						'giving-day-blocks'
					),
					previewPercentLabel
			  );
		const previewRaised = isPreviewFill
			? Math.round( ( goal * PREVIEW_PERCENT ) / 100 )
			: raised;
		body = (
			<>
				{ isPreviewFill && (
					<Notice
						status="info"
						isDismissible={ false }
						className="giving-day-goal-progress__notice"
					>
						{ previewMessage }
					</Notice>
				) }
				<Bar
					percent={ displayPercent }
					goal={ goal }
					raised={ previewRaised }
					donors={ donors }
					currency={ currency }
					orientation={ orientation }
					showPercent={ showPercent }
					showRaised={ showRaised }
					showGoal={ showGoal }
					showDonorCount={ showDonorCount }
				/>
			</>
		);
	}

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Campaign', 'giving-day-blocks' ) }>
					<SelectControl
						label={ __( 'Campaign', 'giving-day-blocks' ) }
						value={ campaignId || 0 }
						options={ campaignOptions }
						onChange={ ( value ) =>
							setAttributes( {
								campaignId: parseInt( value, 10 ) || undefined,
							} )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody title={ __( 'Layout', 'giving-day-blocks' ) }>
					<ToggleGroupControl
						label={ __( 'Orientation', 'giving-day-blocks' ) }
						value={ orientation }
						onChange={ ( value ) =>
							setAttributes( { orientation: value } )
						}
						isBlock
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption
							value="horizontal"
							label={ __( 'Horizontal', 'giving-day-blocks' ) }
						/>
						<ToggleGroupControlOption
							value="vertical"
							label={ __( 'Vertical', 'giving-day-blocks' ) }
						/>
					</ToggleGroupControl>
				</PanelBody>
				<PanelBody title={ __( 'Display', 'giving-day-blocks' ) }>
					<ToggleControl
						label={ __(
							'Show raised amount',
							'giving-day-blocks'
						) }
						checked={ !! showRaised }
						onChange={ ( value ) =>
							setAttributes( { showRaised: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show goal amount', 'giving-day-blocks' ) }
						checked={ !! showGoal }
						onChange={ ( value ) =>
							setAttributes( { showGoal: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show percent', 'giving-day-blocks' ) }
						checked={ !! showPercent }
						onChange={ ( value ) =>
							setAttributes( { showPercent: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show donor count', 'giving-day-blocks' ) }
						checked={ !! showDonorCount }
						onChange={ ( value ) =>
							setAttributes( { showDonorCount: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Animate bar', 'giving-day-blocks' ) }
						help={ __(
							'Eases the bar fill on first paint and on live updates. Disabled automatically when the visitor prefers reduced motion.',
							'giving-day-blocks'
						) }
						checked={ !! animateBar }
						onChange={ ( value ) =>
							setAttributes( { animateBar: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Animate numbers', 'giving-day-blocks' ) }
						help={ __(
							'Counts the raised amount and percent up to the new value. Disabled automatically when the visitor prefers reduced motion.',
							'giving-day-blocks'
						) }
						checked={ !! animateNumbers }
						onChange={ ( value ) =>
							setAttributes( { animateNumbers: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>{ body }</div>
		</>
	);
}

function Bar( {
	percent,
	goal,
	raised,
	donors,
	currency,
	orientation,
	showPercent,
	showRaised,
	showGoal,
	showDonorCount,
} ) {
	const hasGoal = Number( goal ) > 0;
	const fillStyle =
		orientation === 'vertical'
			? { height: `${ percent }%` }
			: { width: `${ percent }%` };
	const ariaValueText = hasGoal
		? sprintf(
				/* translators: 1: raised amount, 2: goal amount, 3: percent. */
				__( '%1$s raised of %2$s, %3$s%%.', 'giving-day-blocks' ),
				formatCurrency( raised, currency ),
				formatCurrency( goal, currency ),
				Math.round( percent )
		  )
		: undefined;

	return (
		<div className="giving-day-goal-progress__inner">
			{ showRaised && (
				<p className="giving-day-goal-progress__raised">
					{ formatCurrency( raised, currency ) }
				</p>
			) }
			{ hasGoal ? (
				<div
					className="giving-day-goal-progress__track"
					role="progressbar"
					aria-valuenow={ Math.round( percent ) }
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
					aria-valuetext={ ariaValueText }
				>
					<span
						className="giving-day-goal-progress__fill"
						style={ fillStyle }
						aria-hidden="true"
					/>
				</div>
			) : (
				<div
					className="giving-day-goal-progress__track"
					aria-hidden="true"
				>
					<span
						className="giving-day-goal-progress__fill"
						style={ fillStyle }
					/>
				</div>
			) }
			<div className="giving-day-goal-progress__meta">
				{ hasGoal && showGoal && (
					<span className="giving-day-goal-progress__goal">
						{ sprintf(
							/* translators: %s: goal amount, e.g. $50,000 */
							__( 'of %s', 'giving-day-blocks' ),
							formatCurrency( goal, currency )
						) }
					</span>
				) }
				{ hasGoal && showPercent && (
					<span className="giving-day-goal-progress__percent">
						{ Math.round( percent ) }%
					</span>
				) }
				{ showDonorCount && donors > 0 && (
					<span className="giving-day-goal-progress__donors">
						{ formatNumber( donors ) }{ ' ' }
						{ __( 'donors', 'giving-day-blocks' ) }
					</span>
				) }
			</div>
		</div>
	);
}
