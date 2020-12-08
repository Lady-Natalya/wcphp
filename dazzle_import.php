// V 1.0.1
// Use this to mark orders as complete in bulk by exporting your postage log
// Be sure to put the WooCommerce order number into the Reference ID box when printing each label
// Put this in a code snippet or into functions.php

// This script:
// - Adds a submenu item called DAZzle Import to WooCommerce menu on the side
// - Creates a simple page in there that lets you upload the tab-delimited .txt file DAZzle exported
// - Parses that file and looks for order numbers in the Reference ID column
// - Changes order status of found orders to "Completed"


// ################
// Add Link to Menu
// ################


add_action( 'post_edit_form_tag' , 'post_edit_form_tag' );

function post_edit_form_tag( ) {
	echo ' enctype="multipart/form-data"';
}

add_action('admin_menu', 'dazzle_imported_menu_link');

function dazzle_imported_menu_link() {
	add_submenu_page( 'woocommerce', 'DAZzle Import Page' /* Title of the page itself. */, 'Dazzle Import' /* How it displays in the menu on the left. */, 'manage_options', 'dazzle-import-page', 'dazzle_imported_menu_link_callback' ); 
}


// #############################
// Display Page and Process File
// #############################


function dazzle_imported_menu_link_callback() {
	global $post; // Used later to see if valid order ID
	echo '<h2 style="margin-bottom:0;">DAZzle Import</h2>
	<div style="display:inline-block;background:#FFF;color:#000;margin:0.5rem;padding:0.5rem;width:100%;">';

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
			$errors[]='File size must be less than 2 MB.';
		}

		if(empty($errors)==true){
			// We end up here if the file checks out.  Now it can be parsed.
			$file = $_FILES['upload']['tmp_name'];
			echo '<span>Loaded File ', $file_name, '</span><br /><br />';
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
			* User should have uploaded a tab-delimited text file exported by DAZzle
			* This loop will break it up and then grab the Reference IDs which should be order numbers
			* It will mark each found order number as completed if the order exists
			*/
			$array_file = explode("\n",$str);
			foreach ($array_file as $line)
			{
				$arr_line = explode("\t", $line);
				// $order_id = $arr_line[15];
			  	$order_str = preg_replace('/[^0-9,\+]/', '', $arr_line[15]); // Reference ID is the 16th column of the exported file.  preg_replace is making sure that we end up with 0-9 or + only. Maximum length is 25 characters.
				$arr_order = explode("+", $order_str);
				foreach ($arr_order as $order_id)
				{
					if (is_numeric($order_id))
					{
						if ($order = wc_get_order($order_id))
						{
							update_post_meta($order_id, 'usps_tracking_number', $arr_line[12]);
							//echo $order_id, ' ';
						}
					}
				}
			}
		  	
			$completed_count = 0;
			
			foreach ($array_file as $line)
			{
				$arr_line = explode("\t", $line);
				// $order_id = $arr_line[15];
			  	$order_str = preg_replace('/[^0-9,\+]/', '', $arr_line[15]);
				$arr_order = explode("+", $order_str);
				foreach ($arr_order as $order_id)
				{
					if (is_numeric($order_id))
					{
						if ($order = wc_get_order($order_id))
						{
							$link = '<a href="'. admin_url( 'post.php?post=' . absint( $order->get_id() ) . '&action=edit' ) .'" >' . $order_id . '</a>';
							
							$usps = get_post_meta( $order_id, 'usps_tracking_number', true);
							if ($order->get_status() == 'completed')
							{
								echo '<span style="background-color: #FFFFDD;">Order #' , $link , ' has had its USPS tracking number set to ', $usps ,' and had already been marked as COMPLETED</span><br />';
							}
							else
							{					  
								$order->set_status('completed');
								$order->save();

								echo '<span>Order #' , $link , ' has had its USPS tracking number set to ', $usps ,' and has had its status changed to: ' , strtoupper($order->get_status( )) , '</span><br />';
								$completed_count += 1;
							}
							$count += 1;
						}
						else
						{
							echo '<span style="background-color: #FFBBBB;">Order #' , $order_id , ' DOES NOT EXIST</span><br />'; // Maybe they typed the reference ID into DAZzle wrong?
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
