<?php
if ( !defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly

if ( !class_exists( 'TC_Checkin_Notifications' ) ) {
	class TC_Checkin_Notifications {

		var $version		 = '1.1.4';
		var $title		 = 'Check-in Notifications';
		var $name		 = 'chin';
		var $dir_name	 = 'check-in-notifications';
		var $location	 = 'plugins';
		var $plugin_dir	 = '';
		var $plugin_url	 = '';

		function __construct() {
			add_action( 'tc_check_in_notification', array( &$this, 'tc_check_in_notification_email' ));
			add_filter( 'tc_settings_email_sections', array( &$this, 'tc_email_notifications_section' ) );
			add_filter( 'tc_settings_email_fields', array( &$this, 'tc_email_notifications_fields' ) );
                        add_filter( 'wp_mail_content_type', array( &$this, 'set_content_type' ) );
			add_action( 'init', array( &$this, 'localization' ), 10 );
		}

		
								//Plugin localization function
		function localization() {

// Load up the localization file if we're using WordPress in a different language
// Place it in this plugin's "languages" folder and name it "tc-[value in wp-config].mo"
			if ( $this->location == 'mu-plugins' ) {
				load_muplugin_textdomain( 'chin', 'languages/' );
			} else if ( $this->location == 'subfolder-plugins' ) {
				load_plugin_textdomain( 'chin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
			} else if ( $this->location == 'plugins' ) {
				load_plugin_textdomain( 'chin', false, 'languages/' );
			} else {
				
			}

			$temp_locales	 = explode( '_', get_locale() );
			$this->language	 = ($temp_locales[ 0 ]) ? $temp_locales[ 0 ] : 'en';
		}
		
		//ading section in the e-mail tab
		function tc_email_notifications_section( $sections ) {

			$sections[] = array(
				'name'			 => 'email_notifications',
				'title'			 => __( 'Check-in Notifications', 'chin' ),
				'description'	 => '',
			);

			return $sections;
		}

		//ading fields in the e-mail notifications section
		function tc_email_notifications_fields( $fields ) {
			$fields[] = array(
				'field_name'		 => 'subject',
				'field_title'		 => __( 'Set the mail subject', 'chin' ),
				'field_type'		 => 'option',
				'default_value'		 => __( 'Ticket Check-in', 'chin' ),
				'field_description'	 => __( 'Set the mail subject', 'chin' ),
				'section'			 => 'email_notifications'
			);

			$fields[] = array(
				'field_name'		 => 'from_name',
				'field_title'		 => __( 'From Name', 'chin' ),
				'field_type'		 => 'option',
				'default_value'		 => get_option( 'blogname' ),
				'field_description'	 => __( 'This name will appear as sent from name in the e-mail.', 'chin' ),
				'section'			 => 'email_notifications'
			);

			$fields[] = array(
				'field_name'		 => 'from_email',
				'field_title'		 => __( 'From E-Mail Address', 'chin' ),
				'field_type'		 => 'option',
				'default_value'		 => get_option( 'admin_email' ),
				'field_description'	 => __( 'This e-mail will appear as sender address.', 'chin' ),
				'section'			 => 'email_notifications'
			);

			$fields[] = array(
				'field_name'		 => 'checkin_notifications_text',
				'field_title'		 => __( 'Check-in Notifications Text', 'chin' ),
				'field_type'		 => 'function',
				'function'			 => 'tc_get_notification_message',
				'default_value'		 => __( 'Hello (OWNER_NAME), your ticket has been checked at (EVENT)', 'chin' ),
				'field_description'	 => __( 'Set the text that will be sent to ticket owner e-mail like information about the event, map, program etc...'
				. 'You can use following placeholders (OWNER_NAME), (EVENT), (TICKET TYPE)', 'chin' ),
				'section'			 => 'email_notifications'
			);

			$fields[] = array(
				'field_name'		 => 'checkin_notifications',
				'field_title'		 => __( 'Send Check-in Notification To Owner', 'chin' ),
				'field_type'		 => 'function',
				'function'			 => 'tc_yes_no_checkins',
				'default_value'		 => 'yes',
				'field_description'	 => __( 'Check the field to send notifications to owner e-mail when they are checked-in.', 'chin' ),
				'section'			 => 'email_notifications'
			);

			$fields[] = array(
				'field_name'		 => 'checkin_notifications_buyer',
				'field_title'		 => __( 'Send Check-in Notification To Buyer', 'chin' ),
				'field_type'		 => 'function',
				'function'			 => 'tc_yes_no_checkins',
				'default_value'		 => 'no',
				'field_description'	 => __( 'Check the field to send notifications to buyer e-mail when they are checked-in.', 'chin' ),
				'section'			 => 'email_notifications'
			);

			return $fields;
		}
       
                function set_content_type( $content_type ) {
                        return 'text/html';
                }

		//function responsible for sending e-mails
		function tc_check_in_notification_email($ticket_id) {
			$order_id	 = wp_get_post_parent_id( $ticket_id );
			$order		 = new TC_Order( $order_id );

                        $tc_email_settings	 = get_option( 'tc_email_setting', false );
			$tc_get_owner_mail	 = get_post_meta( $ticket_id, 'owner_email', true );

                        $tc_general_settngs = get_option('tc_general_setting');
                        $tc_owner_fields = $tc_general_settngs['show_owner_fields'];
			$tc_get_buyer_mail	 = isset( $order->details->tc_cart_info[ 'buyer_data' ][ 'email_post_meta' ] ) ? $order->details->tc_cart_info[ 'buyer_data' ][ 'email_post_meta' ] : '';
			$tc_get_buyer_mail	 = apply_filters( 'tc_ticket_checkin_buyer_email', $tc_get_buyer_mail, $order->details->ID );
			if ( $tc_email_settings[ 'checkin_notifications' ] == 'yes' || $tc_email_settings[ 'checkin_notifications_buyer' ] == 'yes' ) {

				if ( $tc_email_settings[ 'checkin_notifications' ] == 'no' ) {
					$tc_get_owner_mail = '';
				}

				if ( $tc_email_settings[ 'checkin_notifications_buyer' ] == 'no' ) {
					$tc_get_buyer_mail = '';
				}

				$tc_checkin_mail = array( $tc_get_owner_mail, $tc_get_buyer_mail );

                                if($tc_owner_fields == 'no'){
                                    
                                    $tc_get_owner_first_name = get_post_meta( $order_id, '_billing_first_name', true );
                                    $tc_get_owner_last_name	 = get_post_meta( $order_id, '_billing_last_name', true );
                                    
                                    if(empty($tc_get_owner_first_name) && empty($tc_get_owner_last_name)) {
                                    
                                        $tc_get_owner_first_name = $order->details->tc_cart_info[ 'buyer_data' ][ 'first_name_post_meta' ];
                                        $tc_get_owner_last_name	 = $order->details->tc_cart_info[ 'buyer_data' ][ 'last_name_post_meta' ];
                                    }
                                    
                                } else {
                                    
                                    $tc_get_owner_first_name = get_post_meta( $ticket_id, 'first_name', true );
                                    $tc_get_owner_last_name	 = get_post_meta( $ticket_id, 'last_name', true );
                                }
                                
				$tc_get_event_name		 = get_post_meta( $ticket_id, 'event_id', true );
				$tc_get_event_name		 = get_the_title( $tc_get_event_name );
				$check_text				 = $tc_email_settings[ 'checkin_notifications_text' ];
				$ticket_type_id			 = get_post_meta( $ticket_id, apply_filters( 'tc_ticket_type_id', 'ticket_type_id' ), true );
				$ticket_type_name		 = apply_filters( 'tc_checkout_owner_info_ticket_title', get_the_title( $ticket_type_id ), $ticket_type_id );
				
				$latest_checkin_meta = end(get_post_meta($ticket_id, 'tc_checkins', true));
				$checkin_date = $latest_checkin_meta['date_checked'];
				$checkin_status = $latest_checkin_meta['status'];
				$checkin_api_key_id = $latest_checkin_meta['api_key_id'];
				
				if($checkin_status != 'Pass') {
					return;
				}
				
				$checkin_api_key = get_post_meta($checkin_api_key_id, 'api_key_name')[0];
				
				$tcac_pdf_url = $ticket_id . '-' . $checkin_api_key_id . '-' . $checkin_date . '.pdf';
				$tc_attachments = array( ABSPATH . 'wp-content/plugins/tickera-attendence-certificates/pdfs/' . $tcac_pdf_url );

				$tc_placeholders		 = array( 'OWNER_NAME', 'EVENT', 'TICKET_TYPE', 'SESSION');
				$tc_placeholder_values	 = array( $tc_get_owner_first_name . ' ' . $tc_get_owner_last_name, $tc_get_event_name, $ticket_type_name, $checkin_api_key );
				$tc_message				 = str_replace( $tc_placeholders, $tc_placeholder_values, $tc_email_settings[ 'checkin_notifications_text' ] );

				$tc_headers = 'From: ' . $tc_email_settings[ 'from_name' ] . ' <' . $tc_email_settings[ 'from_email' ] . '>' . "\r\n";
				
				wp_mail( $tc_checkin_mail, $tc_email_settings[ 'subject' ], stripcslashes( apply_filters( 'tc_checkin_notification', $tc_message ) ), $tc_headers);

				remove_filter( 'wp_mail_content_type', 'set_content_type' );
			} // if($tc_email_settings['checkin_notifications'] == 'yes' || $tc_email_settings['checkin_notifications_buyer'] == 'yes' )
		}

// function tc_check_in_notification_email($ticket_id)
	} 
	//class TC_Checkin_Notifications
} //if (!class_exists('TC_Checkin_Notifications'))
// checking notifiation function for yes and no
$tc_checkin_notifications = new TC_Checkin_Notifications();

function tc_yes_no_checkins( $field_name, $default_value = '' ) {

	$tc_email_settings = get_option( 'tc_email_setting', false );

	if ( isset( $tc_email_settings[ $field_name ] ) ) {
		$checked = $tc_email_settings[ $field_name ];
	} else {
		if ( $default_value !== '' ) {
			$checked = $default_value;
		} else {
			$checked = 'no';
		}
	}
	?>
	<label>
		<input type="radio" name="tc_email_setting[<?php echo esc_attr( $field_name ); ?>]" value="yes" <?php checked( $checked, 'yes', true ); ?>  /><?php _e( 'Yes', 'chin' ); ?>
	</label>
	<label>
		<input type="radio" name="tc_email_setting[<?php echo esc_attr( $field_name ); ?>]" value="no" <?php checked( $checked, 'no', true ); ?> /><?php _e( 'No', 'chin' ); ?>
	</label>
	<?php
}

//function tc_yes_no_checkins($field_name, $default_value = '')
//wp editor for notification message
function tc_get_notification_message( $field_name, $default_value = '' ) {
	global $tc_email_settings;
	if ( isset( $tc_email_settings[ $field_name ] ) ) {
		$value = $tc_email_settings[ $field_name ];
	} else {
		if ( $default_value !== '' ) {
			$value = $default_value;
		} else {
			$value = '';
		}
	}
	wp_editor( html_entity_decode( stripcslashes( $value ) ), $field_name, array( 'textarea_name' => 'tc_email_setting[' . $field_name . ']', 'textarea_rows' => 2 ) );
}

//Addon updater 

if ( function_exists( 'tc_plugin_updater' ) ) {
	tc_plugin_updater( 'check-in-notification', __FILE__ );
}
?>