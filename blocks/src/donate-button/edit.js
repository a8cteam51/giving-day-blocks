import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	const { label, targetType, targetId } = attributes;
	const blockProps = useBlockProps( { className: 'giving-day-donate-button' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Target', 'giving-day-blocks' ) } initialOpen>
					<SelectControl
						label={ __( 'Target type', 'giving-day-blocks' ) }
						value={ targetType || 'auto' }
						options={ [
							{ label: __( 'Auto (use current post)', 'giving-day-blocks' ), value: 'auto' },
							{ label: __( 'Team', 'giving-day-blocks' ), value: 'team' },
							{ label: __( 'Beneficiary', 'giving-day-blocks' ), value: 'beneficiary' },
							{ label: __( 'None (no designation)', 'giving-day-blocks' ), value: 'none' },
						] }
						onChange={ ( value ) => setAttributes( { targetType: value, targetId: 0 } ) }
						help={ __( '"Auto" picks the current Team or Beneficiary at render.', 'giving-day-blocks' ) }
					/>
					{ ( targetType === 'team' || targetType === 'beneficiary' ) && (
						<NumberControl
							label={ __( 'Target post ID', 'giving-day-blocks' ) }
							value={ targetId || 0 }
							onChange={ ( value ) => setAttributes( { targetId: parseInt( value, 10 ) || 0 } ) }
							min={ 0 }
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<RichText
					tagName="span"
					className="giving-day-donate-button__label"
					value={ label }
					onChange={ ( value ) => setAttributes( { label: value } ) }
					placeholder={ __( 'Donate', 'giving-day-blocks' ) }
					allowedFormats={ [] }
				/>
			</div>
		</>
	);
}
