<?php
/**
 * "Addbrowserstojob" action
 *
 * @since 1.0.0
 * @package TestSwarm
 */

class AddbrowserstojobAction extends Action {

	/**
	 * @actionMethod POST: Required.
     * @actionParam int job_id
	 * @actionParam int runMax
	 * @actionParam array browserSets
	 * @actionAuth: Required.
	 */
	public function doAction() {
        $conf = $this->getContext()->getConf();
		$db = $this->getContext()->getDB();
		$request = $this->getContext()->getRequest();

        $jobID = $request->getInt( 'job_id' );
        $runMax = $request->getInt( "runMax" );
        $browserSets = $request->getArray( "browserSets" );

        if ( !$jobID
            || !$browserSets || !count( $browserSets)
        ) {
            $this->setError( 'missing-parameters' );
            return;
        }

        if ( $runMax < 1 || $runMax > 99 ) {
            $this->setError( "invalid-input", "runMax must be a number between 1 and 99." );
            return;
        }

		$jobOwner = $db->getOne(str_queryf(
			"SELECT
				users.name as user_name
			FROM jobs, users
			WHERE jobs.id = %u
			AND   users.id = jobs.user_id
			LIMIT 1;",
			$jobID
		));

		if ( !$jobOwner ) {
			// Job row by this ID didn't exist
			$this->setError( "invalid-input" );
			return;
		}

		// Check authentication
		if ( $request->getSessionData( "auth" ) !== "yes" || $request->getSessionData( "username" ) !== $jobOwner ) {
			$this->setError( "requires-auth" );
			return;
		}

		// Generate a list of user agent IDs based on the selected browser sets
        $browserSetsCnt = count( $browserSets );
        $browserSets = array_unique( $browserSets );
        if ( $browserSetsCnt !== count( $browserSets ) ) {
            $this->setError( "invalid-input", "Duplicate entries in browserSets parameter." );
            return;
        }

        $uaIDs = array();

        foreach ( $browserSets as $browserSet ) {
            if ( !isset( $conf->browserSets->$browserSet ) ) {
                $this->setError( "invalid-input", "Unknown browser set: $browserSet." );
                return;
            }
            // Merge the arrays, and re-index with unique (prevents duplicate entries)
            $uaIDs = array_unique( array_merge( $uaIDs, $conf->browserSets->$browserSet ) );
        }

        if ( !count( $uaIDs ) ) {
            $this->setError( "data-corrupt", "No user agents matched the generated browserset filter." );
            return;
        }

		$skipUaIDs = array();

		$browserRows = $db->getRows(str_queryf(
			'SELECT
				DISTINCT(useragent_id)
			FROM runs
			INNER JOIN run_useragent ON runs.id = run_useragent.run_id
			WHERE job_id = %u;',
			$jobID
		));

		if ( $browserRows ) {
			foreach ( $browserRows as $browserRow ) {
				$skipUaIDs[] = $browserRow->useragent_id;
			}
		}

		// Generate a list of user agent IDs based on the selected browser sets
		$browserSetsCnt = count( $browserSets );
		$browserSets = array_unique( $browserSets );
		if ( $browserSetsCnt !== count( $browserSets ) ) {
			$this->setError( "invalid-input", "Duplicate entries in browserSets parameter." );
			return;
		}

		$uaIDs = array_diff($uaIDs, $skipUaIDs);

		if ( !count( $uaIDs ) ) {
			$this->setError( "data-corrupt", "All user agents matching the generated browserset filter have existing runs for this job." );
			return;
		}

		$runRows = $db->getRows(str_queryf(
			"SELECT id
			FROM runs
			WHERE job_id = %u;",
			$jobID
		));

		if ( $runRows ) {
            foreach ( $runRows as $runRow ) {

                // Schedule run_useragent entries for all user agents matching
                // the browerset(s) for this job.
                foreach ( $uaIDs as $uaID ) {
                    $isInserted = $db->query(str_queryf(
                        "INSERT INTO run_useragent (run_id, useragent_id, max, updated, created)
                        VALUES(%u, %s, %u, %s, %s);",
                        $runRow->id,
                        $uaID,
                        $runMax,
                        swarmdb_dateformat( SWARM_NOW ),
                        swarmdb_dateformat( SWARM_NOW )
                    ));
                }
            }
        }

        $this->setData( array(
            'jobID' => $jobID,
            'result' => 'ok',
        ) );
    }
}
