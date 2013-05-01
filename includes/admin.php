<?php
/**
 * Member Discounts Admin
 *
 * @package     Member Discounts
 * @subpackage  admin
 * @copyright   Copyright (c) 2013, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * EDD_RCP_Discounts_Admin Class
 *
 * @since 1.0
 */
class EDD_RCP_Discounts_Admin {


	function __construct() {

		add_action( 'admin_head',       array( $this, 'css'                  ) );
		add_action( 'add_meta_boxes',   array( $this, 'metaboxes'            ) );
		add_action( 'admin_menu',       array( $this, 'metaboxes'            ) );
		add_action( 'save_post',        array( $this, 'save_meta_box'        ) );
		add_filter( 'enter_title_here', array( $this, 'change_default_title' ) );
		add_filter( 'post_updated_messages', array( $this, 'updated_messages' ) );
		add_filter( 'manage_edit-edd_rcp_discount_columns', array( $this, 'columns' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_columns' ), 10, 2 );

	}


	/**
	 * Add / remove meta boxes
	 *
	 * @since 1.0
	 * @return void
	 */
	public function metaboxes() {

		add_meta_box( 'edd_rcp_discount_metabox_save', __( 'Save Member Discount', 'edd-rcp' ),  array( __CLASS__, 'render_save_metabox' ), 'edd_rcp_discount', 'side', 'core' );
		add_meta_box( 'edd_rcp_discount_metabox_options', __( 'Discount Options', 'edd-rcp' ),  array( __CLASS__, 'render_options_metabox' ), 'edd_rcp_discount', 'normal', 'high' );

		remove_meta_box( 'submitdiv', 'edd_rcp_discount', 'core' );
		remove_meta_box( 'slugdiv', 'edd_rcp_discount', 'core' );

		// Plugin compatibility with Restrict Content Pro
		remove_meta_box( 'rcp_meta_box', 'edd_rcp_discount', 'normal' );
		// Plugin compatibility with WordPress SEO
		remove_meta_box( 'wpseo_meta', 'edd_rcp_discount', 'normal' );
	}


	/**
	 * Add inline CSS to the top of the edit / new screen for Member Discounts
	 *
	 * This is to hide the Permalink section
	 *
	 * @since 1.0
	 * @return void
	 */
	public function css() {
		global $post, $pagenow;

		if( 'post.php' !== $pagenow && 'post-new.php' !== $pagenow )
			return;
		if( 'edd_rcp_discount' !== get_post_type( $post->ID ) )
			return;

		echo '<style>#edit-slug-box{display:none;}</style>';
	}


	/**
	 * Render the Save meta box
	 *
	 * @since 1.0
	 * @return void
	 */
	public function render_save_metabox() {
		global $post;
?>
		<div><?php _e( 'If the conditions are met, this discount will be automatically applied at checkout.', 'edd-rcp' ); ?></div>
<?php
		$text = 'publish' === get_post_status( $post->ID ) ? __( 'Update', 'edd-rcp' ) : __( 'Create', 'edd-rcp' );
		submit_button( $text );
		do_action( 'edd_rcp_discounts_save_metabox', $post->ID );
		wp_nonce_field( basename( __FILE__ ), 'edd_rcp_discounts_meta_box_nonce' );
	}


	/**
	 * Render the options meta box
	 *
	 * @since 1.0
	 * @return void
	 */
	public function render_options_metabox() {
		global $post;

		if( ! class_exists( 'RCP_Levels' ) ) {
			echo __( '<strong>Restrict Content Pro is not active. Please install and activate it.', 'edd-rcp' );
			return;
		}

		$subscription = get_post_meta( $post->ID, '_edd_rcp_discount_subscription', true );
		$amount       = get_post_meta( $post->ID, '_edd_rcp_discount_amount', true );
		$levels_db    = new RCP_Levels;
		$levels       = $levels_db->get_levels();
?>
		<table>
			<tr>
				<td>
					<label for="edd-rcp-subscription"><?php _e( 'Subscription Level', 'edd-rcp' ); ?></label>
				</td>
				<td>
					<select name="_edd_rcp_discount_subscription" id="edd-rcp-subscription">
						<?php foreach( $levels as $level ) : ?>
							<option value="<?php echo $level->id; ?>"<?php selected( $subscription, $level->id ); ?>><?php echo $level->name; ?></option>
						<?php endforeach; ?>
					</select>
					<?php _e( 'Choose the subscription levels members must have for this discount to apply.', 'edd-rcp' ); ?>
				</td>
			</tr>
			<tr>
				<td>
					<label for="edd-rcp-amount"><?php _e( 'Discount Amount', 'edd-rcps' ); ?>&nbsp;%&nbsp;</label>
				</td>
				<td>
					<input type="number" min="1" step="1" id="edd-rcp-amount" name="_edd_rcp_discount_amount" value="<?php echo absint( $amount ); ?>"/>
				</td>
			</tr>
		</table>
<?php
		do_action( 'edd_rcp_discounts_options_metabox', $post->ID );
	}


	/**
	 * Save post meta when the save_post action is called
	 *
	 * @since 1.0
	 * @param int $post_id Download (Post) ID
	 * @global array $post All the data of the the current post
	 * @return void
	 */
	public function save_meta_box( $post_id) {
		global $post;

		if ( ! isset( $_POST['edd_rcp_discounts_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['edd_rcp_discounts_meta_box_nonce'], basename( __FILE__ ) ) )
			return $post_id;

		if ( ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX') && DOING_AJAX ) || isset( $_REQUEST['bulk_edit'] ) ) return $post_id;

		if ( isset( $post->post_type ) && $post->post_type == 'revision' )
			return $post_id;

		if ( ! current_user_can( 'manage_shop_discounts' ) ) {
			return $post_id;
		}

		// The default fields that get saved
		$fields = apply_filters( 'edd_rcp_discounts_metabox_fields_save', array(
				'_edd_rcp_discount_subscription',
				'_edd_rcp_discount_amount'
			)
		);

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				if ( is_string( $_POST[ $field ] ) ) {
					$new = sanitize_text_field( $_POST[ $field ] );
				} else {
					$new = array_map( 'sanitize_text_field', $_POST[ $field ] );
				}

				$new = apply_filters( 'edd_rcp_discounts_metabox_save' . $field, $new );

				update_post_meta( $post_id, $field, $new );
			} else {
				delete_post_meta( $post_id, $field );
			}
		}

		// Force the discount to be marked as published
		if( 'publish' !== get_post_status( $post->ID ) )
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	}


	/**
	 *  Change the "Enter title here" text
	 *
	 * @since 1.0
	 * @return void
	 */
	public function change_default_title( $title ) {

		$screen = get_current_screen();

		if( 'edd_rcp_discount' == $screen->post_type ) {
			$title = __( 'Enter discount title. This will be shown on checkout.', 'edd-rcp' );
		}

		return $title;
	}


	/**
	 * Updated Messages
	 *
	 * Returns an array of with all updated messages.
	 *
	 * @since 1.0
	 * @param array $messages Post updated message
	 * @return array $messages New post updated messages
	 */
	public function updated_messages( $messages ) {
		global $post, $post_ID;

		$messages['edd_rcp_discount'] = array(
			1 => __( 'Member Discount updated.', 'edd-rcp' ),
			4 => __( 'Member Discount updated.', 'edd-rcp' ),
			6 => __( 'Member Discount published.', 'edd-rcp' ),
			7 => __( 'Member Discount saved.', 'edd-rcp' ),
			8 => __( 'Member Discount submitted.', 'edd-rcp' )
		);

		return $messages;
	}


	/**
	 * Register list table columns
	 *
	 * @since 1.0
	 * @param $columns array An array of existing columns
	 * @return array
	 */
	public function columns( $columns ) {
		$columns['subscription'] = __( 'Subscription Level', 'edd-rcp' );
		$columns['amount']       = __( 'Discount Amount', 'edd-rcp' );
		unset( $columns['date'] );
		return $columns;
	}


	/**
	 *  render the custom column content
	 *
	 * @since 1.0
	 * @param $column_name string The name of the column being rendered
	 * @param $post_id int The post ID a column is being rendered for
	 * @return void
	 */
	public function render_columns( $column_name, $post_id ) {
		if ( 'edd_rcp_discount' !== get_post_type( $post_id ) )
			return;

		if( ! class_exists( 'RCP_Payments' ) )
			return;

		$levels_db = new RCP_Levels;
		$level_id  = get_post_meta( $post_id, '_edd_rcp_discount_subscription', true );

		switch( $column_name ) {

			case 'subscription' :
				echo $levels_db->get_level_field( $level_id, 'name' );
				break;

			case 'amount' :
				echo absint( get_post_meta( $post_id, '_edd_rcp_discount_amount', true ) ) . '%';
				break;

		}
	}

}