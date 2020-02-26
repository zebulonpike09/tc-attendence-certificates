<?php
define('ABSPATH' , $_GET['abs']);

include('tickera-attendance-certificates.php');

$ticket_id = $_GET["ticket_id"];
$pdf_type = $_GET["pdf_type"];

$file = 'pdfs/' . $ticket_id . '-' . $pdf_type . '.pdf'; 

//$file = $_GET["file"];

//$file = 'pdfs/16630-14544-1535664675.pdf';

if (file_exists($file)) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header("Content-Type: application/force-download");
    header('Content-Disposition: attachment; filename=' . urlencode(basename($file)));
    // header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));
    ob_clean();
    flush();
    readfile($file);
    exit;
} else {
	if($pdf_type == 'general') {
		//echo 'got here';
		//if(class_exists('TC_Attendance_Certificates')) {
		//echo 'yay'; } else { echo ' nay'; } 
		TC_Attendance_Certificates::tcac_generate_general_pdf($ticket_id);
	} else if($pdf_type == 'sessions') {
		TC_Attendance_Certificates::tcac_generate_sessions_pdf($ticket_id);
	}
	
	header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header("Content-Type: application/force-download");
    header('Content-Disposition: attachment; filename=' . urlencode(basename($file)));
    // header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));
    ob_clean();
    flush();
    readfile($file);
    exit;
} 
?>