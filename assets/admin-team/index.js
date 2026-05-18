/**
 * Giving Day — Team details sidebar panel.
 *
 * Registers a PluginDocumentSettingPanel on giving_team edit screens that
 * binds to the post meta registered server-side in PostTypes\Team.
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useEntityProp, useEntityRecords } from '@wordpress/core-data';
import {
	__experimentalNumberControl as NumberControl,
	FormTokenField,
	PanelRow,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const config = window.givingDayTeamEditor || {};
const META_KEYS = config.metaKeys || {};

/**
 * Multi-select campaign picker backed by useEntityRecords. Stores the
 * selection as an int[] in the bound meta key so the Aggregator's
 * campaign-membership lookups keep working.
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

function TeamDetailsPanel() {
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
			name="giving-day-team-details"
			title={ __( 'Team details', 'giving-day-blocks' ) }
			className="giving-day-team-details"
		>
			<CampaignPicker
				value={ meta?.[ META_KEYS.campaignIds ] || [] }
				onChange={ ( ids ) => updateMeta( META_KEYS.campaignIds, ids ) }
			/>

			<NumberControl
				label={ __( 'Team goal amount', 'giving-day-blocks' ) }
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
				<p
					style={ {
						fontSize: 12,
						color: '#555',
						margin: '4px 0 0 0',
					} }
				>
					{ __(
						'Goal is optional. When set, future Team-level Goal Progress blocks will use it; leave at 0 to hide goal visuals.',
						'giving-day-blocks'
					) }
				</p>
			</PanelRow>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'giving-day-team-details', {
	render: TeamDetailsPanel,
	icon: 'groups',
} );
