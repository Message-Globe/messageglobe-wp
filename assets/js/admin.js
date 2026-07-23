/* global window, document */
( function () {
	'use strict';

	var cfg = window.messageglobeAdmin || {};
	var i18n = cfg.i18n || {};

	/**
	 * POST to admin-ajax and hand the parsed JSON back to a callback.
	 */
	function post( action, data, done ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce || '' );
		Object.keys( data ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				done( json );
			} )
			.catch( function () {
				done( { success: false, data: { message: i18n.error || 'Request failed.' } } );
			} );
	}

	/**
	 * Render a result box. All text uses textContent, so responses are never
	 * treated as HTML.
	 */
	function showResult( el, ok, message, list ) {
		if ( ! el ) {
			return;
		}

		el.className = 'messageglobe-test-result notice ' + ( ok ? 'notice-success' : 'notice-error' );
		el.style.display = 'block';
		el.textContent = '';

		var p = document.createElement( 'p' );
		p.textContent = message || '';
		el.appendChild( p );

		if ( list && list.length ) {
			var ul = document.createElement( 'ul' );
			ul.style.margin = '0 0 8px 20px';
			ul.style.listStyle = 'disc';
			list.forEach( function ( name ) {
				var li = document.createElement( 'li' );
				li.textContent = name;
				ul.appendChild( li );
			} );
			el.appendChild( ul );
		}
	}

	function busy( button, label ) {
		button.dataset.original = button.textContent;
		button.disabled = true;
		button.textContent = label;
	}

	function ready( button ) {
		button.disabled = false;
		if ( button.dataset.original ) {
			button.textContent = button.dataset.original;
		}
	}

	function value( id ) {
		var el = document.getElementById( id );
		return el ? el.value : '';
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		// Test connection.
		var connBtn = document.getElementById( 'messageglobe-test-connection' );
		if ( connBtn ) {
			connBtn.addEventListener( 'click', function () {
				var result = document.getElementById( 'messageglobe-connection-result' );
				busy( connBtn, i18n.testing || 'Testing…' );
				post( 'messageglobe_test_connection', {}, function ( json ) {
					ready( connBtn );
					var data = json.data || {};
					showResult( result, !! json.success, data.message, data.senders );
				} );
			} );
		}

		// Test SMS.
		var smsBtn = document.getElementById( 'messageglobe-test-sms' );
		if ( smsBtn ) {
			smsBtn.addEventListener( 'click', function () {
				var result = document.getElementById( 'messageglobe-sms-result' );
				busy( smsBtn, i18n.sending || 'Sending…' );
				post( 'messageglobe_test_sms', {
					to: value( 'messageglobe-test-sms-to' ),
					message: value( 'messageglobe-test-sms-message' )
				}, function ( json ) {
					ready( smsBtn );
					var data = json.data || {};
					showResult( result, !! json.success, data.message );
				} );
			} );
		}

		// Test email.
		var emailBtn = document.getElementById( 'messageglobe-test-email' );
		if ( emailBtn ) {
			emailBtn.addEventListener( 'click', function () {
				var result = document.getElementById( 'messageglobe-email-result' );
				busy( emailBtn, i18n.sending || 'Sending…' );
				post( 'messageglobe_test_email', {
					to: value( 'messageglobe-test-email-to' )
				}, function ( json ) {
					ready( emailBtn );
					var data = json.data || {};
					showResult( result, !! json.success, data.message );
				} );
			} );
		}

		// Email tab: hide the manual SMTP rows while the MessageGlobe preset is
		// on. Rows are hidden (not disabled) so their values still submit and
		// are never wiped by a preset-mode save.
		var preset = document.getElementById( 'smtp_use_preset' );
		if ( preset ) {
			var manualRows = document.querySelectorAll( '.messageglobe-smtp-manual' );
			var syncManual = function () {
				var hide = preset.checked;
				manualRows.forEach( function ( row ) {
					row.style.display = hide ? 'none' : '';
				} );
			};
			preset.addEventListener( 'change', syncManual );
			syncManual();
		}
	} );
}() );
