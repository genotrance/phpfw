<?php

/**
 * The MailServer Module
 *
 * This module provides a simple wrapper around PHP's IMAP library while adding some 
 * functionality to make interacting with the mail server as easy as possible.
 *
 * This module can be used by other modules as follows:-
 * - Copy module file to module directory
 * - Adjust configuration file as needed (see further below on configuration file requirements)
 * - In any action run:
 * - $this->exec_module_action('MailServer', 'action')
 *
 * The MailServer module requires the following entries in the configuration file.
 *
 * [mailserver]
 * hostname = imap.server.com	: Name of the email host to connect to
 * port = number			: Port number to connect to for the above host
 * root = Mail/			: Root folder on the server
 * secure = true/false		: Set to true to never send password as plain text
 * ssl = true/false			: Set to true to connect using SSL
 * novalidate-cert = true/false	: Set to true if no need to validate the server certificate
 * tls = true/false			: Set to true to force usage of tls
 * notls = true/false		: Set to true to not use tls even if available on server
 *
 * The defaults are as below:
 * [mailserver]
 * hostname = localhost
 * port = 143
 * root = ''
 * secure = false
 * ssl = false
 * novalidate-cert = false
 * tls = false
 * notls = false
 */
class MailServer extends Module {
	/** 
	 * @var array $server_config Connection information loaded from the configuration file
	 */
	var $server_config = array(
		'hostname' => 'localhost',
		'port' => 143,
		'root' => '',
		'secure' => false,
		'ssl' => false,
		'novalidate-cert' => false,
		'tls' => false,
		'notls' => false
	);
	
	/**
	 * @var resource $connection Connection to the server
	 */
	var $connection = null;
	
	/**
	 * @var string $current_user Name of the user currently logged in to the server
	 */
	var $current_user = '';
	
	/**
	 * @var string $current_mailbox Name of the mailbox currently opened
	 */
	var $current_mailbox = '';
	
	///////////////////////////////////////////////////////
	// Basic Setup

	/**
	 * Constructor for the email server module
	 *
	 * The constructor registers all error messages used by this module.
	 */
	function MailServer() {
		// Register all error messages
		$this->register();
	}
	
	/**
	 * Get the configuration from config file
	 *
	 * Load all configuration items under section 'mailserver' in the
	 * main configuration file. Default values selected are described 
	 * in the module description.
	 */
	function get_configuration() {
		// Load values from config file if applicable
		if (isset($this->application->config['mailserver'])) {
			$keys = explode(' ', 'hostname port root secure ssl novalidate-cert tls notls');
			foreach ($keys as $key)
				if (isset_and_non_empty($this->application->config['mailserver'][$key]))
					$this->server_config[$key] = $this->application->config['mailserver'][$key];
		}

		// Save connection information
		$this->server_config['server'] = 
			'{' . 
			$this->server_config['hostname'] . 
			':' . 
			$this->server_config['port'] . 
			'}';
	}
	
	///////////////////////////////////////////////////////
	// Connection related code

	/**
	 * Connect to the server in a half-open mode (don't select any mailbox)
	 *
	 * Connect to the server using the username and password specified. This
	 * action should be invoked from another module before executing any other
	 * actions.
	 *
	 * Function loads the configuration from config file.
	 *
	 * @param string $username Username to connect to the server with
	 * @param string $password Password to authenticate with
	 *
	 * @return resource Returns the server connection
	 */
	function connect($username, $password) {
		// Get configuration from file
		$this->get_configuration();

		// Figure out what flags to set
		$flags = '';
		$flag_names = explode(' ', 'secure ssl novalidate-cert tls notls');
		foreach ($flag_names as $flag_name)
			if (isset($this->server_config[$flag_name]) && $this->server_config[$flag_name] == true)
				$flags .= "/$flag_name";
		
		// Connect to the server
		$this->connection = imap_open(
			rtrim($this->server_config['server'], '}') . $flags . '}',
			$username,
			$password,
			OP_HALFOPEN
		);

		// Display error if failure
		if ($this->connection === false)
			$this->error->display_error('ERROR_MAILSERVER_CONNECTION_FAILED', imap_last_error());
		
		// Save current user
		$this->current_user = $username;
		
		// Return connection or false
		return $this->connection;
	}
	
	/**
	 * Expunge currently selected mailbox
	 *
	 * Select a mailbox using $this->open_mailbox($mailbox_name);
	 */
	function expunge() {
		// Perform the expunge
		$returned = imap_expunge($this->connection);
		
		// Display error if failure
		if ($returned == false)
			$this->error->display_error('ERROR_MAILSERVER_EXPUNGE_FAILED', imap_last_error());
			
		return $returned;
	}

	/**
	 * Disconnect from the current server if connected
	 */
	function disconnect() {
		// Disconnect if connected
		if (isset($this->connection) && $this->connection !== false)
			imap_close($this->connection);
			
		// Reset the logged in user and current mailbox
		$this->current_user = '';
		$this->current_mailbox = '';
	}

	///////////////////////////////////////////////////////
	// Mailbox related code

	/**
	 * Open the specified mailbox
	 *
	 * Call $this->connect($username, $password ) before calling this function
	 *
	 * @param string $mailbox_name Name of the mailbox to open, default INBOX
	 */
	function open_mailbox($mailbox_name='INBOX') {
		// Open the mailbox
		$returned = imap_reopen(
			$this->connection,
			$this->server_config['server'] . $mailbox_name
		);

		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_OPEN_MAILBOX_FAILED', $mailbox_name, imap_last_error());
		else
			$this->current_mailbox = $mailbox_name;
		
		return $returned;
	}

	/**
	 * Create the specified mailbox
	 *
	 * @param string $mailbox_name Name of the mailbox to create
	 */
	function create_mailbox($mailbox_name) {
		// Do the create
		$returned = imap_createmailbox(
			$this->connection, 
			$this->server_config['server'] . $mailbox_name
		);

		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_CREATE_MAILBOX_FAILED', $mailbox_name, imap_last_error());
			
		return $returned;
	}
	
	/**
	 * Rename the specified mailbox as requested
	 *
	 * @param string $mailbox_name Name of the mailbox to rename
	 * @param string $new_mailbox_name New name of the mailbox
	 */
	function rename_mailbox($mailbox_name, $new_mailbox_name) {
		// Do the rename
		$returned = imap_renamemailbox(
			$this->connection, 
			$this->server_config['server'] . $mailbox_name,
			$this->server_config['server'] . $new_mailbox_name
		);

		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_RENAME_MAILBOX_FAILED', 
				$mailbox_name, $new_mailbox_name, imap_last_error());
			
		return $returned;
	}
	
	/**
	 * Delete the specified mailbox
	 *
	 * @param string $mailbox_name Name of the mailbox to delete
	 */
	function delete_mailbox($mailbox_name) {
		// Do the delete
		$returned = imap_deletemailbox(
			$this->connection, 
			$this->server_config['server'] . $mailbox_name
		);

		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_DELETE_MAILBOX_FAILED', $mailbox_name, imap_last_error());
			
		return $returned;
	}

	/**
	 * Get all mailboxes for current connection
	 *
	 * @return array Returns an array of mailbox name strings
	 */
	function get_mailboxes() {
		// Get the list of mailboxes in folder root
		$list = imap_list(
			$this->connection,
			$this->server_config['server'] . $this->server_config['root'],
			'*'
		);
		
		// Remove full server path
		for ($i = 0; $i < sizeof($list); $i++)
			$list[$i] = substr($list[$i], strlen($this->server_config['server']));
		
		// Sort list by name
		if (is_array($list)) sort($list);
		
		// Display error if failure
		if (!is_array($list))
			$this->error->display_error('ERROR_MAILSERVER_GET_MAILBOXES_FAILED', imap_last_error());
			
		return $list;
	}
	
	/**
	 * Get only subscribed mailboxes for current connection
	 *
	 * @return array Returns an array of mailbox name strings
	 */
	function get_subscribed_mailboxes() {
		// Get the list of subscribed mailboxes
		$list = imap_lsub(
			$this->connection,
			$this->server_config['server'],
			'*'
		);
		
		// Remove full server path
		for ($i = 0; $i < sizeof($list); $i++)
			$list[$i] = substr($list[$i], strlen($this->server_config['server']));
		
		// Sort list by name
		if (is_array($list)) sort($list);
		
		// Display error if failure
		if (!is_array($list))
			$this->error->display_error('ERROR_MAILSERVER_GET_SUBSCRIBED_MAILBOXES_FAILED', imap_last_error());
			
		return $list;
	}
	
	/**
	 * Get the status information for the specified mailbox
	 *
	 * @param string $mailbox_name Name of the mailbox, default INBOX
	 *
	 * @return array Returns an associative array with fields 'num_messages', 'num_recent', 'num_unseen' and 'uid_validity'
	 */
	function get_mailbox_status($mailbox_name='INBOX') {
		// Get the mailbox status
		$status = imap_status(
			$this->connection, 
			$this->server_config['server'] . $mailbox_name,
			SA_MESSAGES | SA_RECENT | SA_UNSEEN | SA_UIDVALIDITY
		);
		
		// Display error if failure
		if (!$status)
			$this->error->display_error('ERROR_MAILSERVER_GET_MAILBOX_STATUS_FAILED', 
				$mailbox_name, imap_last_error());

		// Return information as an array
		return array(
			'num_messages' => $status->messages,
			'num_recent' => $status->recent,
			'num_unread' => $status->unseen,
			'uid_validity' => $status->uidvalidity,
		);
	}
	
	/**
	 * Subscribe to the specified mailbox
	 *
	 * @param string $mailbox_name Name of the mailbox to subscribe to
	 */
	function subscribe_mailbox($mailbox_name) {
		// Subscribe
		$returned = imap_subscribe(
			$this->connection, 
			$this->server_config['server'] . $mailbox_name
		);

		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_SUBSCRIBE_MAILBOX_FAILED', 
				$mailbox_name, imap_last_error());
			
		return $returned;
	}
	
	/**
	 * Unsubscribe from the specified mailbox
	 *
	 * @param string $mailbox_name Name of the mailbox to unsubscribe from
	 */
	function unsubscribe_mailbox($mailbox_name) {
		// Unsubscribe
		$returned = imap_unsubscribe(
			$this->connection, 
			$this->server_config['server'] . $mailbox_name
		);

		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_UNSUBSCRIBE_MAILBOX_FAILED', 
				$mailbox_name, imap_last_error());
			
		return $returned;
	}
	
	///////////////////////////////////////////////////////
	// Message information related code
	
	/**
	 * Get all the message headers for the current mailbox
	 *
	 * The object returned has several members which are documented here:
	 * http://www.php.net/manual/en/function.imap-headerinfo.php
	 *
	 * The Msgno member value is replaced with the message UID instead.
	 *
	 * @return array Returns an array of message header associative arrays with multiple fields
	 */
	function get_all_messages() {
		// Get all message numbers
		$list = imap_search(
			$this->connection, 
			'ALL'
		);

		// Display error if failure
		if ($list === false)
			$this->error->display_error('ERROR_MAILSERVER_MESSAGE_SEARCH_FAILED', imap_last_error());

		// Sort the list
		if (is_array($list)) sort($list);

		// Store the headers for all the emails
		$headers = array();
		foreach ($list as $id) {
			// Headers for this message
			$header = array();
			
			// Get the headers for each message
			$header_obj = imap_headerinfo(
				$this->connection, 
				$id
			);
			
			// Convert msgno to uid since it is unique whereas msgno changes
			$header['email_uid'] = imap_uid(
				$this->connection, 
				$header_obj->Msgno
			);

			// The other message properties
			$header['message_id'] = $header_obj->message_id;
			$header['udate'] = $header_obj->udate;
			$header['subject'] = $header_obj->subject;
			$header['size'] = $header_obj->Size;
			
			// Unread flag
			$header['unread'] = 0;
			if (isset($header_obj->Unseen) && $header_obj->Unseen == 'U' || $header_obj->Recent == 'N')
				$header['unread'] = 1;
				
			// Answered flag
			$header['answered'] = 0;
			if (isset($header_obj->Answered) && $header_obj->Answered == 'A')
				$header['answered'] = 1;
			
			// Draft flag
			$header['draft'] = 0;
			if (isset($header_obj->Draft) && $header_obj->Draft == 'X')
				$header['draft'] == 1;
			
			// Email information
			$types = explode(' ', 'from to cc bcc reply_to sender');
			foreach ($types as $type) {
				$header[$type] = array();
				if (isset($header_obj->$type))
					$header[$type] = $header_obj->$type;
			}
			
			array_push($headers, $header);
		}
		
		return $headers;
	}
	
	/** 
	 * Get the raw headers for the specified message uid
	 *
	 * @param int $message_uid The message uid to pull headers for
	 *
	 * @return string Returns a string containing all the header information
	 */
	function get_raw_headers($message_uid) {
		// Get the headers
		$returned = imap_fetchheader(
			$this->connection,
			$message_uid,
			FT_UID
		);
		
		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_FETCH_HEADER_FAILED', $message_uid, imap_last_error());
		
		return $returned;
	}
	
	// Get a list of message parts
	function get_message_parts($message_uid) {
		// Get the structure
		$struct = imap_fetchstructure(
			$this->connection,
			$message_uid,
			FT_UID
		);
		
		// Display error if failure
		if ($struct === false) break;
		
		return $struct;
	}
	
	///////////////////////////////////////////////////////
	// Message action related code
	
	/**
	 * Copy the specified messages to the specified mailbox
	 *
	 * @param string $message_uids One or more message uids, comma separated
	 * @param string $mailbox_name Mailbox to copy the messages to
	 */
	function copy_messages($message_uids, $mailbox_name) {
		// Don't copy anything if source and destination mailbox are the same
		if ($this->current_mailbox == $mailbox_name) return;
		
		// Perform the copy
		$returned = imap_mail_copy(
			$this->connection,
			$message_uids,
			$mailbox_name,
			CP_UID
		);
		
		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_MESSAGE_COPY_FAILED', 
				$message_uids, $mailbox_name, imap_last_error());
		
		return $returned;
	}

	/**
	 * Move the specified messages to the specified mailbox
	 *
	 * @param string $message_uids One or more message uids, comma separated
	 * @param string $mailbox_name Mailbox to move the messages to
	 */
	function move_messages($message_uids, $mailbox_name) {
		// Don't move anything if source and destination mailbox are the same
		if ($this->current_mailbox == $mailbox_name) return;
		
		// Perform the move
		$returned = imap_mail_move(
			$this->connection,
			$message_uids,
			$mailbox_name,
			CP_UID
		);
		
		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_MESSAGE_MOVE_FAILED', 
				$message_uids, $mailbox_name, imap_last_error());
		
		return $returned;
	}

	/** 
	 * Mark specified messages as read
	 *
	 * @param string $message_uids One or more message uids, comma separated, to mark as read
	 */
	function mark_messages_as_read($message_uids) {
		// Mark as seen
		$returned = imap_setflag_full(
			$this->connection,
			$message_uids,
			"\\Seen",
			ST_UID
		);
		
		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_MARK_MESSAGE_READ_FAILED', 
				$message_uids, imap_last_error());
		
		return $returned;
	}
	
	/** 
	 * Mark specified messages as unread
	 *
	 * @param string $message_uids One or more message uids, comma separated, to mark as unread
	 */
	function mark_messages_as_unread($message_uids) {
		// Clear seen flag
		$returned = imap_clearflag_full(
			$this->connection,
			$message_uids,
			"\\Seen",
			ST_UID
		);
		
		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_MARK_MESSAGE_UNREAD_FAILED', 
				$message_uids, imap_last_error());
		
		return $returned;
	}

	/** 
	 * Delete specified messages
	 *
	 * @param string $message_uids One or more message uids, comma separated, to delete
	 */
	function delete_messages($message_uids) {
		// Delete
		$returned = imap_delete(
			$this->connection,
			$message_uids,
			FT_UID
		);
		
		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_DELETE_MESSAGE_FAILED', 
				$message_uids, imap_last_error());
		
		return $returned;
	}
	
	/** 
	 * Undelete specified messages
	 *
	 * @param string $message_uids One or more message uids, comma separated, to undelete
	 */
	function undelete_messages($message_uids) {
		// Undelete
		$returned = imap_undelete(
			$this->connection,
			$message_uids,
			FT_UID
		);
		
		// Display error if failure
		if ($returned === false)
			$this->error->display_error('ERROR_MAILSERVER_UNDELETE_MESSAGE_FAILED', 
				$message_uids, imap_last_error());
		
		return $returned;
	}

	///////////////////////////////////////////////////////
	// Error messages
	
	/** 
	 * Register all error messages
	 *
	 * This action is called by the constructor. For internal use only.
	 */
	function register() {
		// Register error messages
		$this->register_error(
			'ERROR_MAILSERVER_CONNECTION_FAILED', 
			"Failed to connect to the server. Please check configuration. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_EXPUNGE_FAILED', 
			"Failed expunge. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_OPEN_MAILBOX_FAILED', 
			"Failed to open mailbox '%s'. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_CREATE_MAILBOX_FAILED', 
			"Failed to create mailbox '%s'. Errors: %s", 
			"ERROR"
		);

		$this->register_error(
			'ERROR_MAILSERVER_RENAME_MAILBOX_FAILED', 
			"Failed to rename mailbox '%s' to '%s'. Errors: %s", 
			"ERROR"
		);

		$this->register_error(
			'ERROR_MAILSERVER_DELETE_MAILBOX_FAILED', 
			"Failed to delete mailbox '%s'. Errors: %s", 
			"ERROR"
		);

		$this->register_error(
			'ERROR_MAILSERVER_GET_MAILBOXES_FAILED', 
			"Failed to get all mailboxes. Errors: %s", 
			"ERROR"
		);

		$this->register_error(
			'ERROR_MAILSERVER_GET_SUBSCRIBED_MAILBOXES_FAILED', 
			"Failed to get subscribed mailboxes. Errors: %s", 
			"ERROR"
		);

		$this->register_error(
			'ERROR_MAILSERVER_GET_MAILBOX_STATUS_FAILED', 
			"Failed to get status for mailbox '%s'. Errors: %s", 
			"ERROR"
		);

		$this->register_error(
			'ERROR_MAILSERVER_SUBSCRIBE_MAILBOX_FAILED', 
			"Failed to subscribe to mailbox '%s'. Errors: %s", 
			"ERROR"
		);

		$this->register_error(
			'ERROR_MAILSERVER_SUBSCRIBE_MAILBOX_FAILED', 
			"Failed to unsubscribe from mailbox '%s'. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_MESSAGE_SEARCH_FAILED', 
			"Failed message search. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_FETCH_HEADER_FAILED', 
			"Failed to fetch headers for message uid '%s'. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_MESSAGE_COPY_FAILED', 
			"Failed to copy message uids '%s' to mailbox '%s'. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_MESSAGE_MOVE_FAILED', 
			"Failed to move message uids '%s' to mailbox '%s'. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_MARK_MESSAGE_READ_FAILED', 
			"Failed to mark message uids '%s' as read. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_MARK_MESSAGE_READ_FAILED', 
			"Failed to mark message uids '%s' as unread. Errors: %s", 
			"ERROR"
		);

		$this->register_error(
			'ERROR_MAILSERVER_DELETE_MESSAGE_FAILED', 
			"Failed to delete message uids '%s'. Errors: %s", 
			"ERROR"
		);
		
		$this->register_error(
			'ERROR_MAILSERVER_UNDELETE_MESSAGE_FAILED', 
			"Failed to undelete message uids '%s'. Errors: %s", 
			"ERROR"
		);
	}
}

?>