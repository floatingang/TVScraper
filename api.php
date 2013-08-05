<?php

require_once('config.php');


require_once('TVShowScraperDB.php');
require_once('TVShowScraperDDU.php');
require_once('TVShowScraperTVU.php');
require_once('TVShowScraperRSS.php');
require_once('TVShowScraperWikipedia.php');
require_once('Logger.php');

function postCleanUp($params, $toBeRemoved, $logger) {

	ob_start();
	var_dump($params);var_dump($toBeRemoved);
	$d = ob_get_clean();
	$logger->log($d);
	
	
	$newParams = array();
	foreach ($params as $p => $v) {
		if (!in_array($p, $toBeRemoved) && $p != 'action' && $p != 'format') {
			$logger->log("Adding $p to param array");
			$newParams[$p] = $v;
		}
	}
	return $newParams;
}

function checkPostParameters($post, $validParams, $logger) {
	$params = array();

	foreach ($validParams as $p) {
		$export = TRUE;
		if (substr($p, 0, 1) == '+') {
			$p = substr($p, 1);
			$export = FALSE;
		}
		$logger->log("Checking param $p, export =  " . ($export ? 'TRUE' : 'FALSE'));
		if ($p == '_other') {
			$params[] = postCleanUp($post, $validParams, $logger);
		} else if (isset($post[$p])) {
			if ($export) {
				$logger->log("Extracting param $p from array");
				$params[] = $post[$p];
			}
		} else {
			$logger->error("Expected parameter $p not found");
			return FALSE;
		}
	}

	return $params;
}

set_time_limit(0);

/* array definition:

key = method
values = 
	save => saveNeeded on OK
	params => method parameters:
		PARAMETER  => parameter is mandatory and will be extracted by POST array and passed as a standalone parameter
		+PARAMETER => parameter is mandatory but will not be extracted
		_other     => all other parameters, passed as an array

		e.g.: 
		'testMethod' => array( 'params' => 'param1', '+param2', '_ohter' )
		$_POST = array(
			'action' => 'testMethod',
			'param1' => 'value1',	
			'param2' => 'value2',	
			'param3' => 'value3'	
		)

		will call $tv->testMethod($param1, array('param2' => 'value2', 'param3' => 'value3'))

*/

$simpleMethods = array(
/* method */ 'getAllTVShows' 			=> array( ),
/* method */ 'getActiveScrapers'	=> array( ),
/* method */ 'addTVShow' 				=> array( 'save' => TRUE,	'params' => array( '+title', '_other' )),
/* method */ 'addSeason' 				=> array( 'save' => TRUE,	'params' => array( 'showId', '+n', '_other' )),
/* method */ 'addScraper' 				=> array( 'save' => TRUE,	'params' => array( 'rootId', '+source', '+uri', '_other' )),
/* method */ 'removeTVShow' 			=> array( 'save' => TRUE,	'params' => array( 'showId' )),
/* method */ 'removeSeason' 			=> array( 'save' => TRUE,	'params' => array( 'seasonId' )),
/* method */ 'removeScraper' 			=> array( 'save' => TRUE,	'params' => array( 'scraperId' )),
/* method */ 'removeScrapedSeason' 		=> array( 'save' => TRUE,	'params' => array( 'scrapedSeasonId' )),
/* method */ 'getBestFilesForSeason'	=> array( 'save' => FALSE,	'params' => array( 'seasonId' )),
/* method */ 'getBestFileForEpisode'	=> array( 'save' => FALSE,	'params' => array( 'episodeId' )),
/* method */ 'getTVShow' 				=> array( 'save' => FALSE,	'params' => array( 'showId' )),
/* method */ 'getTVShowSeasons'			=> array( 'save' => FALSE,	'params' => array( 'showId' )),
/* method */ 'getTVShowScrapers'		=> array( 'save' => FALSE,	'params' => array( 'showId' )),
/* method */ 'getSeasonEpisodes'		=> array( 'save' => FALSE,	'params' => array( 'seasonId' )),
/* method */ 'getSeasonScrapers'		=> array( 'save' => FALSE,	'params' => array( 'seasonId' )),
/* method */ 'getScrapedSeasons'		=> array( 'save' => FALSE,	'params' => array( 'showId' )),
/* method */ 'getScrapedSeasonsTBN'		=> array( ),
		
/* method */ 'createSeasonScraperFromScraped'		=> array( 'save' => TRUE,	'params' => array( 'scrapedSeasonId' )),
/* method */ 'getFile'	 				=> array( 'save' => FALSE,	'params' => array( 'fileId' )),
/* method */ 'getFilesForEpisode'		=> array( 'save' => FALSE,	'params' => array( 'episodeId' )),
/* method */ 'getScraper' 				=> array( 'save' => FALSE,	'params' => array( 'scraperId' )),
/* method */ 'getSeason' 				=> array( 'save' => FALSE,	'params' => array( 'seasonId' )),
/* method */ 'setSeason' 				=> array( 'save' => TRUE,	'params' => array( 'seasonId', '_other' )),
/* method */ 'setScrapedSeason'			=> array( 'save' => TRUE,	'params' => array( 'scrapedSeasonId', '_other' )),
/* method */ 'setScraper' 				=> array( 'save' => TRUE,	'params' => array( 'scraperId', '_other' ))
);


$log_file = LOG_DIR . '/api.log';
$log_level = LOGGER_DEBUG;

$logger = new Logger($log_file, $log_level);
$logger->log("------ START -----");
ob_start();
var_dump($_POST);
$d = ob_get_clean();
$logger->log($d);



$action = $_POST['action'];
$format = isset($_POST['format']) ? $_POST['format'] : 'json';

$saveNeeded = FALSE;
$res = array();

$fp = fopen(LIB_FILE, 'r');

if (isset($simpleMethods[$action])) {
	
	if (isset($simpleMethods[$action]['save']) && $simpleMethods[$action]['save'] === TRUE) {
		flock($fp, LOCK_EX);
	} else {
		flock($fp, LOCK_SH);
	}
	
	$tv = new TVShowScraperDB(LIB_FILE);
	$tv->setLogger($logger);

	$params = array();
	if (isset($simpleMethods[$action]['params'])) {
		$params = checkPostParameters($_POST, $simpleMethods[$action]['params'], $logger);
		if ($params === FALSE) {
			$res['status'] = 'error';
			$res['errmsg'] = $logger->errmsg();
		}
	} 

	if (!isset($res['status'])) {
		$ret = call_user_func_array(array($tv, $action), $params);
		if ($ret === FALSE) {
			$res['status'] = 'error';
			$res['errmsg'] = "Error executing $action - " . $logger->errmsg();
		} else {
			$res['status'] = 'ok';
			$res['result'] = $ret;
			$saveNeeded = (isset($simpleMethods[$action]['save']) && $simpleMethods[$action]['save'] === TRUE) ? TRUE : FALSE;
		}
	}

} else {
	switch ($action) {
		case /* method */ 'runScraper':
			
			
			if (isset($_POST['scraperId'])) {
				
				$showOnlyNew = (isset($_POST['showOnlyNew']) && $_POST['showOnlyNew'] == 'false' ? FALSE : TRUE);
				$saveResults = (isset($_POST['saveResults']) && $_POST['saveResults'] == 'false' ? FALSE : TRUE);
					
				flock($fp, $saveResults ? LOCK_EX : LOCK_SH);

				$tv = new TVShowScraperDB(LIB_FILE);
				$tv->setLogger($logger);
				$scraper = $tv->getScraper($_POST['scraperId']);
				
				if ($scraper === FALSE) {
					$res['status'] = 'error';
					$res['errmsg'] = 'Cannot find scraper ' . $_POST['scraperId'];
					
				} else {
					
					switch($scraper['source']) {
						case 'DDU':
							$ddu = new TVShowScraperDDU($tv, DDU_LOGIN, DDU_PASSWORD);
							$ddu->setLogger($logger);
								
							$res['status'] = 'ok';
							$res['result'] = $ddu->runScraper($_POST['scraperId'], $showOnlyNew, $saveResults);
							$saveNeeded = TRUE;
							break;
						case 'TVU':
							$rss = new TVShowScraperTVU($tv);
							$rss->setLogger($logger);

							$res['status'] = 'ok';
							$res['result'] = $rss->runScraper($_POST['scraperId'], $showOnlyNew, $saveResults);
							$saveNeeded = TRUE;
							break;
						case 'RSS':
							$rss = new TVShowScraperRSS($tv);
							$rss->setLogger($logger);

							$res['status'] = 'ok';
							$res['result'] = $rss->runScraper($_POST['scraperId'], $showOnlyNew, $saveResults);
							$saveNeeded = TRUE;
							break;
						case 'wikipedia':
							$wiki = new TVShowScraperWikipedia($tv);
							$wiki->setLogger($logger);
							
							$res['status'] = 'ok';
							$res['result'] = $wiki->runScraper($_POST['scraperId'], $showOnlyNew, $saveResults);
							$saveNeeded = TRUE;
							break;
						default:
							$res['status'] = 'error';
							$res['errmsg'] = 'scraper source ' . $scraper['source'] . ' unknown';
							break;
					}
					if ($res['status'] == 'ok' && $res['result'] === FALSE) {
						$res['status'] = 'error';
						$res['errmsg'] = 'Error running scraper';
						unset($res['result']);
					}
				}
			} else {
				$res['status'] = 'error';
				$res['errmsg'] = 'scraperId not provided';
			}
			break;

			
		default:
			$res['status'] = 'error';
			$res['errmsg'] = "unknown action '$action'";
			
	}
}

if ($saveNeeded) $tv->save(LIB_FILE);

flock($fp, LOCK_UN);
fclose($fp);

ob_start();
var_dump($res);
$d = ob_get_clean();
$logger->log($d);
$logger->log("------ END -----");
switch ($format) {
	case 'json':
		header('Content-type: application/json');
		echo json_encode($res);
		break;
	case 'txt':
		header('Content-type: application/txt');
		var_dump($res);
		break;
}


?>