/* WooCommerce DAZzle and WorldShip Address Export script
*  Version 2.0.0
*
*  Export lots of addresses from WooCommerce orders into a .txt file DAZzle can use to import addresses quickly
*  As of 2.0.0 addresses can also be exported as a .csv for UPS WorldShip
*
*  This script can be copied into functions.php or in a script managing plugin
*
*  DAZzle Instructions:
*  Use this to export a list of order addresses for USPS DAZzle as a tab-deliminated text file
*  Go to Address Book --> File --> Import and select the text file, it should load them into the address book
*  In DAZzle on the first time you load the output file you will need to map the fields
*  You will also need to select tab as the delimiter and select Skip first row
*  Once configured, press OK and DAZzle will load the addresses into the address book
*  The addresses will not be verified yet.  Highlight them all and press Ctrl+Z.
*  DAZzle will verify the addresses, and you can begin printing shipping labels.
*  You can now delete ADDRESSES.txt
*
*  Hereafter all you have to do is re-download ADDRESSES.txt every time you want to import more orders
*  Make sure to delete the old ADDRESSES.txt so that you don't get a (1) added to the filename so DAZzle will remember the field mapping
*
*
*  WorldShip Instructions:
*  To import addresses to UPS WorldShip select the orders in WooCommerce
*  Choose Export Addresses for WorldShip and save the .csv file
*  In UPS WorldShip you'll need to configure the import map first
*  Go to Import-Export -> Tools -> Import/Export Wizard -> I need help with importing information into WorldShip -> Next
*  Addresses -> Next -> *Data Connection Name:  Enter some name like AddressImport
*  Click Browse and select ADDRESSES.csv which you should have downloaded a moment ago
*  Datasource Type should say Microsoft Text Driver -- Press Next
*  On the map page, in the field list highlight CompanyOrName and at the bottom press Define Primary Key
*  Then drag each line to its corresponding field in WorldShip
*  If you don't ship internationally then do NOT drag "County" over, just leave it
*  Press Save Map when finished and then Exit to WorldShip
*
*  From now on click Batch Import-Export -> Batch Import and then the name of the import schema you chose earlier
*  I always select "Overwrite existing records" but that's up to you
*  Next -> Next -> Save
*  Addresses should be imported and you can start printing labels!
*
*
*  I hope this script helps you save time with WooCommerce, DAZzle, and WorldShip */

// cleanData will format the data to be compatible with .csv file format
function cleanData(&$str)
{
	$str = preg_replace("/\t/", "\\t", $str);
	$str = preg_replace("/\r?\n/", "\\n", $str);
  	
	if(strstr($str, '"')) $str = '"' . str_replace('"', '""', $str) . '"';
}

// Add DAZzle Bulk Export Addresses to the orders dropdown
add_action('admin_footer-edit.php', 'dazzle_bulk_admin_footer');
// insert it in the dropdown
function dazzle_bulk_admin_footer() {

	global $post_type;
	if($post_type == 'shop_order') {
		?>
		<script type="text/javascript">
			jQuery(document).ready(function() {
				jQuery('<option>').val('dazzleexport').text('<?php _e('Export Addresses for DAZzle')?>').appendTo("select[name='action']");
				
			});
		</script>
		<?php
	}
}

// Add WorldShip Bulk Export Addresses to the orders dropdown
add_action('admin_footer-edit.php', 'worldship_bulk_admin_footer');
// insert it in the dropdown
function worldship_bulk_admin_footer() {

	global $post_type;
	if($post_type == 'shop_order') {
		?>
		<script type="text/javascript">
			jQuery(document).ready(function() {
				jQuery('<option>').val('worldshipexport').text('<?php _e('Export Addresses for WorldShip')?>').appendTo("select[name='action']");
				
			});
		</script>
		<?php
	}
}

// Function to run when the user presses "Apply" with Export Addresses for DAZzle or WorldShip selected
add_action('load-edit.php', 'dazzle_worldship_address_export');

function dazzle_worldship_address_export() {
	global $typenow;
	$post_type = $typenow;
	// Make sure it's a Woocommerce order
	if($post_type == 'shop_order')
	{
		$wp_list_table = _get_list_table('WP_Posts_List_Table');
	  
		// See if it was the new dazzleexport action
		$action = $wp_list_table->current_action();
		if((strcasecmp($action, "dazzleexport") != 0) && (strcasecmp($action, "worldshipexport") != 0)) return;
	  
	  	// Create an array of the orders that were selected.  WC orders are posts in the WP db.
		if(isset($_REQUEST['post'])) {
			$order_ids = array_map('intval', $_REQUEST['post']);
		}
		if(empty($order_ids))
		{
			// If we get here that means no orders were selected.
			return;
		}
	  	
	  	// Setup to bulk export.
		$addressdata = array();
	  	$delimiter = 44;  // Defaulting to Comma -- DAZzle uses TAB, WorldShip uses Comma
	  	$ext = 'csv';  // Defaulting to csv -- DAZzle used txt, WorldShip uses csv
	  
		switch($action) {
			case 'dazzleexport':
				// Write addresses from each selected order to $addressdata array
				foreach( $order_ids as $order_id ) {
					$order = new WC_Order( $order_id );
					$addressdata[] = array(
						"FirstName" => strtoupper($order->shipping_first_name),
						"LastName" => strtoupper($order->shipping_last_name),
						"Company" => $order->shipping_company,
						"Address1" => preg_replace('[\.]', NULL, $order->shipping_address_1),
						"Address2" => $order->shipping_address_2,
						"Address3" => $order->shipping_address_3,
						"City" => $order->shipping_city,
						"State" => $order->shipping_state,
						"PostalCode" => $order->shipping_postcode,
						"Country" => $order->shipping_country,
						"Phone" => $order->billing_phone,
						// "EMail" => $order->billing_email,  Depending on your shipping situation you may want e-mails disabled for DAZzle
						/* The following items need to be present, but can be left empty. */
						"ReturnCode" => "",
						"CarrierRoute" => "",
						"DeliveryPoint" => ""
					);
				}
				$delimiter = 9; // DAZzle needs to use TAB
				$ext = 'txt'; // DAZzle needs to use txt
				break;
			case 'worldshipexport':
				// Write addresses from each selected order to $addressdata array
				foreach( $order_ids as $order_id ) {
					$order = new WC_Order( $order_id );
				  	$name_company_array = format_worldship_name($order); // Company needs to go first if present -- if NOT present we need to use Name
					$addressdata[] = array(
						"CompanyOrName" => $name_company_array[0],
					  	"Attention" => $name_company_array[1],
						"Address1" => preg_replace('[\.]', NULL, $order->shipping_address_1),
						"Address2" => $order->shipping_address_2,
						"Address3" => $order->shipping_address_3,
						"City" => $order->shipping_city,
						"State" => $order->shipping_state,
						"PostalCode" => $order->shipping_postcode,
						"Country" => $order->shipping_country,
						"Phone" => $order->billing_phone,
						"EMail" => $order->billing_email,
					);
				}
				break;
		}
	  	// Create a file using the array we just generated.
		$out = fopen("php://output", 'w');

		$flag = false;
	  	$row_id=1;
		foreach($addressdata as $row) {
			if(!$flag) {
				// display field/column names as first row
				fputcsv($out, array_keys($row), chr($delimiter), '"');	
				$flag = true;
			}
			array_walk($row, __NAMESPACE__ . '\cleanData');
			fputcsv($out, array_values($row), chr($delimiter), '"');
		}
		// Send the newly created file to the user.
		fclose($out);

		// Force the download
		header('Content-Disposition: attachment; filename="ADDRESSES.'.$ext.'" . basename($out) . ""');
		header("Content-Length: " . filesize($out));
		header("Content-Type: application/octet-stream;");
		header("Pragma: no-cache");
		header("Expires: 0");
		header("Cache-Control: must-revalidate");
		readfile($out);
	  	die();
	}
}

function format_worldship_name($order) {
  	$ret = array();
	$formatted_name = strtoupper($order->shipping_first_name . ' ' . $order->shipping_last_name);
  
  	if (empty($order->shipping_company)) {
		$ret[0] = $formatted_name;
	  	$ret[1] = '';
	} else {
	  	$ret[0] = strtoupper($order->shipping_company);
	  	$ret[1] = $formatted_name;
	}
  	return $ret;
}


add_action('admin_notices', 'custom_bulk_admin_notices');
 
function custom_bulk_admin_notices() {
 
  global $post_type, $pagenow;

  if($pagenow == 'edit.php' && $post_type == 'shop_order'){
    echo "<div class=\"updated\"><p>keep the hands clappin'</p></div>";
  }
}
