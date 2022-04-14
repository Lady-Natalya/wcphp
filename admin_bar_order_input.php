// V 1.0.2
// Creates a box at the top of the Admin view where one can type in an order (or post) ID and be taken to its edit page

add_action('admin_bar_menu', 'add_toolbar_items', 100);
function add_toolbar_items($admin_bar){
	$admin_bar->add_menu(
	  	array(
			'id'    => 'order-input',
			'title' => '<input id="orderlookup"
				onClick="if (orderlookup.value.length > 0) {
					let sanitizedVal = orderlookup.value.replace(/\D/g, \'\');
					window.location = \'https://mosaicartsupply.com/wp-admin/post.php?post=\' + sanitizedVal + \'&action=edit\';
				}"
				class="testclass"
				placeholder="Go to Order #"
				style="display:inline-block; min-width:6rem; padding:0 0.5rem; background-color:#FFFFFF; border:none; box-shadow: inset 0 0 1px #000000;"
				>
				<script>window.addEventListener("pageshow", () => {
					orderlookup.value = \'\';
				});

				let orderinput = document.getElementById("orderlookup");
				orderinput.addEventListener("keyup", function(event) {
					if (event.keyCode === 13) {
						event.preventDefault();
						orderinput.click();
					}
				});</script>',
			'html' => '',
			'meta'  => array('title' => __('Order Input'),),
		)
	);
}

