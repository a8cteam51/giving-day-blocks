/**
 * Term-edit-screen helper for the Cause Area image meta field.
 *
 * Loaded as plain ES (no build step) on edit-tags screens for `giving_cause`.
 * Opens the WordPress media frame, writes the chosen attachment ID into the
 * hidden input rendered by Admin\CauseTermMeta, and updates the inline
 * preview thumbnail. wp.media is loaded via wp_enqueue_media().
 */
( function () {
	'use strict';

	var translate =
		window.wp && window.wp.i18n && window.wp.i18n.__
			? window.wp.i18n.__
			: function ( s ) {
					return s;
			  };

	function init() {
		var fields = document.querySelectorAll(
			'.giving-day-cause-image-field'
		);
		if ( ! fields.length || ! window.wp || ! window.wp.media ) {
			return;
		}
		Array.prototype.forEach.call( fields, bindField );
	}

	function bindField( field ) {
		var input = field.querySelector( 'input[type="hidden"]' );
		var preview = field.querySelector(
			'.giving-day-cause-image-preview'
		);
		var chooseBtn = field.querySelector(
			'.giving-day-cause-image-choose'
		);
		var removeBtn = field.querySelector(
			'.giving-day-cause-image-remove'
		);
		if ( ! input || ! preview || ! chooseBtn ) {
			return;
		}

		var frame;

		chooseBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( ! frame ) {
				frame = window.wp.media( {
					title: translate(
						'Select Cause Area image',
						'giving-day-blocks'
					),
					button: {
						text: translate(
							'Use this image',
							'giving-day-blocks'
						),
					},
					library: { type: 'image' },
					multiple: false,
				} );
				frame.on( 'select', function () {
					var selection = frame.state().get( 'selection' );
					if ( ! selection ) {
						return;
					}
					var attachment = selection.first();
					if ( ! attachment ) {
						return;
					}
					var data = attachment.toJSON();
					var url =
						( data.sizes &&
							data.sizes.medium &&
							data.sizes.medium.url ) ||
						data.url ||
						'';
					setImage( data.id, url );
				} );
			}
			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				setImage( 0, '' );
			} );
		}

		function setImage( id, url ) {
			input.value = id ? String( id ) : '';
			preview.innerHTML = '';

			if ( id && url ) {
				preview.dataset.empty = '0';
				var img = document.createElement( 'img' );
				img.src = url;
				img.alt = '';
				img.style.maxWidth = '100%';
				img.style.height = 'auto';
				img.style.display = 'block';
				preview.appendChild( img );
				if ( removeBtn ) {
					removeBtn.removeAttribute( 'hidden' );
				}
				chooseBtn.textContent = translate(
					'Replace image',
					'giving-day-blocks'
				);
			} else {
				preview.dataset.empty = '1';
				var span = document.createElement( 'span' );
				span.className = 'giving-day-cause-image-placeholder';
				span.style.display = 'inline-block';
				span.style.padding = '0.5em';
				span.style.border = '1px dashed #c3c4c7';
				span.style.color = '#646970';
				span.textContent = translate(
					'No image set.',
					'giving-day-blocks'
				);
				preview.appendChild( span );
				if ( removeBtn ) {
					removeBtn.setAttribute( 'hidden', 'hidden' );
				}
				chooseBtn.textContent = translate(
					'Choose image',
					'giving-day-blocks'
				);
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
