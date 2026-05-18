/**
 * Chunked-import progress driver for the donations Import flow.
 *
 * Reads config from `window.givingDayImport` (localized server-side):
 *   ajaxUrl, action, nonce, key, total, batchSize, i18n.
 *
 * On "Start import" click, posts to admin-ajax in a loop, one batch
 * at a time, sequentially. Batches are processed server-side; this
 * loop just paces them so any single PHP request stays under the
 * timeout. When the server reports `done: true`, navigates to the
 * redirect URL so the existing "done" flash flow takes over.
 */
( function () {
	'use strict';

	var cfg = window.givingDayImport || {};
	if ( ! cfg.key || ! cfg.ajaxUrl || ! cfg.action || ! cfg.nonce ) {
		return;
	}

	var btn, progress, bar, label;

	function init() {
		btn = document.getElementById( 'giving-day-import-start' );
		progress = document.getElementById( 'giving-day-import-progress' );
		bar = document.getElementById( 'giving-day-import-progress-bar' );
		label = document.getElementById( 'giving-day-import-progress-label' );
		if ( ! btn || ! progress || ! bar || ! label ) {
			return;
		}
		btn.addEventListener( 'click', start );
	}

	function start() {
		btn.disabled = true;
		progress.style.display = '';
		label.textContent = cfg.i18n && cfg.i18n.starting ? cfg.i18n.starting : 'Starting…';
		runNextBatch();
	}

	function runNextBatch() {
		var body = new URLSearchParams();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'key', cfg.key );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( response ) {
				return response.json().then( function ( payload ) {
					return { ok: response.ok, payload: payload };
				} );
			} )
			.then( function ( res ) {
				if ( ! res.ok || ! res.payload || ! res.payload.success ) {
					handleFailure( res.payload );
					return;
				}
				handleBatchResult( res.payload.data || {} );
			} )
			.catch( function ( err ) {
				handleFailure( { data: { message: err && err.message ? err.message : 'Network error' } } );
			} );
	}

	function handleBatchResult( data ) {
		var total = parseInt( data.total, 10 ) || cfg.total || 0;
		var offset = parseInt( data.offset, 10 ) || 0;
		var pct = total > 0 ? Math.min( 100, Math.round( ( offset / total ) * 100 ) ) : 100;

		bar.style.width = pct + '%';
		label.textContent = formatProgress( offset, total, data );

		if ( data.done && data.redirect_url ) {
			label.textContent = cfg.i18n && cfg.i18n.completed ? cfg.i18n.completed : 'Finalizing…';
			window.location.href = data.redirect_url;
			return;
		}
		runNextBatch();
	}

	function handleFailure( payload ) {
		var message =
			payload && payload.data && payload.data.message
				? payload.data.message
				: 'Unknown error';
		var template = cfg.i18n && cfg.i18n.failed ? cfg.i18n.failed : 'Batch failed: %s';
		label.textContent = template.replace( '%s', message );
		btn.disabled = false;
	}

	function formatProgress( offset, total, data ) {
		var template =
			cfg.i18n && cfg.i18n.progress
				? cfg.i18n.progress
				: '%1$s of %2$s processed (%3$s created, %4$s skipped, %5$s errors)';
		return template
			.replace( '%1$s', String( offset ) )
			.replace( '%2$s', String( total ) )
			.replace( '%3$s', String( parseInt( data.created, 10 ) || 0 ) )
			.replace( '%4$s', String( parseInt( data.skipped, 10 ) || 0 ) )
			.replace( '%5$s', String( parseInt( data.errors, 10 ) || 0 ) );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
