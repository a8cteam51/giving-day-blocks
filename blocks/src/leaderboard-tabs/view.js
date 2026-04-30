/**
 * Tab UI for giving-day/leaderboard-tabs (WAI-ARIA tabs + optional URL hash).
 */
import { __ } from '@wordpress/i18n';

function slugFromPanel( panel ) {
	const slug = panel?.dataset?.tabSlug;
	if ( slug && slug !== '' ) {
		return slug;
	}
	return `tab-${ panel?.dataset?.campaignId || '0' }`;
}

function readHashTab( instanceId ) {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const m = window.location.hash.match(
		new RegExp( `^#tab-${ instanceId }=([^&]+)$` )
	);
	return m ? decodeURIComponent( m[ 1 ] ) : null;
}

function setHashTab( instanceId, slug ) {
	if ( typeof window === 'undefined' || ! slug ) {
		return;
	}
	const next = `#tab-${ instanceId }=${ encodeURIComponent( slug ) }`;
	if ( window.location.hash !== next ) {
		const url = `${ window.location.pathname }${ window.location.search }${ next }`;
		window.history.replaceState( null, '', url );
	}
}

function initTabs( root ) {
	if ( root.dataset.gdTabsInit === '1' ) {
		return;
	}
	root.dataset.gdTabsInit = '1';

	const panels = Array.from(
		root.querySelectorAll( ':scope > .giving-day-leaderboard' )
	);
	if ( panels.length === 0 ) {
		return;
	}

	const defaultIdx = Math.min(
		panels.length - 1,
		Math.max( 0, parseInt( root.dataset.defaultTab || '0', 10 ) )
	);

	const tablist = document.createElement( 'ul' );
	tablist.className = 'giving-day-leaderboard-tabs__tablist';
	tablist.setAttribute( 'role', 'tablist' );
	tablist.setAttribute(
		'aria-label',
		__( 'Leaderboards', 'giving-day-blocks' )
	);

	const tabs = [];
	const instanceId = root.dataset.tabsInstanceId || '0';

	let selected = defaultIdx;
	const hashSlug = readHashTab( instanceId );
	if ( hashSlug ) {
		const idx = panels.findIndex(
			( p ) => slugFromPanel( p ) === hashSlug
		);
		if ( idx >= 0 ) {
			selected = idx;
		}
	}

	panels.forEach( ( panel, index ) => {
		const slug = slugFromPanel( panel );
		const label =
			panel.dataset.tabLabel && panel.dataset.tabLabel.trim() !== ''
				? panel.dataset.tabLabel
				: `${ __( 'Tab', 'giving-day-blocks' ) } ${ index + 1 }`;

		const tabId = `gd-lb-tab-${
			root.dataset.campaignId || '0'
		}-${ instanceId }-${ index }`;
		const panelId = `gd-lb-panel-${
			root.dataset.campaignId || '0'
		}-${ instanceId }-${ index }`;

		panel.id = panelId;
		panel.setAttribute( 'role', 'tabpanel' );
		panel.setAttribute( 'aria-labelledby', tabId );
		panel.classList.add( 'giving-day-leaderboard-tabs__panel' );

		const li = document.createElement( 'li' );
		li.setAttribute( 'role', 'presentation' );
		const btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'giving-day-leaderboard-tabs__tab';
		btn.id = tabId;
		btn.setAttribute( 'role', 'tab' );
		btn.setAttribute( 'aria-controls', panelId );
		btn.setAttribute(
			'aria-selected',
			index === selected ? 'true' : 'false'
		);
		btn.setAttribute( 'tabindex', index === selected ? '0' : '-1' );
		btn.dataset.tabIndex = String( index );
		btn.dataset.tabSlug = slug;
		btn.textContent = label;
		li.appendChild( btn );
		tablist.appendChild( li );
		tabs.push( btn );

		if ( index !== selected ) {
			panel.setAttribute( 'hidden', 'hidden' );
		} else {
			panel.removeAttribute( 'hidden' );
		}
	} );

	root.insertBefore( tablist, root.firstChild );

	const select = ( index, opts = {} ) => {
		const { focusTab = false } = opts;
		const next = Math.max( 0, Math.min( panels.length - 1, index ) );
		selected = next;
		panels.forEach( ( panel, i ) => {
			const on = i === selected;
			const btn = tabs[ i ];
			btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			btn.setAttribute( 'tabindex', on ? '0' : '-1' );
			if ( on ) {
				panel.removeAttribute( 'hidden' );
			} else {
				panel.setAttribute( 'hidden', 'hidden' );
			}
		} );
		if ( focusTab ) {
			tabs[ selected ]?.focus( { preventScroll: true } );
		}
		setHashTab( instanceId, slugFromPanel( panels[ selected ] ) );
	};

	tabs.forEach( ( btn, index ) => {
		btn.addEventListener( 'click', () => select( index ) );
	} );

	tablist.addEventListener( 'keydown', ( e ) => {
		const key = e.key;
		if (
			key !== 'ArrowRight' &&
			key !== 'ArrowLeft' &&
			key !== 'Home' &&
			key !== 'End'
		) {
			return;
		}
		e.preventDefault();
		let next = selected;
		if ( key === 'ArrowRight' ) {
			next = selected + 1;
			if ( next >= panels.length ) {
				next = 0;
			}
		} else if ( key === 'ArrowLeft' ) {
			next = selected - 1;
			if ( next < 0 ) {
				next = panels.length - 1;
			}
		} else if ( key === 'Home' ) {
			next = 0;
		} else if ( key === 'End' ) {
			next = panels.length - 1;
		}
		select( next, { focusTab: true } );
	} );

	window.addEventListener( 'hashchange', () => {
		const slug = readHashTab( instanceId );
		if ( ! slug ) {
			return;
		}
		const idx = panels.findIndex( ( p ) => slugFromPanel( p ) === slug );
		if ( idx >= 0 ) {
			select( idx, { focusTab: false } );
		}
	} );
}

function boot() {
	const roots = document.querySelectorAll(
		'.giving-day-leaderboard-tabs[data-campaign-id]'
	);
	roots.forEach( ( root, index ) => {
		if ( ! root.dataset.tabsInstanceId ) {
			root.dataset.tabsInstanceId = String( index + 1 );
		}
		initTabs( root );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
