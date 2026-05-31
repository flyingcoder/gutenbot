/* global jQuery, gutenbotData */
jQuery( function ( $ ) {

	// ── Scan ──────────────────────────────────────────────────────────────────
	$( '#gutenbot-scan-btn' ).on( 'click', async function () {
		const $btn    = $( this ).prop( 'disabled', true ).text( 'Scanning…' );
		const $bar    = $( '#gutenbot-scan-progress' ).show();
		const $label  = $( '#gutenbot-scan-label' ).show();
		const $status = $( '#gutenbot-scan-status' );

		const init = await $.post( gutenbotData.ajaxurl, {
			action:   'gutenbot_scan_init',
			_wpnonce: gutenbotData.scanNonce,
		} );

		if ( ! init.success ) {
			$label.text( 'Error: ' + init.data.message );
			$btn.prop( 'disabled', false ).text( 'Scan Site' );
			return;
		}

		let { total, remaining } = init.data;
		$bar.attr( 'max', total || 1 ).attr( 'value', 0 );

		while ( remaining > 0 ) {
			const step = await $.post( gutenbotData.ajaxurl, {
				action:   'gutenbot_scan_page',
				_wpnonce: gutenbotData.scanNonce,
			} );

			if ( ! step.success ) {
				$label.text( 'Error: ' + step.data.message );
				$btn.prop( 'disabled', false ).text( 'Scan Site' );
				return;
			}

			remaining = step.data.remaining;
			$bar.attr( 'value', total - remaining );
			$label.text( 'Scanning ' + ( total - remaining ) + ' of ' + total + ' — ' + step.data.post_title );
		}

		const fin = await $.post( gutenbotData.ajaxurl, {
			action:   'gutenbot_scan_finalize',
			_wpnonce: gutenbotData.scanNonce,
		} );

		if ( fin.success ) {
			$status
				.text( 'Complete' )
				.removeClass( 'notice-warning notice-error notice-info' )
				.addClass( 'notice-success' );
			$( '#gutenbot-scanned-at' ).text( fin.data.scanned_at );
			$label.text( 'Scan complete.' );
		} else {
			$label.text( 'Finalize error: ' + fin.data.message );
		}

		$btn.prop( 'disabled', false ).text( 'Scan Site' );
	} );

	// ── Sync ──────────────────────────────────────────────────────────────────
	$( '#gutenbot-sync-btn' ).on( 'click', function () {
		const $btn    = $( this ).prop( 'disabled', true ).text( 'Syncing…' );
		const $status = $( '#gutenbot-sync-status' );

		$.post( gutenbotData.ajaxurl, {
			action:   'gutenbot_sync_supabase',
			_wpnonce: gutenbotData.syncNonce,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$status
					.text( 'Complete' )
					.removeClass( 'notice-warning notice-error' )
					.addClass( 'notice-success' );
				$( '#gutenbot-synced-at' ).text( res.data.synced_at );
			} else {
				$status
					.text( 'Error: ' + res.data.message )
					.removeClass( 'notice-success notice-warning' )
					.addClass( 'notice-error' );
			}
		} )
		.fail( function () {
			$status.text( 'Request failed.' ).addClass( 'notice-error' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( 'Sync to Supabase' );
		} );
	} );

} );
