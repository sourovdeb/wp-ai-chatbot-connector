/* Sourov AI Gateway — admin helper.
 * Calls the public /status route and renders which providers are configured.
 * No secrets are ever sent to or stored in the browser.
 */
( function () {
	'use strict';
	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}
	ready( function () {
		var el = document.getElementById( 'sourov-ai-status' );
		if ( ! el || typeof window.wpApiSettings === 'undefined' ) { return; }
		fetch( wpApiSettings.root + 'sourov-ai/v1/status', {
			headers: { 'X-WP-Nonce': wpApiSettings.nonce }
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				el.textContent = JSON.stringify( data, null, 2 );
			} )
			.catch( function ( e ) { el.textContent = 'status error: ' + e; } );
	} );
} )();
