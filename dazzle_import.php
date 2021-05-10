/*
* V 1.0.2
* Use this to mark orders as complete in bulk by exporting your postage log
* Be sure to put the WooCommerce order number into the Reference ID box when printing each label
* Put this in a code snippet or into functions.php
*
* This script:
* - Adds a submenu item called DAZzle Import to WooCommerce menu on the side
* - Creates a simple page in there that lets you upload the tab-delimited .txt file DAZzle exported
* - Parses that file and looks for order numbers in the Reference ID column
* - Changes order status of found orders to "Completed"
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
		'woocommerce',	/* parent_slug - Name of parent menu or WP admin page */
	  	'DAZzle Import Page',	/* page_title - Title of the page itself */
		'Dazzle Import',	/* menu_title - How it displays in the menu on the left */
	 	'manage_options',	/* capability - Required capability for user to use this menu */
		'dazzle-import-page',	/* menu_slug - Unique slug name for this menu */
		'dazzle_imported_menu_link_callback'	/* function - Name of function called for page output */
	); 
}


// #############################
// Display Page and Process File
// #############################


function dazzle_imported_menu_link_callback() {
	global $post; // Used later to see if valid order ID
	?>
	<h2 style="margin-bottom:0;">DAZzle Import</h2>
	<div style="display:inline-block;background:#FFF;color:#000;margin:0.5rem;padding:0.5rem;width:100%;">
	<?php

	if(isset($_FILES['upload']))
	{
		// User already uploaded a file.
		$errors= array();
		$file_name = $_FILES['upload']['name'];
		$file_size =$_FILES['upload']['size'];
		$file_tmp = $_FILES['upload']['tmp_name'];
		$file_type=$_FILES['upload']['type'];
		$file_ext=strtolower(end(explode('.',$_FILES['upload']['name'])));

		if(strcasecmp($file_ext, "txt") != 0){
			$errors[]="File extension incorrect.  Please choose a .TXT file.";
		}

		if($file_size > 2097152){
		  	/*
		  	*	This number is arbitrary, but a size limit ought to be specified for security purposes.
		  	*	Feel free to change this number depending on the nature of your e-commerce site.
		  	*	Default is 2097152, or 2 MB.
		  	*/
			$errors[]='File size must be less than 2 MB.';
		}

		if(empty($errors)==true){
			// We end up here if the file checks out.  Now it can be parsed.
			$file = $_FILES['upload']['tmp_name'];
			
	  		?>
	  		<span>Loaded File ', $file_name, '</span><br />
	  		<br />
	  		<?php
		  	
			$str = file_get_contents($_FILES['upload']['tmp_name']);
			$count = 0;

			// Sanitize uploaded file -- this might not be necessary
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
			* So the best we can do is assume the last seen tracking number for a given order ID is, itself correct.
			*/
			$array_file = explode("\n",$str);
			foreach ($array_file as $line)
			{
				$arr_line = explode("\t", $line);
			  	/*
				* Reference ID is the 16th column of the exported file.
			  	* preg_replace is making sure that we end up with 0-9 or + only.
				* Maximum length is 25 characters.
			  	*/
				$order_str = preg_replace('/[^0-9,\+]/', '', $arr_line[15]);
				$arr_order = explode("+", $order_str);
				foreach ($arr_order as $order_id)
				{
					if (is_numeric($order_id))
					{
						if ($order = wc_get_order($order_id))
						{
						  	// This meta data field will be stored in the db, and can be used by other scripts or plugins
							update_post_meta($order_id, 'usps_tracking_number', $arr_line[12]);
						}
					}
				}
			}
		  	
			$completed_count = 0;
			
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
							$link = '<a href="'. admin_url( 'post.php?post=' . absint( $order->get_id() ) . '&action=edit' ) .'" >' . $order_id . '</a>';
							
						  	$usps = get_post_meta( $order_id, 'usps_tracking_number', true);
						  	
						  	// We only want to mark an order completed once.  If it was already completed we'll leave this note.
							if ($order->get_status() == 'completed')
							{
								echo '<span style="padding: 0 0.125rem; background-color: #FFFFDD;">Order #' , $link , ' has had its USPS tracking number set to ', $usps ,' and had already been marked as COMPLETED</span><br />';
							}
							else
							{					  
								$order->set_status('completed');
								$order->save();
								echo '<span style"padding: 0 0.125rem;">Order #' , $link , ' has had its USPS tracking number set to ', $usps ,' and has had its status changed to: ' , strtoupper($order->get_status( )) , '</span><br />';
								$completed_count += 1;
							}
							$count += 1;
						}
						else
						{
						  	// We use this to let the user know if a given reference ID in DAZzle was not a valid order id.
							echo '<span style="padding: 0 0.125rem; background-color: #FFBBBB;">Order #' , $order_id , ' DOES NOT EXIST</span><br />';
						}
					}
				}
			}
			echo '<ul>
				<li>File size: ' . $_FILES[upload][size] . '</li>
				<li>File type: ' . $_FILES[upload][type] . '</li>
			</ul>
			<br />Number of Orders Processed:  ' , $count;
		  	echo '<br />Number of Orders Marked Complete:  ', $completed_count;
		}else{
			print_r($errors);
		}
	}
	else
	{
		// Looks like a file has not been uploaded yet.  This will display the file upload box.
		echo 'Use this tool to import a USPS Endicia DAZzle .txt file of exported printed labels to import into WooCommerce and update the orders.<br /><br />
		<form action="" method="POST" enctype="multipart/form-data">
			<input type="file" name="upload" \>
			<input type="submit" value="Upload" \>
		</form>';
	}
	echo '</div>';
}
