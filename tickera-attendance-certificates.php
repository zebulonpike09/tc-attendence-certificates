

<?php
/*
* Plugin Name: Tickera - Certificates of Attendance
* Description: A plugin created to demonstrate how to build a PDF document from WordPress posts.
* Version: 1.0
* Author: Luke Robinson
*/

/*
Notes for future implementation: this file in addition to the my-events.php file in myaccount template files for wp are the main files. You will have to add in the tab in the events 
tab in the myaccount. For the editing, you have to create a new post category for the pdf templates.
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$tcac = new TC_Attendance_Certificates();

require_once('tc-checkin-email.php');
require_once(ABSPATH . 'wp-content/plugins/tickera/includes/tcpdf/examples/tcpdf_include.php');
require_once('libraries/action-scheduler/action-scheduler.php');

  // --------------------------------------------------------------------------------
  // Class : TC_Attendance_Certificates
  // Description :
  //   This class houses the majority of the functionality behind creating, 
  //   managing, and sending CE Credit/Attendance Certificates
  // Important Methods :
  //   email_certs($event_id) : sends emails to all of the users that attended a event
  //   tcac_generate_general_pdf($ticket_id) : generates certificate PDF for the event as a whole
  //   tcac_generate_sessions_pdf($ticket_id) : generates certificates PDF for each sessions attended
  // --------------------------------------------------------------------------------

class TC_Attendance_Certificates {
	// ----- value of the current ticket - for internal use primarily
	public $current_cert_id;
	
	// ----- whether or not to create a pdf for a session when an attendee checks in - for internal use primarily
	public $auto_generate_pdfs = true;
	
	// ----- value of the current event - for internal use primarily
	public $current_event_id;

	// --------------------------------------------------------------------------------
	// Function : construct
	// Description : 
	//   Functions triggered on initialization of the instance of a TC_Attendance_Certificates
	//   object. Primary purpose is to add actions neccessary for the class to function.
	// Return/Parameters : nothing important
	// --------------------------------------------------------------------------------
	public function __construct() {
		add_action('tc_check_in_notification', array(&$this, 'set_current_cert_id'));
		if($this->auto_generate_pdfs) {
			add_action('tc_after_checkin_array_update', array(&$this, 'tcac_auto_generate_pdf'));
		}
		add_action( 'admin_enqueue_scripts', array(&$this, 'tcac_email_enqueue') );
		add_action('post_submitbox_misc_actions', array(&$this, 'get_event_id'), 5, 1);
		add_action('tcac_test_send_emails', array(&$this, 'email_sender'));
	}
	
	// --------------------------------------------------------------------------------
	// Function : get_event_id 
	// Description : Retrieves the current event_id 
	// Parameters : 
	//   $post => a post object
	// --------------------------------------------------------------------------------
	public function get_event_id($post) {
		$this->current_event_id = $post->ID;
	}
	
	// --------------------------------------------------------------------------------
	// Function : tcac_email_enqueue
	// Description : 
	//   Enqueues the tcac_send_emails.js file neccessary for handling the sending of
	//   emails when the send email button is clicked. Also pushes neccessary variables
	//   to this script
	// --------------------------------------------------------------------------------	
	public function tcac_email_enqueue($hook) {	
		wp_enqueue_script( 'tcac-email-ajax-script', plugins_url( '/tcac_send_emails.js', __FILE__ ), array('jquery') );

		wp_localize_script( 'tcac-email-ajax-script', 'ajax_object',
				array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce('tcac_email_certs') ) );
	}	
	
	// --------------------------------------------------------------------------------
	// Function : email_certs
	// Description : 
	//   This function sends an email to all attendees of a certain event. This email
	//   contains two attachments, a pdf of attendence for the event as a whole and a 
	//   pdf that contains all of the certificates for each session attended or tickera
	//   api_key checked in to. 
	// --------------------------------------------------------------------------------
	public static function email_certs($event_id) {
		$args = array(
			'post_type'  => 'tc_tickets_instances',
			'meta_query' => array(
				array(
					'key'   => 'event_id',
					'value' => $event_id,
				)
			), 
		);
		$tickets = get_posts($args);
		
		$event_name = get_the_title($event_id);
		$email_message = get_post_meta($event_id, 'email_message', true);
		
		$emails = array();
		foreach($tickets as $ticket) {
			$email = get_post_meta($ticket->ID, 'owner_email', true);
			$first_name = get_post_meta($ticket->ID, 'first_name', true);
			$last_name = get_post_meta($ticket->ID, 'last_name', true);
			$emails[] = array(
				'ticket' => $ticket,
				'email' => $email,
				'first_name' => $first_name,
				'last_name' => $last_name,
			);
		}
		
		$tcac_emails = array();
		
		foreach($emails as $email) {
			$placeholders = array('$$event_name', '$$name');
			$values = array($event_name, $email['first_name'] . ' ' . $email['last_name']);
			$email_message_with_vals = str_replace($placeholders, $values, $email_message);
			
			TC_Attendance_Certificates::tcac_generate_general_pdf($email['ticket']->ID, 'F');
			TC_Attendance_Certificates::tcac_generate_sessions_pdf($email['ticket']->ID, 'F');
				
			$tcac_file_name = 'attendence-certificate-' . $email['first_name'] . '-' . $email['last_name'] . '-' . $event_name;
			$tcac_sess_filepath = 'attendence-certificate-' . $email['first_name'] . '-' . $email['last_name'] . '-' . $event_name . '-sessions';
			
			$tcac_cert_filepath = ABSPATH . 'wp-content/plugins/tickera-attendence-certificates/pdfs/' . $tcac_file_name . '.pdf';
			$tcac_sess_cert_filepath = ABSPATH . 'wp-content/plugins/tickera-attendence-certificates/pdfs/' . $tcac_sess_filepath . '.pdf';
			
			$attendee_email = $email['email'];
			
			$tcac_emails[] = array(
				'address' => $attendee_email,
				'subject' => 'Attendance Certificates for ' . $event_name, 
				'email_message' => $email_message_with_vals, 
				'attachments' => array($tcac_cert_filepath, $tcac_sess_cert_filepath), 
			);
		}
		
		as_schedule_single_action(time(), 'tcac_test_send_emails', array('emails' => $tcac_emails));
	}
	
	// --------------------------------------------------------------------------------
	// Function : email_sender
	// Description : 
	//   Sends emails in the $email object passed to it from email_certs
	// Parameters : 
	//   $emails => an array object that contains email address, email_message, and attachments
	// --------------------------------------------------------------------------------
	public function email_sender($emails) {
		foreach($emails as $email){
			wp_mail($email['address'], $email['subject'], $email['email_message'], '', $email['attachments']);
		}
	}
	
	public function set_current_cert_id($ticket_id) {
		$this->current_cert_id = $ticket_id;
	}
	
	public function get_current_cert_id() {
		return $this->current_cert_id;
	}
	
	public static function tcac_generate_pdf($ticket_id) {
		ob_start();
				
		$latest_checkin_meta = end(get_post_meta($ticket_id, 'tc_checkins', true));
		$checkin_date = $latest_checkin_meta['date_checked'];
		$checkin_status = $latest_checkin_meta['status'];
		$checkin_api_key_id = $latest_checkin_meta['api_key_id'];
		
		if($checkin_status != 'Pass') {
			return;
		}
		
		$pdf_url = $ticket_id . '-' . $checkin_api_key_id . '-' . $checkin_date . '.pdf';
		
		$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
		$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
		$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

		if (@file_exists(dirname(__FILE__).'/lang/eng.php')) {
			require_once(dirname(__FILE__).'/lang/eng.php');
			$pdf->setLanguageArray($l);
		}
		
		$pdf->SetFont('helvetica', '', 9);
		$pdf->AddPage();
		$html = '<html>
			<head></head>
			<body>
				<h1>Congrats, you went to ' . $checkin_api_key_id . ' </h1>
			</body>
		</html>';
		$pdf->writeHTML($html, true, 0, true, 0);
		$pdf->lastPage();
		
		ob_end_clean();
		
		$pdf->Output(ABSPATH . 'wp-content/plugins/tickera-attendence-certificates/pdfs/' . $pdf_url, 'D');
	}

	public function tcac_auto_generate_pdf() {
		ob_start();
		
		$ticket_id = $this->current_cert_id;
		
		$latest_checkin_meta = end(get_post_meta($ticket_id, 'tc_checkins', true));
		$checkin_date = $latest_checkin_meta['date_checked'];
		$checkin_status = $latest_checkin_meta['status'];
		$checkin_api_key_id = $latest_checkin_meta['api_key_id'];
		
		if($checkin_status != 'Pass') {
			return;
		}
		
		$pdf_url = $ticket_id . '-' . $checkin_api_key_id . '-' . $checkin_date . '.pdf';
		
		$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
		$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
		$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

		if (@file_exists(dirname(__FILE__).'/lang/eng.php')) {
			require_once(dirname(__FILE__).'/lang/eng.php');
			$pdf->setLanguageArray($l);
		}
		
		$pdf->SetFont('helvetica', '', 9);
		$pdf->AddPage();
		$html = '<html>
			<head></head>
			<body>
				<h1>Congrats, you went to ' . $checkin_api_key_id . ' </h1>
			</body>
		</html>';
		$pdf->writeHTML($html, true, 0, true, 0);
		$pdf->lastPage();
		
		ob_end_clean();
		
		$pdf->Output(ABSPATH . 'wp-content/plugins/tickera-attendence-certificates/pdfs/' . $pdf_url, 'F');
	}
	
	// --------------------------------------------------------------------------------
	// Function : tcac_generate_general_pdf
	// Description : 
	//   This function creates a pdf for the event as a whole and puts the output in
	//   to a place determined by $output
	// Parameters : 
	//   $ticket_id => self explanatory
	//   $output => 'I' means that it will send to the output directly into the browser
    //              'F' measn that it will be saved to a file	
	// --------------------------------------------------------------------------------
	public static function tcac_generate_general_pdf($ticket_id, $output = 'I') {
		ob_start();
		
		$checkins = get_post_meta($ticket_id, 'tc_checkins', true);
		$event_id = get_post_meta($ticket_id, 'event_id', true);
		$event_name = get_the_title($event_id);
		$event_pdf_temp = get_post_meta($event_id, 'pdf_template', true);
		$event_end_date = get_post_meta($event_id, 'event_end_date_time', true);
		
		$attendee_first_name = get_post_meta($ticket_id, 'first_name', true);
		$attendee_last_name = get_post_meta($ticket_id, 'last_name', true);
		$attendee_name = $attendee_first_name . ' ' . $attendee_last_name;
		
		$attendee_first_name = get_post_meta($ticket_id, 'first_name', true);
		$attendee_last_name = get_post_meta($ticket_id, 'last_name', true);
		
		$credit_hour_total = 0;
		
		foreach($checkins as $checkin) {
			$api_key_id = $checkin['api_key_id'];
			$api_credit_hours = get_post_meta($api_key_id, 'api_credit_hours', true);
			$credit_hour_total = $credit_hour_total + $api_credit_hours;
		}
		
		$placeholders = array('$$event_name', '$$name', '$$date',);
		$values = array($api_key_name, $attendee_name, $event_end_date);
		$cert_html = str_replace($placeholders, $values, get_post_field('post_content', $event_pdf_temp));
		
		$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
		$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
		$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
		
		$pdf->SetFont('helvetica', '', 9);
		$pdf->AddPage();
		$html = '<html>
			<head></head>
			<body>
				' . $cert_html . '
			</body>
		</html>';
		$pdf->writeHTML($html, true, 0, true, 0);
		$pdf->lastPage();
		
		ob_end_clean();
		
		$tcac_file_name = 'attendence-certificate-' . $attendee_first_name . '-' . $attendee_last_name . '-' . $event_name;
		
		$pdf->Output(ABSPATH . 'wp-content/plugins/tickera-attendence-certificates/pdfs/' . $tcac_file_name . '.pdf', $output);
	}
	
	// --------------------------------------------------------------------------------
	// Function : tcac_generate_sessions_pdf
	// Description : 
	//    This function creates a certificate for each session that the attendee attended
	// Parameters : see tcac_generate_general_pdf params
	// --------------------------------------------------------------------------------
	public static function tcac_generate_sessions_pdf($ticket_id, $output = 'I') {
		ob_start();
		
		$checkins = self::parse_checkins(get_post_meta($ticket_id, 'tc_checkins', true));
		$event_id = get_post_meta($ticket_id, 'event_id', true);
		$event_name = get_the_title($event_id);
		$event_pdf_temp = get_post_meta($event_id, 'pdf_template', true);
		$event_end_date = get_post_meta($event_id, 'event_end_date_time', true);
		
		$attendee_first_name = get_post_meta($ticket_id, 'first_name', true);
		$attendee_last_name = get_post_meta($ticket_id, 'last_name', true);
		$attendee_name = $attendee_first_name . ' ' . $attendee_last_name;
		
		$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
		$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
		$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

		if (@file_exists(dirname(__FILE__).'/lang/eng.php')) {
			require_once(dirname(__FILE__).'/lang/eng.php');
			$pdf->setLanguageArray($l);
		}
		
		$pdf->SetFont('helvetica', '', 9);
		
		foreach($checkins as $api_key_id) {
			$api_credit_hours = get_post_meta($api_key_id, 'api_credit_hours', true);
			$api_key_name = get_post_meta($api_key_id, 'api_key_name', true);
			$api_key_pdf_temp = get_post_meta($api_key_id, 'pdf_template', true);
			$api_key_aia_num = get_post_meta($api_key_id, 'aia_course_num', true);
			
			$placeholders = array('$$event_name', '$$credit_hour_total', '$$name', '$$date', '$$course_num');
			$values = array($api_key_name, $api_credit_hours, $attendee_name, $event_end_date, $api_key_aia_num);
			$cert_html = str_replace($placeholders, $values, get_post_field('post_content', $api_key_pdf_temp));
			
			$pdf->AddPage();
			$html = '<html>
				<head></head>
				<body>
					' . $cert_html . '
				</body>
			</html>';
			$pdf->writeHTML($html, true, 0, true, 0);
		}
		
		$pdf->lastPage();
		
		ob_end_clean();
		
		$tcac_file_name = 'attendence-certificate-' . $attendee_first_name . '-' . $attendee_last_name . '-' . $event_name . '-sessions';
		
		$pdf->Output(ABSPATH . 'wp-content/plugins/tickera-attendence-certificates/pdfs/' . $tcac_file_name . '.pdf', $output);
	}
	
	// --------------------------------------------------------------------------------
	// Function : parse_checkins
	// Description : 
	//    Ensures that only one certificate is created for each sessions, meaning that 
	//    if an attendee was erroneously chceked in 5 times to a session, only one cert
	//    is created.
	// --------------------------------------------------------------------------------
	private function parse_checkins($checkins) {
		$api_ids = array('');

		foreach($checkins as $checkin) {
			$api_ids[] = $checkin['api_key_id'];
		}
		return array_unique($api_ids);
	}
}

// some TMS custom stuff

add_filter('tc_api_keys_fields', 'add_credit_hours_field');

// --------------------------------------------------------------------------------
// Function : add_credit_hours_field
// Description : 
//   Adds fields to the api_key settings page in the tickera admin
// --------------------------------------------------------------------------------
function add_credit_hours_field($default_fields) {
	$default_fields[] = array(
                    'field_name' => 'offers_ce_credt',
                    'field_title' => __('Offers Continuing Education Credit', 'tc'),
                    'field_type' => 'function',
                    'function' => 'tc_true_false_dropdown',
                    'field_description' => '',
                    'table_visibility' => true,
                    'post_field_type' => 'post_meta',
                );
	$default_fields[] = array(
                    'field_name' => 'api_credit_hours',
                    'field_title' => __('API / Session Credit Hours', 'tc'),
                    'field_type' => 'text',
                    'field_description' => __('The amount of credit hours awarded for attendance to this session', 'tc'),
                    'table_visibility' => true,
                    'post_field_type' => 'post_meta',
                );
	$default_fields[] = array(
                    'field_name' => 'aia_course_num',
                    'field_title' => __('AIA Course Number', 'tc'),
                    'field_type' => 'text',
                    'field_description' => __('This number is the sessions AIA course number', 'tc'),
                    'table_visibility' => true,
                    'post_field_type' => 'post_meta',
                );
	return $default_fields;
}

// --------------------------------------------------------------------------------
// Function : tc_true_false_dropdown
// Description : 
//   The functionality behind the checkbox for if a event offers CE credit in the 
//   api-keys settings page of the tickera admin
// --------------------------------------------------------------------------------
function tc_true_false_dropdown($field_name = '', $post_id = '') {
	if ($post_id !== '') {
        $currently_selected = get_post_meta($post_id, $field_name, true);
    } else {
        $currently_selected = '';
    }
	?>
	<select name="offers_ce_credt_post_meta">
            <option value="yes" <?php selected($currently_selected, 'yes', true); ?>>Yes</option>
			<option value="no" <?php selected($currently_selected, 'no', true); ?>>No</option>
    </select> <?php
}

add_filter('tc_event_fields', 'add_pdf_temp_field');
add_filter('tc_api_keys_fields', 'add_pdf_temp_field');

// --------------------------------------------------------------------------------
// Function : add_pdf_temp_field
// Description : 
//   Adds fields to the bottom of the admin edit event page for pdf template and email message
// --------------------------------------------------------------------------------
function add_pdf_temp_field($default_fields) {
	$default_fields[] = array(
                    'field_name' => 'pdf_template',
                    'field_title' => __('PDF Template', 'tc'),
                    'field_type' => 'function',
                    'function' => 'tc_choose_pdf_template',
                    'field_description' => '',
                    'table_visibility' => true,
                    'post_field_type' => 'post_meta',
                ); 
	$default_fields[] = array(
	                'field_name' => 'email_message',
                    'field_title' => __('CE PDF Email Message', 'tc'),
                    'field_type' => 'textarea_editor',
                    'field_description' => 'Enter the body text of the email that will have PDF Certificates attached. <br>Variables you can use: $$name and $$event_name',
                    'table_visibility' => false,
                    'post_field_type' => 'post_meta',
	);
	return $default_fields;
}

// --------------------------------------------------------------------------------
// Function : tc_choose_pdf_template
// Description : 
//   Functionality for the dropdown box for all of the event pdf templates
// --------------------------------------------------------------------------------
function tc_choose_pdf_template($field_name = '', $post_id = ''){
	if ($post_id !== '') {
        $currently_selected = get_post_meta($post_id, $field_name, true);
    } else {
        $currently_selected = '';
    }
	$args = array(
		'numberposts' => 100,
		'category' => 2803
	);
	$options = get_posts($args);
	?>
	<select name="pdf_template_post_meta">
		<option value="none">None</option>
		<?php foreach($options as $opt) { ?>
		<option value="<?php echo (int) $opt->ID; ?>" <?php selected($currently_selected, $opt->ID, true); ?>><?php echo get_post_field('post_title', $opt); ?></option>
		<?php }?>
	</select>
	<?php
} 

add_action("wp_ajax_tcac_general_pdf", "general_pdf_gen");
add_action("wp_ajax_nopriv_tcac_general_pdf", "my_must_login");

// --------------------------------------------------------------------------------
// Function : general_pdf_gen
// Description : 
//   Ajax handler for when a user clicks see general certificates button on their dashboard
// --------------------------------------------------------------------------------
function general_pdf_gen() {
	if ( !wp_verify_nonce( $_REQUEST['nonce'], "tcac_general_pdf_nonce")) {
      exit("No naughty business please");
   } 
   
   TC_Attendance_Certificates::tcac_generate_general_pdf($_REQUEST['ticket_id']);
}

add_action("wp_ajax_tcac_sessions_pdf", "sessions_pdf_gen");
add_action("wp_ajax_nopriv_tcac_sessions_pdf", "my_must_login");

// --------------------------------------------------------------------------------
// Function : sessions_pdf_gen
// Description : 
//   Ajax handler for when a user clicks see sessions certificates button on their dashboard
// --------------------------------------------------------------------------------
function sessions_pdf_gen() {
	if ( !wp_verify_nonce( $_REQUEST['nonce'], "tcac_sessions_pdf_nonce")) {
      exit("No naughty business please");
   } 
   
   TC_Attendance_Certificates::tcac_generate_sessions_pdf($_REQUEST['ticket_id']);
}

add_action("wp_ajax_tcac_last_year_pdf", "last_year_gen");
add_action("wp_ajax_nopriv_tcac_last_year_pdf", "my_must_login");

// --------------------------------------------------------------------------------
// Function : tcac_email_certs
// Description : 
//   Ajax handler for when a a TMS Admin member clicks send cert emails
// --------------------------------------------------------------------------------
function tcac_email_pdfs() {
	if ( !wp_verify_nonce( $_POST['nonce'], "tcac_email_certs")) {
		exit("No naughty business please");
	}
	
	TC_Attendance_Certificates::email_certs($_POST['event_id']);
}

function tcac_schedule_email_event(){
	as_schedule_single_action(time(), 'tcac_send_email_certs', array('event_id' => 26190));
}

add_action('wp_ajax_tcac_email_certs', 'tcac_email_pdfs');
add_action('wp_ajax_nopriv_tcac_email_certs', 'my_must_login');

// --------------------------------------------------------------------------------
// Function : tcac_send_email_button
// Description : 
//   Function that adds send pdf emails in the event edit page in the admin
// --------------------------------------------------------------------------------
function tcac_send_email_button($post) {
	if(get_post_type($post) != 'tc_events') {
		return;
	}
	
	?>
	<input name="tcac-send-emails" type="submit" class="button button-primary button-large" id="tcac-send-emails-btn" value="<?php esc_attr_e( 'Send Attendence Emails' ); ?>" />
	<br><br>
	<?php
}

add_action('post_submitbox_misc_actions', 'tcac_send_email_button');

function last_year_gen() {
	if ( !wp_verify_nonce( $_REQUEST['nonce'], "tcac_last_year_pdf_nonce")) {
      exit("No naughty business please");
    }
	
	$user_id = $_REQUEST['user_id'];
	
	$customer_orders = get_posts( apply_filters( 'tms_woocommerce_my_account_my_orders_query', array(
		'meta_query'    => array (
			array('key' => '_customer_user', 'value' => $user_id,
			array('key' => '_tc_paid_date', 'compare' => 'EXISTS'),
			'relation' => 'AND'
		),
		'post_type'   => wc_get_order_types( 'view-orders' ),
		'post_status' => array_keys( wc_get_order_statuses() ),
	) ) ));
	
	foreach ( $customer_orders as $customer_order ) {
		$order      = wc_get_order( $customer_order );
		$item_count = $order->get_item_count();
		
		$order_tickets_ids = TC_Orders::get_tickets_ids($order->get_id());
		
		
		foreach ($order_tickets_ids as $ticket_id) {
			$event_id = get_post_meta($ticket_id, 'event_id', true);
			if(get_post_meta($event_id, 'offers_ce_credt', true) == 'yes') {
				TC_Attendance_Certificates::tcac_generate_sessions_pdf($ticket_id);
			}
		}
	}
}

// --------------------------------------------------------------------------------
// Function : my_must_login
// Description : 
//   Ajax handler for when an unauthorized user tries to access the handles
// --------------------------------------------------------------------------------
function my_must_login() {
   echo "You must log in to vote";
   die();
}