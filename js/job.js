/**
 * JavaScript file for the "job" page in the browser.
 *
 * @author John Resig, 2008-2011
 * @since 0.1.0
 * @package TestSwarm
 */
jQuery(function ( $ ) {
	var updateInterval = SWARM.conf.web.ajaxUpdateInterval * 1000,
		$wipejobErr = $( '#swarm-wipejob-error' ),
		$addbrowserstojobErr = $( '#swarm-addbrowserstojob-error' ),
		refreshTableTimout, $indicator;

	$indicator = $( '<span class="btn pull-right disabled">updating <i class="icon-refresh"></i></span>' ).css( 'opacity', 0 );

	function refreshTable() {
		if ( refreshTableTimout ) {
			clearTimeout( refreshTableTimout );
		}
		$indicator.stop(true, true).css( 'opacity', 1 );
		$.get( window.location.href )
		.done( function ( html ) {
			var tableHtml, $targetTable;

			tableHtml = $( html ).find( 'table.swarm-results' ).html();
			$targetTable = $( 'table.swarm-results' );
			if ( tableHtml !== $targetTable.html() ) {
				$targetTable.html( tableHtml );
			}
		})
		.complete( function () {
			// Wether done or failed: Clean up and schedule next update
			setTimeout( function () {
				$indicator.stop(true, true).animate({opacity: 0});
			}, 10 );

			refreshTableTimout = setTimeout( refreshTable, updateInterval );
		});
	}

	refreshTableTimout = setTimeout( refreshTable, updateInterval );

	$( 'table.swarm-results' ).prev().before( $indicator );

	$( document ).on( 'dblclick', 'table.swarm-results td', function () {
		var $el;
		$el = $( this );
		if ( $el.data( 'runStatus' ) !== 'new' ) {
			$.ajax({
				url: SWARM.conf.web.contextpath + 'api.php',
				type: 'POST',
				data: {
					action: 'wiperun',
					job_id: $el.data( 'jobId' ),
					run_id: $el.data( 'runId' ),
					client_id: $el.data( 'clientId' ),
					useragent_id: $el.data( 'useragentId' )
				},
				dataType: 'json',
				success: function ( data ) {
					if ( data.wiperun && data.wiperun.result === 'ok' ) {
						$el.empty().attr( 'class', 'swarm-status swarm-status-new' );
						refreshTable();
					}
				}
			});
		}
	});

	function wipejobFail( data ) {
		$wipejobErr.hide().text( data.error && data.error.info || 'Action failed.' ).slideDown();
	}

	function addbrowserstojobFail( data ) {
		$addbrowserstojobErr.hide().text( data.error && data.error.info || 'Action failed.' ).slideDown();
	}

	function wipeClick( type, success ) {
		$wipejobErr.hide();
		$.ajax({
			url: SWARM.conf.web.contextpath + 'api.php',
			type: 'POST',
			data: {
				action: 'wipejob',
				job_id: SWARM.jobInfo.id,
				type: type
			},
			dataType: 'json',
			success: success,
			error: wipejobFail
		});	
	}

	$( '#swarm-add-browsers' ).click( function () {

		$addbrowserstojobErr.hide();

		var jobData = {
			action: 'addbrowserstojob',
			job_id: SWARM.jobInfo.id
		};

		var formData = $('.swarm-add-browsers-form').serialize();
		$('.swarm-add-browsers-form')[0].reset();

		var combinedData = formData + '&' + $.param(jobData);

		$.ajax({
			url: SWARM.conf.web.contextpath + 'api.php',
			type: 'POST',
			data: combinedData,
			dataType: 'json',
			success: function ( data ) {
				if ( data.addbrowserstojob && data.addbrowserstojob.result === 'ok' ) {
					refreshTable();
					return;
				}
				addbrowserstojobFail( data );
			},
			error: function ( error ) {
				addbrowserstojobFail( error );
			}
		});
	});

	$( '#swarm-job-delete' ).click( function () { 
		wipeClick( 'delete', function ( data ) {
			if ( data.wipejob && data.wipejob.result === 'ok' ) {
				// Right now the only user authorized to delete a job is the creator,
				// the below code makes that assumption.
				window.location.href = SWARM.conf.web.contextpath + 'user/' + SWARM.session.username;
				return;
			}
			wipejobFail( data );
		})
	});

	$( '#swarm-job-reset' ).click( function () { 
		wipeClick( 'reset', function ( data ) {
			if ( data.wipejob && data.wipejob.result === 'ok' ) {
				refreshTable();
				return;
			}
			wipejobFail( data );
		}) 
	});

	$( '#swarm-job-cancel' ).click( function () {
		wipeClick( 'cancel', function ( data ) {
			if ( data.wipejob && data.wipejob.result === 'ok' ) {
				refreshTable();
				return;
			}
			wipejobFail( data );
		}) 
	});
});
