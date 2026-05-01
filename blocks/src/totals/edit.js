import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	TextControl,
	Placeholder,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';

import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';
import { campaignColorStyle } from '../_shared/utils/campaignColorStyle';

import './editor.scss';

const MODE_CHOICES = [
	{
		label: __(
			'Auto (running while live, final once ended)',
			'giving-day-blocks'
		),
		value: 'auto',
	},
	{
		label: __(
			'Final only (hidden until the event ends)',
			'giving-day-blocks'
		),
		value: 'final-only',
	},
];

export default function Edit( { attributes, setAttributes } ) {
	const { campaignId, mode, headline, subhead, showDonorCount, showGoal } =
		attributes;

	const { campaigns, campaign } = useSelect(
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
			};
		},
		[ campaignId ]
	);

	const options = useMemo( () => {
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

	const blockProps = useBlockProps( {
		className: 'giving-day-totals',
		style: campaignColorStyle( meta ),
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Campaign', 'giving-day-blocks' ) }>
					<SelectControl
						label={ __( 'Campaign', 'giving-day-blocks' ) }
						value={ campaignId || 0 }
						options={ options }
						onChange={ ( value ) =>
							setAttributes( {
								campaignId: parseInt( value, 10 ) || undefined,
							} )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Mode', 'giving-day-blocks' ) }
						value={ mode || 'auto' }
						options={ MODE_CHOICES }
						onChange={ ( value ) =>
							setAttributes( { mode: value } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Copy', 'giving-day-blocks' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Headline', 'giving-day-blocks' ) }
						value={ headline }
						onChange={ ( value ) =>
							setAttributes( { headline: value } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Subhead', 'giving-day-blocks' ) }
						value={ subhead }
						onChange={ ( value ) =>
							setAttributes( { subhead: value } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Display', 'giving-day-blocks' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Show goal', 'giving-day-blocks' ) }
						checked={ !! showGoal }
						onChange={ ( value ) =>
							setAttributes( { showGoal: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show donor count', 'giving-day-blocks' ) }
						checked={ !! showDonorCount }
						onChange={ ( value ) =>
							setAttributes( { showDonorCount: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ ! campaignId ? (
					<Placeholder
						icon="chart-bar"
						label={ __( 'Giving Day Totals', 'giving-day-blocks' ) }
						instructions={ __(
							'Pick a campaign to preview the totals.',
							'giving-day-blocks'
						) }
					>
						<SelectControl
							label={ __( 'Campaign', 'giving-day-blocks' ) }
							value={ 0 }
							options={ options }
							onChange={ ( value ) =>
								setAttributes( {
									campaignId:
										parseInt( value, 10 ) || undefined,
								} )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</Placeholder>
				) : (
					<>
						{ headline && (
							<p className="giving-day-totals__headline">
								{ headline }
							</p>
						) }
						<p className="giving-day-totals__amount">
							{ formatCurrency( raised, currency ) }
						</p>
						{ showGoal && goal > 0 && (
							<p className="giving-day-totals__goal">
								{ __( 'of', 'giving-day-blocks' ) }{ ' ' }
								{ formatCurrency( goal, currency ) }
							</p>
						) }
						{ showDonorCount && donors > 0 && (
							<p className="giving-day-totals__donors">
								{ formatNumber( donors ) }{ ' ' }
								{ __( 'donors', 'giving-day-blocks' ) }
							</p>
						) }
						{ subhead && (
							<p className="giving-day-totals__subhead">
								{ subhead }
							</p>
						) }
						{ mode === 'final-only' && (
							<p className="giving-day-totals__mode-hint">
								{ __(
									'Mode: final-only — this block stays hidden until the event ends.',
									'giving-day-blocks'
								) }
							</p>
						) }
					</>
				) }
			</div>
		</>
	);
}
