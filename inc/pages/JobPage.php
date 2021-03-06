<?php
/**
 * "Job" page.
 *
 * @author John Resig, 2008-2011
 * @author Jörn Zaefferer, 2012
 * @since 0.1.0
 * @package TestSwarm
 */

class JobPage extends Page {

	public function execute() {
		$context = $this->getContext();
		$request = $context->getRequest();
		$item = $request->getInt( 'item' );

		$action = JobAction::newFromContext( $context->createDerivedRequestContext(
			array(
				'runs' => true,
				'item' => $item,
			)
		) );
		$action->doAction();

		$this->setAction( $action );
		$this->content = $this->initContent();
	}

	protected function initContent() {
		$request = $this->getContext()->getRequest();
		$auth = $this->getContext()->getAuth();

		$this->setTitle( "Job status" );
		$this->setRobots( "noindex,nofollow" );
		$this->bodyScripts[] = swarmpath( "js/job.js" );
		$this->bodyScripts[] = swarmpath( "js/tooltip.js" );

		$error = $this->getAction()->getError();
		$data = $this->getAction()->getData();
		$html = '';

		if ( $error ) {
			$html .= html_tag( 'div', array( 'class' => 'alert alert-error' ), $error['info'] );
		}

		if ( !isset( $data["info"] ) ) {
			return $html;
		}

		$this->setSubTitle( '#' . $data["info"]["id"] );

		$project = $data['info']['project'];
		$isOwner = $auth && $auth->project->id === $project['id'];

		$html .=
			'<h2>' . $data["info"]["nameHtml"] .'</h2>'
			. '<p><em>Submitted by '
			. html_tag( 'a', array( 'href' => $project['viewUrl'] ), $project['display_title'] )
			. ' '. self::getPrettyDateHtml( $data["info"], 'created' )
			. '</em>.</p>';

		if ( $isOwner ) {
			$html .= '<script>SWARM.jobInfo = ' . json_encode( $data["info"] ) . ';</script>';
			$action_bar = ' <div class="form-actions swarm-item-actions">'
					. ' <div class="pull-right">'
						. ' <button type="button" data-toggle="modal" data-target="#addbrowserstojobModal" class="btn btn-success">Add browsers</button>'
						. ' <div class="btn-group">'
							. ' <button class="btn btn-info dropdown-toggle" data-toggle="dropdown">Reset <span class="caret"></span></button>'
							. ' <ul class="dropdown-menu">'
								. ' <li><a href="#" class="swarm-reset-runs">All</a></li>'
								. ' <li><a href="#" class="swarm-reset-runs-failed">Failed</a></li>'
								. ' <li><a href="#" class="swarm-reset-runs-suspended">Suspended</a></li>'
							. ' </ul>'
						. ' </div>'
						. ' <button class="swarm-suspend-runs btn btn-warning">Suspend job</button>'
						. ' <button class="swarm-delete-job btn btn-danger">Delete job</button>'
					. ' </div>'
				. ' </div>'
				. ' <div id="addbrowserstojobModal" class="modal hide fade" tabindex="-1" role="dialog">'
					. ' <div class="modal-header">'
						. ' <button type="button" class="close" data-dismiss="modal">×</button>'
						. ' <h3>Add browsers</h3>'
					. ' </div>'
					. ' <div class="modal-body">'
						. $this->getAddbrowserstojobFormHtml()
					. ' </div>'
					. ' <div class="modal-footer">'
						. ' <button class="btn" data-dismiss="modal">Close</button>'
						. ' <button class="swarm-add-browsers btn btn-primary" data-dismiss="modal">Add</button>'
					. ' </div>'
				. ' </div>'
				. ' <div class="alert alert-error swarm-wipejob-error" style="display: none;"></div>'
				. ' <div class="alert alert-error swarm-addbrowserstojob-error" style="display: none;"></div>';
		} else {
			$action_bar = '';
		}

		$html .= $action_bar;
		$html .= '<table class="table table-bordered swarm-results"><thead>'
			. self::getUaHtmlHeader( $data['userAgents'], $isOwner )
			. '</thead><tbody>'
			. self::getUaSummaryHtmlRow( $data['uaSummaries'], $data['userAgents'] )
			. self::getUaRunsHtmlRows( $data['runs'], $data['userAgents'], $isOwner )
			. '</tbody></table>';

		$html .= $action_bar;

		return $html;
	}

	protected function getAddbrowserstojobFormHtml(){
		$conf = $this->getContext()->getConf();
		$browserIndex = BrowserInfo::getBrowserIndex();

		$formHtml = <<<HTML
<form class="form-horizontal swarm-add-browsers-form">

	<fieldset>
		<legend>Job information</legend>


		<div class="control-group">
			<label class="control-label" for="form-runMax">Run max:</label>
			<div class="controls">
				<input type="number" name="runMax" required min="1" max="99" value="2" id="form-runMax" size="5">
				<p class="help-block">This is the maximum number of times a run is ran in a user agent. If a run passes
				without failures then it is only ran once. If it does not pass, TestSwarm will re-try the run
				(up to "Run max" times) for that useragent to avoid error pollution due to time-outs, slow
				computers or other unrelated conditions that can cause the server to not receive a success report.</p>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend>Browsers</legend>

		<p>Choose which groups of user agents this job should be ran in. Some of the groups may
		overlap each other, TestSwarm will detect and remove duplicate entries in the resulting set.</p>

HTML;
		foreach ( $conf->browserSets as $browserSet => $browsers ) {
			$set = htmlspecialchars( $browserSet );
			$browsersHtml = '';
			$last = count( $browsers ) -1;
			foreach ( $browsers as $i => $uaID ) {
				$uaData = $browserIndex->$uaID;
				if ( $i === 0 ) {
					$browsersHtml .= '<br>';
				} elseif ( $i === $last ) {
					$browsersHtml .= '<br> and ';
				} else {
					$browsersHtml .= ',<br>';
				}
				$browsersHtml .= htmlspecialchars( $uaData->displayInfo['title'] );
			}
			$formHtml .= <<<HTML
		<div class="control-group">
			<label class="checkbox" for="form-browserset-$set">
				<input type="checkbox" name="browserSets[]" value="$set" id="form-browserset-$set">
				<strong>$set</strong>: $browsersHtml.
			</label>
		</div>
HTML;
		}

		$formHtml .= <<<HTML
	</fieldset>
</form>
HTML;

		return $formHtml;
	}

	/**
	 * Create a table header for user agents.
	 */
	public static function getUaHtmlHeader( $userAgents, $showButtons = false ) {

		$html = '<tr><th>&nbsp;</th>';
		foreach ( $userAgents as $uaID => $userAgent ) {
			$displayInfo = $userAgent['displayInfo'];
			$html .= html_tag_open( 'th', array(
					'data-useragent-id' => $uaID
				) )
				. html_tag( 'div', array(
					'class' => $displayInfo['class'] . ' swarm-icon-small',
					'title' => $displayInfo['title']
				) )
				. ' <br>'
				. html_tag_open( 'span', array(
					'class' => 'label swarm-browsername',
				) ) . $displayInfo['labelHtml'] . '</span><br>';


			if( $showButtons ){
				$html .= ' <br>'
					. html_tag_open( 'div', array(
						'class' => 'swarm-browsermenu',
					) )
						. ' <div class="btn-group btn-block">'
						. ' <button class="btn btn-block btn-info dropdown-toggle" data-toggle="dropdown"><span class="icon-th-list icon-white"></button>'
							. ' <ul class="dropdown-menu">'
								. ' <li><a href="#" class="swarm-reset-browser-runs">Reset All</a></li>'
								. ' <li><a href="#" class="swarm-reset-browser-runs-failed">Reset Failed</a></li>'
								. ' <li><a href="#" class="swarm-reset-browser-runs-suspended">Reset Suspended</a></li>'
								. ' <li><a href="#" class="swarm-suspend-browser-runs">Suspend</a></li>'
								. ' <li><a href="#" class="swarm-delete-browser-runs">Delete</a></li>'
							. ' </ul>'
						. ' </div>'
					. ' </div>';
			}

			$html .= ' </th>';
		}

		$html .= '</tr>';
		return $html;
	}

	/**
	 * Create a table header for user agents.
	 */
	public static function getUaSummaryHtmlRow( $uaSummaries, $userAgents ) {

		$html = '<tr>'
			. html_tag_open( 'td', array(
				'class' => 'swarm-progress-cell'
			) )
			. '</td>';

		foreach ( $userAgents as $uaID => $userAgent ) {
			$html .= html_tag_open( 'td', array(
				'class' => 'swarm-progress-cell'
			) )
			. html_tag_open( 'div', array(
				'class' => 'progress swarm-progress'
			));

			$total = $uaSummaries[$uaID]['total'];
			foreach ( $uaSummaries[$uaID]['counts'] as $status => $count ) {

				$percentOfJob = ( $count / $total ) * 100;

				$html .= html_tag( 'div', array(
					'class' => 'bar swarm-status-' . $status,
					'style' => 'width: ' . $percentOfJob . '%'
				));

			}

			$html .= ' </div>'
				. ' </td>';
		}

		$html .= '</tr>';
		return $html;
	}

	/**
	 * Create table rows for a table of ua run results.
	 * This is used on the JobPage.
	 *
	 * @param Array $runs List of runs, from JobAction.
	 * @param Array $userAgents List of uaData objects.
	 * @param bool $showResetRun: Whether to show the reset buttons for individual runs.
	 *  This does not check authororisation or load related javascript for the buttons.
	 */
	public static function getUaRunsHtmlRows( $runs, $userAgents, $showResetRun = false ) {
		$html = '';

		foreach ( $runs as $run ) {
			$html .= '<tr><th class="swarm-label"><a href="' . htmlspecialchars( $run['info']['url'] ) . '" data-toggle="tooltip" title="' . htmlspecialchars( $run['info']['name'] ) . '">'
				. htmlspecialchars( $run['info']['name'] ) . '</a></th>';

			// Looping over $userAgents instead of $run["uaRuns"],
			// to avoid shifts in the table (github.com/jquery/testswarm/issues/13)
			foreach ( $userAgents as $uaID => $uaInfo ) {
				if ( isset( $run['uaRuns'][$uaID] ) ) {
					$uaRun = $run['uaRuns'][$uaID];
					$html .= html_tag_open( 'td', array(
						'class' => 'swarm-status swarm-status-' . $uaRun['runStatus'],
						'data-run-id' => $run['info']['id'],
						'data-run-status' => $uaRun['runStatus'],
						'data-useragent-id' => $uaID,
						// Un-ran tests don't have a client id
						'data-client-id' => isset( $uaRun['clientID'] ) ? $uaRun['clientID'] : '',
					));
					if ( isset( $uaRun['runResultsUrl'] ) && isset( $uaRun['runResultsLabel'] ) ) {
						$title = $userAgents[$uaID]['displayInfo']['title'];
						$runResultsTooltip = "Open run results for $title";
						$runResultsTagOpen = html_tag_open( 'a', array(
							'rel' => 'nofollow',
							'href' => $uaRun['runResultsUrl'],
							'title' => $runResultsTooltip,
						) );
						$html .=
							$runResultsTagOpen
							. ( $uaRun['runResultsLabel']
								? $uaRun['runResultsLabel']
								: self::getStatusIconHtml( $uaRun['runStatus'] )
							). '</a>'
							. $runResultsTagOpen
							. html_tag( 'i', array(
								'class' => 'swarm-show-results icon-list-alt pull-right',
								'title' => $runResultsTooltip,
							) )
							. '</a>'
							. ( $showResetRun ?
								html_tag( 'i', array(
									'class' => 'swarm-reset-run-single icon-remove-circle pull-right',
									'title' => "Re-schedule run for $title",
								) )
								: ''
							);
					} else {
						$html .= self::getStatusIconHtml( $uaRun['runStatus'] );
					}
					$html .= '</td>';
				} else {
					// This run isn't schedules to be ran in this UA
					$html .= '<td class="swarm-status swarm-status-notscheduled"></td>';
				}
			}
		}

		return $html;
	}

	public static function getStatusIconHtml( $status ) {
		static $icons = array(
			"new" => '<i class="icon-time" title="Scheduled, awaiting run."></i>',
			"progress" => '<i class="icon-repeat swarm-status-progressicon" title="In progress.."></i>',
			"suspended" => '<i class="icon-minus-sign" title="Suspended"></i>',
			"passed" => '<i class="icon-ok" title="Passed!"></i>',
			"failed" => '<i class="icon-remove" title="Completed with failures"></i>',
			"timedout" => '<i class="icon-flag" title="Maximum execution time exceeded"></i>',
			"heartbeat" => '<i class="icon-heart" title="Heartbeat caused result submission"></i>',
			"error" => '<i class="icon-warning-sign" title="Aborted by an error"></i>',
			"lost" => '<i class="icon-question-sign" title="Client lost connection with the swarm"></i>',
		);
		return isset( $icons[$status] ) ? $icons[$status] : '';
	}

	/**
	 * Not used anywhere yet. The colors, icons and tooltips should be
	 * easy to understand. If not, this table is ready for use.
	 * @example:
	 *     '<div class="row"><div class="span6">' . getStatusLegend() . '</div></div>'
	 */
	public static function getStatusLegend() {
		return
			'<table class="table table-condensed table-bordered swarm-results">'
			. '<tbody>'
			. '<tr><td class="swarm-status swarm-status-new">'
				. self::getStatusIconHtml( "new" )
				. '</td><td>Scheduled</td>'
			. '</tr>'
			. '<tr><td class="swarm-status swarm-status-progress">'
				. self::getStatusIconHtml( "progress" )
				. '</td><td>In progress..</td>'
			. '</tr>'
			. '<tr><td class="swarm-status swarm-status-passed">'
				. self::getStatusIconHtml( "passed" )
				. '</td><td>Passed!</td>'
			. '</tr>'
			. '<tr><td class="swarm-status swarm-status-failed">'
				. self::getStatusIconHtml( "failed" )
				. '</td><td>Completed with failures</td>'
			. '</tr>'
			. '<tr><td class="swarm-status swarm-status-timedout">'
			. self::getStatusIconHtml( "timedout" )
			. '</td><td>Maximum execution time exceeded</td>'
			. '</tr>'
			. '<tr><td class="swarm-status swarm-status-heartbeat">'
			. self::getStatusIconHtml( "heartbeat" )
			. '</td><td>Heartbeat caused result submission</td>'
			. '</tr>'
			. '<tr><td class="swarm-status swarm-status-suspended">'
				. self::getStatusIconHtml( "suspended" )
				. '</td><td>Suspended</td>'
			. '</tr>'
			. '<tr><td class="swarm-status swarm-status-error">'
				. self::getStatusIconHtml( "error" )
				. '</td><td>Aborted by an error</td>'
			. '</tr>'
			. '<tr><td class="swarm-status swarm-status-lost">'
				. self::getStatusIconHtml( "lost" )
				. '</td><td>Client lost connection with the swarm</td>'
			. '</tr>'
			. '<tr><td class="swarm-status swarm-status-notscheduled">'
				. ''
				. '</td><td>This browser was not part of the browserset for this job.</td>'
			. '</tr>'
			. '</tbody></table>';
	}

	/**
	 * Create a single row summarising the ua runs of a job. See also #getUaRunsHtmlRows.
	 * This is used on the ProjectPage.
	 * @param Array $job
	 * @param Array $userAgents List of uaData objects.
	 */
	public static function getJobHtmlRow( $job, $userAgents ) {
		$html = '<tr><th class="swarm-label">'
			. '<a href="' . htmlspecialchars( $job['info']['viewUrl'] ) . '" data-toggle="tooltip" title="' . htmlspecialchars( $job['info']['nameText'] ) . '">' . htmlspecialchars( $job['info']['nameText'] ) . '</a>'
			. ' '
			. self::getPrettyDateHtml( $job['info'], 'created', array( 'class' => 'swarm-result-date' ) )
			. "</th>\n";

		foreach ( $userAgents as $uaID => $uaData ) {
			$html .= self::getJobStatusHtmlCell( isset( $job['summaries'][$uaID]['status'] ) ? $job['summaries'][$uaID]['status'] : false );
		}

		$html .= '</tr>';
		return $html;

	}

	/**
	 * Create a singe cell summarising the ua runs of a job. See also #getJobHtmlRow.
	 * This is used on the ProjectsPage.
	 * @param string|bool $status Status, or false to create a "skip" cell with
	 *  "notscheduled" status.
	 */
	public static function getJobStatusHtmlCell( $status = false ) {
		return $status
				? ( '<td class="swarm-status-cell"><div class="swarm-status swarm-status-' . $status . '">'
					. self::getStatusIconHtml( $status )
					. '</div></td>'
				)
				: '<td class="swarm-status swarm-status-notscheduled"></td>';
	}

}
