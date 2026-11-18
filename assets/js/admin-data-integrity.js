/**
 * Data Integrity Tools — Admin JS.
 *
 * Handles scan → report → backfill flow, purge with confirmation,
 * and donor stat recalculation. All operations batch via AJAX.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

/* global jQuery, sdDataIntegrity */
( function( $ ) {
	'use strict';

	var config = sdDataIntegrity;
	var $progress = $( '#sd-progress-area' );
	var $bar = $( '#sd-progress-bar' );
	var $text = $( '#sd-progress-text' );
	var $results = $( '#sd-results-area' );

	// ── Tab Switching ───────────────────────────────────────────

	$( '#sd-data-tools-tabs .nav-tab' ).on( 'click', function( e ) {
		e.preventDefault();
		var target = $( this ).attr( 'href' ).replace( '#', 'tab-' );

		$( '.nav-tab' ).removeClass( 'nav-tab-active' );
		$( this ).addClass( 'nav-tab-active' );

		$( '.sd-tool-section' ).removeClass( 'active' );
		$( '#' + target ).addClass( 'active' );
	} );

	// Open the tab a link points at (the dashboard links to #orders).
	if ( window.location.hash ) {
		$( '#sd-data-tools-tabs .nav-tab[href="' + window.location.hash + '"]' ).trigger( 'click' );
	}

	// ── Helpers ─────────────────────────────────────────────────

	function showProgress( text, percent ) {
		$progress.show();
		$bar.css( 'width', percent + '%' );
		$text.text( text );
	}

	function hideProgress() {
		$progress.hide();
		$bar.css( 'width', '0%' );
		$text.text( '' );
	}

	function disableButtons( disabled ) {
		$( '.button', '.sd-tool-section' ).prop( 'disabled', disabled );
	}

	function post( action, data ) {
		return $.ajax( {
			url: config.ajaxUrl,
			type: 'POST',
			data: $.extend( { action: action, nonce: config.nonce }, data ),
		} );
	}

	// ── Scan ────────────────────────────────────────────────────

	$( '#sd-scan-btn' ).on( 'click', function() {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( config.i18n.scanning );
		$( '#sd-scan-results' ).empty();
		$( '#sd-backfill-actions' ).hide();
		hideProgress();

		post( 'skdon_integrity_scan' ).done( function( response ) {
			$btn.prop( 'disabled', false ).text( 'Scan All Records' );

			if ( ! response.success ) {
				$( '#sd-scan-results' ).html(
					'<div class="notice notice-error"><p>' + ( response.data || 'Scan failed.' ) + '</p></div>'
				);
				return;
			}

			renderScanResults( response.data );
		} ).fail( function() {
			$btn.prop( 'disabled', false ).text( 'Scan All Records' );
			$( '#sd-scan-results' ).html(
				'<div class="notice notice-error"><p>Request failed.</p></div>'
			);
		} );
	} );

	function renderScanResults( report ) {
		var $container = $( '#sd-scan-results' );
		var hasAnyIssues = false;

		$.each( report, function( postType, data ) {
			var cssClass = data.has_issues ? 'sd-scan-issues' : 'sd-scan-ok';
			var html = '<div class="sd-entity-card ' + cssClass + '">';
			html += '<h3>' + escHtml( data.label ) +
				' <span class="sd-count-badge' + ( data.has_issues ? '' : ' ok' ) + '">' +
				data.total + ' records</span></h3>';

			if ( ! data.has_issues ) {
				html += '<p style="color:#00a32a;">✓ ' + config.i18n.noIssues + '</p>';
			} else {
				hasAnyIssues = true;
				html += '<div class="sd-field-list">';

				$.each( data.issues, function( field, count ) {
					if ( count > 0 ) {
						html += '<p>• <strong>' + escHtml( field ) + '</strong>: ' +
							count + ' records missing';
						if ( data.sample_ids && data.sample_ids[ field ] ) {
							html += ' <small>(e.g. IDs: ' + data.sample_ids[ field ].join( ', ' ) + ')</small>';
						}
						html += '</p>';
					}
				} );

				$.each( data.specials || {}, function( key, count ) {
					if ( count > 0 ) {
						var labels = {
							'memorial_year_taxonomy': 'Missing memorial year taxonomy',
							'import_hash': 'Missing import dedup hash',
							'donor_display_name_email': 'Donor display name is an email (on memorials)',
							'display_name_is_email': 'Display name is an email (on donors)',
							'order_date_mismatch': 'Memorial date does not match order date',
						};
						var label = labels[ key ] || key;
						html += '<p>• <strong>' + escHtml( label ) + '</strong>: ' + count + ' records</p>';
					}
				} );

				html += '</div>';
			}

			html += '</div>';
			$container.append( html );
		} );

		if ( hasAnyIssues ) {
			$( '#sd-backfill-actions' ).show();
		}
	}

	// ── Backfill ────────────────────────────────────────────────

	$( '#sd-backfill-btn' ).on( 'click', function() {
		disableButtons( true );

		// Build queue of entity types to backfill.
		var queue = Object.keys( config.entityTypes );
		var totalFixed = 0;
		var totalProcessed = 0;
		// The activity log's entry for this whole Backfill (#36).
		var run = { id: '' };

		function processNext() {
			if ( queue.length === 0 ) {
				showProgress( config.i18n.complete + ' Fixed ' + totalFixed + ' fields across ' + totalProcessed + ' records.', 100 );
				disableButtons( false );
				return;
			}

			var postType = queue[0];
			var label = config.entityTypes[ postType ];

			backfillBatch( postType, label, 0, run, 1 === queue.length, function( batchProcessed, batchFixed ) {
				totalProcessed += batchProcessed;
				totalFixed += batchFixed;
				queue.shift();
				processNext();
			} );
		}

		processNext();
	} );

	function backfillBatch( postType, label, offset, run, isLast, onComplete ) {
		var batchProcessed = 0;
		var batchFixed = 0;

		function nextBatch( currentOffset ) {
			showProgress(
				config.i18n.backfilling + ' ' + label + '… (' + currentOffset + ' processed)',
				0
			);

			post( 'skdon_integrity_backfill', {
				post_type: postType,
				offset: currentOffset,
				run_id: run.id,
				final: isLast ? 1 : 0,
			} ).done( function( response ) {
				if ( ! response.success ) {
					showProgress( 'Error: ' + ( response.data || 'Unknown' ), 0 );
					disableButtons( false );
					return;
				}

				var d = response.data;
				run.id = d.run_id || run.id;
				batchProcessed += d.processed;
				batchFixed += d.fixed;

				var pct = d.total > 0 ? Math.round( ( d.offset / d.total ) * 100 ) : 100;
				showProgress(
					config.i18n.backfilling + ' ' + label + '… ' + d.offset + '/' + d.total +
					' (' + batchFixed + ' fixed)',
					pct
				);

				if ( d.done ) {
					onComplete( batchProcessed, batchFixed );
				} else {
					nextBatch( d.offset );
				}
			} ).fail( function() {
				showProgress( 'Request failed during ' + label + ' backfill.', 0 );
				disableButtons( false );
			} );
		}

		nextBatch( offset );
	}

	// ── Purge ───────────────────────────────────────────────────

	$( document ).on( 'click', '.sd-purge-btn', function() {
		var postType = $( this ).data( 'post-type' );
		var label = $( this ).data( 'label' );
		var count = $( this ).data( 'count' );

		// First confirmation: standard confirm dialog.
		var warning = config.i18n.purgeWarning.replace( '%s', label + ' (' + count + ')' );
		if ( ! confirm( warning ) ) {
			return;
		}

		// Second confirmation: type the post type slug.
		var typed = prompt( config.i18n.confirmPurge + '\n\n' + postType );
		if ( typed !== postType ) {
			alert( 'Confirmation did not match. Purge cancelled.' );
			return;
		}

		disableButtons( true );
		purgeBatch( postType, label, typed );
	} );

	function purgeBatch( postType, label, confirmation ) {
		var totalDeleted = 0;
		var runId = '';

		function nextBatch() {
			post( 'skdon_integrity_purge', {
				post_type: postType,
				confirmation: confirmation,
				run_id: runId,
			} ).done( function( response ) {
				if ( ! response.success ) {
					showProgress( 'Error: ' + ( response.data || 'Unknown' ), 0 );
					disableButtons( false );
					return;
				}

				var d = response.data;
				runId = d.run_id || runId;
				totalDeleted += d.deleted;

				if ( d.done ) {
					showProgress(
						config.i18n.complete + ' Deleted ' + totalDeleted + ' ' + label + '.',
						100
					);
					disableButtons( false );

					// Update the count badge on the purge card.
					$( '.sd-purge-btn[data-post-type="' + postType + '"]' )
						.data( 'count', 0 )
						.prop( 'disabled', true )
						.closest( '.sd-entity-card' )
						.find( '.sd-count-badge' )
						.text( '0' )
						.addClass( 'ok' );
				} else {
					var pct = d.remaining > 0
						? Math.round( ( totalDeleted / ( totalDeleted + d.remaining ) ) * 100 )
						: 100;
					showProgress(
						config.i18n.purging + ' ' + label + '… ' + totalDeleted + ' deleted, ' + d.remaining + ' remaining',
						pct
					);
					nextBatch();
				}
			} ).fail( function() {
				showProgress( 'Request failed during purge.', 0 );
				disableButtons( false );
			} );
		}

		showProgress( config.i18n.purging + ' ' + label + '…', 0 );
		nextBatch();
	}

	// ── Reset Sync Flags ────────────────────────────────────────

	$( '#sd-reset-sync-flags' ).on( 'click', function() {
		if ( ! confirm( 'Reset sync flags on all WooCommerce orders? This allows them to be re-processed by Legacy Order Sync.' ) ) {
			return;
		}

		var $btn = $( this ).prop( 'disabled', true );

		$.ajax( {
			url: config.ajaxUrl,
			type: 'POST',
			data: {
				action: 'skdon_legacy_sync_reset',
				nonce: config.nonce,
			},
		} ).done( function( response ) {
			$btn.prop( 'disabled', false );
			if ( response.success ) {
				$results.empty().append( $( '<div class="notice notice-success"><p></p></div>' ).find( 'p' ).text( response.data.message ).end() );
			} else {
				$results.empty().append( $( '<div class="notice notice-error"><p></p></div>' ).find( 'p' ).text( response.data || 'Failed.' ).end() );
			}
		} ).fail( function() {
			$btn.prop( 'disabled', false );
			$results.html( '<div class="notice notice-error"><p>Request failed.</p></div>' );
		} );
	} );

	// ── Repair records merged by Legacy Order Sync (#40) ────────

	$( '#sd-repair-merges' ).on( 'click', function() {
		var $btn = $( this ).prop( 'disabled', true );
		post( 'skdon_integrity_repair_merges' ).done( function( response ) {
			var data    = response && response.data ? response.data : {};
			var message = response.success ? data.message : ( data || 'Failed.' );
			var failed  = ( data.failed || [] ).join( '; ' );
			$results.empty().append(
				$( '<div class="notice"><p></p></div>' )
					.addClass( response.success && ! failed ? 'notice-success' : 'notice-error' )
					.find( 'p' ).text( message + ( failed ? ' ' + failed : '' ) ).end()
			);
			if ( response.success && ! failed ) {
				window.setTimeout( function() { window.location.reload(); }, 1500 );
			} else {
				$btn.prop( 'disabled', false );
			}
		} ).fail( function() {
			$btn.prop( 'disabled', false );
			$results.html( '<div class="notice notice-error"><p>Request failed.</p></div>' );
		} );
	} );

	// ── Refunds (#34) ───────────────────────────────────────────

	function showResult( response, reload ) {
		var data    = response && response.data ? response.data : {};
		var message = response.success ? data.message : ( data || 'Failed.' );
		$results.empty().append(
			$( '<div class="notice"><p></p></div>' )
				.addClass( response.success ? 'notice-success' : 'notice-error' )
				.find( 'p' ).text( message ).end()
		);
		if ( response.success && reload ) {
			window.setTimeout( function() { window.location.reload(); }, 1500 );
		}
		return response.success;
	}

	$( '.sd-assign-refund' ).on( 'click', function() {
		var $btn = $( this ).prop( 'disabled', true );
		post( 'skdon_integrity_assign_refund', {
			order_id: $btn.data( 'order' ),
			refund_id: $btn.data( 'refund' ),
			record: $btn.closest( 'tr' ).find( '.sd-refund-record' ).val(),
		} ).done( function( response ) {
			if ( ! showResult( response, true ) ) {
				$btn.prop( 'disabled', false );
			}
		} ).fail( function() {
			$btn.prop( 'disabled', false );
			$results.html( '<div class="notice notice-error"><p>Request failed.</p></div>' );
		} );
	} );

	$( '#sd-apply-refunds' ).on( 'click', function() {
		var $btn = $( this ).prop( 'disabled', true );
		post( 'skdon_integrity_apply_refunds' ).done( function( response ) {
			showResult( response, false );
			$btn.prop( 'disabled', false );
		} ).fail( function() {
			$btn.prop( 'disabled', false );
			$results.html( '<div class="notice notice-error"><p>Request failed.</p></div>' );
		} );
	} );

	// ── Recalculate Donors ──────────────────────────────────────

	$( '#sd-recalc-btn' ).on( 'click', function() {
		disableButtons( true );
		var totalUpdated = 0;
		var totalProcessed = 0;
		var runId = '';

		function nextBatch( offset ) {
			post( 'skdon_integrity_recalc_donors', { offset: offset, run_id: runId } ).done( function( response ) {
				if ( ! response.success ) {
					showProgress( 'Error: ' + ( response.data || 'Unknown' ), 0 );
					disableButtons( false );
					return;
				}

				var d = response.data;
				runId = d.run_id || runId;
				totalProcessed += d.processed;
				totalUpdated += d.updated;

				var pct = d.total > 0 ? Math.round( ( d.offset / d.total ) * 100 ) : 100;
				showProgress(
					config.i18n.recalculating + ' ' + d.offset + '/' + d.total +
					' donors (' + totalUpdated + ' updated)',
					pct
				);

				if ( d.done ) {
					showProgress(
						config.i18n.complete + ' Recalculated ' + totalProcessed + ' donors, ' + totalUpdated + ' had changed values.',
						100
					);
					disableButtons( false );
				} else {
					nextBatch( d.offset );
				}
			} ).fail( function() {
				showProgress( 'Request failed during recalculation.', 0 );
				disableButtons( false );
			} );
		}

		showProgress( config.i18n.recalculating, 0 );
		nextBatch( 0 );
	} );

	// ── Utils ───────────────────────────────────────────────────

	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

} )( jQuery );
