/**
 * Translate tab: pick target language, select rows, preview cost, translate.
 *
 * Bulk translate is a client-side loop over the single-post AJAX endpoint —
 * one post per request, sequential, not parallel. Each Gemini call stays
 * small, the user gets a live per-row progress log, and one post's API
 * error doesn't abort the rest of the batch. Rows flip to "Review draft"
 * in place as each finishes.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		// Language selector navigates (server re-renders statuses per language).
		$( '#fatw-lang-select' ).on( 'change', function () {
			window.location.href = $( this ).val();
		} );

		var $table = $( '#fatw-table' );
		if ( ! $table.length ) {
			return; // Not on the Translate tab.
		}

		var $previewBtn = $( '#fatw-preview-btn' );
		var $translateBtn = $( '#fatw-translate-btn' );
		var $selectedCount = $( '#fatw-selected-count' );
		var $previewResult = $( '#fatw-preview-result' );
		var $previewRows = $( '#fatw-preview-rows' );
		var $progress = $( '#fatw-progress' );
		var $progressFill = $( '#fatw-progress-fill' );
		var $progressLog = $( '#fatw-progress-log' );

		// ------------------------------------------------------- selection

		function selectable() {
			return $table.find( '.fatw-check:not(:disabled)' );
		}

		function selected() {
			var items = [];
			$table.find( '.fatw-check:checked:not(:disabled)' ).each( function () {
				var $row = $( this ).closest( 'tr' );
				items.push( { id: $( this ).val(), label: $row.find( 'strong' ).first().text() } );
			} );
			return items;
		}

		function refreshCount() {
			var n = selected().length;
			$selectedCount.text( n + ' selected' );
			$translateBtn.prop( 'disabled', 0 === n );
		}

		$( '#fatw-select-all' ).on( 'click', function ( e ) {
			e.preventDefault();
			selectable().filter( function () {
				return $( this ).closest( 'tr' ).is( ':visible' );
			} ).prop( 'checked', true );
			refreshCount();
		} );

		$( '#fatw-select-none' ).on( 'click', function ( e ) {
			e.preventDefault();
			selectable().prop( 'checked', false );
			$( '#fatw-check-all' ).prop( 'checked', false );
			refreshCount();
		} );

		$( '#fatw-check-all' ).on( 'change', function () {
			selectable().filter( function () {
				return $( this ).closest( 'tr' ).is( ':visible' );
			} ).prop( 'checked', this.checked );
			refreshCount();
		} );

		$table.on( 'change', '.fatw-check', refreshCount );

		// ---------------------------------------------------- title filter

		$( '#fatw-filter' ).on( 'input', function () {
			var q = $( this ).val().toLowerCase();
			$table.find( 'tbody tr' ).each( function () {
				var title = $( this ).data( 'title' ) || '';
				$( this ).toggle( '' === q || -1 !== String( title ).indexOf( q ) );
			} );
		} );

		// ------------------------------------------------------------ ajax

		function call( action, postId ) {
			return $.post( fatwData.ajaxUrl, {
				action: action,
				nonce: fatwData.nonce,
				post_id: postId,
				lang: fatwData.lang
			} );
		}

		// --------------------------------------------------------- preview

		$previewBtn.on( 'click', function () {
			var items = selected();
			if ( ! items.length ) {
				window.alert( 'Select at least one item first.' );
				return;
			}

			$previewBtn.prop( 'disabled', true ).text( 'Checking…' );
			$previewRows.empty();
			$previewResult.hide();

			var totals = { segments: 0, words: 0, cost: 0 };
			var chain = $.Deferred().resolve();

			items.forEach( function ( item ) {
				chain = chain.then( function () {
					return call( 'fatw_preview', item.id ).then(
						function ( res ) {
							var $tr = $( '<tr>' );
							$tr.append( $( '<td>' ).text( item.label ) );
							if ( res.success ) {
								totals.segments += res.data.string_count;
								totals.words += res.data.word_count;
								totals.cost += res.data.cost_usd;
								$tr.append( $( '<td>' ).text( res.data.string_count ) )
									.append( $( '<td>' ).text( res.data.word_count ) )
									.append( $( '<td>' ).text( '$' + res.data.cost_usd.toFixed( 4 ) ) );
							} else {
								$tr.append( $( '<td colspan="3">' ).text( ( res.data && res.data.message ) || 'error' ) );
							}
							$previewRows.append( $tr );
						},
						function () {
							$previewRows.append(
								$( '<tr>' ).append( $( '<td>' ).text( item.label ) ).append( $( '<td colspan="3">' ).text( 'request failed' ) )
							);
						}
					);
				} );
			} );

			chain.always( function () {
				$( '#fatw-total-segments' ).text( totals.segments );
				$( '#fatw-total-words' ).text( totals.words );
				$( '#fatw-total-cost' ).text( '$' + totals.cost.toFixed( 4 ) );
				$previewResult.show();
				$previewBtn.prop( 'disabled', false ).text( 'Preview Cost' );
			} );
		} );

		// ------------------------------------------------------- translate

		function markRowDone( postId, editUrl, previewUrl ) {
			var $row = $table.find( 'tr[data-post-id="' + postId + '"]' );
			$row.find( '.fatw-check' ).prop( { checked: false, disabled: true } );
			var $cell = $row.find( '.fatw-col-status' ).empty()
				.append( $( '<span class="fatw-badge is-draft">' ).text( 'Draft awaiting review' ) )
				.append( ' ' );
			if ( previewUrl ) {
				$cell.append( $( '<a>' ).attr( { href: previewUrl, target: '_blank', rel: 'noopener' } ).text( 'Preview' ) )
					.append( ' · ' );
			}
			$cell.append( $( '<a>' ).attr( 'href', editUrl ).text( 'Edit' ) );
		}

		// ------------------------------------------------- donate milestone

		function showDonatePopup( lifetime ) {
			if ( $( '#fatw-donate-overlay' ).length || ! fatwData.donateUrl ) {
				return;
			}
			var $overlay = $( '<div id="fatw-donate-overlay">' );
			var $box = $( '<div class="fatw-donate-popup">' )
				.append( $( '<h2>' ).text( lifetime + ' pages translated!' ) )
				.append( $( '<p>' ).text( 'Free AI Translator for WPML has now translated ' + lifetime + ' pages for you at raw API cost — no credit markup. If it\u2019s saving you real money, a small donation keeps it free and maintained.' ) )
				.append(
					$( '<p class="fatw-donate-popup-actions">' )
						.append( $( '<a class="button button-primary">' ).attr( { href: fatwData.donateUrl, target: '_blank', rel: 'noopener' } ).text( 'Buy me a coffee' ) )
						.append( ' ' )
						.append( $( '<button type="button" class="button">' ).text( 'Maybe later' ).on( 'click', function () {
							$overlay.remove();
						} ) )
				);
			$overlay.append( $box ).on( 'click', function ( e ) {
				if ( e.target === this ) {
					$overlay.remove();
				}
			} );
			$( 'body' ).append( $overlay );
		}

		$translateBtn.on( 'click', function () {
			var items = selected();
			if ( ! items.length ) {
				return;
			}
			var langName = fatwData.langName || 'the selected language';
			if ( ! window.confirm( 'Translate ' + items.length + ' item(s) to ' + langName + '? Each creates a draft for review; API cost applies.' ) ) {
				return;
			}

			$translateBtn.prop( 'disabled', true ).text( 'Translating…' );
			$previewBtn.prop( 'disabled', true );
			$progressLog.empty();
			$progressFill.css( 'width', '0%' );
			$progress.show();

			var done = 0;
			var ok = 0;
			var milestone = 0; // Highest lifetime count that crossed a multiple of 5 in this batch.
			var chain = $.Deferred().resolve();

			items.forEach( function ( item ) {
				chain = chain.then( function () {
					var $li = $( '<li>' ).text( 'Translating: ' + item.label + ' …' );
					$progressLog.append( $li );
					return call( 'fatw_translate', item.id ).then(
						function ( res ) {
							done++;
							$progressFill.css( 'width', Math.round( ( done / items.length ) * 100 ) + '%' );
							if ( res.success ) {
								ok++;
								if ( res.data.lifetime && 0 === res.data.lifetime % 5 ) {
									milestone = res.data.lifetime;
								}
								$li.text( '✓ ' + item.label + ' — ' + res.data.message + ' ' );
								if ( res.data.preview_url ) {
									$li.append( $( '<a>' ).attr( { href: res.data.preview_url, target: '_blank', rel: 'noopener' } ).text( 'Preview' ) )
										.append( ' · ' );
								}
								$li.append( $( '<a>' ).attr( 'href', res.data.edit_url ).text( 'Edit' ) );
								markRowDone( item.id, res.data.edit_url, res.data.preview_url );
							} else {
								$li.text( '✗ ' + item.label + ' — ' + ( ( res.data && res.data.message ) || 'failed' ) ).addClass( 'is-error' );
							}
						},
						function () {
							done++;
							$progressFill.css( 'width', Math.round( ( done / items.length ) * 100 ) + '%' );
							$li.text( '✗ ' + item.label + ' — request failed' ).addClass( 'is-error' );
						}
					);
				} );
			} );

			chain.always( function () {
				$progressLog.append( $( '<li class="is-summary">' ).text( 'Done — ' + ok + ' of ' + items.length + ' translated.' ) );
				$translateBtn.text( 'Translate Selected' );
				$previewBtn.prop( 'disabled', false );
				refreshCount();
				if ( milestone > 0 ) {
					showDonatePopup( milestone );
				}
			} );
		} );

		refreshCount();
	} );
} )( jQuery );
