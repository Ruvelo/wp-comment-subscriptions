/**
 * Ruvelo Comment Subscriptions: "Subscribe without commenting" in place.
 * The form posts normally without JavaScript; with it, it uses the REST API
 * and announces the result in the form's live region. Plain JavaScript, no
 * build step.
 */
( function () {
	'use strict';

	var config = window.ruveloCommentSubscriptions || {};
	if ( ! config.rest || ! window.fetch || ! window.FormData ) {
		return;
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.matches || ! form.matches( '[data-ruvelo-cs-subscribe]' ) ) {
			return;
		}
		event.preventDefault();

		var data = new FormData( form );
		var button = form.querySelector( 'button[type="submit"]' );
		var message = form.querySelector( '[data-ruvelo-cs-msg]' );
		var headers = { 'Content-Type': 'application/json' };
		if ( config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		if ( button ) {
			button.setAttribute( 'aria-busy', 'true' );
			button.disabled = true;
		}

		window.fetch( config.rest + 'subscribe', {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( {
				post: parseInt( data.get( 'post_id' ), 10 ),
				email: data.get( 'email' ) || '',
				website: data.get( 'website' ) || '',
			} ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				show( message, result.body && result.body.message ? result.body.message : config.error, result.ok ? 'success' : 'danger' );
				if ( result.ok ) {
					var input = form.querySelector( 'input[type="email"]' );
					if ( input ) {
						input.value = '';
					}
				}
			} )
			.catch( function () {
				form.submit(); // Fall back to the plain form post.
			} )
			.finally( function () {
				if ( button ) {
					button.removeAttribute( 'aria-busy' );
					button.disabled = false;
				}
			} );
	} );

	function show( element, text, tone ) {
		if ( ! element ) {
			return;
		}
		element.className = 'ruvelo-cs-msg is-' + tone;
		element.textContent = '';
		// A fresh text node after a tick, so screen readers announce it again.
		window.setTimeout( function () {
			element.textContent = text;
		}, 50 );
	}
}() );
