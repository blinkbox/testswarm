<?php
/**
 * "Result" action.
 *
 * @author Timo Tijhof, 2012
 * @since 1.0.0
 * @package TestSwarm
 */
class ResultAction extends Action {
	// Currently being run in a client
	public static $STATE_BUSY = 1;

	// Run by the client is finished, results
	// have been submitted.
	public static $STATE_FINISHED = 2;

	// Run by the client was aborted by the client.
	// Either the test (inject.js) lost pulse internally
	// and submitted a partial result, or the test runner (run.js)
	// aborted the test after conf.client.runTimeout.
	public static $STATE_ABORTED = 3;

	// Client did not submit results, and from CleanAction it
	// was determined that the client died (no longer sends pings).
	public static $STATE_LOST = 4;
	
	// Client has hit the heartbeat timeout and all the results were submitted.
	public static $STATE_HEARTBEAT = 5;

	/**
	 * @actionParam int item: Runresults ID.
	 */
	public function doAction() {
		$context = $this->getContext();
		$db = $context->getDB();
		$conf = $context->getConf();
		$request = $context->getRequest();

		$item = $request->getInt( 'item' );
		$row = $db->getRow(str_queryf(
			'SELECT
				id,
				run_id,
				client_id,
				status,
				error,
				total,
				fail,
				updated,
				created,
				report_html_size,
				LENGTH( report_html ) as \'compressed_size\'
			FROM runresults
			WHERE id = %u;',
			$item
		));

		if ( !$row ) {
			$this->setError( 'invalid-input', 'Runresults ID not found.' );
			return;
		}

		$data = array();

		// A job can be deleted without nuking the runresults,
		// this is by design so results stay permanently accessible
		// under a simple url.
		// If the job is no longer in existance, properties
		// 'otherRuns' and 'job' will be set to null.
		$runRow = $db->getRow(str_queryf(
			'SELECT
				id,
				url,
				name,
				job_id
			FROM runs
			WHERE id = %u;',
			$row->run_id
		));

		if ( !$runRow ) {
			$data['otherRuns'] = null;
			$data['job'] = null;
		} else {
			$data['otherRuns'] = JobAction::getDataFromRunRows( $context, array( $runRow ) );

			$jobID = intval( $runRow->job_id );

			$data['job'] = array(
				'id' => $jobID,
				'url' => swarmpath( "job/$jobID", "fullurl" ),
			);
		}

		$clientRow = $db->getRow(str_queryf(
			'SELECT
				id,
				name,
				useragent_id,
				useragent,
				device_name
			FROM clients
			WHERE id = %u;',
			$row->client_id
		));

		$data['info'] = array(
			'id' => intval( $row->id ),
			'runID' => intval( $row->run_id ),
			'clientID' => intval( $row->client_id ),
			'status' => self::getStatus( $row->status ),
		);

		$data['client'] = array(
			'id' => $clientRow->id,
			'name' => $clientRow->name,
			'uaID' => $clientRow->useragent_id,
			'uaRaw' => $clientRow->useragent,
			'viewUrl' => swarmpath( 'client/' . $clientRow->id ),
			'deviceName' => $clientRow->device_name,
		);

		// MERGE ISSUE - IS THIS NO LONGER REQUIRED?
		$data['resultInfo'] = array(
			'id' => $resultsID,
			'runID' => $row->run_id,
			'fail' => $row->fail,
			'total' => $row->total,
			'error' => $row->error,
			'clientID' => $row->client_id,
			'status' => self::getStatus( $row->status ),
			'reportHtmlSize' => $row->report_html_size,
			'reportHtmlCompressedSize' => $row->compressed_size,
			'reportHtmlCompressionRatio' => $row->report_html_size == 0 ? 0 : round( ($row->report_html_size - $row->compressed_size) / $row->report_html_size * 100 / 1, 2 )
		);

		// If still busy or if the client was lost, then the last update time is irrelevant
		// Alternatively this could test if $row->updated == $row->created, which would effectively
		// do the same.
		if ( $row->status == self::$STATE_BUSY || $row->status == self::$STATE_LOST ) {
			$data['info']['runTime'] = null;
		} else {
			$data['info']['runTime'] = gmstrtotime( $row->updated ) - gmstrtotime( $row->created );
			self::addTimestampsTo( $data['info'], $row->updated, 'saved' );
		}
		self::addTimestampsTo( $data['info'], $row->created, 'started' );

		$this->setData( $data );
	}

	public static function getStatus( $statusId ) {
		$mapping = array();
		$mapping[self::$STATE_BUSY] = 'Busy';
		$mapping[self::$STATE_FINISHED] = 'Finished';
		$mapping[self::$STATE_ABORTED] = 'Aborted';
		$mapping[self::$STATE_LOST] = 'Client lost';
		$mapping[self::$STATE_HEARTBEAT] = 'Timed-out (heartbeat)';

		return isset( $mapping[$statusId] )
			? $mapping[$statusId]
			: false;
	}
}
