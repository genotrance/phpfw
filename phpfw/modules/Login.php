<?php

/**
 * The Login Module
 *
 * This module can be used by other modules as follows:-
 * - Copy module file to module directory
 * - Adjust configuration file as needed (see further below on configuration file requirements)
 * - In any action that requires the user to be logged in, run:
 * - $this->exec_module_action('Login', 'check_login')
 *
 * After login and logout, the module executes the default action specified in the configuration.
 *
 * The Login module requires the following entries in the configuration file.
 *
 * [login]
 * table = login_table_name	: Name of the table in the database that contains login information
 * user_field = user_field_name	: Name of the field that contains the user name
 * pass_field = password_field_name : Name of the field that contains the password (encrypted using PASSWORD())
 *
 * The defaults are as below:
 * [login]
 * table = user
 * user_field = email
 * pass_field = password
 */
class Login extends Module {
	/**
	 * @var string $table Name of the table in the database that contains login information
	 *
	 * Default: 'user'
	 */
	var $table;

	/**
	 * @var string $table Name of the field that contains the user name
	 *
	 * Default: 'email'
	 */
	var $user_field;

	/**
	 * @var string $table Name of the field that contains the password (encrypted using PASSWORD())
	 *
	 * Default: 'password'
	 */
	var $pass_field;
	
	/**
	 * Constructor for Login module
	 *
	 * Register exceptions in the constructor
	 */
	function Login() {
		$this->exceptions = array('login_user_form');
	}
	
	/**
	 * Get the configuration from the config file
	 */
	function get_configuration() {
		// Default initialize
		$this->table = 'user';
		$this->user_field = 'email';
		$this->pass_field = 'password';

		// Load values from config file if applicable
		if (isset($this->application->config['login'])) {
			if (isset($this->application->config['login']['table']))
				$this->table = $this->application->config['login']['table'];
			if (isset($this->application->config['login']['user_field']))
				$this->table = $this->application->config['login']['user_field'];
			if (isset($this->application->config['login']['pass_field']))
				$this->table = $this->application->config['login']['pass_field'];
		}
	}
	
	/**
	 * Login the user
	 */
	function login_user()
	{
		// Load the configuration
		$this->get_configuration();
		
		// Check if login form was filled
		if (isset($_POST[$this->user_field]) && isset($_POST[$this->pass_field])) {
			// Check if specified login exists
			$rows =& $this->sql->select_query('SQL_SELECT', $this->table, "where ".$this->user_field."='".$_POST[$this->user_field]."'");
			if (count($rows) != 1) {
				sleep(5);    // Sleep to avoid abuse
				$this->login_user_form(true);
				return false;
			}
		
			// Check if login/password matches
			$rows =& $this->sql->select_query('SQL_SELECT', 
				$this->table, "where ".$this->user_field."='".$_POST[$this->user_field]."' and ".
				$this->pass_field."=PASSWORD('".$_POST[$this->pass_field]."')");
			if (count($rows) != 1) {
				sleep(5);    // Sleep to avoid abuse
				$this->login_user_form(true);
				return false;
			}
			$row = $rows[0];
		
			// Save some of the values in the PHP session
			$_SESSION['session_user'] = $_POST[$this->user_field];
			$_SESSION['session_authenticated'] = 1;

			// Run the default action
			$_SERVER['QUERY_STRING'] = '';
			$this->controller->execute();
		} else {
			$this->login_user_form();
			return false;
		}
	
		return false;        
	}
 
	/**
	 * Log out the current user. This is done by deleting the session
	 */
	function logout_user()
	{
		// Delete all the saved session data
		$_SESSION = array();
	
		// Delete the session cookie
		if (isset($_COOKIE[session_name()])) {
			setcookie(session_name(), '', time()-42000, '/');
		}
	
		// Destroy the session data in PHP
		session_destroy();

		// Run the default action
		$_SERVER['QUERY_STRING'] = '';
		$this->controller->execute();
	}

	/**
	 * Display login form
	 *
	 * @param bool $failed Set to true if previous login attempt failed.
	 */
	function login_user_form($failed=false) {
		// Load the configuration
		$this->get_configuration();
		
		// Header
		$view = new View("Login");
		$view->h2();
		$view->nl();
		$view->center();
		$this->output .= $view->get_data();
		
		// User label
		$view->set_data("User name:");
		$view->label($this->user_field);
		$user_label = $view->get_data();
		
		// User text box
		$view->reset_data();
		$view->set_properties(array("size" => 33, "maxlength" => 60));
		$view->input_text($this->user_field);
		$user_textbox = $view->get_data();
		
		// Pass label
		$view->set_data("Password:");
		$view->label($this->pass_field);
		$pass_label = $view->get_data();
		
		// Pass text box
		$view->reset_data();
		$view->input_password($this->pass_field);
		$pass_textbox = $view->get_data();
		
		// Submit and cancel buttons
		$view->reset_data();
		$view->input_submit();
		$view->sp();
		$view->push();
		$view->input_cancel();
		$view->pop_prepend();
		$buttons = $view->data;

		// Create table
		$view->set_data(array(
			$user_label => $user_textbox,
			$pass_label => $pass_textbox,
			' ' => $buttons));
		$view->table_two_column_associative();
		$view->set_properties(array(
			"action" => $this->controller->encode_url('Login', 'login_user'),
			"method" => "POST",
			"onsubmit" => "javascript: return form.validate(this);"));
		$view->form("login");
		$view->center();
		
		$this->output .= $view->get_data();
		
		// Print failed message
		if ($failed) {
			$view->set_data("Login failed!");
			$view->set_properties(array("color" => "red"));
			$view->font();
			$view->nl();
			$view->center();
			$this->output .= $view->get_data();
		}
	}

	/**
	* Check if a user is logged in
	*
	* @param bool $forward Forward the user to the login form if true (default: true)
	*/
	function check_login($forward=true) {
		if (!isset($_SESSION) || !isset($_SESSION['session_authenticated']) || $_SESSION['session_authenticated'] != 1) {
			if ($forward == true) {
				$this->login_user_form();
				$this->render();
				exit;
			}
			else return false;
		}
	
		return true;
	}
}

?>
