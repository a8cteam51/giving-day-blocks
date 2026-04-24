/**
 * Giving Day — Campaign details sidebar panel.
 *
 * Registers a PluginDocumentSettingPanel on giving_campaign edit screens
 * that binds to the post meta registered server-side.
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import {
	DateTimePicker,
	SelectControl,
	TextControl,
	__experimentalNumberControl as NumberControl,
	ColorIndicator,
	ColorPicker,
	Notice,
	PanelRow,
	Dropdown,
	Button,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const config = window.givingDayCampaignEditor || {};
const META_KEYS = config.metaKeys || {};

const TIMEZONE_CHOICES = [
	'UTC',
	'America/New_York',
	'America/Chicago',
	'America/Denver',
	'America/Los_Angeles',
	'America/Toronto',
	'America/Mexico_City',
	'Europe/London',
	'Europe/Paris',
	'Europe/Berlin',
	'Europe/Madrid',
	'Africa/Johannesburg',
	'Asia/Tokyo',
	'Asia/Singapore',
	'Asia/Kolkata',
	'Australia/Sydney',
];

function DateTimeField( { label, value, onChange, help } ) {
	const display = value ? new Date( value ).toLocaleString() : __( 'Not set', 'giving-day-blocks' );
	return (
		<PanelRow>
			<div style={ { width: '100%' } }>
				<div style={ { fontWeight: 600, marginBottom: 4 } }>{ label }</div>
				<Dropdown
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							variant="secondary"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							style={ { width: '100%', justifyContent: 'space-between' } }
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

/**
 * Single swatch + popover picker that writes a hex string into the given
 * meta key. Clearing the value falls back to the theme.json token, so
 * "empty" is a valid, meaningful state.
 */
function ColorField( { label, value, onChange, help } ) {
	return (
		<PanelRow>
			<div style={ { width: '100%' } }>
				<div style={ { fontWeight: 600, marginBottom: 4 } }>{ label }</div>
				<Dropdown
					contentClassName="giving-day-campaign-color-picker"
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							variant="secondary"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							style={ {
								width: '100%',
								justifyContent: 'flex-start',
								gap: 8,
							} }
						>
							<ColorIndicator colorValue={ value || 'transparent' } />
							<span>
								{ value || __( 'Use theme default', 'giving-day-blocks' ) }
							</span>
						</Button>
					) }
					renderContent={ () => (
						<div style={ { padding: 8 } }>
							<ColorPicker
								color={ value || '#000000' }
								onChange={ onChange }
								enableAlpha
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
					<p style={ { fontSize: 12, color: '#555', marginTop: 4 } }>
						{ help }
					</p>
				) }
			</div>
		</PanelRow>
	);
}

function CampaignDetailsPanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	if ( postType !== config.postType ) {
		return null;
	}

	const [ meta, setMeta ] = useEntityProp( 'postType', config.postType, 'meta' );
	const updateMeta = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const timezoneOptions = ( () => {
		const base = [ ...TIMEZONE_CHOICES ];
		const current = meta?.[ META_KEYS.timezone ] || config.defaultTimezone;
		if ( current && ! base.includes( current ) ) {
			base.unshift( current );
		}
		return base.map( ( tz ) => ( { label: tz, value: tz } ) );
	} )();

	const statusOptions = [
		{ label: __( 'Auto (compute from dates)', 'giving-day-blocks' ), value: '' },
		{ label: __( 'Scheduled', 'giving-day-blocks' ), value: 'scheduled' },
		{ label: __( 'Live', 'giving-day-blocks' ), value: 'live' },
		{ label: __( 'Ended', 'giving-day-blocks' ), value: 'ended' },
	];

	return (
		<PluginDocumentSettingPanel
			name="giving-day-campaign-details"
			title={ __( 'Campaign details', 'giving-day-blocks' ) }
			className="giving-day-campaign-details"
		>
			<DateTimeField
				label={ __( 'Pre-event start', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.preEventStart ] || '' }
				onChange={ ( value ) => updateMeta( META_KEYS.preEventStart, value || '' ) }
				help={ __(
					'When the pre-event countdown begins. Leave empty to skip the pre-event state.',
					'giving-day-blocks'
				) }
			/>

			<DateTimeField
				label={ __( 'Event start', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.startDatetime ] || '' }
				onChange={ ( value ) => updateMeta( META_KEYS.startDatetime, value || '' ) }
			/>

			<DateTimeField
				label={ __( 'Event end', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.endDatetime ] || '' }
				onChange={ ( value ) => updateMeta( META_KEYS.endDatetime, value || '' ) }
			/>

			<SelectControl
				label={ __( 'Timezone', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.timezone ] || config.defaultTimezone || 'UTC' }
				options={ timezoneOptions }
				onChange={ ( value ) => updateMeta( META_KEYS.timezone, value ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			<NumberControl
				label={ __( 'Goal amount', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.goalAmount ] ?? 0 }
				min={ 0 }
				step={ 1 }
				onChange={ ( value ) =>
					updateMeta( META_KEYS.goalAmount, value === '' ? 0 : Number( value ) )
				}
				__next40pxDefaultSize
			/>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<div style={ { fontWeight: 600, marginBottom: 4 } }>
						{ __( 'Currency', 'giving-day-blocks' ) }
					</div>
					<code>
						{ meta?.[ META_KEYS.currency ] || config.storeCurrency || 'USD' }
					</code>
					<p style={ { fontSize: 12, color: '#555', marginTop: 4 } }>
						{ __(
							'Inherited from WooCommerce. Change at Settings → WooCommerce.',
							'giving-day-blocks'
						) }
					</p>
				</div>
			</PanelRow>

			<TextControl
				label={ __( 'Donation product IDs', 'giving-day-blocks' ) }
				help={ __(
					'Comma-separated WooCommerce product IDs that feed this campaign.',
					'giving-day-blocks'
				) }
				value={ ( meta?.[ META_KEYS.donationProducts ] || [] ).join( ', ' ) }
				onChange={ ( value ) => {
					const ids = value
						.split( ',' )
						.map( ( v ) => parseInt( v.trim(), 10 ) )
						.filter( ( n ) => Number.isInteger( n ) && n > 0 );
					updateMeta( META_KEYS.donationProducts, ids );
				} }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			<SelectControl
				label={ __( 'Status override', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.statusOverride ] || '' }
				options={ statusOptions }
				onChange={ ( value ) => updateMeta( META_KEYS.statusOverride, value ) }
				help={ __(
					'Force a state for previews. Leave on Auto for normal event behavior.',
					'giving-day-blocks'
				) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			<Notice status="info" isDismissible={ false }>
				{ __(
					'Dev overrides below simulate fundraising data until the order aggregator ships.',
					'giving-day-blocks'
				) }
			</Notice>

			<NumberControl
				label={ __( 'Dev: raised override', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.raisedOverride ] ?? 0 }
				min={ 0 }
				step={ 1 }
				onChange={ ( value ) =>
					updateMeta(
						META_KEYS.raisedOverride,
						value === '' ? 0 : Number( value )
					)
				}
				__next40pxDefaultSize
			/>

			<NumberControl
				label={ __( 'Dev: donor count override', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.donorCountOverride ] ?? 0 }
				min={ 0 }
				step={ 1 }
				onChange={ ( value ) =>
					updateMeta(
						META_KEYS.donorCountOverride,
						value === '' ? 0 : parseInt( value, 10 )
					)
				}
				__next40pxDefaultSize
			/>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Second sidebar panel dedicated to the per-campaign color palette. These
 * values are emitted on the block wrappers as inline `--giving-day-*` CSS
 * custom properties; blocks/src/_shared/tokens.scss consumes them with a
 * theme.json fallback, so clearing a color restores the theme default.
 */
function CampaignColorsPanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	if ( postType !== config.postType ) {
		return null;
	}

	const [ meta, setMeta ] = useEntityProp( 'postType', config.postType, 'meta' );
	const updateMeta = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const colorRows = [
		{
			key: META_KEYS.colorPrimary,
			label: __( 'Primary', 'giving-day-blocks' ),
			help: __(
				'Main brand color — headlines, progress fills, primary buttons.',
				'giving-day-blocks'
			),
		},
		{
			key: META_KEYS.colorSecondary,
			label: __( 'Secondary', 'giving-day-blocks' ),
			help: __(
				'Supporting color — countdown digits, section dividers.',
				'giving-day-blocks'
			),
		},
		{
			key: META_KEYS.colorAccent,
			label: __( 'Accent', 'giving-day-blocks' ),
			help: __( 'Highlights and focus states.', 'giving-day-blocks' ),
		},
		{
			key: META_KEYS.colorSurface,
			label: __( 'Surface', 'giving-day-blocks' ),
			help: __( 'Block background.', 'giving-day-blocks' ),
		},
		{
			key: META_KEYS.colorMuted,
			label: __( 'Muted text', 'giving-day-blocks' ),
			help: __(
				'Secondary text and labels ("Donors", "Goal", date captions).',
				'giving-day-blocks'
			),
		},
	];

	return (
		<PluginDocumentSettingPanel
			name="giving-day-campaign-colors"
			title={ __( 'Brand colors', 'giving-day-blocks' ) }
			className="giving-day-campaign-colors"
		>
			<Notice status="info" isDismissible={ false }>
				{ __(
					'These colors cascade into every Giving Day block on the campaign via CSS custom properties. Clear a field to fall back to the theme default.',
					'giving-day-blocks'
				) }
			</Notice>

			{ colorRows.map( ( row ) => (
				<ColorField
					key={ row.key }
					label={ row.label }
					help={ row.help }
					value={ meta?.[ row.key ] || '' }
					onChange={ ( value ) => {
						if ( ! value ) {
							updateMeta( row.key, '' );
							return;
						}
						const hex = typeof value === 'string' ? value : value?.hex || '';
						updateMeta( row.key, hex );
					} }
				/>
			) ) }
		</PluginDocumentSettingPanel>
	);
}

function CampaignSidebar() {
	return (
		<>
			<CampaignDetailsPanel />
			<CampaignColorsPanel />
		</>
	);
}

registerPlugin( 'giving-day-campaign-details', {
	render: CampaignSidebar,
	icon: 'heart',
} );
