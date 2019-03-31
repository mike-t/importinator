<?php
// ===================================================
// Importinator Data Feed for Ingram Micro Australia
// ===================================================
// Author: Michael Walton
// Updated:	30/07/2015
// Copyright: VIKING IT
// Desc: Imports and cleans data feed file from Ingram
//		 Micro into the Importinator DB.
//		 Intended to be run using CLI as a cron job.
//		 CURRENTLY UPDATES ONLY PRICE, STOCK LEVELS INFO
//		 AND REMOVES STOCK FROM DB IF NOT IN FEED.
// TODO: * Goutte error handling.
//		 * BRAND MODEL TITLE (e.g. CISCO 2821 Catalyst 24 Port Switch)
// ===================================================

// ===================================================
// record start exection time
// ===================================================
$totaltime = 0;
$mtime = microtime();
$mtime = explode(" ",$mtime);
$starttime = $mtime[1] + $mtime[0];

// ===================================================
// Use the Goutte library for interacting with IM website
// ===================================================
require dirname(__FILE__) . '/../vendor/autoload.php';
use Goutte\Client;

// ===================================================
// Include credentials file
// ===================================================
require dirname(__FILE__) . '/../credentials.php';

// ===================================================
// "CONSTANTS"
// ===================================================
$DATA_DIR = __DIR__.'/data/';
$DATA_URL = 'http://au.ingrammicro.com/_layouts/CommerceServer/IM/Login.aspx?ReturnUrl=%2f_layouts%2fCommerceServer%2fIM%2fFileDownload.aspx%3fDisplayName%3dSTD_FULL_FILEFEED.TXT%26FileName%3dSTDPRICE_FULL.TXT.zip%2527';
$DATA_LOCAL = $DATA_DIR.'IM_'.date('Y-m-d').'_'.'STDPRICE_FULL.TXT';
$INFILE = $DATA_LOCAL;

// ===================================================
// Set Header (for www users) and display title
// ===================================================
header('Content-Type: text/plain');		
header("Pragma: no-cache");
header("Expires: 0");
echo("================================================\n\r");
echo("Importinator - Ingram Micro AU Product Import\n\r");
echo("(c) VIKING IT\n\r");
echo("================================================\n\r\n\r");

// ===================================================
// log into vendor and pull feed
// ===================================================
// If a local cache of the data exists, and is less than 3 hours old use it
if (file_exists($DATA_LOCAL) && (filemtime($DATA_LOCAL) > (time() - 60 * 60 * 3))) {

	// Cache file is less than 3 hours old, use the file as-is
	echo("[".getExecutedTime($starttime)."s]\tUsing local cache for product file...\n\r");
	$file = file_get_contents($DATA_LOCAL);
	$feed_exists = true;
	$info['cache_used'] = 'true';

} else {

	// Cache is out-of-date, load the data from our remote server and save it over our cache for next time.
	echo("[".getExecutedTime($starttime)."s]\tRetrieving product file from supplier...\n\r");
	$client = new Client();

	// log into vendor and retrieve latest feed
	$crawler = $client->request('GET', $DATA_URL);
	$form = $crawler->selectButton('Log in')->form();
	$crawler = $client->submit($form, array('ctl00$PlaceHolderMain$txtUserEmail' => $im_user, 'ctl00$PlaceHolderMain$txtPassword' => $im_password));
	$zipped_file = $client->getResponse()->getContent();

	// save it to disk
	file_put_contents($DATA_LOCAL.'.zip', $zipped_file, LOCK_EX);
	
	// unzip inventory feed
	echo("[".getExecutedTime($starttime)."s]\tDecompressing product file...\n\r");
	$zip = new ZipArchive;
	if ($zip->open($DATA_LOCAL.'.zip') === TRUE) {
		$zip->extractTo($DATA_DIR);
		$zip->close();
		// clean up files
		rename($DATA_DIR.'/STDPRICE_FULL.TXT', $DATA_LOCAL);
		unlink($DATA_LOCAL.'.zip');
	} else {
		die('Error: Failed to open compressed data feed.');
	}

	// replace problematic characters in infile
	echo("[".getExecutedTime($starttime)."s]\tCleaning product file...\n\r");
	file_put_contents($INFILE, str_replace('\,', ',',file_get_contents($INFILE)));

	// save it to cache
	//file_put_contents($DATA_LOCAL, $file, LOCK_EX);
	$info['cache_used'] = 'false';
}

// ===================================================
// Update the Importinator DB with feed/infile
// ===================================================
// output
echo("[".getExecutedTime($starttime)."s]\tUpdating Importinator DB with product file...\n\r");

// open DB connection
$mysqli_dest = mysqli_connect($db_importinator_host, $db_importinator_user, $db_importinator_pw, $db_importinator_db);
if (!$mysqli_dest) die('Error: Source DB Connection failed: ' . mysqli_connect_error());

// Truncate then write to destination DB using the infile method (fast for large data sets)
$mysqli_dest->query("SET FOREIGN_KEY_CHECKS = 0;");
$mysqli_dest->query("SET UNIQUE_CHECKS = 0;");
if(!$mysqli_dest->query("TRUNCATE TABLE products;")) die('Error: Product table truncate failed: (' . $mysqli_dest->errno . ') ' . $mysqli_dest->error);
if(!$mysqli_dest->query("LOAD DATA LOCAL INFILE '".$INFILE."' REPLACE INTO TABLE products FIELDS TERMINATED BY ',' LINES TERMINATED BY '\n' IGNORE 1 LINES;")) die('Error: Bulk update failed: (' . $mysqli_dest->errno . ') ' . $mysqli_dest->error);
$infile_info = $mysqli_dest->info;
$mysqli_dest->query("SET FOREIGN_KEY_CHECKS = 1;");
$mysqli_dest->query("SET UNIQUE_CHECKS = 1;");

// remove cache files older than 2 days
echo("[".getExecutedTime($starttime)."s]\tRemoving old product files...\n\r");
foreach (new DirectoryIterator($DATA_DIR) as $fileInfo) {
    if (!$fileInfo->isDot() && !$fileInfo->isDir() && time() - $fileInfo->getCTime() >= 172800 && $fileInfo->getFilename() !== '.gitignore') {
        unlink($fileInfo->getRealPath());
    }
}

// ===================================================
// Output success information
// ===================================================
echo("[".getExecutedTime($starttime)."s]\tSuccess: Imported Ingram Micro product file: " . $DATA_LOCAL . " into Importinator.\n\r\t" . $infile_info . "\n\r");
echo("\n\r================================================\n\r");

// ===================================================
// getExecutedTime Function
// ===================================================
// Parameters: starttime(microtime) - microtime to compare current time to.
// Returns: Time difference in seconds to provided start time.
// ===================================================
function getExecutedTime($starttime) {
	$mtime = microtime();
	$mtime = explode(" ",$mtime); 
	$endtime = $mtime[1] + $mtime[0]; 
	$totaltime = round(($endtime - $starttime), 1);
	return $totaltime;
}
?>