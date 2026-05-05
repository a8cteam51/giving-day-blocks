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
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	DateTimePicker,
	SelectControl,
	__experimentalNumberControl as NumberControl,
	ColorIndicator,
	ColorPicker,
	Notice,
	PanelRow,
	Dropdown,
	Button,
	Spinner,
	BaseControl,
	FormTokenField,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

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

/**
 * Single swatch + popover picker that writes a hex string into the given
 * meta key. Clearing the value falls back to the theme.json token, so
 * "empty" is a valid, meaningful state.
 * @param root0
 * @param root0.label
 * @param root0.value
 * @param root0.onChange
 * @param root0.help
 */
function ColorField( { label, value, onChange, help } ) {
	return (
		<PanelRow>
			<div style={ { width: '100%' } }>
				<div style={ { fontWeight: 600, marginBottom: 4 } }>
					{ label }
				</div>
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
							<ColorIndicator
								colorValue={ value || 'transparent' }
							/>
							<span>
								{ value ||
									__(
										'Use theme default',
										'giving-day-blocks'
									) }
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

/**
 * Returns a stable list of products by ID, in the order given.
 * Caches lookups in component state so flipping the picker open/closed
 * doesn't refetch existing values from the WC REST API.
 */
function useProductRecords( ids ) {
	const [ records, setRecords ] = useState( {} );
	const recordsRef = useRef( records );
	recordsRef.current = records;

	useEffect( () => {
		const missing = ids.filter( ( id ) => ! recordsRef.current[ id ] );
		if ( missing.length === 0 ) {
			return;
		}
		let cancelled = false;
		apiFetch( {
			path: `/wc/v3/products?include=${ missing.join(
				','
			) }&per_page=${ missing.length }`,
		} )
			.then( ( results ) => {
				if ( cancelled || ! Array.isArray( results ) ) {
					return;
				}
				const next = { ...recordsRef.current };
				results.forEach( ( p ) => {
					next[ p.id ] = { id: p.id, name: p.name };
				} );
				setRecords( next );
			} )
			.catch( () => {
				/* swallow — labels just stay as #ID */
			} );
		return () => {
			cancelled = true;
		};
	}, [ ids ] );

	return records;
}

/**
 * WooCommerce product picker for the campaign's donation products. Replaces
 * the legacy comma-separated ID input with a search-as-you-type token field
 * plus a one-click "Create donation product" button so admins never have to
 * leave the campaign editor to set up the underlying product.
 */
function DonationProductPicker( { value, onChange } ) {
	const ids = ( value || [] ).map( ( id ) => parseInt( id, 10 ) ).filter( Boolean );
	const records = useProductRecords( ids );

	const [ search, setSearch ] = useState( '' );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ isCreating, setIsCreating ] = useState( false );
	const [ message, setMessage ] = useState( null );
	const searchRef = useRef( '' );

	const campaignId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);

	const debounceRef = useRef( null );
	const handleInputChange = useCallback( ( input ) => {
		setSearch( input );
		searchRef.current = input;
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		debounceRef.current = setTimeout( () => {
			if ( ! input || input.length < 2 ) {
				setSuggestions( [] );
				return;
			}
			apiFetch( {
				path: `/wc/v3/products?search=${ encodeURIComponent(
					input
				) }&per_page=10&status=publish`,
			} )
				.then( ( results ) => {
					if ( searchRef.current !== input ) {
						return;
					}
					if ( ! Array.isArray( results ) ) {
						setSuggestions( [] );
						return;
					}
					setSuggestions(
						results.map( ( p ) => ( { id: p.id, name: p.name } ) )
					);
				} )
				.catch( () => setSuggestions( [] ) );
		}, 300 );
	}, [] );

	useEffect(
		() => () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		},
		[]
	);

	const tokens = ids.map( ( id ) => {
		const record = records[ id ];
		return record ? `${ record.name } (#${ id })` : `#${ id }`;
	} );
	const suggestionLabels = suggestions
		.filter( ( s ) => ! ids.includes( s.id ) )
		.map( ( s ) => `${ s.name } (#${ s.id })` );

	const handleTokenChange = ( newTokens ) => {
		// Map labels back to IDs. Suggestions are looked up first; if a token
		// already corresponds to a known record use that; otherwise drop it.
		const labelToId = new Map();
		suggestions.forEach( ( s ) =>
			labelToId.set( `${ s.name } (#${ s.id })`, s.id )
		);
		Object.values( records ).forEach( ( r ) =>
			labelToId.set( `${ r.name } (#${ r.id })`, r.id )
		);
		ids.forEach( ( id ) => {
			const r = records[ id ];
			if ( r ) {
				labelToId.set( `${ r.name } (#${ r.id })`, r.id );
			} else {
				labelToId.set( `#${ id }`, id );
			}
		} );
		const nextIds = newTokens
			.map( ( t ) => labelToId.get( t ) )
			.filter( ( id ) => Number.isInteger( id ) && id > 0 );
		onChange( nextIds );
	};

	const handleCreate = useCallback( () => {
		if ( ! campaignId ) {
			setMessage( {
				status: 'error',
				body: __(
					'Save the campaign first so a product can be linked to it.',
					'giving-day-blocks'
				),
			} );
			return;
		}
		setIsCreating( true );
		setMessage( null );
		apiFetch( {
			path: `/giving-day/v1/campaign/${ campaignId }/setup/donation-product`,
			method: 'POST',
		} )
			.then( ( result ) => {
				const newId = parseInt( result?.product_id, 10 );
				if ( newId && ! ids.includes( newId ) ) {
					onChange( [ ...ids, newId ] );
				}
				setMessage( {
					status: 'success',
					body: sprintf(
						/* translators: %s: product name. */
						__(
							'Created and linked: %s',
							'giving-day-blocks'
						),
						result?.product_name || `#${ newId }`
					),
				} );
			} )
			.catch( ( err ) => {
				setMessage( {
					status: 'error',
					body:
						err?.message ||
						__(
							'Could not create donation product.',
							'giving-day-blocks'
						),
				} );
			} )
			.finally( () => setIsCreating( false ) );
	}, [ campaignId, ids, onChange ] );

	return (
		<BaseControl
			label={ __( 'Donation products', 'giving-day-blocks' ) }
			help={ __(
				'WooCommerce products that donors actually purchase. Type to search, or click the button below to auto-create one.',
				'giving-day-blocks'
			) }
			__nextHasNoMarginBottom
		>
			<FormTokenField
				value={ tokens }
				suggestions={ suggestionLabels }
				onInputChange={ handleInputChange }
				onChange={ handleTokenChange }
				placeholder={ __( 'Search products…', 'giving-day-blocks' ) }
				__experimentalExpandOnFocus
				__nextHasNoMarginBottom
			/>
			<div style={ { marginTop: 8 } }>
				<Button
					variant="secondary"
					onClick={ handleCreate }
					isBusy={ isCreating }
					disabled={ isCreating || ! campaignId }
				>
					{ __( 'Create donation product', 'giving-day-blocks' ) }
				</Button>
				{ search && search.length === 1 && (
					<p
						style={ { fontSize: 12, color: '#555', marginTop: 4 } }
					>
						{ __(
							'Type at least 2 characters to search.',
							'giving-day-blocks'
						) }
					</p>
				) }
			</div>
			{ message && (
				<div style={ { marginTop: 8 } }>
					<Notice
						status={ message.status }
						isDismissible
						onRemove={ () => setMessage( null ) }
					>
						{ message.body }
					</Notice>
				</div>
			) }
		</BaseControl>
	);
}

/**
 * Sidebar checklist that surfaces the per-campaign prerequisites. Hits
 * /giving-day/v1/campaign/{id}/setup once on mount and after meta saves
 * so admins see at a glance what's left to configure.
 */
function CampaignSetupPanel() {
	const { postType, postId, isSaving } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postType: editor.getCurrentPostType(),
			postId: editor.getCurrentPostId(),
			isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
		};
	}, [] );

	const [ status, setStatus ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const wasSaving = useRef( false );

	const fetchStatus = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		setLoading( true );
		apiFetch( {
			path: `/giving-day/v1/campaign/${ postId }/setup`,
		} )
			.then( ( result ) => setStatus( result?.status || null ) )
			.catch( () => setStatus( null ) )
			.finally( () => setLoading( false ) );
	}, [ postId ] );

	useEffect( () => {
		fetchStatus();
	}, [ fetchStatus ] );

	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			fetchStatus();
		}
		wasSaving.current = isSaving;
	}, [ isSaving, fetchStatus ] );

	if ( postType !== config.postType ) {
		return null;
	}

	const items = [
		{
			key: 'startDatetime',
			label: __( 'Event start date', 'giving-day-blocks' ),
			missing: __(
				'Set in "Campaign details" above.',
				'giving-day-blocks'
			),
		},
		{
			key: 'endDatetime',
			label: __( 'Event end date', 'giving-day-blocks' ),
			missing: __(
				'Set in "Campaign details" above.',
				'giving-day-blocks'
			),
		},
		{
			key: 'goalAmount',
			label: __( 'Goal amount', 'giving-day-blocks' ),
			missing: __(
				'Set a positive goal in "Campaign details".',
				'giving-day-blocks'
			),
		},
		{
			key: 'donationProduct',
			label: __( 'Donation product', 'giving-day-blocks' ),
			missing: __(
				'Pick or create a donation product above so checkout and offline donations work.',
				'giving-day-blocks'
			),
		},
	];

	return (
		<PluginDocumentSettingPanel
			name="giving-day-campaign-setup"
			title={ __( 'Setup status', 'giving-day-blocks' ) }
			className="giving-day-campaign-setup"
		>
			{ loading && ! status && (
				<PanelRow>
					<Spinner />
				</PanelRow>
			) }
			{ status &&
				items.map( ( item ) => {
					const ok = !! status[ item.key ]?.ok;
					return (
						<PanelRow key={ item.key }>
							<div style={ { width: '100%' } }>
								<div
									style={ {
										display: 'flex',
										alignItems: 'center',
										gap: 8,
									} }
								>
									<span
										aria-hidden="true"
										style={ {
											display: 'inline-block',
											width: 18,
											height: 18,
											borderRadius: '50%',
											background: ok
												? '#1aab27'
												: '#d63638',
											color: '#fff',
											fontSize: 12,
											fontWeight: 700,
											textAlign: 'center',
											lineHeight: '18px',
										} }
									>
										{ ok ? '✓' : '!' }
									</span>
									<strong>{ item.label }</strong>
								</div>
								{ ! ok && (
									<p
										style={ {
											fontSize: 12,
											color: '#555',
											margin: '4px 0 0 26px',
										} }
									>
										{ item.missing }
									</p>
								) }
							</div>
						</PanelRow>
					);
				} ) }
		</PluginDocumentSettingPanel>
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

	const [ meta, setMeta ] = useEntityProp(
		'postType',
		config.postType,
		'meta'
	);
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
		{
			label: __( 'Auto (compute from dates)', 'giving-day-blocks' ),
			value: '',
		},
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
				onChange={ ( value ) =>
					updateMeta( META_KEYS.preEventStart, value || '' )
				}
				help={ __(
					'When the pre-event countdown begins. Leave empty to skip the pre-event state.',
					'giving-day-blocks'
				) }
			/>

			<DateTimeField
				label={ __( 'Event start', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.startDatetime ] || '' }
				onChange={ ( value ) =>
					updateMeta( META_KEYS.startDatetime, value || '' )
				}
			/>

			<DateTimeField
				label={ __( 'Event end', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.endDatetime ] || '' }
				onChange={ ( value ) =>
					updateMeta( META_KEYS.endDatetime, value || '' )
				}
			/>

			<SelectControl
				label={ __( 'Timezone', 'giving-day-blocks' ) }
				value={
					meta?.[ META_KEYS.timezone ] ||
					config.defaultTimezone ||
					'UTC'
				}
				options={ timezoneOptions }
				onChange={ ( value ) =>
					updateMeta( META_KEYS.timezone, value )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			<NumberControl
				label={ __( 'Goal amount', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.goalAmount ] ?? 0 }
				min={ 0 }
				step={ 1 }
				onChange={ ( value ) =>
					updateMeta(
						META_KEYS.goalAmount,
						value === '' ? 0 : Number( value )
					)
				}
				__next40pxDefaultSize
			/>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<div style={ { fontWeight: 600, marginBottom: 4 } }>
						{ __( 'Currency', 'giving-day-blocks' ) }
					</div>
					<code>
						{ meta?.[ META_KEYS.currency ] ||
							config.storeCurrency ||
							'USD' }
					</code>
					<p style={ { fontSize: 12, color: '#555', marginTop: 4 } }>
						{ __(
							'Inherited from WooCommerce. Change at Settings → WooCommerce.',
							'giving-day-blocks'
						) }
					</p>
				</div>
			</PanelRow>

			<DonationProductPicker
				value={ meta?.[ META_KEYS.donationProducts ] || [] }
				onChange={ ( ids ) =>
					updateMeta( META_KEYS.donationProducts, ids )
				}
			/>

			<SelectControl
				label={ __( 'Status override', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.statusOverride ] || '' }
				options={ statusOptions }
				onChange={ ( value ) =>
					updateMeta( META_KEYS.statusOverride, value )
				}
				help={ __(
					'Force a state for previews. Leave on Auto for normal event behavior.',
					'giving-day-blocks'
				) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
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

	const [ meta, setMeta ] = useEntityProp(
		'postType',
		config.postType,
		'meta'
	);
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
						const hex =
							typeof value === 'string'
								? value
								: value?.hex || '';
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
			<CampaignSetupPanel />
			<CampaignDetailsPanel />
			<CampaignColorsPanel />
		</>
	);
}

registerPlugin( 'giving-day-campaign-details', {
	render: CampaignSidebar,
	icon: 'heart',
} );
