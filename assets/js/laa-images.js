/* global jQuery, wp, laaImages */
( function ( $ ) {
	'use strict';

	/** Media picker for the mask and logo fields. */
	$( document ).on( 'click', '.laa-media-pick', function ( event ) {
		event.preventDefault();

		var $wrap = $( this ).closest( '.laa-media' );
		var frame = wp.media( {
			title: 'Выберите изображение',
			library: { type: 'image' },
			multiple: false,
			button: { text: 'Использовать' }
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

			$wrap.find( 'input[type=hidden]' ).val( attachment.id );
			$wrap.find( '.laa-media-thumb' ).html( '<img src="' + thumb + '" alt="" width="60" height="60">' );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.laa-media-clear', function ( event ) {
		event.preventDefault();
		var $wrap = $( this ).closest( '.laa-media' );
		$wrap.find( 'input[type=hidden]' ).val( 0 );
		$wrap.find( '.laa-media-thumb' ).empty();
	} );

	/**
	 * Live preview: posts the form's *current* values, so you can see a change
	 * before deciding to save it. The form's own `action` field is dropped —
	 * this goes to the ajax handler, not to the save handler.
	 */
	$( document ).on( 'click', '#laa-preview-button', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var $box = $( '#laa-preview-box' );

		var fields = $( '#laa-preset-form' ).serializeArray().filter( function ( field ) {
			return field.name !== 'action' && field.name !== '_wpnonce' && field.name !== '_wp_http_referer';
		} );

		// Same script serves the cover screen and the infographic screen; the
		// action it posts to comes from the localised config.
		fields.push( { name: 'action', value: laaImages.action || 'laa_preview_image' } );
		fields.push( { name: 'nonce', value: laaImages.nonce } );
		fields.push( { name: 'preview_title', value: $( '#laa-preview-title' ).val() } );

		var $points = $( '#laa-preview-points' );
		if ( $points.length ) {
			fields.push( { name: 'preview_points', value: $points.val() } );
		}

		$button.prop( 'disabled', true ).text( 'Собираю…' );
		$box.html( '<p class="description">Собираю картинку…</p>' );

		$.post( laaImages.ajaxUrl, $.param( fields ) )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					var message = response && response.data ? response.data.message : 'Не удалось собрать картинку.';
					$box.html( '<div class="notice notice-error inline"><p></p></div>' ).find( 'p' ).text( message );
					return;
				}

				$box.empty();
				$( '<img>' ).attr( 'src', response.data.dataUri ).appendTo( $box );

				var note = $( '<p class="description"></p>' ).text( 'Исходник: ' + response.data.source );
				$box.append( note );

				if ( response.data.notice ) {
					$box.append( $( '<p class="laa-error"></p>' ).text( response.data.notice ) );
				}
			} )
			.fail( function () {
				$box.html( '<div class="notice notice-error inline"><p>Запрос не прошёл.</p></div>' );
			} )
			.always( function () {
				$button.prop( 'disabled', false ).text( 'Предпросмотр' );
			} );
	} );
}( jQuery ) );
