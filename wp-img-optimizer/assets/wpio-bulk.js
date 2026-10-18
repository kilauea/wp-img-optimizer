/* global jQuery, WPIO */
( function ( $ ) {
	'use strict';

	// Convert this many attachments per AJAX request.
	var BATCH_SIZE = 5;

	function chunk( arr, size ) {
		var out = [];
		for ( var i = 0; i < arr.length; i += size ) {
			out.push( arr.slice( i, i + size ) );
		}
		return out;
	}

	$( function () {
		var $start = $( '#wpio-start' );
		var $bar = $( '#wpio-bar' );
		var $status = $( '#wpio-status' );
		var $log = $( '#wpio-log' );
		var $report = $( '#wpio-report' );
		var $progressWrap = $( '.wpio-progress-wrap' );

		$start.on( 'click', function () {
			$start.prop( 'disabled', true );
			$log.empty();
			$report.hide();
			$progressWrap.show();
			$status.text( WPIO.strings.running );

			// Step 1: initialise (reset totals, fetch ID list).
			$.post( WPIO.ajaxUrl, {
				action: 'wpio_bulk_init',
				nonce: WPIO.nonce
			} ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					fail( 'init failed' );
					return;
				}
				var ids = resp.data.ids || [];
				var total = resp.data.total || 0;
				if ( total === 0 ) {
					$status.text( WPIO.strings.done + ' (0 images)' );
					$start.prop( 'disabled', false );
					return;
				}
				runBatches( chunk( ids, BATCH_SIZE ), 0, total, 0 );
			} ).fail( function () {
				fail( 'init request error' );
			} );
		} );

		function runBatches( batches, index, total, processed ) {
			if ( index >= batches.length ) {
				$status.text( WPIO.strings.done );
				$start.prop( 'disabled', false );
				return;
			}
			var ids = batches[ index ];

			$.post( WPIO.ajaxUrl, {
				action: WPIO.action,
				nonce: WPIO.nonce,
				ids: ids
			} ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					fail( 'batch failed' );
					return;
				}
				processed += ids.length;
				var pct = Math.round( ( processed / total ) * 100 );
				$bar.css( 'width', pct + '%' ).text( pct + '%' );
				$status.text( WPIO.strings.running + ' ' + processed + ' / ' + total );

				( resp.data.log || [] ).forEach( function ( line ) {
					$( '<div/>' ).text( line ).appendTo( $log );
				} );

				updateReport( resp.data.totals );
				runBatches( batches, index + 1, total, processed );
			} ).fail( function () {
				fail( 'batch request error' );
			} );
		}

		function updateReport( totals ) {
			if ( ! totals ) {
				return;
			}
			$report.show();
			$( '#wpio-r-files' ).text( totals.files );
			$( '#wpio-r-orig' ).text( totals.orig );
			$( '#wpio-r-new' ).text( totals.new );
			$( '#wpio-r-pct' ).text( totals.pct + '%' );
		}

		function fail( msg ) {
			$status.text( 'Error: ' + msg );
			$start.prop( 'disabled', false );
		}
	} );
} )( jQuery );
