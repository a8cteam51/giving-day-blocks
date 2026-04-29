/**
 * Giving Day — Match details sidebar panels.
 *
 * Three panels mounted on `giving_match` post edit screens:
 *
 *   1. "How does this match work?" — radio between dollar-for-dollar
 *      and donor-unlock with conditional fields under each option.
 *      This is the "for dummies" entry point: the explanatory copy
 *      teaches a first-time admin what each mechanic means without
 *      needing docs.
 *   2. "When is it running?" — toggle between "the whole event" and
 *      "a specific window", exposing start/end DateTimePickers when
 *      the latter is picked.
 *   3. "Sponsor" — name + logo media picker.
 *   4. (Dev) "Dev: simulated progress" — overrides the matched-so-far
 *      / donors-so-far number until the WC order Aggregator lands.
 *
 * Binds directly to the meta registered in PostTypes\GivingMatch via
 * @wordpress/core-data's useEntityProp, mirroring CampaignEditor.
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { useEffect, useState } from '@wordpress/element';
import {
	DateTimePicker,
	SelectControl,
	TextControl,
	RadioControl,
	ToggleControl,
	Button,
	Notice,
	PanelRow,
	Dropdown,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { MediaUpload } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

const config = window.givingDayMatchEditor || {};
const META = config.metaKeys || {};
const TYPES = config.matchTypes || {
	dollarForDollar: 'dollar_for_dollar',
	donorUnlock: 'donor_unlock',
};

function DateTimeField( { label, value, onChange, help } ) {
	const display = value
		? new Date( value ).toLocaleString()
		: __( 'Not set', 'giving-day-blocks' );
	return (
		<PanelRow>
			<div style={ { width: '100%' } }>
				<div style={ { fontWeight: 600, marginBottom: 4 } }>
					{ label }
				</div>
				<Dropdown
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							variant="secondary"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							style={ {
								width: '100%',
								justifyContent: 'space-between',
							} }
						>
							{ display }
						</Button>
					) }
					renderContent={ () => (
						<div style={ { padding: 8 } }>
							<DateTimePicker
								currentDate={ value || undefined }
								onChange={ onChange }
								is12Hour
							/>
							{ value && (
								<Button
									variant="tertiary"
									onClick={ () => onChange( '' ) }
									style={ { marginTop: 8 } }
								>
									{ __( 'Clear', 'giving-day-blocks' ) }
								</Button>
							) }
						</div>
					) }
				/>
				{ help && (
					<p
						style={ {
							fontSize: 12,
							color: '#555',
							marginTop: 4,
						} }
					>
						{ help }
					</p>
				) }
			</div>
		</PanelRow>
	);
}

function MatchTypePanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	if ( postType !== config.postType ) {
		return null;
	}

	const [ meta, setMeta ] = useEntityProp(
		'postType',
		config.postType,
		'meta'
	);
	const updateMeta = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const matchType = meta?.[ META.matchType ] || TYPES.dollarForDollar;
	const isDollarForDollar = matchType === TYPES.dollarForDollar;
	const isDonorUnlock = matchType === TYPES.donorUnlock;

	return (
		<PluginDocumentSettingPanel
			name="giving-day-match-type"
			title={ __(
				'How does this match work?',
				'giving-day-blocks'
			) }
			className="giving-day-match-type"
		>
			<RadioControl
				selected={ matchType }
				onChange={ ( value ) =>
					updateMeta( META.matchType, value )
				}
				options={ [
					{
						value: TYPES.dollarForDollar,
						label: __(
							'Donations are doubled',
							'giving-day-blocks'
						),
					},
					{
						value: TYPES.donorUnlock,
						label: __(
							'A bonus unlocks at a donor goal',
							'giving-day-blocks'
						),
					},
				] }
			/>

			{ isDollarForDollar && (
				<>
					<p
						style={ {
							fontSize: 13,
							color: '#555',
							margin: '4px 0 12px',
						} }
					>
						{ __(
							'A sponsor matches every donation — dollar-for-dollar — up to a set ceiling. Best when you want every gift to feel rewarded.',
							'giving-day-blocks'
						) }
					</p>
					<NumberControl
						label={ __(
							'Every $1 becomes $___',
							'giving-day-blocks'
						) }
						help={ __(
							'2 = double, 3 = triple, etc.',
							'giving-day-blocks'
						) }
						value={ meta?.[ META.multiplier ] ?? 2 }
						min={ 1 }
						step={ 0.5 }
						onChange={ ( value ) =>
							updateMeta(
								META.multiplier,
								value === '' ? 2 : Number( value )
							)
						}
						__next40pxDefaultSize
					/>
					<NumberControl
						label={ __(
							'…up to $___ total',
							'giving-day-blocks'
						) }
						help={ __(
							'Total sponsor commitment. The match stops contributing once this cap is reached.',
							'giving-day-blocks'
						) }
						value={ meta?.[ META.capAmount ] ?? 0 }
						min={ 0 }
						step={ 100 }
						onChange={ ( value ) =>
							updateMeta(
								META.capAmount,
								value === '' ? 0 : Number( value )
							)
						}
						__next40pxDefaultSize
					/>
				</>
			) }

			{ isDonorUnlock && (
				<>
					<p
						style={ {
							fontSize: 13,
							color: '#555',
							margin: '4px 0 12px',
						} }
					>
						{ __(
							'A sponsor adds a flat amount once enough people donate. Best when you want to drive the number of donors, not raw dollars.',
							'giving-day-blocks'
						) }
					</p>
					<NumberControl
						label={ __( 'Unlock $___', 'giving-day-blocks' ) }
						help={ __(
							'The flat sponsor amount that contributes when the donor goal is reached.',
							'giving-day-blocks'
						) }
						value={ meta?.[ META.unlockAmount ] ?? 0 }
						min={ 0 }
						step={ 100 }
						onChange={ ( value ) =>
							updateMeta(
								META.unlockAmount,
								value === '' ? 0 : Number( value )
							)
						}
						__next40pxDefaultSize
					/>
					<NumberControl
						label={ __(
							'…when ___ people donate',
							'giving-day-blocks'
						) }
						help={ __(
							'Counts unique donors during the match window.',
							'giving-day-blocks'
						) }
						value={ meta?.[ META.donorThreshold ] ?? 0 }
						min={ 0 }
						step={ 1 }
						onChange={ ( value ) =>
							updateMeta(
								META.donorThreshold,
								value === '' ? 0 : parseInt( value, 10 )
							)
						}
						__next40pxDefaultSize
					/>
				</>
			) }
		</PluginDocumentSettingPanel>
	);
}

function MatchWindowPanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	if ( postType !== config.postType ) {
		return null;
	}

	const [ meta, setMeta ] = useEntityProp(
		'postType',
		config.postType,
		'meta'
	);
	const updateMeta = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const start = meta?.[ META.startDatetime ] || '';
	const end = meta?.[ META.endDatetime ] || '';
	const hasWindow = !! start || !! end;
	const [ windowMode, setWindowMode ] = useState(
		hasWindow ? 'window' : 'event'
	);

	useEffect( () => {
		setWindowMode( hasWindow ? 'window' : 'event' );
	}, [ hasWindow ] );

	return (
		<PluginDocumentSettingPanel
			name="giving-day-match-window"
			title={ __( 'When is it running?', 'giving-day-blocks' ) }
			className="giving-day-match-window"
		>
			<RadioControl
				selected={ windowMode }
				onChange={ ( value ) => {
					setWindowMode( value );
					if ( value === 'event' ) {
						setMeta( {
							...meta,
							[ META.startDatetime ]: '',
							[ META.endDatetime ]: '',
						} );
					}
				} }
				options={ [
					{
						value: 'event',
						label: __(
							'The whole event',
							'giving-day-blocks'
						),
					},
					{
						value: 'window',
						label: __(
							'Just a specific window',
							'giving-day-blocks'
						),
					},
				] }
			/>

			{ windowMode === 'window' && (
				<>
					<DateTimeField
						label={ __( 'Start', 'giving-day-blocks' ) }
						value={ start }
						onChange={ ( value ) =>
							updateMeta(
								META.startDatetime,
								value || ''
							)
						}
					/>
					<DateTimeField
						label={ __( 'End', 'giving-day-blocks' ) }
						value={ end }
						onChange={ ( value ) =>
							updateMeta( META.endDatetime, value || '' )
						}
						help={ __(
							'Tip: short windows (1–2 hours) drive urgency spikes.',
							'giving-day-blocks'
						) }
					/>
				</>
			) }

			<ToggleControl
				label={ __( 'Active', 'giving-day-blocks' ) }
				help={ __(
					'Quick kill switch — turn off to hide the match without changing dates.',
					'giving-day-blocks'
				) }
				checked={ !! meta?.[ META.active ] }
				onChange={ ( value ) =>
					updateMeta( META.active, !! value )
				}
			/>
		</PluginDocumentSettingPanel>
	);
}

function MatchSponsorPanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	if ( postType !== config.postType ) {
		return null;
	}

	const [ meta, setMeta ] = useEntityProp(
		'postType',
		config.postType,
		'meta'
	);
	const updateMeta = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const logoId = meta?.[ META.sponsorLogoId ] || 0;

	return (
		<PluginDocumentSettingPanel
			name="giving-day-match-sponsor"
			title={ __( 'Sponsor', 'giving-day-blocks' ) }
			className="giving-day-match-sponsor"
			initialOpen={ false }
		>
			<TextControl
				label={ __( 'Sponsor name', 'giving-day-blocks' ) }
				value={ meta?.[ META.sponsorName ] || '' }
				onChange={ ( value ) =>
					updateMeta( META.sponsorName, value )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<div
						style={ { fontWeight: 600, marginBottom: 4 } }
					>
						{ __( 'Sponsor logo', 'giving-day-blocks' ) }
					</div>
					<MediaUpload
						onSelect={ ( media ) =>
							updateMeta(
								META.sponsorLogoId,
								media?.id || 0
							)
						}
						allowedTypes={ [ 'image' ] }
						value={ logoId }
						render={ ( { open } ) => (
							<Button
								variant="secondary"
								onClick={ open }
								style={ { width: '100%' } }
							>
								{ logoId
									? __( 'Replace logo', 'giving-day-blocks' )
									: __( 'Choose logo…', 'giving-day-blocks' ) }
							</Button>
						) }
					/>
					{ logoId > 0 && (
						<Button
							variant="tertiary"
							onClick={ () =>
								updateMeta( META.sponsorLogoId, 0 )
							}
							style={ { marginTop: 8 } }
						>
							{ __( 'Remove logo', 'giving-day-blocks' ) }
						</Button>
					) }
				</div>
			</PanelRow>
		</PluginDocumentSettingPanel>
	);
}

function MatchCampaignPanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	const campaigns = useSelect(
		( select ) =>
			select( 'core' ).getEntityRecords(
				'postType',
				config.campaignType,
				{
					per_page: 50,
					orderby: 'date',
					order: 'desc',
				}
			) || [],
		[]
	);
	if ( postType !== config.postType ) {
		return null;
	}

	const [ meta, setMeta ] = useEntityProp(
		'postType',
		config.postType,
		'meta'
	);
	const updateMeta = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const options = [
		{
			label: __( '— Select a campaign —', 'giving-day-blocks' ),
			value: 0,
		},
		...campaigns.map( ( c ) => ( {
			value: c.id,
			label: c.title?.rendered || c.title?.raw || `#${ c.id }`,
		} ) ),
	];

	return (
		<PluginDocumentSettingPanel
			name="giving-day-match-campaign"
			title={ __( 'Campaign', 'giving-day-blocks' ) }
			className="giving-day-match-campaign"
		>
			<SelectControl
				label={ __( 'Belongs to', 'giving-day-blocks' ) }
				value={ meta?.[ META.campaignId ] || 0 }
				options={ options }
				onChange={ ( value ) =>
					updateMeta(
						META.campaignId,
						parseInt( value, 10 ) || 0
					)
				}
				help={ __(
					'Each match belongs to a single Giving Day Campaign.',
					'giving-day-blocks'
				) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</PluginDocumentSettingPanel>
	);
}

function MatchDevPanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	if ( postType !== config.postType ) {
		return null;
	}

	const [ meta, setMeta ] = useEntityProp(
		'postType',
		config.postType,
		'meta'
	);
	const updateMeta = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const matchType = meta?.[ META.matchType ] || TYPES.dollarForDollar;

	return (
		<PluginDocumentSettingPanel
			name="giving-day-match-dev"
			title={ __(
				'Dev: simulated progress',
				'giving-day-blocks'
			) }
			className="giving-day-match-dev"
			initialOpen={ false }
		>
			<Notice status="info" isDismissible={ false }>
				{ __(
					'These overrides simulate live progress until the WooCommerce order aggregator ships. Set them to test the front-end UI; clear them once real donations land.',
					'giving-day-blocks'
				) }
			</Notice>

			{ matchType === TYPES.donorUnlock ? (
				<NumberControl
					label={ __(
						'Donors so far (override)',
						'giving-day-blocks'
					) }
					value={ meta?.[ META.donorsOverride ] ?? 0 }
					min={ 0 }
					step={ 1 }
					onChange={ ( value ) =>
						updateMeta(
							META.donorsOverride,
							value === '' ? 0 : parseInt( value, 10 )
						)
					}
					__next40pxDefaultSize
				/>
			) : (
				<NumberControl
					label={ __(
						'Matched so far (override)',
						'giving-day-blocks'
					) }
					value={ meta?.[ META.matchedOverride ] ?? 0 }
					min={ 0 }
					step={ 100 }
					onChange={ ( value ) =>
						updateMeta(
							META.matchedOverride,
							value === '' ? 0 : Number( value )
						)
					}
					__next40pxDefaultSize
				/>
			) }
		</PluginDocumentSettingPanel>
	);
}

function MatchSidebar() {
	return (
		<>
			<MatchCampaignPanel />
			<MatchTypePanel />
			<MatchWindowPanel />
			<MatchSponsorPanel />
			<MatchDevPanel />
		</>
	);
}

registerPlugin( 'giving-day-match-editor', {
	render: MatchSidebar,
	icon: 'heart',
} );
