/*
* V 2.0.2
* Use this to mark orders as complete in bulk by exporting your postage log
* Be sure to put the WooCommerce order number into the Reference ID box when printing each label
* Put this in a code snippet or into functions.php
*
* This script:
* - Adds a submenu item called DAZzle + WorldShip Import to WooCommerce menu on the side
* - Creates a simple page in there that lets you upload the tab-delimited .txt file DAZzle exported
* - As of 2.0.0 you can also import a WorldShip .xml file
* - When you import the file it'll process a .txt as a DAZzle file and a .xml as a WorldShip file
* - Parses the uploaded file and looks for order numbers in the Reference ID column
* - Changes order status of found orders to "Completed" and inputs tracking numbers
* - In either DAZzle or WorldShip a multi-order box can have multiple order numbers recorded in the Reference ID as NUM + NUM
* - Example:  96652 + 95563
* - Quirks with DAZzle mean it will only upload to WooCommerce the MOST RECENTLY PRINTED tracking number
* - WorldShip multiple tracking numbers are already supported
*
*
*	Version 2.0.2:
*	- Fixed a bug where combination orders with multiple boxes would have the tracking numbers doubled
*
*	Version 2.0.0:
*	- Allows for similar import from UPS WorldShip.
*/

// ################
// Add Link to Menu
// ################


add_action( 'post_edit_form_tag' , 'post_edit_form_tag' );

function post_edit_form_tag( ) {
	echo ' enctype="multipart/form-data"';
}

add_action('admin_menu', 'dazzle_imported_menu_link');

function dazzle_imported_menu_link() {
	add_submenu_page(
		'woocommerce',							/* parent_slug - Name of parent menu or WP admin page */
	  	'DAZzle and WorldShip Import Page',					/* page_title - Title of the page itself */
		'Dazzle + WorldShip Import',						/* menu_title - How it displays in the menu on the left */
	 	'manage_options',						/* capability - Required capability for user to use this menu */
		'dazzle-ws-import-page',					/* menu_slug - Unique slug name for this menu */
		'dazzle_ws_import_menu_link_callback'	/* function - Name of function called for page output */
	); 
}


// #############################
// Display Page and Process File
// #############################


function dazzle_ws_import_menu_link_callback() {
	global $post; // Used later to see if valid order ID
	?>
	<style>
		h2 {
			margin-bottom:0;
		}
		div.main-div {
			display:inline-block;
		  	background:#FFF;
			color:#000;
			margin:0.5rem;
			padding:0.5rem;
			width:100%;
		}
	  	.tracking {
			padding: 0 0.125rem;
		}
		.bg-completed {
			background-color: #FFFFDD;
		}
		.bg-error {
			background-color: #FFBBBB;
		}
	</style>
	<h2>DAZzle and WorldShip Import</h2>
	<div class="main-div">
	<?php

	if(isset($_FILES['upload']))
	{
		// User already uploaded a file.
		$errors= array();
		$file_name = $_FILES['upload']['name'];
		$file_size =$_FILES['upload']['size'];
		$file_tmp = $_FILES['upload']['tmp_name'];
	  	$file_type = -1;
		$file_ext=strtolower(end(explode('.',$_FILES['upload']['name'])));

		if(strcasecmp($file_ext, "txt") == 0){
			$file_type = 0;
		} else if (strcasecmp($file_ext, "xml") == 0){
			$file_type = 1;
		} else $errors[]="File extension incorrect.  Please choose a .TXT or .XML file.";

		if($file_size > 4194304){
		  	/*
		  	*	This number is arbitrary, but a size limit ought to be specified for security purposes.
		  	*	Feel free to change this number depending on the nature of your e-commerce site.
		  	*	Default is 4194304, or 4 MB.
		  	*/
			$errors[]='File size must be less than 4 MB.';
		}

		if(empty($errors)==true){
			// We end up here if the file checks out.  Now it can be parsed.
			$file = $_FILES['upload']['tmp_name'];
			
	  		?>
	  		<span>Loaded File <?php echo $file_name;?></span><br />
	  		<br />
	  		<?php
		  	
			$str = file_get_contents($_FILES['upload']['tmp_name']);
			$count = 0;
		  	$completed_count = 0;

		  	switch ($file_type) {
				case 0: // TXT file from DAZzle
					$sanitized_str = sanitize_file($str);
					
					/*
					* User should have uploaded a tab-delimited text file exported by DAZzle.
					* This loop will break it up and then grab the Reference IDs which should be order numbers.
					* It will mark each found order number as completed if the order exists.
					*
					* IMPORTANT:
					* DAZzle exports every label when you choose a date range, including labels that had issues and needed to be refunded.
					* It does NOT, however, export refund-request status.
					* And there is no way to edit a shipping label once created, so if there are any errors you must refund it and make a new label.
					* Because we can't check whether or not a label was refunded, the best we can do is make sure we're only saving the LAST tracking number.
					* This means we can't save all tracking numbers to an order if it had multiple boxes.
					* We have no way of knowing if multiple tracking numbers w/ same order ID = multiple boxes OR cancelled labels.
					* So the best we can do is assume the last seen tracking number for a given order ID is, itself, correct.
					*/
					
					$array_file = explode("\n",$sanitized_str);
					foreach ($array_file as $line)
					{
						$arr_line = explode("\t", $line);
						/*
						* Reference ID is the 16th column of the exported file.
						* preg_replace is making sure that we end up with 0-9 or + only.
						* Maximum length is 25 characters.
						*/
					  	$arr_order = check_ref_id_for_order_numbers($arr_line[15]);
					  
						foreach ($arr_order as $order_id)
						{
							if (validate_order_id($order_id))
							{
								// This meta data field will be stored in the db, and can be used by other scripts or plugins
								update_post_meta($order_id, 'usps_tracking_number', $arr_line[12]);
							}
						}
					}

					/*
					* Because of the aforementioned DAZzle issue with tracking numebers and edited labels, we have to loop through the array again.
					* WooCommerce by default sends an e-mail to a customer when the order has had its status changed to completed.
					* If you update your completed order e-mail to include the tracking number, you want to make sure you send a valid tracking number.
					* So we're not going to update the order status until this loop, because it's safe to assume that the last tracking number saved for a given order ID is probably valid.
					*/
					foreach ($array_file as $line)
					{
						$arr_line = explode("\t", $line);
						$order_str = preg_replace('/[^0-9,\+]/', '', $arr_line[15]);
						$arr_order = explode("+", $order_str);
						foreach ($arr_order as $order_id)
						{
							if (is_numeric($order_id))
							{
								if ($order = wc_get_order($order_id))
								{
									// We're going to display for the user a list of completed orders and their tracking numbers, with links to each order.
								  	$link = format_order_link($order_id);

									$usps = get_post_meta( $order_id, 'usps_tracking_number', true);

									// We only want to mark an order completed once.  If it was already completed we'll leave this note.
								  	$status = $order->get_status();
									if (($status == 'completed') || ($status == 'refunded')) {
										echo '<span class="tracking bg-completed">Order #' , $link , ' has had its USPS tracking number set to ', $usps ,' and had already been marked as ' , strtoupper($status) , '</span><br />';
									}
									else
									{					  
										$order->set_status('completed');
										$order->save();
										echo '<span class="tracking">Order #' , $link , ' has had its USPS tracking number set to ', $usps ,' and has had its status changed to: COMPLETED</span><br />';
										$completed_count += 1;
									}
									$count += 1;
								} else echo '<span class="tracking bg-error">Order #' , $order_id , ' DOES NOT EXIST</span><br />'; 
							}
						}
					}	    
					break;
				case 1: // XML file from WorldShip
					$xml = simplexml_load_string($str);
					foreach ($xml->Shipment as $shipment)
					{
						// Each shipment may contain multiple boxes, and each box may contain multiple Order IDs
						$temp_valid_order_id_array = array();
						$temp_valid_box_tracking_array = array();

					  	if ($shipment->VoidIndicator == 'Y') {
							continue 1;  // Exclude voided shipments
						}
					  	foreach ($shipment->Packages->Package as $package)
						{
							if ($package->VoidIndicator == 'Y') {
								continue 1;  // Exclude voided boxes
							}
							foreach ($package->ReferenceNumbers->children() as $reference)
							{
								$arr_order = check_ref_id_for_order_numbers($reference);
								foreach ($arr_order as $order_id)
								{
									if (validate_order_id($order_id))
									{
									  	// echo 'Checking to see if we should put Order ID in Array: '. $order_id . '<br />';
										if (!in_array($order_id, $temp_valid_order_id_array)) {
										  	// echo 'Putting Order ID in Array: '. $order_id . '<br />';
											array_push($temp_valid_order_id_array, $order_id);
										}
										if (!in_array($package->TrackingNumber, $temp_valid_box_tracking_array)) { // This one needed in case of a multi-box combination order (multiple order IDs)
											array_push($temp_valid_box_tracking_array, $package->TrackingNumber);
										}
									  	$count += 1;
									}
								}
							}
						}
					  	if((!empty($temp_valid_box_tracking_array)) && (!empty($temp_valid_order_id_array))){
							foreach($temp_valid_order_id_array as $order_id) {
							  	$tracking_str = '';
							  	$tracking_link_str = '';
								foreach($temp_valid_box_tracking_array as $tracking_number) {
								  	$tracking_link = format_ups_link($tracking_number);
								  	if (empty($tracking_str)) {
										$tracking_str = $tracking_number;
										$tracking_link_str = $tracking_link;
									} else {
										$tracking_str = $tracking_str . ' + ' . $tracking_number;
									  	$tracking_link_str = $tracking_link_str . ' + ' . $tracking_link;
									}
								}
							  	if (validate_order_id($order_id) && ($order = wc_get_order($order_id)))
								{
								  	$link = format_order_link($order_id);
									update_post_meta($order_id, 'ups_tracking_number', (string)$tracking_str);
								  	$status = $order->get_status();
									if (($status == 'completed') || ($status == 'refunded')) {
										echo '<span class="tracking bg-completed">Order #' . $link . ' has had its UPS tracking set to ' . $tracking_link_str . ' and had already been marked as ' , strtoupper($status) , '</span><br />';
									} else {
										$order->set_status('completed');
										$order->save();
										echo '<span class="tracking">Order #' . $link . ' has had its UPS tracking set to ' . $tracking_link_str . ' and has had its status changed to: COMPLETED</span><br />';
										$completed_count += 1;
									}
								} else echo '<span class="tracking bg-error">Order #' , $order_id , ' DOES NOT EXIST</span><br />';
							}
						}
					}
					break;
				default: // There may have been an error.
					break;
			}
		  
			echo "<ul>
				<li>File size: " . $_FILES['upload']['size'] . "</li>
				<li>File type: " . $_FILES['upload']['type'] . "</li>
			</ul>
			<br />Number of Box Labels Processed:  " , $count;
		  	echo '<br />Number of Orders Marked Complete:  ', $completed_count;
		}else{
			print_r($errors);
		}
	}
	else
	{
		// Looks like a file has not been uploaded yet.  This will display the file upload box.
		echo 'Use this tool to import a USPS Endicia DAZzle .txt file or a UPS WorldShip .xml file of exported printed labels to import into WooCommerce and update the orders.<br /><br />
		<form action="" method="POST" enctype="multipart/form-data">
			<input type="file" name="upload" \>
			<input type="submit" value="Upload" \>
		</form>';
	}
	echo '</div>';
}

function sanitize_file($str) {
	// Sanitize uploaded DAZzle file
	$validation_data = array(
		"'" => "",
		"," => "",
		"&" => "AND",
		"Outbound/Return" => "OutboundOrReturn",
		"/" => "-",
		"Postage ($)" => "Postage",
		"Balance ($)" => "Balance",
		"Declared Value ($)" => "DeclaredValue",
		"$" => "USD",
		"Weight (oz)" => "WeightOunces",
		"Reference ID" => "ReferenceID",
		"Group Code" => "GroupCode",
	);
	foreach ($validation_data as $find => $replace)
	{	
		$str = str_replace($find, $replace, $str);
	}
	return $str;
}

function check_ref_id_for_order_numbers($ref_str) {
	$order_str = preg_replace('/[^0-9,\+]/', '', $ref_str);
	return explode("+", $order_str);
}

function validate_order_id($order_id) {
	if (is_numeric($order_id))
	{
		if ($order = wc_get_order($order_id))
		{
			return true;
		}
	}
  	return false;
}

function format_order_link($order_id) {
	return '<a href="'. admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' ) .'" >' . $order_id . '</a>';
}
function format_ups_link($tracking_number) {
	return '<a href="http://wwwapps.ups.com/WebTracking/track?track=yes&trackNums=' . $tracking_number . '">' . $tracking_number . '</a>';
}
