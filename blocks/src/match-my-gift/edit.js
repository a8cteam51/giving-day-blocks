import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	Placeholder,
	Notice,
	ExternalLink,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';
import { campaignColorStyle } from '../_shared/utils/campaignColorStyle';
import {
	MATCH_TYPE_DOLLAR_FOR_DOLLAR,
	MATCH_TYPE_DONOR_UNLOCK,
} from '../_shared/utils/matchProgress';

import './editor.scss';

/**
 * Editor preview for the Match My Gift block.
 *
 * The block.json attributes deliberately keep DISPLAY-level preferences
 * (which match to show, what to show alongside it). The MATCH itself —
 * mechanic, multiplier, donor threshold, window — is configured on the
 * `giving_match` post via the dedicated post-edit screen plugin (see
 * src/Admin/MatchEditor.php). The notice + ExternalLink at the bottom
 * teach editors that hand-off explicitly.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		campaignId,
		matchSelection,
		matchId,
		variant,
		showSponsorLogo,
		showSponsorName,
		showCountdown,
		hideWhenComplete,
		showOutsideWindow,
	} = attributes;

	const { campaigns, matches, campaign, match } = useSelect(
		( select ) => {
			const core = select( 'core' );
			return {
				campaigns:
					core.getEntityRecords( 'postType', 'giving_campaign', {
						per_page: 50,
						orderby: 'date',
						order: 'desc',
					} ) || [],
				matches: campaignId
					? core.getEntityRecords( 'postType', 'giving_match', {
							per_page: 50,
							orderby: 'date',
							order: 'asc',
							meta_key: '_giving_campaign_id',
							meta_value: campaignId,
					  } ) || []
					: [],
				campaign: campaignId
					? core.getEntityRecord(
							'postType',
							'giving_campaign',
							campaignId
					  )
					: null,
				match: matchId
					? core.getEntityRecord(
							'postType',
							'giving_match',
							matchId
					  )
					: null,
			};
		},
		[ campaignId, matchId ]
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

	const matchOptions = useMemo( () => {
		const opts = [
			{
				label: __(
					'— Select a specific match —',
					'giving-day-blocks'
				),
				value: 0,
			},
		];
		( matches || [] ).forEach( ( m ) => {
			const title = m.title?.rendered || m.title?.raw || `#${ m.id }`;
			const typeLabel =
				m.meta?._giving_match_type === MATCH_TYPE_DONOR_UNLOCK
					? __( 'donor unlock', 'giving-day-blocks' )
					: __( 'dollar-for-dollar', 'giving-day-blocks' );
			opts.push( {
				value: m.id,
				label: `${ title } — ${ typeLabel }`,
			} );
		} );
		return opts;
	}, [ matches ] );

	// Pick the match driving the editor preview. Auto mode previews the
	// first available match (the active-matches REST endpoint isn't
	// available in the editor without a roundtrip we don't want here).
	const previewMatch =
		matchSelection === 'specific' ? match : matches?.[ 0 ] || null;

	const meta = campaign?.meta || {};
	const previewMeta = previewMatch?.meta || {};
	const currency = meta._giving_currency || 'USD';

	const blockProps = useBlockProps( {
		className: `giving-day-match giving-day-match--${ variant }`,
		'data-variant': variant,
		style: campaignColorStyle( meta ),
	} );

	const previewType =
		previewMeta._giving_match_type || MATCH_TYPE_DOLLAR_FOR_DOLLAR;

	let body;
	if ( ! campaignId ) {
		body = (
			<Placeholder
				icon="heart"
				label={ __(
					'Giving Day: Match My Gift',
					'giving-day-blocks'
				) }
				instructions={ __(
					'Pick a campaign in the block sidebar to preview a sponsor match.',
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
	} else if ( ! previewMatch ) {
		body = (
			<Placeholder
				icon="heart"
				label={ __(
					'Giving Day: Match My Gift',
					'giving-day-blocks'
				) }
				instructions={ __(
					'No matches found on this campaign yet. Create one — pick a mechanic (donations doubled vs. unlock at a donor goal) and a window — and it will show up here.',
					'giving-day-blocks'
				) }
			>
				<ExternalLink
					href={ addQueryArgs( 'post-new.php', {
						post_type: 'giving_match',
					} ) }
				>
					{ __( 'Create a match…', 'giving-day-blocks' ) }
				</ExternalLink>
			</Placeholder>
		);
	} else {
		body = (
			<MatchPreview
				match={ previewMatch }
				meta={ previewMeta }
				type={ previewType }
				currency={ currency }
				showSponsorLogo={ showSponsorLogo }
				showSponsorName={ showSponsorName }
				showCountdown={ showCountdown }
			/>
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
								matchId: undefined,
							} )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody title={ __( 'Match', 'giving-day-blocks' ) }>
					<ToggleGroupControl
						label={ __(
							'Which match to show',
							'giving-day-blocks'
						) }
						value={ matchSelection }
						onChange={ ( value ) =>
							setAttributes( {
								matchSelection: value,
								matchId:
									value === 'auto' ? undefined : matchId,
							} )
						}
						isBlock
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption
							value="auto"
							label={ __(
								'Active right now',
								'giving-day-blocks'
							) }
						/>
						<ToggleGroupControlOption
							value="specific"
							label={ __(
								'Pick a specific one',
								'giving-day-blocks'
							) }
						/>
					</ToggleGroupControl>
					{ matchSelection === 'auto' && (
						<p className="giving-day-match__hint">
							{ __(
								'When more than one match is active, the one ending soonest wins. Hides when none is active (see "Outside the window" below to opt out).',
								'giving-day-blocks'
							) }
						</p>
					) }
					{ matchSelection === 'specific' && (
						<>
							<SelectControl
								label={ __(
									'Match',
									'giving-day-blocks'
								) }
								value={ matchId || 0 }
								options={ matchOptions }
								onChange={ ( value ) =>
									setAttributes( {
										matchId:
											parseInt( value, 10 ) ||
											undefined,
									} )
								}
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
							{ campaignId && (
								<p className="giving-day-match__hint">
									<ExternalLink
										href={ addQueryArgs(
											'post-new.php',
											{
												post_type: 'giving_match',
											}
										) }
									>
										{ __(
											'Create a new match…',
											'giving-day-blocks'
										) }
									</ExternalLink>
								</p>
							) }
						</>
					) }
				</PanelBody>
				<PanelBody
					title={ __( 'Display', 'giving-day-blocks' ) }
				>
					<ToggleGroupControl
						label={ __( 'Variant', 'giving-day-blocks' ) }
						value={ variant }
						onChange={ ( value ) =>
							setAttributes( { variant: value } )
						}
						isBlock
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption
							value="card"
							label={ __( 'Card', 'giving-day-blocks' ) }
						/>
						<ToggleGroupControlOption
							value="banner"
							label={ __( 'Banner', 'giving-day-blocks' ) }
						/>
					</ToggleGroupControl>
					<ToggleControl
						label={ __( 'Sponsor logo', 'giving-day-blocks' ) }
						checked={ !! showSponsorLogo }
						onChange={ ( value ) =>
							setAttributes( { showSponsorLogo: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Sponsor name', 'giving-day-blocks' ) }
						checked={ !! showSponsorName }
						onChange={ ( value ) =>
							setAttributes( { showSponsorName: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Countdown while active',
							'giving-day-blocks'
						) }
						checked={ !! showCountdown }
						onChange={ ( value ) =>
							setAttributes( { showCountdown: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'When the match finishes',
						'giving-day-blocks'
					) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __( 'Behavior', 'giving-day-blocks' ) }
						value={ hideWhenComplete }
						options={ [
							{
								label: __(
									'Hide the block',
									'giving-day-blocks'
								),
								value: 'hide',
							},
							{
								label: __(
									'Keep it, show "Goal reached"',
									'giving-day-blocks'
								),
								value: 'show-goal-reached',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { hideWhenComplete: value } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Outside the window',
						'giving-day-blocks'
					) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __( 'Behavior', 'giving-day-blocks' ) }
						value={ showOutsideWindow }
						options={ [
							{
								label: __( 'Hide', 'giving-day-blocks' ),
								value: 'hide',
							},
							{
								label: __(
									'Show "Starts in…" countdown',
									'giving-day-blocks'
								),
								value: 'show-scheduled',
							},
							{
								label: __(
									'Show "Match ended"',
									'giving-day-blocks'
								),
								value: 'show-ended',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { showOutsideWindow: value } )
						}
						help={ __(
							'Optional. Lets one block double as a pre-match teaser without authoring a separate countdown block.',
							'giving-day-blocks'
						) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ campaignId && previewMatch && (
					<Notice
						status="info"
						isDismissible={ false }
						className="giving-day-match__notice"
					>
						{ __(
							'Editor preview. The mechanic and window are configured on the match itself — open the match post to change them.',
							'giving-day-blocks'
						) }{ ' ' }
						<ExternalLink
							href={ addQueryArgs( 'post.php', {
								post: previewMatch.id,
								action: 'edit',
							} ) }
						>
							{ sprintf(
								/* translators: %s: match title. */
								__( 'Edit "%s"', 'giving-day-blocks' ),
								previewMatch.title?.rendered ||
									previewMatch.title?.raw ||
									`#${ previewMatch.id }`
							) }
						</ExternalLink>
					</Notice>
				) }
				{ body }
			</div>
		</>
	);
}

function MatchPreview( {
	match,
	meta,
	type,
	currency,
	showSponsorLogo,
	showSponsorName,
	showCountdown,
} ) {
	const sponsorName = meta._giving_match_sponsor_name || '';
	const title = match.title?.rendered || match.title?.raw || '';

	if ( type === MATCH_TYPE_DONOR_UNLOCK ) {
		const threshold = Number( meta._giving_match_donor_threshold || 0 );
		const donors = Number( meta._giving_match_donors_override || 0 );
		const unlock = Number( meta._giving_match_unlock_amount || 0 );
		const remaining = Math.max( 0, threshold - donors );
		const pct = threshold > 0
			? Math.max(
					0,
					Math.min( 100, ( donors / threshold ) * 100 )
			  )
			: 0;
		return (
			<div className="giving-day-match__inner">
				<MatchHeader
					title={ title }
					sponsorName={ sponsorName }
					showSponsorName={ showSponsorName }
					showSponsorLogo={ showSponsorLogo }
					typeLabel={ __( 'Donor unlock', 'giving-day-blocks' ) }
				/>
				<p className="giving-day-match__headline">
					{ remaining > 0
						? sprintf(
								/* translators: 1: donors needed (number), 2: amount (formatted). */
								__(
									'%1$s more donors unlock %2$s.',
									'giving-day-blocks'
								),
								formatNumber( remaining ),
								formatCurrency( unlock, currency )
						  )
						: sprintf(
								/* translators: %s: amount (formatted). */
								__(
									'Goal reached — %s unlocked!',
									'giving-day-blocks'
								),
								formatCurrency( unlock, currency )
						  ) }
				</p>
				<MatchBar pct={ pct } />
				<p className="giving-day-match__meta">
					{ sprintf(
						/* translators: 1: donors so far, 2: donor threshold. */
						__(
							'%1$s of %2$s donors',
							'giving-day-blocks'
						),
						formatNumber( donors ),
						formatNumber( threshold )
					) }
				</p>
				{ showCountdown && (
					<p className="giving-day-match__countdown-hint">
						{ __(
							'Countdown shows on the front end while the window is active.',
							'giving-day-blocks'
						) }
					</p>
				) }
			</div>
		);
	}

	const cap = Number( meta._giving_match_cap_amount || 0 );
	const matched = Number( meta._giving_match_matched_override || 0 );
	const multiplier = Number( meta._giving_match_multiplier || 2 );
	const remaining = Math.max( 0, cap - matched );
	const pct =
		cap > 0
			? Math.max( 0, Math.min( 100, ( matched / cap ) * 100 ) )
			: 0;
	return (
		<div className="giving-day-match__inner">
			<MatchHeader
				title={ title }
				sponsorName={ sponsorName }
				showSponsorName={ showSponsorName }
				showSponsorLogo={ showSponsorLogo }
				typeLabel={ __(
					'Dollar-for-dollar',
					'giving-day-blocks'
				) }
			/>
			<p className="giving-day-match__headline">
				{ sprintf(
					/* translators: %s: multiplier label, e.g. "2×". */
					__(
						'Your gift goes %s further.',
						'giving-day-blocks'
					),
					formatMultiplier( multiplier )
				) }
			</p>
			<MatchBar pct={ pct } />
			<p className="giving-day-match__meta">
				{ remaining > 0
					? sprintf(
							/* translators: 1: matched amount, 2: cap amount, 3: remaining amount. */
							__(
								'%1$s of %2$s matched — %3$s still available.',
								'giving-day-blocks'
							),
							formatCurrency( matched, currency ),
							formatCurrency( cap, currency ),
							formatCurrency( remaining, currency )
					  )
					: sprintf(
							/* translators: %s: cap amount. */
							__(
								'%s fully matched — thank you!',
								'giving-day-blocks'
							),
							formatCurrency( cap, currency )
					  ) }
			</p>
			{ showCountdown && (
				<p className="giving-day-match__countdown-hint">
					{ __(
						'Countdown shows on the front end while the window is active.',
						'giving-day-blocks'
					) }
				</p>
			) }
		</div>
	);
}

function MatchHeader( {
	title,
	sponsorName,
	showSponsorName,
	showSponsorLogo,
	typeLabel,
} ) {
	return (
		<header className="giving-day-match__header">
			<p className="giving-day-match__type">{ typeLabel }</p>
			<h3 className="giving-day-match__title">{ title }</h3>
			{ ( showSponsorLogo || showSponsorName ) && sponsorName && (
				<p className="giving-day-match__sponsor">
					{ __( 'Sponsored by', 'giving-day-blocks' ) }{ ' ' }
					<strong>{ sponsorName }</strong>
				</p>
			) }
		</header>
	);
}

function MatchBar( { pct } ) {
	return (
		<div className="giving-day-match__track">
			<span
				className="giving-day-match__fill"
				style={ { width: `${ Math.round( pct ) }%` } }
			/>
		</div>
	);
}

function formatMultiplier( m ) {
	const n = Number( m );
	if ( ! Number.isFinite( n ) || n <= 0 ) {
		return '';
	}
	if ( Math.floor( n ) === n ) {
		return `${ n }×`;
	}
	return `${ n.toFixed( 1 ) }×`;
}
