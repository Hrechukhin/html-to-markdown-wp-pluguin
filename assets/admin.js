/* global H2MD, jQuery */
( function ( $ ) {
	'use strict';

	var total = 0;
	var offset = 0;

	function post( action, data ) {
		return $.post(
			H2MD.ajaxUrl,
			$.extend( { action: action, nonce: H2MD.nonce }, data || {} )
		);
	}

	function setStatus( text, isError ) {
		$( '#h2md-status' )
			.text( text )
			.toggleClass( 'is-error', !! isError );
	}

	function setProgress( processed, totalCount ) {
		var pct = totalCount ? Math.round( ( processed / totalCount ) * 100 ) : 0;
		$( '#h2md-progress-wrap' ).show();
		$( '#h2md-progress-fill' ).css( 'width', pct + '%' );
		$( '#h2md-progress-text' ).text( processed + ' / ' + totalCount + ' (' + pct + '%)' );
	}

	function appendLog( entries ) {
		var $log = $( '#h2md-log' ).show();
		entries.forEach( function ( e ) {
			var line;
			if ( 'ok' === e.status ) {
				line = '<div class="h2md-log-ok">✓ ' + escapeHtml( e.file ) + '</div>';
			} else {
				line =
					'<div class="h2md-log-err">✗ ' +
					escapeHtml( e.url ) +
					' — ' +
					escapeHtml( e.message ) +
					'</div>';
			}
			$log.append( line );
		} );
		$log.scrollTop( $log[ 0 ].scrollHeight );
	}

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function buildQueue() {
		setStatus( H2MD.i18n.building );
		$( '#h2md-build, #h2md-export' ).prop( 'disabled', true );
		$( '#h2md-log' ).empty();

		post( 'h2md_build_queue' )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					setStatus( H2MD.i18n.error + ' ' + ( res && res.data ? res.data.message : '' ), true );
					$( '#h2md-build' ).prop( 'disabled', false );
					return;
				}
				total = res.data.total;
				offset = 0;
				setStatus( H2MD.i18n.found + ' ' + total + ' · ' + res.data.dir );
				$( '#h2md-build' ).prop( 'disabled', false );
				$( '#h2md-export' ).prop( 'disabled', total === 0 );
			} )
			.fail( function () {
				setStatus( H2MD.i18n.error + ' AJAX', true );
				$( '#h2md-build' ).prop( 'disabled', false );
			} );
	}

	function processBatch() {
		post( 'h2md_process_batch', { offset: offset } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					setStatus( H2MD.i18n.error + ' ' + ( res && res.data ? res.data.message : '' ), true );
					$( '#h2md-build, #h2md-export' ).prop( 'disabled', false );
					return;
				}
				offset = res.data.processed;
				setProgress( res.data.processed, res.data.total );
				appendLog( res.data.log );

				if ( res.data.completed ) {
					finish();
				} else {
					processBatch();
				}
			} )
			.fail( function () {
				setStatus( H2MD.i18n.error + ' AJAX', true );
				$( '#h2md-build, #h2md-export' ).prop( 'disabled', false );
			} );
	}

	function finish() {
		post( 'h2md_finish' )
			.done( function ( res ) {
				if ( res && res.success ) {
					setStatus( H2MD.i18n.done + ' ' + res.data.pages );
				}
				$( '#h2md-build, #h2md-export' ).prop( 'disabled', false );
			} )
			.fail( function () {
				$( '#h2md-build, #h2md-export' ).prop( 'disabled', false );
			} );
	}

	function startExport() {
		if ( ! total ) {
			return;
		}
		setStatus( H2MD.i18n.exporting );
		$( '#h2md-build, #h2md-export' ).prop( 'disabled', true );
		offset = 0;
		setProgress( 0, total );
		processBatch();
	}

	$( function () {
		$( '#h2md-build' ).on( 'click', buildQueue );
		$( '#h2md-export' ).on( 'click', startExport );
	} );
} )( jQuery );
