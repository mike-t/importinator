<?php
// ===================================================
// Importinator Data Feed for Ingram Micro Australia
// PRICES AND STOCK LEVELS ONLY - DB TO DB
// ===================================================
// Author: Michael Walton
// Updated:	21/07/2015
// Copyright: VIKING IT
// Desc: Imports and cleans data feed and updates stock
// 		 levels and prices directly into the Wordpress
//		 Woocommerce 'wp_postmeta' table.
// TODO: * new stock!
//		 * discontinued products removed/hidden + 301 file for nginx based on permalinks
//		 * update product name (wp_post)
//		 * Stock ETA (requires custom meta_key)
// 		 * virtual stock
//		 * kick off cache crawl/creation?
//		 * make function for getting log time, tidy log output vs debug output
// ===================================================

// permalink structure
//'https://www.vikingit.com.au/shop/' + $category +'/' + 

// ===================================================
// record start exection time
// ===================================================
$totaltime = 0;
$mtime = microtime();
$mtime = explode(" ",$mtime);
$starttime = $mtime[1] + $mtime[0];

// ===================================================
// enable/disable debugging
// ===================================================
$DEBUG = true;

// ===================================================
// Include credentials file
// ===================================================
require dirname(__FILE__) . '/../credentials.php';

// ===================================================
// Include the WordPress header
// ===================================================
define('WP_USE_THEMES', false);
require($wordpress_www_root .'/wp-blog-header.php');

// ===================================================
// PRICE MARGINS
// ===================================================
$LOW_MARGIN = 1.10;
$MID_MARGIN = 1.15;
$HIGH_MARGIN = 1.20;

// ===================================================
// FILES
// ===================================================
$INFILE = '/tmp/db_update.csv';
$REWRITE_FILE = '/etc/nginx/product_rewrites_301.conf';

// ===================================================
// variables
// ===================================================
$info = array();
$output = '';
$loop_count = 1;
$bound_sku = 0;
$bound_post_id = 0;
$bound_meta_key = '';
$infile_info = '';

// map the meta keys to db values for dynamic work later (WP meta_key on left, db field on right)
$wp_meta_keys = array('_sku'=>'Ingram SKU', '_stock_status'=>NULL, '_stock_eta'=>'Backlog ETA', '_stock'=>'Available Quantity', '_regular_price'=>'Cost Price incGST', '_price'=>'Cost Price incGST');

// ===================================================
// Set Header (for www users) and display title
// ===================================================
header('Content-Type: text/plain');		
header("Pragma: no-cache");
header("Expires: 0");
echo("================================================\n\r");
echo("Importinator - WP WooCommerce Stock Update\n\r");
echo("(c) VIKING IT\n\r");
echo("================================================\n\r");

// ===================================================
// db connection & prepared statement if required
// ===================================================
// source DB connection
$mysqli_source = mysqli_connect($db_importinator_host, $db_importinator_user, $db_importinator_pw, $db_importinator_db);
if (!$mysqli_source) die("Source DB Connection failed: " . mysqli_connect_error());

// destinaton DB connection
$mysqli_dest = mysqli_connect($db_wordpress_host, $db_wordpress_user, $db_wordpress_pw, $db_wordpress_db);
if (!$mysqli_dest) die("Destination DB Connection failed: " . mysqli_connect_error());

// ===================================================
// Remove and 301 discontinued and missing products
// ===================================================
// open rewrite file for writing
$rewrite_handle = fopen($REWRITE_FILE, 'a');
if (!$rewrite_handle) die ('Could not open/create rewrite file for writing: ' . $REWRITE_FILE);

// Query DB for discontinued / missing products
$obsolete_products = $mysqli_dest->query("SELECT post_id, meta_value AS `sku`
FROM `vikingit.com.au`.wp_postmeta
INNER JOIN `vikingit.com.au`.wp_posts ON post_id = ID
WHERE 1
AND post_status NOT LIKE 'trash'
AND meta_key LIKE '_sku'
AND	meta_value NOT IN (
	SELECT `Ingram Part Number`
	FROM importinator.products
	WHERE `Discontinued / Obsoleted date` > CURDATE() + 0
	OR `Discontinued / Obsoleted date` LIKE '' 
	OR `Discontinued / Obsoleted date` IS NULL
)");

// create 301's for discontinued products and remove them from wordpress
while($obsolete_product = $obsolete_products->fetch_assoc()) {
	// add a date added header to the nginx rewrite file
	if ($loop_count ==  1) fwrite($rewrite_handle, "\n# Added " . date('d-m-Y'). "\n");

	// log output
	echo("\n\r\n\rRemoving product/post: " .$obsolete_product['post_id']);
	
	// get the current permalink and title for rewriting from WordPress and create the 301 rewrite
	$post_url = get_permalink($obsolete_product['post_id']);	
	fwrite($rewrite_handle, "location = " . substr($post_url, strlen(get_site_url()) + 1) . " { return 301 " . $wordpress_discontinued_uri . "/?search=" . urlencode(get_the_title($obsolete_product['post_id'])) . "; }\n");
	
	// trash the product/post in WordPress
	wp_trash_post($obsolete_product['post_id']);

	$loop_count++;
}
// reset loop count
$loop_count = 1;

// close nginx 301 file
fclose($rewrite_handle);

// reload nginx (POSSIBLY UNSAFE - ENUSRE THIS SCRIPT IS NOT ACESSIBLE TO THE INTERNET)
$shell_output = shell_exec('nginx -s reload');

// logging info
if ($DEBUG) {
	$mtime = microtime();
	$mtime = explode(" ",$mtime);
	$endtime = $mtime[1] + $mtime[0];
	$totaltime = round(($endtime - $starttime), 1);
	echo("\n\r\n\r[" . $totaltime . "s] Remove discontinued and missing products and created 301's (nginx output: " . $shell_output . ").");
}
// ===================================================

// ===================================================
// Prepare and exectute MySQL INFILE with updated 
// product details.
// ===================================================
// select all the products we wish to update
// in this case all products
$sql_get_products = "
# Price and stock level export
SELECT
products.`Ingram Part Number` AS `Ingram SKU`,
products.`Available Quantity`,
products.`Customer Price with Tax` AS `Cost Price incGST`,
products.`Retail Price with Tax` AS `Retail Price incGST`
FROM
products
WHERE 1
AND ((products.`Discontinued / Obsoleted date` IS NULL) OR (products.`Discontinued / Obsoleted date` LIKE ''))";
$feed_products = $mysqli_source->query($sql_get_products);

// create prepared statement and bind parameters for getting destination data
if (!($stmt_post = $mysqli_dest->prepare("SELECT post_id FROM wp_postmeta WHERE meta_key LIKE '_sku' AND meta_value LIKE ?;"))) die("Prepare failed: (" . $mysqli_dest->errno . ") " . $mysqli_dest->error);
if (!($stmt_post->bind_param('s', $bound_sku))) die("Binding parameters failed: (" . $stmt_post->errno . ") " . $stmt_post->error);
if (!($stmt_meta = $mysqli_dest->prepare("SELECT meta_id FROM wp_postmeta WHERE meta_key LIKE ? AND post_id = ?;"))) die("Prepare failed: (" . $mysqli_dest->errno . ") " . $mysqli_dest->error);
if (!($stmt_meta->bind_param('si', $bound_meta_key, $bound_post_id))) die("Binding parameters failed: (" . $stmt_meta->errno . ") " . $stmt_meta->error);

// open infile for writing
$infile_handle = fopen($INFILE, 'w');
if (!$infile_handle) die ('Could not open/create infile for writing: ' . $INFILE);

// loop through products creating a CSV for bulk insert using MySQL's INFILE (much faster when large volume of records)
while($feed_product = $feed_products->fetch_assoc()) {

	// limitation
	//if ($loop_count >= 100) continue;

	// debug info
	if ($DEBUG) echo("\n\r[".$loop_count."] SKU: ".$feed_product[$wp_meta_keys['_sku']]);

	// get the meta_id and post id for each row by matching SKU
	$post_id = 0;
	$bound_sku = intval($feed_product[$wp_meta_keys['_sku']]);
	$stmt_post->execute();
	$stmt_post->store_result();
	$stmt_post->bind_result($post_id);
	$stmt_post->fetch();
	$bound_post_id = $post_id;

	// if we got a matching post /product by sku then using the post id, get the meta_id for each meta_value
	if ($post_id > 0) {

		// debugging
		if ($DEBUG) echo(", matching post_id: ".$post_id);
		
		foreach ($wp_meta_keys as $wp_meta_key=>$wp_meta_key_db_val) {
			$meta_id = 0;
			$bound_meta_key = $wp_meta_key;
			$stmt_meta->execute();
			$stmt_meta->store_result();
			$stmt_meta->bind_result($meta_id);
			$stmt_meta->fetch();
			#while($row = $result->fetch_row()) $meta_id = $row[0];

			// if the row existed in the products table (wp_meta) add it to infile
			if ($meta_id > 0) {

				// debugging
				//if ($DEBUG) echo(" (Updating meta_key: ".$wp_meta_key.")");
				
				// create row
				$output .= $meta_id."\t".$post_id."\t".$wp_meta_key."\t";
				
				// add meta value and close row
				switch ($wp_meta_key) {
					
					// stock status - TODO account for virtual products
					case '_stock_status':
						if ($feed_product[$wp_meta_keys['_stock']] > 0) {							
							$output .= 'instock' . "\n";
						}else{
							$output .= 'outofstock' . "\n";
						}
						break;

					// stock eta - requires custom field in wp
					case '_stock_eta':
						//if ($feed_product[$wp_meta_keys['_stock']] > 0) {							
						//	$output .= 'instock' . "\n";
						//}else{
						//	$output .= 'outofstock' . "\n";
						//}
						break;
					
					// price with markup
					case '_regular_price':
						$output .= round($feed_product[$wp_meta_key_db_val] * $MID_MARGIN, 2). "\n";
						break;
					// worpdress legacy price field
					case '_price':
						$output .= round($feed_product[$wp_meta_key_db_val] * $MID_MARGIN, 2). "\n";
						break;
					
					// all other values / meta_keys
					default:
						$output .= $feed_product[$wp_meta_key_db_val] . "\n";
				}
			}
		}
		// write this product to the infile (change to every 100 products? divisible by 100 cleanly)
		fwrite($infile_handle, $output);
		$output = '';
	}

	// time taken for this product
	if ($DEBUG) {
		$mtime = microtime();
		$mtime = explode(" ",$mtime); 
		$endtime = $mtime[1] + $mtime[0]; 
		$totaltime = round(($endtime - $starttime), 1);
		echo(", Time: ".$totaltime . "s");
	}

	$loop_count++;
}

// close infile
fclose($infile_handle);

// Write to destination DB using the infile method (fast for large data sets)
$mysqli_dest->query("SET FOREIGN_KEY_CHECKS = 0;");
$mysqli_dest->query("SET UNIQUE_CHECKS = 0;");
if(!$mysqli_dest->query("LOAD DATA LOCAL INFILE '".$INFILE."' REPLACE INTO TABLE wp_postmeta FIELDS TERMINATED BY '\t' LINES TERMINATED BY '\n'"))  die("Bulk update failed: (" . $mysqli_dest->errno . ") " . $mysqli_dest->error);
$infile_info = $mysqli_dest->info;
$mysqli_dest->query("SET FOREIGN_KEY_CHECKS = 1;");
$mysqli_dest->query("SET UNIQUE_CHECKS = 1;");

// remove infile from tmp
unlink($INFILE);

// debug info - time take for SQL infile query
if ($DEBUG) {
	$mtime = microtime();
	$mtime = explode(" ",$mtime);
	$endtime = $mtime[1] + $mtime[0];
	$totaltime = round(($endtime - $starttime), 1);
	echo("\n\r\n\rSQL INFILE import complete. Time: ".$totaltime . "s\n\r" . $infile_info);
}
// ===================================================

// ===================================================
// Purge WP Rocket cache for the domain 
// (faster than a full wp_post_update())
// ===================================================
//rocket_clean_post($post_id);
rocket_clean_domain();
if ($DEBUG) {
	$mtime = microtime();
	$mtime = explode(" ",$mtime);
	$endtime = $mtime[1] + $mtime[0];
	$totaltime = round(($endtime - $starttime), 1);
	echo("\n\r\n\rPurged WP Rocket Cache. Time: ".$totaltime . "s");
}
// kick off cache creation?
// ===================================================

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
// fix loopcount
$loop_count--;
$info['executiontime'] = $totaltime;
$info['count'] = $loop_count;

// ===================================================
// Summary output
// ===================================================
echo("\n\r\n\r" . $loop_count . ' products updated directly into "' . $db_wordpress_db . '" on ' . $db_wordpress_host . ' in ' . $totaltime.' seconds.');
echo("\n\r================================================\n\r");
?>