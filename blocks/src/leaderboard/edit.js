import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	RangeControl,
	TextControl,
	Placeholder,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';

import { useLeaderboard } from '../_shared/hooks/useLeaderboard';
import { useCampaignStatus } from '../_shared/hooks/useCampaignStatus';
import { campaignColorStyle } from '../_shared/utils/campaignColorStyle';

import LeaderboardBody from './LeaderboardBody';
import './editor.scss';

const DIMENSIONS = [
	{ label: __( 'Top donors', 'giving-day-blocks' ), value: 'top_donors' },
	{ label: __( 'Top teams', 'giving-day-blocks' ), value: 'top_teams' },
	{
		label: __( 'Top beneficiaries', 'giving-day-blocks' ),
		value: 'top_beneficiaries',
	},
	{ label: __( 'Top causes', 'giving-day-blocks' ), value: 'top_causes' },
];

export default function Edit( { attributes, setAttributes, context } ) {
	const {
		campaignId,
		tabLabel,
		dimension,
		limit,
		filterTermId,
		groupByParentTermId,
		showAmount,
		showAvatar,
		anonymize,
		refreshInterval,
	} = attributes;

	const rawParent = context[ 'giving-day/parentCampaignId' ];
	const parentCampaign =
		rawParent !== undefined && rawParent !== null ? Number( rawParent ) : 0;
	const resolvedId = campaignId || parentCampaign || 0;

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
				campaign: resolvedId
					? core.getEntityRecord(
							'postType',
							'giving_campaign',
							resolvedId
					  )
					: null,
			};
		},
		[ resolvedId ]
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

	const query = useMemo(
		() => ( {
			dimension: dimension || 'top_teams',
			limit: limit || 10,
			filterTermId: filterTermId || 0,
			groupByParentTermId: groupByParentTermId || 0,
			anonymize: !! anonymize,
		} ),
		[ dimension, limit, filterTermId, groupByParentTermId, anonymize ]
	);

	const { status } = useCampaignStatus( resolvedId );
	let pollMs = 60000;
	if ( refreshInterval > 0 ) {
		pollMs = refreshInterval;
	} else if ( status === 'live' ) {
		pollMs = 15000;
	}

	const { data, loading } = useLeaderboard( resolvedId, query, {
		intervalMs: pollMs,
	} );

	const meta = campaign?.meta || {};
	const blockProps = useBlockProps( {
		className: 'giving-day-leaderboard is-editor',
		style: campaignColorStyle( meta ),
	} );

	const rows = data?.rows;
	const groups = data?.groups;
	const currency = data?.currency || meta._giving_currency || 'USD';

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Campaign', 'giving-day-blocks' ) }>
					<SelectControl
						label={ __( 'Campaign', 'giving-day-blocks' ) }
						value={ campaignId || 0 }
						help={
							parentCampaign && ! campaignId
								? __(
										'Using the tab container campaign.',
										'giving-day-blocks'
								  )
								: ''
						}
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
				<PanelBody title={ __( 'Leaderboard', 'giving-day-blocks' ) }>
					<SelectControl
						label={ __( 'Dimension', 'giving-day-blocks' ) }
						value={ dimension || 'top_teams' }
						options={ DIMENSIONS }
						onChange={ ( value ) =>
							setAttributes( { dimension: value } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<RangeControl
						label={ __( 'List size', 'giving-day-blocks' ) }
						value={ limit || 10 }
						onChange={ ( v ) =>
							setAttributes( { limit: v || 10 } )
						}
						min={ 1 }
						max={ 50 }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __(
							'Filter term ID (team category or cause)',
							'giving-day-blocks'
						) }
						type="number"
						value={ filterTermId ? String( filterTermId ) : '' }
						onChange={ ( v ) =>
							setAttributes( {
								filterTermId: parseInt( v, 10 ) || 0,
							} )
						}
						help={ __(
							'Optional. Narrows teams or beneficiaries to one taxonomy term.',
							'giving-day-blocks'
						) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __(
							'Group by parent team category (term ID)',
							'giving-day-blocks'
						) }
						type="number"
						value={
							groupByParentTermId
								? String( groupByParentTermId )
								: ''
						}
						onChange={ ( v ) =>
							setAttributes( {
								groupByParentTermId: parseInt( v, 10 ) || 0,
							} )
						}
						help={ __(
							'Optional. Renders one sub-list per child of this parent term (best with Top teams).',
							'giving-day-blocks'
						) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Display', 'giving-day-blocks' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Show amounts', 'giving-day-blocks' ) }
						checked={ !! showAmount }
						onChange={ ( v ) => setAttributes( { showAmount: v } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Show avatars', 'giving-day-blocks' ) }
						checked={ !! showAvatar }
						onChange={ ( v ) => setAttributes( { showAvatar: v } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Anonymize donors', 'giving-day-blocks' ) }
						checked={ !! anonymize }
						onChange={ ( v ) => setAttributes( { anonymize: v } ) }
						help={ __(
							'Applies to the Top donors dimension.',
							'giving-day-blocks'
						) }
						__nextHasNoMarginBottom
					/>
					<RangeControl
						label={ __(
							'Refresh interval (seconds)',
							'giving-day-blocks'
						) }
						value={ Math.round(
							( refreshInterval || 15000 ) / 1000
						) }
						onChange={ ( v ) =>
							setAttributes( {
								refreshInterval: Math.max( 5, v || 15 ) * 1000,
							} )
						}
						min={ 5 }
						max={ 300 }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Tabs container', 'giving-day-blocks' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Tab label (inside Leaderboard Tabs)',
							'giving-day-blocks'
						) }
						value={ tabLabel || '' }
						onChange={ ( v ) =>
							setAttributes( { tabLabel: v || '' } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ resolvedId <= 0 ? (
					<Placeholder
						icon="awards"
						label={ __(
							'Giving Day Leaderboard',
							'giving-day-blocks'
						) }
						instructions={ __(
							'Select a campaign, or place this block inside Leaderboard Tabs to inherit its campaign.',
							'giving-day-blocks'
						) }
					>
						<SelectControl
							value={ 0 }
							options={ campaignOptions }
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
					<div className="giving-day-leaderboard__inner">
						<LeaderboardBody
							rows={ rows }
							groups={ groups }
							currency={ currency }
							showAmount={ showAmount }
							showAvatar={ showAvatar }
							loading={ loading }
						/>
					</div>
				) }
			</div>
		</>
	);
}
