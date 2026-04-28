import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	TextControl,
	Placeholder,
	Spinner,
	Notice,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect, useMemo, useState } from '@wordpress/element';

import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';
import { campaignColorStyle } from '../_shared/utils/campaignColorStyle';
import {
	shouldHideDays,
	PREVIEW_REMAINING_MS,
} from '../_shared/utils/eventDuration';

import './editor.scss';

function CountdownDigits( { label, remaining, hideDays = false } ) {
	const safe = Math.max( 0, remaining );
	const days = Math.floor( safe / 86400 );
	const hours = Math.floor( ( safe % 86400 ) / 3600 );
	const minutes = Math.floor( ( safe % 3600 ) / 60 );
	const seconds = Math.floor( safe % 60 );
	return (
		<div className="giving-day-countdown__countdown" aria-label={ label }>
			{ ! hideDays && (
				<div className="giving-day-countdown__unit">
					<span className="giving-day-countdown__value">
						{ days }
					</span>
					<span className="giving-day-countdown__label">
						{ __( 'days', 'giving-day-blocks' ) }
					</span>
				</div>
			) }
			<div className="giving-day-countdown__unit">
				<span className="giving-day-countdown__value">
					{ String( hours ).padStart( 2, '0' ) }
				</span>
				<span className="giving-day-countdown__label">
					{ __( 'hours', 'giving-day-blocks' ) }
				</span>
			</div>
			<div className="giving-day-countdown__unit">
				<span className="giving-day-countdown__value">
					{ String( minutes ).padStart( 2, '0' ) }
				</span>
				<span className="giving-day-countdown__label">
					{ __( 'minutes', 'giving-day-blocks' ) }
				</span>
			</div>
			<div className="giving-day-countdown__unit">
				<span className="giving-day-countdown__value">
					{ String( seconds ).padStart( 2, '0' ) }
				</span>
				<span className="giving-day-countdown__label">
					{ __( 'seconds', 'giving-day-blocks' ) }
				</span>
			</div>
		</div>
	);
}

function StateBody( { state, attributes, campaign } ) {
	const meta = campaign?.meta || {};
	const goal = Number( meta._giving_goal_amount || 0 );
	const raised = Number( meta._giving_raised_override || 0 );
	const donors = Number( meta._giving_donor_count_override || 0 );
	const currency = meta._giving_currency || 'USD';

	// Tick every second so the canvas countdown feels live, mirroring the
	// hydrated front end. One-second precision is plenty here.
	const [ now, setNow ] = useState( () => Date.now() );
	useEffect( () => {
		const id = setInterval( () => setNow( Date.now() ), 1000 );
		return () => clearInterval( id );
	}, [] );

	// Pre preview honours the real campaign start so editors see actual
	// days-until-event. Live preview starts at 23:59:59 on mount and ticks
	// from there — the real end date isn't meaningful for layout checks and
	// is often already in the past by the time someone edits the post.
	const livePreviewTargetMs = useMemo(
		() => Date.now() + PREVIEW_REMAINING_MS,
		[]
	);

	const hideDays = shouldHideDays(
		meta._giving_start_datetime,
		meta._giving_end_datetime
	);

	if ( state === 'scheduled' ) {
		const startMs = meta._giving_start_datetime
			? Date.parse( meta._giving_start_datetime )
			: NaN;
		const target = Number.isFinite( startMs )
			? startMs
			: now + 14 * 86400 * 1000;
		const remaining = Math.max( 0, Math.floor( ( target - now ) / 1000 ) );
		return (
			<>
				<p className="giving-day-countdown__headline">
					{ attributes.preHeadline }
				</p>
				<CountdownDigits
					remaining={ remaining }
					label={ attributes.preHeadline }
				/>
			</>
		);
	}
	if ( state === 'live' ) {
		const remaining = Math.max(
			0,
			Math.floor( ( livePreviewTargetMs - now ) / 1000 )
		);
		return (
			<>
				<p className="giving-day-countdown__headline">
					{ attributes.liveHeadline }
				</p>
				<CountdownDigits
					remaining={ remaining }
					label={ attributes.liveHeadline }
					hideDays={ hideDays }
				/>
			</>
		);
	}

	return (
		<>
			<p className="giving-day-countdown__headline">
				{ attributes.endedHeadline }
			</p>
			<p className="giving-day-countdown__final">
				{ formatCurrency( raised, currency ) }
			</p>
			<p className="giving-day-countdown__final-meta">
				{ __( 'raised toward a goal of', 'giving-day-blocks' ) }{ ' ' }
				{ formatCurrency( goal, currency ) }
			</p>
			{ attributes.showDonorCount && donors > 0 && (
				<p className="giving-day-countdown__donors">
					{ formatNumber( donors ) }{ ' ' }
					{ __( 'donors', 'giving-day-blocks' ) }
				</p>
			) }
		</>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const { campaignId, previewState, hidePostEvent } = attributes;

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

	// Focus-driven preview: while a headline input is focused, the canvas
	// mirrors its state so editors see their copy render live. Cleared on
	// blur so the tab choice the user made still wins afterwards.
	const [ focusedState, setFocusedState ] = useState( null );

	const resolvedState = useMemo( () => {
		if ( focusedState ) {
			return focusedState;
		}
		if ( previewState ) {
			return previewState;
		}
		const meta = campaign?.meta;
		const override = meta?._giving_status_override;
		if (
			override &&
			[ 'scheduled', 'live', 'ended' ].includes( override )
		) {
			return override;
		}
		return 'scheduled';
	}, [ focusedState, previewState, campaign ] );

	const focusHandlers = ( state ) => ( {
		onFocus: () => setFocusedState( state ),
		onBlur: () => setFocusedState( null ),
	} );

	const blockProps = useBlockProps( {
		className: `giving-day-countdown giving-day-countdown--${ resolvedState }`,
		'data-status': resolvedState,
		style: campaignColorStyle( campaign?.meta ),
	} );

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
					<ToggleGroupControl
						label={ __(
							'Editor preview state',
							'giving-day-blocks'
						) }
						value={ resolvedState }
						onChange={ ( value ) =>
							setAttributes( { previewState: value || '' } )
						}
						isBlock
						help={ __(
							'Only affects the editor canvas. On the front end, logged-in users can use ?givingday=pre|live|post.',
							'giving-day-blocks'
						) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption
							value="scheduled"
							label={ __( 'Pre', 'giving-day-blocks' ) }
						/>
						<ToggleGroupControlOption
							value="live"
							label={ __( 'Live', 'giving-day-blocks' ) }
						/>
						<ToggleGroupControlOption
							value="ended"
							label={ __( 'Post', 'giving-day-blocks' ) }
						/>
					</ToggleGroupControl>
				</PanelBody>
				<PanelBody
					title={ __( 'Headlines', 'giving-day-blocks' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Pre-event headline',
							'giving-day-blocks'
						) }
						placeholder={ __(
							'Giving Day starts in',
							'giving-day-blocks'
						) }
						value={ attributes.preHeadline }
						onChange={ ( value ) =>
							setAttributes( { preHeadline: value } )
						}
						{ ...focusHandlers( 'scheduled' ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Live headline', 'giving-day-blocks' ) }
						placeholder={ __( 'Giving now', 'giving-day-blocks' ) }
						value={ attributes.liveHeadline }
						onChange={ ( value ) =>
							setAttributes( { liveHeadline: value } )
						}
						{ ...focusHandlers( 'live' ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Ended headline', 'giving-day-blocks' ) }
						placeholder={ __( 'Thank you!', 'giving-day-blocks' ) }
						value={ attributes.endedHeadline }
						onChange={ ( value ) =>
							setAttributes( { endedHeadline: value } )
						}
						{ ...focusHandlers( 'ended' ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Display', 'giving-day-blocks' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __(
							'Show donor count on ended state',
							'giving-day-blocks'
						) }
						checked={ !! attributes.showDonorCount }
						onChange={ ( value ) =>
							setAttributes( { showDonorCount: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Hide post-event state',
							'giving-day-blocks'
						) }
						help={ __(
							'Use this when pairing with a standalone Totals block placed elsewhere.',
							'giving-day-blocks'
						) }
						checked={ !! hidePostEvent }
						onChange={ ( value ) =>
							setAttributes( { hidePostEvent: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ ! campaignId ? (
					<Placeholder
						icon="clock"
						label={ __(
							'Giving Day: Countdown',
							'giving-day-blocks'
						) }
						instructions={ __(
							'Pick a campaign in the block sidebar to preview the countdown and totals.',
							'giving-day-blocks'
						) }
					>
						<SelectControl
							label={ __( 'Campaign', 'giving-day-blocks' ) }
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
				) : isResolving ? (
					<Spinner />
				) : (
					<>
						{ resolvedState === 'ended' && hidePostEvent && (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'Post-event state is hidden on the front end for this block.',
									'giving-day-blocks'
								) }
							</Notice>
						) }
						<StateBody
							state={ resolvedState }
							attributes={ attributes }
							campaign={ campaign }
						/>
					</>
				) }
			</div>
		</>
	);
}
