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
		define( 'EDD_RCP_PRODUCT_NAME', 'EDD Restrict Content Pro Member Discounts' );
		define( 'EDD_RCP_VERSION', '1.0.2' );

		if( !class_exists( 'EDD_SL_Plugin_Updater' ) ) {
			// load our custom updater
			include( dirname( __FILE__ ) . '/EDD_SL_Plugin_Updater.php' );
		}

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

		// register our license key settings
		add_filter( 'edd_settings_general', array( $this, 'settings' ), 1 );

		// activate license key on settings save
		add_action( 'admin_init', array( $this, 'activate_license' ) );

		// auto updater

		// retrieve our license key from the DB
		$edd_rcp_license_key = isset( $edd_options['edd_rcp_license_key'] ) ? trim( $edd_options['edd_rcp_license_key'] ) : '';

		// setup the updater
		$edd_updater = new EDD_SL_Plugin_Updater( EDD_RCP_STORE_API_URL, __FILE__, array(
				'version' 	=> EDD_RCP_VERSION, 		// current version number
				'license' 	=> $edd_rcp_license_key, // license key (used get_option above to retrieve from DB)
				'item_name' => EDD_RCP_PRODUCT_NAME, // name of this plugin
				'author' 	=> 'Pippin Williamson'  // author of this plugin
			)
		);

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


	/**
	 * Add our extension settings
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return array
	 */
	public function settings( $settings ) {
		$license_settings = array(
			array(
				'id' => 'edd_volume_discounts_license_header',
				'name' => '<strong>' . __( 'Volume Discounts', 'edd-rcp' ) . '</strong>',
				'desc' => '',
				'type' => 'header',
				'size' => 'regular'
			),
			array(
				'id' => 'edd_rcp_license_key',
				'name' => __( 'License Key', 'edd-rcp' ),
				'desc' => __( 'Enter your license for Volume Discounts to receive automatic upgrades', 'edd-rcp' ),
				'type'  => 'license_key',
				'size'  => 'regular',
				'options' => array( 'is_valid_license_option' => 'edd_volume_discounts_license_active' )
			)
		);

		return array_merge( $settings, $license_settings );
	}


	/**
	 * Activate a license key
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function activate_license() {

		global $edd_options;

		if( ! isset( $_POST['edd_settings_general'] ) )
			return;
		if( ! isset( $_POST['edd_settings_general']['edd_rcp_license_key'] ) )
			return;

		if( get_option( 'edd_volume_discounts_license_active' ) == 'valid' )
			return;

		$license = sanitize_text_field( $_POST['edd_settings_general']['edd_rcp_license_key'] );

		// data to send in our API request
		$api_params = array(
			'edd_action'=> 'activate_license',
			'license' 	=> $license,
			'item_name' => urlencode( EDD_RCP_PRODUCT_NAME ) // the name of our product in EDD
		);

		// Call the custom API.
		$response = wp_remote_get( add_query_arg( $api_params, EDD_RCP_STORE_API_URL ), array( 'timeout' => 15, 'body' => $api_params, 'sslverify' => false ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) )
			return false;

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		update_option( 'edd_volume_discounts_license_active', $license_data->license );

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



/**
 * Registers the new license field type
 *
 * @access      private
 * @since       10
 * @return      void
*/

if( ! function_exists( 'edd_license_key_callback' ) ) {
	function edd_license_key_callback( $args ) {
		global $edd_options;

		if( isset( $edd_options[ $args['id'] ] ) ) { $value = $edd_options[ $args['id'] ]; } else { $value = isset( $args['std'] ) ? $args['std'] : ''; }
		$size = isset( $args['size'] ) && !is_null($args['size']) ? $args['size'] : 'regular';
		$html = '<input type="text" class="' . $args['size'] . '-text" id="edd_settings_' . $args['section'] . '[' . $args['id'] . ']" name="edd_settings_' . $args['section'] . '[' . $args['id'] . ']" value="' . esc_attr( $value ) . '"/>';

		if( 'valid' == get_option( $args['options']['is_valid_license_option'] ) ) {
			$html .= wp_nonce_field( $args['id'] . '_nonce', $args['id'] . '_nonce', false );
			$html .= '<input type="submit" class="button-secondary" name="' . $args['id'] . '_deactivate" value="' . __( 'Deactivate License',  'edd-recurring' ) . '"/>';
		}
		$html .= '<label for="edd_settings_' . $args['section'] . '[' . $args['id'] . ']"> '  . $args['desc'] . '</label>';

		echo $html;
	}
}