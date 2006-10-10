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
 * key_field = key_field_name : Name of the field that contains the primary key 
 * user_field = user_field_name	: Name of the field that contains the user name
 * pass_field = password_field_name : Name of the field that contains the password (encrypted using PASSWORD())
 *
 * The defaults are as below:
 * [login]
 * table = user
 * key_field = user_id
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
	 * @var string $table Name of the field that contains the primary key
	 *
	 * Default: 'user_id'
	 */
	var $key_field;

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
		$this->register_exception('login_user_form');
		$this->register_error('ERROR_LOGIN_NEW_PASSWORD_MISMATCH', 
			"New passwords do not match. Please try again.", "ERROR");
		$this->register_error('ERROR_LOGIN_CURRENT_PASSWORD_INCORRECT', 
			"Current password incorrect. Please try again.", "ERROR");
		$this->register_error('ERROR_LOGIN_NEW_PASSWORD_TOO_SHORT', 
			"Your new password is too short. At least 8 characters are required. Please try again.", "ERROR");
		$this->disable_template_library();
	}
	
	/**
	 * Get the configuration from the config file
	 */
	function get_configuration() {
		// Default initialize
		$this->table = 'user';
		$this->key_field = 'user_id';
		$this->user_field = 'email';
		$this->pass_field = 'password';

		// Load values from config file if applicable
		if (isset($this->application->config['login'])) {
			if (isset_and_non_empty($this->application->config['login']['table']))
				$this->table = $this->application->config['login']['table'];
			if (isset_and_non_empty($this->application->config['login']['key_field']))
				$this->key_field = $this->application->config['login']['key_field'];
			if (isset_and_non_empty($this->application->config['login']['user_field']))
				$this->user_field = $this->application->config['login']['user_field'];
			if (isset_and_non_empty($this->application->config['login']['pass_field']))
				$this->pass_field = $this->application->config['login']['pass_field'];
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
		if (isset_and_non_empty($_POST[$this->user_field]) && isset($_POST[$this->pass_field])) {
			// Check if specified login exists
			$rows =& $this->sql->select_query('SQL_SELECT', 
				$this->table, "where ".$this->user_field."='".$_POST[$this->user_field]."'");
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
			$_SESSION['session_user_id'] = $row[$this->key_field];
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
		// Load the configuration
		$this->get_configuration();
		
		if (!isset($_SESSION) || 
			!isset($_SESSION['session_authenticated']) || 
			$_SESSION['session_authenticated'] != 1) {
			if ($forward == true) {
				$this->login_user();
				$this->render();
				exit;
			}
			else return false;
		}
	
		return true;
	}
	
	/*
	 * This function updates the login of the currently logged in user
	 * 
	 * Function requires the user to be logged in in order to change their login. It changes the login of the
	 * active user.
	 *
	 * @param string $new_login A string containing the updated login. String is escaped by the function using addslashes()
	 */
	function update_login($new_login) {
		// Verify that we are logged in
		$this->check_login();
		
		// Escape the provided string
		$new_login = addslashes($new_login);
		
		// Update login only if it has changed
		if ($new_login != $_SESSION['session_user']) {
			// Update the database row
			$this->database->sql->update_query(
				'SQL_UPDATE', $this->table, 
				$this->user_field."='".$new_login."'", 
				$this->user_field."='".$_SESSION['session_user']."'", "nocheck");
				
			// Update the session variable
			$_SESSION['session_user'] = $new_login;
		}
	}
	
	/*
	 * This function creates a form to update the password of the currently logged in user
	 *
	 * Function requires the user to be logged in in order to change their login. It changes the login of the
	 * active user. It should be directly invoked.
	 */
	function change_password() {
		// Verify that we are logged in
		$this->check_login();

		if (isset_and_non_empty($_POST['current_password']) && 
			isset_and_non_empty($_POST['new_password']) && 
			isset_and_non_empty($_POST['new_password_repeat'])) {
			$current_password = addslashes($_POST['current_password']);
			$new_password = addslashes($_POST['new_password']);
			$new_password_repeat = addslashes($_POST['new_password_repeat']);
			
			// Check if current password is correct
			$rows =& $this->sql->select_query('SQL_SELECT', 
				$this->table, "where ".$this->user_field."='".$_SESSION['session_user']."' and ".
				$this->pass_field."=PASSWORD('".$current_password."')");
			if (count($rows) != 1) {
				sleep(5);    // Sleep to avoid abuse
				unset($_POST['current_password']);
				unset($_POST['new_password']);
				unset($_POST['new_password_repeat']);
				$this->change_password();
				$this->error->display_error('ERROR_LOGIN_CURRENT_PASSWORD_INCORRECT');
			}

			// Check new passwords
			if ($new_password != $new_password_repeat) {
				unset($_POST['current_password']);
				unset($_POST['new_password']);
				unset($_POST['new_password_repeat']);
				$this->change_password();
				$this->error->display_error('ERROR_LOGIN_NEW_PASSWORD_MISMATCH');
			}
			
			// Check password length
			if (strlen($new_password) < 8) {
				unset($_POST['current_password']);
				unset($_POST['new_password']);
				unset($_POST['new_password_repeat']);
				$this->change_password();
				$this->error->display_error('ERROR_LOGIN_NEW_PASSWORD_TOO_SHORT');
			}
			
			// Update the password field
			$this->sql->update_query('SQL_UPDATE', $this->table,
				$this->pass_field."=PASSWORD('".$new_password."')",
				$this->user_field."='".$_SESSION['session_user']."'", "nocheck");
				
			// Run the default action
			$_SERVER['QUERY_STRING'] = '';
			$this->controller->execute();
		} else {
			// Display the change password form
			
			// Create the labels
			$view = new View("Current password");
			$view->label("current_password");
			$label_op = $view->get_data();
			
			$view->set_data("New password");
			$view->label("new_password");
			$label_np = $view->get_data();

			$view->set_data("New password (repeat)");
			$view->label("new_password_repeat");
			$label_npr = $view->get_data();
			
			// Create the password fields
			$view->reset_data();
			$view->set_properties(array('required' => 'required'));
			$view->input_password("current_password");
			$op_data = $view->data;

			$view->reset_data();
			$view->set_properties(array('required' => 'required'));
			$view->input_password("new_password");
			$np_data = $view->data;

			$view->reset_data();
			$view->set_properties(array('required' => 'required'));
			$view->input_password("new_password_repeat");
			$npr_data = $view->data;

			// Create submit button
			$view->input_submit();
			$view->sp();
			$view->push();

			// Create cancel button
			$view->input_cancel('cancel');
			$view->pop_prepend();
			
			$buttons = $view->get_data();

			// Create the table
			$view->set_data(array(
					$label_op => $op_data,
					$label_np => $np_data,
					$label_npr => $npr_data,
					' ' => $buttons
				)
			);
			$view->table_two_column_associative();
			
			// Create the form
			$view->set_properties(array(
					'action' => $this->controller->encode_url('Login', 'change_password'),
					'method' => "POST",
					'onsubmit' => "javascript:return form.validate(this);"
				)
			);
			$view->form('change_password');
			$view->push();
			
			// Header
			$view->set_data("Change password");
			$view->h2();
			$view->nl();
			$view->pop_append();
			
			$view->center();
			$this->output = $view->get_data();
		}
	}
}

?>
