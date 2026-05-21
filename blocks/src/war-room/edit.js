import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	CheckboxControl,
	RangeControl,
	Placeholder,
	Notice,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';

import WarRoom, { ALL_PANELS, PANEL_LABELS } from './WarRoom';

import './editor.scss';

export default function Edit( { attributes, setAttributes } ) {
	const { campaignId, panels, refreshInterval } = attributes;
	const selectedPanels =
		Array.isArray( panels ) && panels.length ? panels : ALL_PANELS;

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

	const blockProps = useBlockProps( {
		className: 'giving-day-warroom',
	} );

	const togglePanel = ( id ) => ( checked ) => {
		const next = new Set( selectedPanels );
		if ( checked ) {
			next.add( id );
		} else {
			next.delete( id );
		}
		// Preserve canonical order so storage is stable.
		setAttributes( {
			panels: ALL_PANELS.filter( ( p ) => next.has( p ) ),
		} );
	};

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Campaign', 'giving-day-blocks' ) }>
					<SelectControl
						label={ __( 'Campaign', 'giving-day-blocks' ) }
						value={ campaignId ? String( campaignId ) : '' }
						options={ [
							{
								label: __( '— Select —', 'giving-day-blocks' ),
								value: '',
							},
							...campaigns.map( ( c ) => ( {
								label:
									c.title?.raw ||
									c.title?.rendered ||
									`#${ c.id }`,
								value: String( c.id ),
							} ) ),
						] }
						onChange={ ( v ) =>
							setAttributes( { campaignId: Number( v ) || 0 } )
						}
					/>
					{ campaign && (
						<p className="components-form-token-field__help">
							{ campaign.title?.raw || campaign.title?.rendered }
						</p>
					) }
				</PanelBody>
				<PanelBody title={ __( 'Panels', 'giving-day-blocks' ) }>
					{ ALL_PANELS.map( ( panel ) => (
						<CheckboxControl
							key={ panel }
							label={ PANEL_LABELS[ panel ] }
							checked={ selectedPanels.includes( panel ) }
							onChange={ togglePanel( panel ) }
						/>
					) ) }
				</PanelBody>
				<PanelBody
					title={ __( 'Refresh', 'giving-day-blocks' ) }
					initialOpen={ false }
				>
					<RangeControl
						label={ __(
							'Refresh interval (seconds)',
							'giving-day-blocks'
						) }
						value={ Math.round(
							( refreshInterval || 10000 ) / 1000
						) }
						min={ 5 }
						max={ 60 }
						step={ 5 }
						onChange={ ( v ) =>
							setAttributes( {
								refreshInterval:
									Math.max( 5, Number( v ) || 10 ) * 1000,
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<Notice status="info" isDismissible={ false }>
				{ __(
					'War Room renders only for users with the manage_woocommerce capability.',
					'giving-day-blocks'
				) }
			</Notice>

			{ ! campaignId ? (
				<Placeholder
					label={ __( 'Giving Day: War Room', 'giving-day-blocks' ) }
					instructions={ __(
						'Pick a campaign in the sidebar to load the dashboard.',
						'giving-day-blocks'
					) }
				/>
			) : (
				<WarRoom
					campaignId={ campaignId }
					panels={ selectedPanels }
					intervalMs={ refreshInterval || 10000 }
				/>
			) }
		</div>
	);
}
