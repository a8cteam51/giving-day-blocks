import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InnerBlocks,
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import {
	PanelBody,
	SelectControl,
	RangeControl,
	Button,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useMemo, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { campaignColorStyle } from '../_shared/utils/campaignColorStyle';

import './editor.scss';

const ALLOWED_BLOCKS = [ 'giving-day/leaderboard' ];

const INNER_TEMPLATE = [
	[ 'giving-day/leaderboard', { dimension: 'top_teams', limit: 10 } ],
];

export default function Edit( { attributes, setAttributes, clientId } ) {
	const { campaignId, defaultTabIndex } = attributes;

	const { replaceInnerBlocks, updateBlockAttributes } =
		useDispatch( blockEditorStore );

	const innerBlocks = useSelect(
		( select ) =>
			select( blockEditorStore ).getBlock( clientId )?.innerBlocks || [],
		[ clientId ]
	);

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
	const blockProps = useBlockProps( {
		className: 'giving-day-leaderboard-tabs is-editor',
		style: campaignColorStyle( meta ),
	} );

	const onAutoPopulate = async () => {
		if ( ! campaignId ) {
			return;
		}
		try {
			const res = await apiFetch( {
				path: '/giving-day/v1/team-categories?parent=0',
			} );
			const terms = res?.terms || [];
			if ( ! Array.isArray( terms ) || terms.length === 0 ) {
				return;
			}
			const blocks = terms.map( ( t ) =>
				createBlock( 'giving-day/leaderboard', {
					campaignId,
					tabLabel: t.name,
					dimension: 'top_teams',
					groupByParentTermId: t.id,
					limit: 10,
				} )
			);
			replaceInnerBlocks( clientId, blocks );
		} catch ( e ) {
			// eslint-disable-next-line no-console
			console.error( e );
		}
	};

	const syncCampaignToChildren = useCallback( () => {
		if ( ! campaignId ) {
			return;
		}
		innerBlocks.forEach( ( child ) => {
			if ( child.name === 'giving-day/leaderboard' ) {
				updateBlockAttributes( child.clientId, { campaignId } );
			}
		} );
	}, [ campaignId, innerBlocks, updateBlockAttributes ] );

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
					<RangeControl
						label={ __(
							'Default tab (index)',
							'giving-day-blocks'
						) }
						value={ defaultTabIndex || 0 }
						onChange={ ( v ) =>
							setAttributes( { defaultTabIndex: v || 0 } )
						}
						min={ 0 }
						max={ 20 }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="giving-day-leaderboard-tabs__toolbar">
					<Button
						variant="secondary"
						onClick={ onAutoPopulate }
						disabled={ ! campaignId }
					>
						{ __(
							'Auto-populate from Team Categories',
							'giving-day-blocks'
						) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ syncCampaignToChildren }
						disabled={ ! campaignId }
					>
						{ __(
							'Apply campaign to all leaderboards',
							'giving-day-blocks'
						) }
					</Button>
				</div>
				<InnerBlocks
					allowedBlocks={ ALLOWED_BLOCKS }
					template={ INNER_TEMPLATE }
					renderAppender={ InnerBlocks.ButtonBlockAppender }
				/>
			</div>
		</>
	);
}
