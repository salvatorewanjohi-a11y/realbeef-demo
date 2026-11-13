<?php
/**
 * Builds the RealBeef Africa site inside WordPress Playground (same pipeline as our Aura Glow and The Daily builds).
 * Run by the blueprint after WooCommerce and the theme are installed. Jar photos are read from /wordpress/rb-images.
 *
 * Real prices (RealBeef's Facebook post and Tushop listing, Nov 2026): 500g Ksh 500, 1kg Ksh 850, 2kg Ksh 1,700.
 * Products marked sample (bundles, the chef tub, the balm) are placeholders until RealBeef confirms them.
 * Staff edit names, prices, photos and badges under Products in wp-admin; the shop on the home page reads them live.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Photos are already web-sized: copy each into uploads and register it, without generating thumbnails.
function rb_sideload( $src, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], basename( $src ) );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! copy( $src, $dest ) ) {
		return 0;
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'] ?: 'image/webp', 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

if ( defined( 'RB_FAST' ) && RB_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

foreach ( [
	'blogname'                       => 'RealBeef Africa',
	'blogdescription'                => '100% grass-fed beef tallow. Pure, single-ingredient cooking fat, delivered same-day in Nairobi and countrywide.',
	'timezone_string'                => 'Africa/Nairobi',
	'woocommerce_currency'           => 'KES',
	'woocommerce_currency_pos'       => 'left_space',
	'woocommerce_price_num_decimals' => '0',
	'woocommerce_price_thousand_sep' => ',',
	'woocommerce_default_country'    => 'KE:KE30',
	'woocommerce_store_city'         => 'Nairobi',
	'woocommerce_manage_stock'       => 'no',
	'woocommerce_enable_reviews'     => 'no',
	'woocommerce_onboarding_profile' => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'   => 'yes',
	'woocommerce_coming_soon'        => 'no',
	'woocommerce_enable_coupons'     => 'no',
] as $k => $v ) {
	update_option( $k, $v );
}

foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample_page = get_page_by_path( 'sample-page' );
if ( $sample_page ) wp_delete_post( $sample_page->ID, true );

// Shop categories, in tab order.
$cats = [
	'starter'  => 'Starter Jars',
	'family'   => 'Family Kitchen Jars',
	'value'    => 'Value Packs',
	'chef'     => 'Chef & Bulk',
	'skincare' => 'Skincare',
];
$cat_ids = [];
$order   = 0;
foreach ( $cats as $slug => $name ) {
	$t                = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
	$cat_ids[ $slug ] = is_wp_error( $t ) ? (int) $t->get_error_data() : $t['term_id'];
	update_term_meta( $cat_ids[ $slug ], 'order', $order++ );
}

/*
 * name, size label, grams, price ('' = price on WhatsApp), "was" price for packs, category, uses, photo, jars in the
 * photo, badge, sample?, short description, WhatsApp enquiry text for products that can't go in the cart.
 * Uses: searing, frying, roasting, mealprep, skin.
 */
$products = [
	[ 'Starter Jar', '500g', 500, 500, '', 'starter', 'searing,roasting,skin', '500g', 1, 'Try it first', false,
		'The easy switch from cooking oil. Lasts a single cook about a month.', '' ],
	[ 'Family Kitchen Jar', '1kg', 1000, 850, '', 'family', 'searing,frying,roasting,mealprep', '1kg', 1, 'Best value jar', false,
		'Everyday frying, roasting and searing for a household of three to five.', '' ],
	[ 'Mega Pack', '2kg', 2000, 1700, '', 'family', 'frying,roasting,mealprep', '2kg', 1, '', false,
		'One big jar for big families, regular deep frying and bulk cooking.', '' ],
	[ 'Starter Trio', '3 × 500g', 1500, 1400, 1500, 'value', 'searing,roasting,skin', '500g', 3, '', true,
		'One for the kitchen, one for the pantry, one to gift a friend.', '' ],
	[ 'Twin Family Pack', '2 × 1kg', 2000, 1650, 1700, 'value', 'frying,roasting,mealprep', '1kg', 2, 'Smart buy', true,
		'Two easy-to-handle jars: one open, one sealed in the pantry.', '' ],
	[ 'Kitchen Stock-Up', '3 × 1kg', 3000, 2400, 2550, 'value', 'frying,roasting,mealprep', '1kg', 3, 'Free delivery', true,
		'Three months for most families, and over the free-delivery line.', '' ],
	[ 'Chef Bulk Tub', '5kg', 5000, 4000, 4250, 'chef', 'frying,searing,mealprep', '2kg', 1, '', true,
		'For restaurants, food trucks, chip shops and meal-prep kitchens.', '' ],
	[ 'Wholesale & Retail Supply', '10kg and up', 10000, '', '', 'chef', 'frying,searing,mealprep', '2kg', 3, '', false,
		'Stock RealBeef on your shelves or in your kitchen at wholesale prices.',
		"Hi RealBeef, I'd like wholesale / bulk pricing for beef tallow.\nBusiness name: \nQuantity per month: \nLocation: " ],
	[ 'Whipped Tallow Balm', 'Coming soon', 0, '', '', 'skincare', 'skin', '500g', 1, '', true,
		'Tallow is already on our label for skincare. A ready-whipped balm is on the way.',
		'Hi RealBeef, please add me to the waitlist for the tallow skin balm.' ],
];

$photos = [];
$count  = 0;
foreach ( $products as [ $name, $size, $grams, $price, $was, $cat, $uses, $photo, $stack, $badge, $sample, $desc, $enquire ] ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_menu_order( $count++ );
	$p->set_short_description( $desc );
	$p->set_category_ids( [ $cat_ids[ $cat ] ] );
	if ( $price !== '' ) $p->set_regular_price( (string) $price );
	if ( $was !== '' ) $p->update_meta_data( '_rb_was', (string) $was );
	$p->update_meta_data( '_rb_size', $size );
	$p->update_meta_data( '_rb_grams', (string) $grams );
	$p->update_meta_data( '_rb_uses', $uses );
	$p->update_meta_data( '_rb_stack', (string) $stack );
	if ( $badge ) $p->update_meta_data( '_rb_badge', $badge );
	if ( $sample ) $p->update_meta_data( '_rb_sample', 'yes' );
	if ( $enquire ) $p->update_meta_data( '_rb_enquire', $enquire );
	$pid = $p->save();

	// One attachment per jar photo, shared by every product that shows that jar.
	if ( ! isset( $photos[ $photo ] ) ) {
		$src              = "/wordpress/rb-images/realbeef-$photo.webp";
		$photos[ $photo ] = file_exists( $src ) ? rb_sideload( $src, $pid, "RealBeef $photo jar" ) : 0;
	}
	if ( $photos[ $photo ] ) set_post_thumbnail( $pid, $photos[ $photo ] );
}

// One-page shop: the WooCommerce shop page and product pages redirect to the home page (functions.php).
foreach ( [ 'cart', 'checkout', 'myaccount' ] as $wc_page ) {
	$id = wc_get_page_id( $wc_page );
	if ( $id > 0 ) wp_delete_post( $id, true );
}

update_option( 'permalink_structure', '/%postname%/' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
delete_option( 'product_cat_children' );
clean_taxonomy_cache( 'product_cat' );
_get_term_hierarchy( 'product_cat' );
flush_rewrite_rules();
echo "RealBeef: $count products\n";
