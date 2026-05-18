/**
 * Giving Day — Beneficiary / Fund details sidebar panel.
 *
 * Registers a PluginDocumentSettingPanel on giving_beneficiary edit
 * screens, binding to the post meta registered server-side in
 * PostTypes\Beneficiary. The post_parent (hierarchy) selector is
 * already provided by WordPress core's Page Attributes panel; taxonomy
 * terms (Cause Areas) are handled by core's Taxonomies panel.
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useEntityProp, useEntityRecords } from '@wordpress/core-data';
import {
	__experimentalNumberControl as NumberControl,
	FormTokenField,
	TextControl,
	PanelRow,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const config = window.givingDayBeneficiaryEditor || {};
const META_KEYS = config.metaKeys || {};

/**
 * Multi-select campaign picker backed by useEntityRecords. Stores the
 * selection as an int[] in the bound meta key.
 * @param root0
 * @param root0.value
 * @param root0.onChange
 */
function CampaignPicker( { value, onChange } ) {
	const ids = Array.isArray( value )
		? value.map( ( id ) => parseInt( id, 10 ) ).filter( ( n ) => n > 0 )
		: [];

	const { records } = useEntityRecords( 'postType', config.campaignType, {
		per_page: 100,
		status: [ 'publish', 'draft', 'private', 'future' ],
		_fields: 'id,title',
	} );

	const campaigns = Array.isArray( records ) ? records : [];

	const labelFor = ( id ) => {
		const found = campaigns.find( ( c ) => c.id === id );
		const title = found?.title?.rendered || `#${ id }`;
		return `${ title } (#${ id })`;
	};

	const tokens = ids.map( labelFor );
	const suggestions = campaigns
		.filter( ( c ) => ! ids.includes( c.id ) )
		.map( ( c ) => `${ c.title?.rendered || c.id } (#${ c.id })` );

	const handleChange = ( newTokens ) => {
		const labelToId = new Map();
		campaigns.forEach( ( c ) => {
			labelToId.set(
				`${ c.title?.rendered || c.id } (#${ c.id })`,
				c.id
			);
		} );
		ids.forEach( ( id ) => labelToId.set( labelFor( id ), id ) );
		const nextIds = newTokens
			.map( ( t ) => labelToId.get( t ) )
			.filter( ( id ) => Number.isInteger( id ) && id > 0 );
		onChange( nextIds );
	};

	return (
		<FormTokenField
			label={ __( 'Campaigns', 'giving-day-blocks' ) }
			value={ tokens }
			suggestions={ suggestions }
			onChange={ handleChange }
			placeholder={ __(
				'Pick one or more campaigns…',
				'giving-day-blocks'
			) }
			__experimentalExpandOnFocus
			__nextHasNoMarginBottom
		/>
	);
}

function BeneficiaryDetailsPanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp(
		'postType',
		config.postType,
		'meta'
	);
	if ( postType !== config.postType ) {
		return null;
	}
	const updateMeta = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	return (
		<PluginDocumentSettingPanel
			name="giving-day-beneficiary-details"
			title={ __( 'Beneficiary / Fund details', 'giving-day-blocks' ) }
			className="giving-day-beneficiary-details"
		>
			<CampaignPicker
				value={ meta?.[ META_KEYS.campaignIds ] || [] }
				onChange={ ( ids ) => updateMeta( META_KEYS.campaignIds, ids ) }
			/>

			<NumberControl
				label={ __( 'Goal amount', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.goalAmount ] ?? 0 }
				min={ 0 }
				step={ 1 }
				onChange={ ( value ) => {
					const parsed = parseFloat( value );
					updateMeta(
						META_KEYS.goalAmount,
						Number.isFinite( parsed ) && parsed >= 0 ? parsed : 0
					);
				} }
				__next40pxDefaultSize
			/>

			<PanelRow>
				<p
					style={ {
						fontSize: 12,
						color: '#555',
						margin: '4px 0 0 0',
					} }
				>
					{ __(
						'Goal is optional. Parent posts roll up children at query time once the hierarchy aggregator ships.',
						'giving-day-blocks'
					) }
				</p>
			</PanelRow>

			<TextControl
				label={ __( 'Parent organization label', 'giving-day-blocks' ) }
				value={ meta?.[ META_KEYS.parentOrg ] || '' }
				onChange={ ( value ) =>
					updateMeta( META_KEYS.parentOrg, value )
				}
				help={ __(
					'Optional free-text display label (e.g. "College of Arts & Sciences" or "Member of: Health Coalition"). Ignored when this post has a Parent set in Page Attributes — the parent post wins.',
					'giving-day-blocks'
				) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'giving-day-beneficiary-details', {
	render: BeneficiaryDetailsPanel,
	icon: 'heart',
} );
