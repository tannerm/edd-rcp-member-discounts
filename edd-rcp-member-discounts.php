<?php
/*
Plugin Name: Easy Digital Downloads - Restrict Content Pro Member Discounts
Plugin URI: http://easydigitaldownloads.com/restrict-content-pro
Description: Give RCP subscribers special discounts to EDD products
Author: Pippin Williamson
Author URI: http://pippinsplugins.com
Version: 1.0
*/

class EDD_RCP {

	private static $instance;


	/**
	 * Get active object instance
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @static
	 * @return object
	 */
	public static function get_instance() {

		if ( ! self::$instance )
			self::$instance = new EDD_Volume_Discounts();

		return self::$instance;
	}

	/**
	 * Class constructor.  Includes constants, includes and init method.
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		define( 'EDD_RCP_STORE_API_URL', 'https://easydigitaldownloads.com' );
		define( 'EDD_RCP_PRODUCT_NAME', 'Restrict Content Pro Member Discounts' );
		define( 'EDD_RCP_VERSION', '1.0' );

		$this->includes();
		$this->init();

	}


	/**
	 * Include our extra files
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	private function includes() {

		if( is_admin() ) {

			include dirname( __FILE__ ) . '/includes/admin.php';

		}

	}


	/**
	 * Run action and filter hooks.
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	private function init() {

		if( ! class_exists( 'Easy_Digital_Downloads' ) )
			return; // EDD not present

		global $edd_options;

		if( is_admin() ) {
			$admin = new EDD_RCP_Discounts_Admin;
		}

		// internationalization
		add_action( 'init', array( $this, 'textdomain' ) );

		// Register the Volume Discounts post type
		add_action( 'init', array( $this, 'register_post_type' ), 100 );

		// Apply discounts to the checkout
		add_action( 'init', array( $this, 'apply_discounts' ) );

		// Increase discount usage count
		add_action( 'edd_insert_payment', array( $this, 'store_discount_id' ), 10, 2 );
		add_action( 'edd_update_payment_status', array( $this, 'increase_use_count' ), 10, 3 );

		$license = new EDD_License( __FILE__, 'Restrict Content Pro Member Discounts', EDD_RCP_VERSION, 'Pippin Williamson', 'edd_rcp_license_key' );

	}


	/**
	 * Load plugin text domain
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public static function textdomain() {

		// Set filter for plugin's languages directory
		$lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
		$lang_dir = apply_filters( 'edd_rcp_lang_directory', $lang_dir );

		// Load the translations
		load_plugin_textdomain( 'edd-rcp', false, $lang_dir );

	}


	/**
	 * register the post type
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function register_post_type() {

		/** Payment Post Type */
		$labels = array(
			'name' 				=> _x( 'Member Discounts', 'post type general name', 'edd-rcp' ),
			'singular_name' 	=> _x( 'Member Discount', 'post type singular name', 'edd-rcp' ),
			'add_new' 			=> __( 'Add New', 'edd-rcp' ),
			'add_new_item' 		=> __( 'Add New Member Discount', 'edd-rcp' ),
			'edit_item' 		=> __( 'Edit Member Discount', 'edd-rcp' ),
			'new_item' 			=> __( 'New Member Discount', 'edd-rcp' ),
			'all_items' 		=> __( 'Member Discounts', 'edd-rcp' ),
			'view_item' 		=> __( 'View Member Discount', 'edd-rcp' ),
			'search_items' 		=> __( 'Search Member Discounts', 'edd-rcp' ),
			'not_found' 		=> __( 'No Member Discounts found', 'edd-rcp' ),
			'not_found_in_trash'=> __( 'No Member Discounts found in Trash', 'edd-rcp' ),
			'parent_item_colon' => '',
			'menu_name' 		=> __( 'Member Discounts', 'edd-rcp' )
		);

		$args = array(
			'labels' 			=> apply_filters( 'edd_rcp_discounts_labels', $labels ),
			'public' 			=> false,
			'show_ui' 			=> true,
			'show_in_menu'      => 'edit.php?post_type=download',
			'query_var' 		=> false,
			'rewrite' 			=> false,
			'capability_type' 	=> 'shop_discount',
			'map_meta_cap'      => true,
			'supports' 			=> array( 'title' ),
			'can_export'		=> false
		);

		register_post_type( 'edd_rcp_discount', $args );
	}


	/**
	 * Apply the discounts to the cart
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function apply_discounts() {

		if( ! function_exists( 'rcp_is_active' ) )
			return;

		// Get cart details
		$cart_amount = edd_get_cart_subtotal();

		// Get member details
		$user_id   = get_current_user_id();
		$sub_id    = rcp_get_subscription_id( $user_id );
		$is_active = ! rcp_is_expired( $user_id ) && rcp_get_status( $user_id ) == 'active' && ! empty( $sub_id );

		$discounts = get_posts( array(
			'post_type'      => 'edd_rcp_discount',
			'posts_per_page' => '1',
			'meta_query'     => array(
				'relation'   => 'AND',
				array(
					'key'    => '_edd_rcp_discount_subscription',
					'value'  => $sub_id
				)
			),
			'fields'         => 'ids'
		) );

		if( $discounts && $is_active ) {

			foreach( $discounts as $discount ) {

				$percent = get_post_meta( $discount, '_edd_rcp_discount_amount', true );
				$amount  = ( $cart_amount * ( $percent / 100 ) ) * -1;
				$amount  = round( $amount, 2 );
				EDD()->fees->add_fee( $amount, get_the_title( $discount ), 'rcp_member_discount' );
				EDD()->session->set( 'rcp_member_discount_id', $discount );
			}

		} else {

			EDD()->fees->remove_fee( 'rcp_member_discount' );
			EDD()->session->set( 'rcp_member_discount_id', null );

		}

	}


	/**
	 * Stores the discount ID in the payment meta when the payment is created
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function store_discount_id( $payment_id = 0, $payment_data = array() ) {

		$discount_id = EDD()->session->get( 'rcp_member_discount_id' );
		EDD()->session->set( 'rcp_member_discount_id', null );

		if( ! empty( $discount_id ) ) {
			add_post_meta( $payment_id, '_edd_rcp_member_discount_id', $discount_id );
		}

	}


	/**
	 * Increase the discount use count when a purchase is completed
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function increase_use_count( $payment_id, $new_status, $old_status ) {

		if ( $old_status == 'publish' || $old_status == 'complete' )
			return; // Make sure that payments are only completed once

		// Make sure the payment completion is only processed when new status is complete
		if ( $new_status != 'publish' && $new_status != 'complete' )
			return;

		$discount_id = get_post_meta( $payment_id, '_edd_rcp_member_discount_id', true );
		if( ! empty( $discount_id ) ) {
			$count = absint( get_post_meta( $discount_id, '_edd_rcp_discount_use_count', true ) );
			if( empty( $count ) ) {
				add_post_meta( $discount_id, '_edd_rcp_discount_use_count', '1' );
			} else {
				$count += 1;
				update_post_meta( $discount_id, '_edd_rcp_discount_use_count', $count );
			}
		}

	}

}


/**
 * Get everything running
 *
 * @since 1.0
 *
 * @access private
 * @return void
 */

function edd_rcp_load() {
	$discounts = new EDD_RCP();
}
add_action( 'plugins_loaded', 'edd_rcp_load' );