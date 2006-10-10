<?php

/**
 * Send an HTML and/or text email
 *
 * This class can be used by any module as follows:-
 * - $this->exec_module_action('Email', 'send_HTML_email', $from, $to, $subject, $html_data, $text_data);
 */
class Email {
	/**
	 * Construct the email headers and send email using mail()
	 *
	 * @param string $from The from email address
	 * @param string $to The to email address
	 * @param string $subject The subject of the email
	 * @param string $html_data The HTML portion of the email (default: 0)
	 * @param string $text_data The text portion of the email (default: 0)
	 *
	 * @return Returns true if mail sent successfully, false otherwise
	 */
	function send_HTML_email($from, $to, $subject, $html_data=0, $text_data=0) {
		$boundary = md5(uniqid(rand(), true));
	
		// Main headers
		$headers = 
			"MIME-Version: 1.0\n" .
			"$from\n" .
			"Content-Type: multipart/alternative; boundary = $boundary\n\n" .
			"This is a MIME encoded message.\n\n";
	
		// HTML chunk
		if ($html_data) {
			$headers .=
				"--$boundary\n" .
				"Content-Type: text/html; charset=ISO-8859-1\n" .
				"Content-Transfer-Encoding: base64\n\n";

			$headers .= chunk_split(base64_encode($html_data));
		}
	
		// Text chuck
		if ($text_data) {
			// Plain text chunk
			$headers .=
				"--$boundary\n" .
				"Content-Type: text/plain; charset=ISO-8859-1\n" .
				"Content-Transfer-Encoding: base64\n\n";
				
			$headers .= chunk_split(base64_encode($text_data));
		}
		
		// Send email
		return mail($to, $subject, "", $headers);
	}
}

?>
