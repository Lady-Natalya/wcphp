/* WooCommerce DAZzle Address Export script
*  Version 0.0.2
*  Export lots of addresses from WooCommerce orders into a .txt file DAZzle can use to import addresses quickly
*
*  This script can be copied into functions.php or in a script managing plugin
*
*  Use this to export a list of order addresses for USPS DAZzle as a tab-deliminated text file
*  DAZzle can load the text file from Design --> Layout --> Address lookup from --> External file
*  In DAZzle on the first time you load the output file you will need to map the fields
*  You will also need to select tab as the delimiter and select Skip first row
*  Once configured, press OK and DAZzle will process and verify all the addresses and re-save the text file
*  Now go to Address Book --> File --> Import and select the text file, it should load them into the address book
*  You can now delete ADDRESSES.txt
*  Hereafter all you have to do is re-download ADDRESSES.txt every time you want to import more orders
*  Make sure to delete the old ADDRESSES.txt so that you don't get a (1) added to the filename so DAZzle will remember the field mapping
*
*  I hope this script helps you save time with WooCommerce and DAZzle */


// cleanData will format the data to be compatible with .csv file format
function cleanData(&$str)
{
	$str = preg_replace("/\t/", "\\t", $str);
	$str = preg_replace("/\r?\n/", "\\n", $str);
  	
	if(strstr($str, '"')) $str = '"' . str_replace('"', '""', $str) . '"';
}

// Add Bulk Export Addresses to the orders dropdown
add_action('admin_footer-edit.php', 'custom_bulk_admin_footer');
// insert it in the dropdown
function custom_bulk_admin_footer() {

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

// Function to run when the user presses "Apply" with Export Addresses for DAZzle selected
add_action('load-edit.php', 'dazzle_address_export');

function dazzle_address_export() {
	global $typenow;
	$post_type = $typenow;
	// Make sure it's a Woocommerce order
	if($post_type == 'shop_order')
	{
		$wp_list_table = _get_list_table('WP_Posts_List_Table');
		// See if it was our new action: dazzleexport
		$action = $wp_list_table->current_action();
		$allowed_actions = array("dazzleexport");
		if(!in_array($action, $allowed_actions)) return;
	  	// Create an array of the orders that were selected.  WC orders are posts in the WP database.
		if(isset($_REQUEST['post'])) {
			$order_ids = array_map('intval', $_REQUEST['post']);
		}
		if(empty($order_ids))
		{
			// If we get here that means no orders were selected.
			return;
		}
		switch($action) {
			case 'dazzleexport':
				// Setup to bulk export.
				$exported = 0;
				$addressdata = array();
				// Write addresses from each selected order to $addressdata array
				foreach( $order_ids as $order_id ) {
				  	// This tells WC that the post id was actually referring to an order
					$order = new WC_Order( $order_id );
					$addressdata[] = array("FirstName" => strtoupper($order->shipping_first_name), "LastName" => strtoupper($order->shipping_last_name), "Company" => $order->shipping_company, "Address1" => preg_replace('[\.]', NULL, $order->shipping_address_1), "Address2" => $order->shipping_address_2, "Address3" => $order->shipping_address_3, "City" => $order->shipping_city, "State" => $order->shipping_state, "PostalCode" => $order->shipping_postcode, "Country" => $order->shipping_country, /*"EMail" => $order->billing_email,*/ "ReturnCode" => "", "CarrierRoute" => "", "DeliveryPoint" => "");
				  	// Exported counter might actually be useless.
					$exported++;
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

			fputcsv($out, array_keys($row), chr(9), '"');	
			$flag = true;
		}
		array_walk($row, __NAMESPACE__ . '\cleanData');
		fputcsv($out, array_values($row), chr(9), '"');
		}
		// Send the newly created file to the user.
		fclose($out);


		// Force the download
		header('Content-Disposition: attachment; filename="ADDRESSES.txt" . basename($out) . ""');
		header("Content-Length: " . filesize($out));
		header("Content-Type: application/octet-stream;");
		header("Pragma: no-cache");
		header("Expires: 0");
		header("Cache-Control: must-revalidate");
		readfile($out);
	  	die();
	}
}
