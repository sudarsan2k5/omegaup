<?php

/*
 * Scoreboard 
 * 
 */

require_once(SERVER_PATH . '/libs/Cache.php');
require_once('ShowRunDetails.php');

class Scoreboard 
{
    // Column to return total score per user
    const total_column = "total";
    const MEMCACHE_PREFIX = "scoreboard";
    const MEMCACHE_EVENTS_PREFIX = "scoreboard_events";
    
    // Contest's data
    private $data;
    private $contest_id;
    private $countProblemsInContest;
    private $showAllRuns;
        
    public function __construct($contest_id, $showAllRuns = false)
    {
        $this->data = array();
        $this->contest_id = $contest_id;
        $this->showAllRuns = $showAllRuns;
    }

    public function getScoreboardTimeLimitUnixTimestamp(Contests $contest)
    {
       
        $start = strtotime($contest->getStartTime());
        $finish = strtotime($contest->getFinishTime());
        if ($this->showAllRuns || ($contest->hasFinished() && $contest->getShowScoreboardAfter()))
        {
            // Show full scoreboard to admin users
            // or if the contest finished and the user wants to show it at the end
            $percentage = 100;
        	
	}
        else
        {
            $percentage = (double)$contest->getScoreboard() / 100.0;
        }
        $limit = $start + (int)(($finish - $start) * $percentage);
                                 
        return $limit;
    }
    
    public function getCountProblemsInContest()
    {
        return $this->countProblemsInContest;
    }
    
    public function generate($withRunDetails = false, $sortByName = false, $filterUsersBy = NULL)
    {
    	$result = null;
        $from_cache = false;
        $cache_key_contestant = "scoreboard-" . $this->contest_id;
        $cache_key_admin = "scoreboard-admin-" . $this->contest_id;
        
        $can_use_contestant_cache = APC_USER_CACHE_ENABLED == true 
                                && APC_USER_CACHE_SCOREBOARD == true 
                                && !$this->showAllRuns 
                                && !$sortByName
                                && is_null($filterUsersBy);
        
        $can_use_admin_cache = APC_USER_CACHE_ENABLED == true 
                                && APC_USER_CACHE_ADMIN_SCOREBOARD == true 
                                && $this->showAllRuns 
                                && !$sortByName
                                && is_null($filterUsersBy);
        
        // If cache is turned on and we're not looking for admin-only runs
        if ($can_use_contestant_cache)
        {
            if($result = apc_fetch($cache_key_contestant))
            {
                $from_cache = true;
            }
            else
            {
                Logger::log("Cache miss for key: " . $cache_key_contestant );
            }            
        }
        else if ($can_use_admin_cache)
        {
            if($result = apc_fetch($cache_key_admin))
            {
                $from_cache = true;
            }
            else
            {
                Logger::log("Cache miss for key: " . $can_use_admin_cache );
            }
        }
        
        if (!$from_cache)
        {
            try
            {
                $contest = ContestsDAO::getByPK($this->contest_id);	

                // Get whether we can cache this scoreboard.
                $pending_runs = RunsDAO::PendingRuns($this->contest_id, $this->showAllRuns);
                $cacheable_for_contestant = !$this->showAllRuns && !$pending_runs;
                $cacheable_for_admin = $this->showAllRuns && !$pending_runs;

                // Get all distinct contestants participating in the contest given contest_id
                $contest_users = RunsDAO::GetAllRelevantUsers($this->contest_id, $this->showAllRuns, $filterUsersBy);

                // Get all problems given contest_id
                $contest_problems = ContestProblemsDAO::GetRelevantProblems($this->contest_id);
            }
            catch(Exception $e)
            {
                throw new ApiException(ApiHttpErrors::invalidDatabaseOperation(), $e);
            }

            $result = array();

            // Save the number of problems internally
            $this->countProblemsInContest = count($contest_problems);

            // Calculate score for each contestant x problem
            foreach ($contest_users as $contestant)
            {
                $user_results = array();
                $user_problems = array();

                foreach ($contest_problems as $problems)
                {
                    $user_problems[$problems->getAlias()] = $this->getScore($problems->getProblemId(), $contestant->getUserId(), $this->getScoreboardTimeLimitUnixTimestamp($contest), $withRunDetails);
                }

                // Add the problems' information
                $user_results['problems'] = $user_problems;

                // Calculate total score for current user            
                $user_results[self::total_column] = $this->getTotalScore($user_problems);

                // And more information on the user
                $user_results['username'] = $contestant->getUsername();
                $user_results['name'] = $contestant->getName() ? $contestant->getName() : $contestant->getUsername();

                // Add contestant results to scoreboard data
                array_push($result, $user_results);
            }

            if ($sortByName == false)
            {
                // Sort users by their total column
                usort($result, array($this, 'compareUserScores'));
            }
            else 
            {
                // Sort users by their name
                usort($result, array($this, 'compareUserNames'));
            }

            // Cache scoreboard if there are no pending runs.
            if ($cacheable_for_contestant && $can_use_contestant_cache)
            {
                if (apc_store($cache_key_contestant, $result, APC_USER_CACHE_SCOREBOARD_TIMEOUT) == false)
                {
                    Logger::log("apc_store failed for problem key: " . $cache_key_contestant);
                }
            }
            else if ($cacheable_for_admin && $can_use_admin_cache)
            {
                if (apc_store($cache_key_admin, $result, APC_USER_CACHE_ADMIN_SCOREBOARD_TIMEOUT) == false)
                {
                    Logger::log("apc_store failed for problem key: " . $cache_key_admin);
                }
            }
	}

    	$this->data = $result;
	return $this->data;                
    }

    public function events()
    {
        $cache = new Cache(self::MEMCACHE_EVENTS_PREFIX);
        $result = $cache->get($this->contest_id);

        if( $this->showAllRuns || $result == null )
        {
            try
            {
                $contest = ContestsDAO::getByPK($this->contest_id);
                    
                // Gets whether we can cache this scoreboard.
                $cacheable = !$this->showAllRuns && !RunsDAO::PendingRuns($this->contest_id, $this->showAllRuns);

                // Get all distinct contestants participating in the contest given contest_id
		$raw_contest_users = RunsDAO::GetAllRelevantUsers($this->contest_id, $this->showAllRuns); 

                // Get all problems given contest_id
                $raw_contest_problems = ContestProblemsDAO::GetRelevantProblems($this->contest_id);

                $run = new Runs();
                $run->setContestId($this->contest_id);
		$run->setStatus('ready');
		if (!$this->showAllRuns) {
			$run->setTest(0);
		}

                $contest_runs = RunsDAO::search($run, 'submit_delay');
            }
            catch(Exception $e)
            {
                throw new ApiException(ApiHttpErrors::invalidDatabaseOperation(), $e);
            }

            $contest_users = array();
            $contest_problems = array();

            foreach ($raw_contest_users as $user)
            {
                    $contest_users[$user->getUserId()] = $user;
            }


            foreach ($raw_contest_problems as $problem)
            {
                    $contest_problems[$problem->getProblemId()] = $problem;
            }

            $result = array();

            // Save the number of problems internally
            $this->countProblemsInContest = count($contest_problems);

            $user_problems_score = array();

            // Calculate score for each contestant x problem
            foreach ($contest_runs as $run)
            {
                if (!isset($user_problems_score[$run->getUserId()]))
                {
                    $user_problems_score[$run->getUserId()] = array();
                }

                if (!isset($user_problems_score[$run->getUserId()][$run->getProblemId()]))
                {
                    $user_problems_score[$run->getUserId()][$run->getProblemId()] = array('points'=>0,'penalty'=>0);
                }

                if ($user_problems_score[$run->getUserId()][$run->getProblemId()]['points'] >= $run->getContestScore())
                {
                        continue;
                }
                
                if (strtotime($run->getTime()) >= $this->getScoreboardTimeLimitUnixTimestamp($contest))
                {
                        continue;
                }

                $user_problems_score[$run->getUserId()][$run->getProblemId()]['points'] = round((float)$run->getContestScore(), 2);
		$user_problems_score[$run->getUserId()][$run->getProblemId()]['penalty'] = 0;

                $data = array();
		$user = $contest_users[$run->getUserId()];

                $data['name'] = $user->getName() ? $user->getName() : $user->getUsername();
                $data['username'] = $user->getUsername();
                $data['delta'] = (int)$run->getSubmitDelay();

                $data['problem'] = array(
                        'alias' => $contest_problems[$run->getProblemId()]->getAlias(),
                        'points' => round((float)$run->getContestScore(), 2),
                        'penalty' => 0
                );

                $data['total'] = array(
                        'points' => 0,
                        'penalty' => 0
                );

                foreach ($user_problems_score[$run->getUserId()] as $problem)
                {
                        $data['total']['points'] += $problem['points'];
                        $data['total']['penalty'] += $problem['penalty'];
                }

                // Add contestant results to scoreboard data
                array_push($result, $data);
            }

            // Cache scoreboard if there are no pending runs
            if ($cacheable)
            {
                    $cache->set($this->contest_id, $result, OMEGAUP_MEMCACHE_SCOREBOARD_TIMEOUT);
            }
	}

	$this->data = $result;
	return $this->data;                
    }
    
   protected function getScore($problem_id, $user_id, $limit_timestamp = NULL, $withRunDetails = false)
   {
        try
        {
            $bestRun = RunsDAO::GetBestRun($this->contest_id, $problem_id, $user_id, $limit_timestamp, $this->showAllRuns);
	}
     
        catch(Exception $e)
        {
            throw new ApiException(ApiHttpErrors::invalidDatabaseOperation(), $e);
        }
        
        if ($withRunDetails && !is_null($bestRun))
        {
	    $runDetails = array();

            if ($bestRun->getGuid() != "")
            {
                $runDetailGenerator = new ShowRunDetails();
                RequestContext::set("run_alias", $bestRun->getGuid());

                $runDetails = $runDetailGenerator->ExecuteApi();                        
                
		// If STATUS="OK" and out_diff is not null, then status is WA
                // OK just means that runner didn't crash. Grader grades after that.
                foreach($runDetails["cases"] as &$case)
                {
                    if ($case["meta"]["status"] == "OK" && !is_null($case["out_diff"]))
                    {
                        $case["meta"]["status"] = "WA";
                    }
                }
                
                unset($runDetails["source"]);
                
            }
            return array(
                "points" => (int)round($bestRun->getContestScore()),
                "penalty" => (int)round($bestRun->getSubmitDelay()),
                "run_details" => $runDetails
            );
        }        
        else
        {
            return array(
                "points" => (int)round($bestRun->getContestScore()),
                "penalty" => (int)round($bestRun->getSubmitDelay())
            );
        }
    }
        
    protected function getTotalScore($scores)
    {        
        
        $sumPoints = 0;
        $sumPenalty = 0;
        // Get sum of all scores
        foreach($scores as $score)
        {
            $sumPoints += $score["points"];
            $sumPenalty += $score["penalty"];
        }
                        
        return array(
          "points" => $sumPoints,
          "penalty" => $sumPenalty
        );
    }
    
    private function compareUserScores($a, $b)
    {        
	if ($a[self::total_column]["points"] == $b[self::total_column]["points"])
	{
		if ($a[self::total_column]["penalty"] == $b[self::total_column]["penalty"])
			return 0;

		return ($a[self::total_column]["penalty"] > $b[self::total_column]["penalty"]) ? 1 : -1;
	}
        
        return ($a[self::total_column]["points"] < $b[self::total_column]["points"]) ? 1 : -1;
    }    
    
    
    private function compareUserNames($a, $b)
    {        	        
        return strcmp($a['username'], $b['username']);
    }   
}

?>
