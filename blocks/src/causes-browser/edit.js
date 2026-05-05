import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import './editor.scss';

export default function Edit( { attributes, setAttributes } ) {
	const { heading, columnsDesktop, showCounts, searchPlaceholder } =
		attributes;

	const blockProps = useBlockProps( {
		className:
			'giving-day-cause-areas giving-day-cause-areas--editor',
		style: {
			'--giving-day-cause-columns': columnsDesktop || 4,
		},
	} );

	const placeholderCards = Array.from( { length: 6 } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Heading', 'giving-day-blocks' ) }>
					<TextControl
						label={ __( 'Heading', 'giving-day-blocks' ) }
						value={ heading }
						onChange={ ( value ) =>
							setAttributes( { heading: value } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __(
							'Search placeholder',
							'giving-day-blocks'
						) }
						value={ searchPlaceholder }
						onChange={ ( value ) =>
							setAttributes( { searchPlaceholder: value } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody title={ __( 'Layout', 'giving-day-blocks' ) }>
					<RangeControl
						label={ __(
							'Desktop columns',
							'giving-day-blocks'
						) }
						value={ columnsDesktop }
						min={ 1 }
						max={ 6 }
						onChange={ ( value ) =>
							setAttributes( {
								columnsDesktop: value || 4,
							} )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __(
							'Show beneficiary count on each card',
							'giving-day-blocks'
						) }
						checked={ !! showCounts }
						onChange={ ( value ) =>
							setAttributes( { showCounts: !! value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="giving-day-cause-areas__heading-row">
					<h2 className="giving-day-cause-areas__heading">
						{ heading ||
							__( 'Browse Cause Areas', 'giving-day-blocks' ) }
					</h2>
				</div>
				<div className="giving-day-cause-areas__search">
					<input
						type="search"
						readOnly
						value=""
						onChange={ () => {} }
						placeholder={
							searchPlaceholder ||
							__(
								'Search beneficiaries',
								'giving-day-blocks'
							)
						}
						aria-label={ __(
							'Search beneficiaries',
							'giving-day-blocks'
						) }
					/>
				</div>
				<div
					className="giving-day-cause-areas__grid"
					aria-hidden="true"
				>
					{ placeholderCards.map( ( _, idx ) => (
						<div
							// eslint-disable-next-line react/no-array-index-key
							key={ idx }
							className="giving-day-cause-areas__card giving-day-cause-areas__card--placeholder"
						>
							<span className="giving-day-cause-areas__card-image">
								<span className="giving-day-cause-areas__card-image-fallback" />
							</span>
							<span className="giving-day-cause-areas__card-body">
								<span className="giving-day-cause-areas__card-name">
									{ __(
										'Cause Area',
										'giving-day-blocks'
									) }
								</span>
								{ showCounts && (
									<span className="giving-day-cause-areas__card-count">
										—
									</span>
								) }
							</span>
						</div>
					) ) }
				</div>
				<p className="giving-day-cause-areas__editor-note">
					{ __(
						'Live grid, drill-down, and search appear on the front end.',
						'giving-day-blocks'
					) }
				</p>
			</div>
		</>
	);
}
