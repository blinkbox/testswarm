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
		$action = JobAction::newFromContext( $this->getContext() );
		$action->doAction();

		$this->setAction( $action );
		$this->content = $this->initContent();
	}

	protected function initContent() {
		$request = $this->getContext()->getRequest();

		$this->setTitle( "Job status" );
		$this->setRobots( "noindex,nofollow" );
		$this->bodyScripts[] = swarmpath( "js/job.js" );

		$error = $this->getAction()->getError();
		$data = $this->getAction()->getData();
		$html = '';

		if ( $error ) {
			$html .= html_tag( 'div', array( 'class' => 'alert alert-error' ), $error['info'] );
		}

		if ( !isset( $data["jobInfo"] ) ) {
			return $html;
		}

		$this->setSubTitle( '#' . $data["jobInfo"]["id"] );

		$html .=
			'<h2>' . $data["jobInfo"]["name"] .'</h2>'
			. '<p><em>Submitted by '
			. html_tag( "a", array( "href" => swarmpath( "user/{$data["jobInfo"]["ownerName"]}" ) ), $data["jobInfo"]["ownerName"] )
			. ' on ' . htmlspecialchars( date( "Y-m-d H:i:s", gmstrtotime( $data["jobInfo"]["creationTimestamp"] ) ) )
			. ' (UTC)' . '</em>.</p>';

		if ( $request->getSessionData( "auth" ) === "yes" && $data["jobInfo"]["ownerName"] == $request->getSessionData( "username" ) ) {
			$html .= '<script>SWARM.jobInfo = ' . json_encode( $data["jobInfo"] ) . ';</script>'
				. ' <div class="form-actions">'
					. ' <button id="swarm-job-delete" class="btn btn-danger">Delete job</button>'
					. ' <button id="swarm-job-cancel" class="btn btn-warning">Cancel job</button>'
					. ' <button id="swarm-job-reset" class="btn btn-info">Reset job</button>'
					. ' <button type="button" data-toggle="modal" data-target="#addbrowserstojobModal" class="btn btn-success">Add browsers</button>'
				. ' </div>'
				. ' <div id="addbrowserstojobModal" class="modal hide fade" tabindex="-1" role="dialog">'
					. ' <div class="modal-header">'
						. ' <button type="button" class="close" data-dismiss="modal">×</button>'
						. ' <h3>Add browsers</h3>'
					. ' </div>'
					. ' <div class="modal-body">'
						. $this->getAddtojobFormHtml()
					. ' </div>'
					. ' <div class="modal-footer">'
						. ' <button class="btn" data-dismiss="modal">Close</button>'
						. ' <button id="swarm-add-browsers" class="btn btn-primary" data-dismiss="modal">Add</button>'
					. ' </div>'
				. ' </div>'
				. ' <div class="alert alert-error" id="swarm-wipejob-error" style="display: none;"></div>'
				. ' <div class="alert alert-error id="swarm-addbrowserstojob-error" style="display: none;"></div>';
		}

		$html .= '<table class="table table-bordered swarm-results"><thead>'
			. self::getUaHtmlHeader( $data['userAgents'] )
			. '</thead><tbody>'
			. self::getUaRunsHtmlRows( $data['runs'], $data['userAgents'] )
			. '</tbody></table>';

		return $html;
	}

	protected function getAddtojobFormHtml(){
		$conf = $this->getContext()->getConf();
		$swarmUaIndex = BrowserInfo::getSwarmUAIndex();

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
		foreach ( $conf->browserSets as $set => $browsers ) {
			$set = htmlspecialchars( $set );
			$browsersHtml = '';
			$last = count( $browsers ) - 1;
			foreach ( $browsers as $i => $browser ) {
				if ( $i !== 0 ) {
					$browsersHtml .= $i === $last ? '<br> and ' : ',<br>';
				} else {
					$browsersHtml .= '<br>';
				}
				$browsersHtml .= htmlspecialchars( $swarmUaIndex->$browser->displaytitle );
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

	public static function getUaHtmlHeader( $userAgents ) {
		$html = '<tr><th>&nbsp;</th>';

		foreach ( $userAgents as $userAgent ) {
			$html .= '<th><img src="' . swarmpath( 'img/' . $userAgent['displayicon'] )
				. '.sm.png" class="swarm-browsericon '
				. '" alt="' . htmlspecialchars( $userAgent['displaytitle'] )
				. '" title="' . htmlspecialchars( $userAgent['displaytitle'] )
				. '"><br>'
				. htmlspecialchars( preg_replace( '/\w+ /', '', $userAgent['displaytitle'] ) )
				. '</th>';
		}

		$html .= '</tr>';
		return $html;
	}

	public static function getUaRunsHtmlRows( $runs, $userAgents ) {
		$html = '';

		foreach ( $runs as $run ) {
			$html .= '<tr><th><a href="' . htmlspecialchars( $run['info']['url'] ) . '">'
				. $run['info']['name'] . '</a></th>';

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
						$html .=
							html_tag_open( 'a', array(
								'rel' => 'nofollow',
								'href' => $uaRun['runResultsUrl'],
							) )
							. ( $uaRun['runResultsLabel']
								? $uaRun['runResultsLabel']
								: UserPage::getStatusIconHtml( $uaRun['runStatus'] )
							)
							. '<i class="icon-list-alt pull-right" title="' . htmlspecialchars(
								"Open run results for {$userAgents[$uaID]['displaytitle']}"
							) . '"></i>'
							. '</a>';
					} else {
						$html .= UserPage::getStatusIconHtml( $uaRun['runStatus'] );
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
}
