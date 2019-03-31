<?php
// ===================================================
// Importinator Data Feed for Italian-Botique.com
// ===================================================
// Author: Michael Walton
// Updated:	20/03/2015
// Copyright: JUCA
// Desc: Imports and cleans data feed file ready for
//       import into Wordpress as CSV.
// TODO: 
// ===================================================

// ===================================================
// enable/disable debugging
// ===================================================
$DEBUG = false;

// ===================================================
// "CONSTANTS"
// ===================================================
$DATA_DIR = __DIR__.'/data/';
$DATA_URL = 'http://www.italian-boutique.com/export/googlebase-export-AU.txt';
$DATA_LOCAL = $DATA_DIR.'IB_'.date('Y-m-d').'_'.basename($DATA_URL);

// ===================================================
// record start exection time
// ===================================================
$totaltime = 0;
$mtime = microtime();
$mtime = explode(" ",$mtime);
$starttime = $mtime[1] + $mtime[0];

// ===================================================
// variables
// ===================================================
$info = array();
$feed_data = array();
$html_table = '<table class="table table-striped table-hover ">' . "\n";
$csv = "";
$loop_count = 1;

// ===================================================
// check required parameters have been supplied
// ===================================================
if (!isset($_GET['type']) || ($_GET['type'] !== 'json' && $_GET['type'] !== 'csv')) die(json_encode(array('error'=>1,'data'=>'No valid data type provided.')));

// ===================================================
// log into vendor and pull feed
// ===================================================
// If a local cache of the data exists, and is less than 1 hour old use it
if (file_exists($DATA_LOCAL) && (filemtime($DATA_LOCAL) > (time() - 60 * 60 ))) {
   // Cache file is less than 1 hour old, use the file as-is
   $file = file_get_contents($DATA_LOCAL);
   $info['cache_used'] = 'true';
} else {
   // Cache is out-of-date, load the data from our remote server and save it over our cache for next time.
   $file = file_get_contents($DATA_URL);
   file_put_contents($DATA_LOCAL, $file, LOCK_EX);
   $info['cache_used'] = 'false';
}

// convert feed to array from csv for processing.
$feed_data = csv_to_array($DATA_LOCAL, "\t");

// loop through feed. Clean up fields and create html table and csv data types
foreach($feed_data as &$feed_product) {
	// Capitilise words in various fields
	$feed_product['title'] = ucwords($feed_product['title']);
	$feed_product['description'] = ucfirst($feed_product['description']);
	$feed_product['product type'] = ucwords($feed_product['product type']);
	$feed_product['gender'] = ucwords($feed_product['gender']);
	$feed_product['age_group'] = ucwords($feed_product['age_group']);
	$feed_product['genre'] = ucwords($feed_product['genre']);
	$feed_product['color'] = ucwords($feed_product['color']);

	// remove non-numeric fields from shipping and weight fields
	$feed_product['shipping'] = preg_replace('/[^0-9.]/', '', $feed_product['shipping']);
	$feed_product['shipping_weight'] = preg_replace('/[^0-9.]/', '', $feed_product['shipping_weight']);
	
	// clean up categories
	$feed_product['product type'] = str_replace("Women's Shoes", "Women > Shoes", $feed_product['product type']);
	$feed_product['product type'] = str_replace("Men's Shoes", "Men > Shoes", $feed_product['product type']);
	$feed_product['product type'] = str_replace("Women's Handbags", "Women > Bags", $feed_product['product type']);
	$feed_product['product type'] = str_replace("Slippers - Thong", "Thongs & Flip Flops", $feed_product['product type']);

	// ==================================
	// create csv data if required
	// ==================================
	if ($_GET['type'] == 'csv') {
		// header row
		if ($loop_count == 1) {
			foreach($feed_product as $product_field_key=>$product_field_value) $csv .= $product_field_key . "\t";
		  	//remove trailing tab from CSV header row and add new line
		  	rtrim($csv);
		  	$csv .= "\n";
	  	}

		// normal row
		foreach($feed_product as $product_field_value) $csv .= $product_field_value . "\t";

		//remove trailing tab from CSV and add new line
		rtrim($csv);
		$csv .= "\n";
	}
	// ==================================

	// ==================================
	// create html (json) data if required
	// ==================================
	if ($_GET['type'] == 'json') {
		if ($loop_count == 1) {
			$html_table .= "\t<thead>\n";
			$html_table .= "\t\t<tr class=\"danger\">\n";
			foreach($feed_product as $product_field_key=>$product_field_value) $html_table .= "\t\t\t<th>".$product_field_key."</th>\n";
			$html_table .= "\t\t</tr>\n";
			$html_table .= "\t</thead>\n";
		  	$html_table .= "\t<tbody>\n";
	  	}

		// normal row
		$html_table .= "\t\t<tr>\n";
		foreach($feed_product as $product_field_value) $html_table .= "\t\t\t<td>".$product_field_value."</td>\n";
		$html_table .= "\t\t</tr>\n";
	}
	// ==================================

    $loop_count++;
}
// close off html table
$html_table .= "\t</tbody>\n</table>\n";

// ===================================================
// calculate total execution time
// ===================================================
$mtime = microtime();
$mtime = explode(" ",$mtime); 
$endtime = $mtime[1] + $mtime[0]; 
$totaltime = round(($endtime - $starttime), 1);

// ===================================================
// Create information array for reference
// ===================================================
$info['executiontime'] = $totaltime;
$info['count'] = $loop_count;

// ===================================================
// Encode and output the data
// ===================================================
if (!$DEBUG) {
	// JSON
	if ($_GET['type'] == 'json') {
		header('Content-Type: application/json');
		header("Pragma: no-cache");
		header("Expires: 0");
		echo(json_encode(array('error'=>0, 'info'=>$info, 'data'=>$html_table)));
	}
	// CSV
	if ($_GET['type'] == 'csv') {
		header("Content-type: text/csv");
		header("Content-Disposition: attachment; filename=".$CLEAN_FILENAME);
		header("Pragma: no-cache");
		header("Expires: 0");
		echo($csv);
	}
}

// ===================================================
// debugging
// ===================================================
if ($DEBUG) {
	echo('<pre>');
	print_r(array(array('error'=>0, 'info'=>$info, 'url'=>$url, 'html_table'=>$html_table)));
	echo('</pre>');
}

// ===================================================
// functions
// ===================================================
function csv_to_array($filename='', $delimiter=',') {
    if(!file_exists($filename) || !is_readable($filename))
        return FALSE;

    $header = NULL;
    $csv_data = array();
    if (($handle = fopen($filename, 'r')) !== FALSE)
    {
        while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE)
        {
            if(!$header)
                $header = $row;
            else
                $csv_data[] = array_combine($header, $row);
        }
        fclose($handle);
    }
    return $csv_data;
}
?>