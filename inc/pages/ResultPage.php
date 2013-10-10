<?php
/**
 * "Result" page.
 *
 * @author Timo Tijhof, 2012
 * @since 1.0.0
 * @package TestSwarm
 */

class ResultPage extends Page {

	protected $foundEmpty = false;

	public function execute() {
		// Handle 'raw' output
		$request = $this->getContext()->getRequest();

		$resultsID = $request->getInt( 'item' );
		$isRaw = $request->getBool( 'raw' );
		$format = $request->getVal( 'format', 'html' );

		if ( $resultsID && $isRaw ) {
			$this->serveRawResults( $resultsID, $format );
			exit;
		}

		// Regular request
		$action = ResultAction::newFromContext( $this->getContext() );
		$action->doAction();

		$this->setAction( $action );
		$this->content = $this->initContent();
	}

	protected function initContent() {
		$request = $this->getContext()->getRequest();
		$resultsID = $request->getInt( 'item' );

		$this->setTitle( 'Run result' );
		$this->setRobots( 'noindex,nofollow' );
		$this->bodyScripts[] = swarmpath( 'js/result.js' );

		$error = $this->getAction()->getError();
		$data = $this->getAction()->getData();
		$html = '';

		if ( $error ) {
			$html .= html_tag( 'div', array( 'class' => 'alert alert-error' ), $error['info'] );
			return $html;
		}

		$this->setSubTitle( '#' . $data['info']['id'] );


		if ( $data['job'] ) {
			$html = '<p><em>'
				. html_tag_open( 'a', array( 'href' => $data['job']['url'], 'title' => 'Back to Job #' . $data['job']['id'] ) ) . '&laquo Back to Job #' . $data['job']['id'] . '</a>'
				. '</em></p>';
		} else {
			$html = '<p><em>Run #' . $data['info']['runID'] . ' has been deleted. Job info unavailable.</em></p>';
		}

		if ( $data['otherRuns'] ) {
			$html .= '<table class="table table-bordered swarm-results"><thead>'
				. JobPage::getUaHtmlHeader( $data['otherRuns']['userAgents'] )
				. '</thead><tbody>'
				. JobPage::getUaRunsHtmlRows( $data['otherRuns']['runs'], $data['otherRuns']['userAgents'] )
				. '</tbody></table>';
		}

		$html .= '<h3>Information</h3>'
			. '<table class="table table-striped">'
			. '<tbody>'
			. '<tr><th>Run</th><td>'
				. ($data['job']
					? html_tag( 'a', array( 'href' => $data['job']['url'] ), 'Job #' . $data['job']['id'] ) . ' / '
					: ''
				)
				. 'Run #' . htmlspecialchars( $data['info']['runID'] )
			. '</td></tr>'
			. '<tr><th>Client</th><td>'
				. html_tag( 'a', array( 'href' => $data['client']['viewUrl'] ), 'Client #' . $data['info']['clientID'] )
				. ' / ' . htmlspecialchars( $data['client']['name'] )
			. '</td></tr>'
			. '<tr><th>UA ID</th><td>'
				. '<code>' . htmlspecialchars( $data['client']['uaID'] ) . '</code>'
			. '<tr><th>User-Agent</th><td>'
				. '<tt>' . htmlspecialchars( $data['client']['uaRaw'] ) . '</tt>'
			. '</td></tr>'
			. '<tr><th>Run time</th><td>'
			. ( isset( $data['info']['runTime'] )
				? number_format( intval( $data['info']['runTime'] ) ) . 's'
				: '?'
			)
			. '</td></tr>'
			. '<tr><th>Status</th><td>'
				. htmlspecialchars( $data['info']['status'] )
			. '</td></tr>'
			. '<tr><th>Started</th><td>'
				. self::getPrettyDateHtml( $data['info'], 'started' )
			. '</td></tr>'
			. ( isset( $data['info']['savedLocalFormatted'] )
				? ('<tr><th>Saved</th><td>'
					. self::getPrettyDateHtml( $data['info'], 'saved' )
					. '</td></tr>'
				)
				: ''
			)
			. '</tbody></table>';

		$html .= '<h3>Results</h3>'
			. '<p class="swarm-toollinks">'
			. html_tag( 'a', array(
				'href' => swarmpath( 'index.php' ) . '?' . http_build_query(array(
					'action' => 'result',
					'item' => $data['info']['id'],
					'raw' => '',
					'format' => 'json',
				)),
				'target' => '_blank',
				'class' => 'swarm-popuplink',
			), 'Open JSON report in a new window' )
			. html_tag( 'a', array(
				'href' => swarmpath( 'index.php' ) . '?' . http_build_query(array(
					'action' => 'result',
					'item' => $data['info']['id'],
					'raw' => '',
				)),
				'target' => '_blank',
				'class' => 'swarm-popuplink',
			), 'Open HTML report in a new window' )
			. '</p>'
			. html_tag( 'iframe', array(
				'src' => swarmpath( 'index.php' ) . '?' . http_build_query(array(
					'action' => 'result',
					'item' => $data['info']['id'],
					'raw' => '',
				)),
				'width' => '100%',
				'class' => 'swarm-result-frame',
			));


		return $html;
	}

	protected function serveRawResults( $resultsID, $format ) {
		$db = $this->getContext()->getDB();

		$this->setRobots( 'noindex,nofollow' );

		// Override frameoptions to allow framing. Note, we can't use
		// Page::setFrameOptions(), as this page does not use the Page class layout.
		header( 'X-Frame-Options: SAMEORIGIN', true );

		$row = $db->getRow(str_queryf(
			'SELECT
				status,
				report_html,
				report_json
			FROM runresults
			WHERE id = %u;',
			$resultsID
		));

		$reportField = 'report_' . $format;

		header( 'Content-Type: text/' . $format . '; charset=utf-8' );
		if ( $row ) {
			$status = intval( $row->status );
			$report = $row->{$reportField};

			// If it finished or was aborted, there should be
			// a (at least partial) report.
			if ( $status === ResultAction::$STATE_FINISHED || $status === ResultAction::$STATE_ABORTED || $status === ResultAction::$STATE_HEARTBEAT ) {
				if ( $report !== '' && $report !== null ) {
					header( 'Content-Encoding: gzip' );
					echo $report;
				} else {
					$this->outputMini(
						'No Content',
						'Client saved results but did not attach a report in ' . strtoupper($format) . ' format.'
					);
				}

			// Client timed-out
			} elseif ( $status === ResultAction::$STATE_LOST ) {
				$this->outputMini(
					'Client Lost',
					'Client lost connection with the swarm.'
				);

			// Still busy? Or some unknown status?
			} else {
				$this->outputMini(
					'In Progress',
					'Client did not submit results yet. Please try again later.'
				);
			}
		} else {
			self::httpStatusHeader( 404 );
			$this->outputMini( 'Not Found' );
		}

		// This is a raw response, the Page should not build.
		exit;
	}
}
