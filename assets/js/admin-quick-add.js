/**
 * Memorial Quick Add: add and remove memorial rows, search donors on file,
 * and show each row's fields only when they apply.
 *
 * Without this script the screen still works: every field shows, and a new
 * donor can be entered by name and email.
 */
( function ( $ ) {
	'use strict';

	const cfg = window.sdQuickAdd || { i18n: {} };
	const $rows = $( '#sd-qa-rows' );
	const template = document.getElementById( 'sd-qa-row-template' );
	if ( ! $rows.length || ! template ) {
		return;
	}

	function initDonorSearch( $scope ) {
		if ( typeof $.fn.select2 !== 'function' ) {
			return;
		}
		$scope.find( '.sd-qa-donor' ).each( function () {
			const $select = $( this );
			$select.select2( {
				width: '100%',
				allowClear: true,
				placeholder: $select.data( 'placeholder' ) || '',
				minimumInputLength: 2,
				ajax: {
					url: cfg.ajaxUrl,
					dataType: 'json',
					delay: 250,
					data: ( params ) => ( { action: cfg.searchAction, nonce: cfg.searchNonce, term: params.term } ),
					processResults: ( response ) => ( { results: response && response.success ? response.data : [] } ),
				},
				language: {
					inputTooShort: () => cfg.i18n.typeMore,
					noResults: () => cfg.i18n.noResults,
					searching: () => cfg.i18n.searching,
				},
			} );
		} );
	}

	// Show a row's fields only when they apply.
	function sync( $row ) {
		const onFile = !! $row.find( '.sd-qa-donor' ).val();
		$row.find( '.sd-qa-new-donor' ).toggleClass( 'sd-qa-hidden', onFile ).find( 'input' ).prop( 'disabled', onFile );

		const pet = $row.find( '.sd-qa-type-input:checked' ).val() === 'pet';
		$row.find( '.sd-qa-species' ).toggleClass( 'sd-qa-hidden', ! pet );

		const family = $row.find( '.sd-qa-family-toggle' ).is( ':checked' );
		$row.find( '.sd-qa-family' ).toggleClass( 'sd-qa-hidden', ! family );
	}

	function renumber() {
		const $all = $rows.children( '.sd-qa-row' );
		$all.each( function ( n ) {
			$( this ).find( '.sd-qa-number' ).first().text( String( n + 1 ) );
		} );
		$all.find( '.sd-qa-remove' ).prop( 'hidden', $all.length < 2 );
	}

	let next = $rows.children( '.sd-qa-row' ).length;

	$( '.sd-qa-add-row' ).on( 'click', function () {
		const html = template.innerHTML.replace( /__INDEX__/g, String( next++ ) );
		const $row = $( html ).appendTo( $rows );
		initDonorSearch( $row );
		sync( $row );
		renumber();
		$row.find( 'input:visible, select:visible' ).first().trigger( 'focus' );
	} );

	$rows.on( 'click', '.sd-qa-remove', function () {
		$( this ).closest( '.sd-qa-row' ).remove();
		renumber();
	} );

	$rows.on( 'change', 'input, select', function () {
		sync( $( this ).closest( '.sd-qa-row' ) );
	} );

	initDonorSearch( $rows );
	$rows.children( '.sd-qa-row' ).each( function () {
		sync( $( this ) );
	} );
	renumber();
}( jQuery ) );
