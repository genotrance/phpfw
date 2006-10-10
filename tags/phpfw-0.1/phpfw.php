<?php

/**
 * Align left for View::table()
 */
define('L', "left");

/**
 * Align center for View::table()
 */
define('C', "center");

/**
 * Align right for View::table()
 */
define('R', "right");

/**
 * Name of date_added field
 */
define ("DATE_ADDED", "date_added");

/**
 * Name of date_updated field
 */
define ("DATE_UPDATED", "date_updated");

/**
 * Format for the date() command
 */
define ('DATE_FORMAT', 'Y-m-d H:i:s');

/**
 * Type for Table: DATA
 */
define ("DATA", 0);

/**
 * Type for Table: LINK
 */
define ("LINK", 1);

/**
 * Global variable to store a reference to the application object (linked in Application constructor)
 */
$__application = 0;

/** 
 * The main application class
 *
 * On instantiation, this class loads all ini files, creates all objects 
 * and then executes actions.
 */
class Application {
	/**
	 * @var array Configuration file array
	 */
	var $config;

	/**
	 * @var object Error The Error object
	 */
	var $error;

	/**
	 * @var object Database The Database object
	 */
	var $database;

	/**
	 * @var object Controller The Controller object
	 */
	var $controller;
	
	/**
	 * @var array Array of script files to use in this application
	 */
	var $scripts;
	
	/**
	 * @var array Array of CSS files to use in this application
	 */
	var $stylesheets;
	
	/**
	 * Constructor for the Application class
	 *
	 * - Loads all ini files
	 * - Creates all objects : Error, Database, Controller
	 * - Executes actions
	 *
	 * @param string $config_file Path to the config file for the application
	 * @param string $session_name The session name
	 * @param string $session_lifetime The time (in seconds) to keep the session active. (default = 3600)
	 */
	function Application($config_file, $session_name, $session_lifetime=3600) {
		// Start session
		session_name($session_name);
		session_set_cookie_params($session_lifetime);
		session_start();
		
		// Initialize members
		$this->scripts = array();
		$this->stylesheets = array();
		
		// Link object to global application reference
		$GLOBALS['__application'] =& $this;
		
		// Load the configuration file
		if (!file_exists($config_file)) 
			$this->display_error("Can not find config file '$config_file'.");
		$this->config = parse_ini_file($config_file, true);

		// Check the configuration file
		$this->check_configuration_file();
		
		// Load errors from file
		$this->error = new Error($this->config['include']['error']);

		// Process [include] section if specified
		$this->load_include_files();
		
		// Create a database object and parse schema
		$this->database = new Database(
			$this->config['database']['adodb'],
			$this->config['database']['type'],
			$this->config['database']['host'],
			$this->config['database']['user'],
			$this->config['database']['pass'],
			$this->config['database']['name'],
			$this->config['include']['sql']);

		// Load the database schema and check it
		$this->database->get_schema($this->config['table_name'], $this->config['table_key']);

		// Create a controller object
		$this->controller = new Controller();
		
		// Add all the modules present in the ['module']['path'] directory
		$modules = glob($this->config['module']['path']."/*.php");
		foreach ($modules as $module) 
			$this->controller->add_module(
				str_replace($this->config['module']['path'].'/', '', str_replace(".php", "", $module)));
		
		// Set a default module and action
		$this->controller->set_default($this->config['module']['default']);
		
		// Execute actions
		$this->controller->execute();
	}
	
	/**
	 * Display an error message - this is used by the Application before the Error class is loaded
	 *
	 * @param string $error_msg The message to display
	 */
	function display_error($error_msg) {
		$view = new View($error_msg);
		$view->set_properties(array("color" => "red"));
		$view->font();
		$view->center();
		$view->render();
		exit;
	}

	/**
	 * Verify that the configuration file has all required elements declared.
	 */
	function check_configuration_file() {
		// Check if all required sections are present
		$sections = array('database', 'module', 'include', 'template', 'table_name', 'table_key');
		foreach ($sections as $section)
			if (!isset($this->config[$section])) 
				$this->display_error("Missing required configuration file section '$section'.");

		// Check if all database sub-sections are present
		$this->check_configuration_section('database', array('adodb', 'type', 'host', 'user', 'name'));
		if (!isset($this->config['database']['pass']))
			$this->display_error("Missing required configuration file sub-section 'pass' for section 'database'.");

		// Check if all module sub-sections are present
		$this->check_configuration_section('module', array('default', 'path'));

		// Check if all include sub-sections are present
		$this->check_configuration_section('include', array('sql', 'error', 'scripts'));
	}

	/**
	 * Helper function for check_configuration_file() to check a configuration section.
	 *
	 * @param string $section Section to check
	 * @param array $sub_sections Array of sub-sections that need to be defined
	 */
	function check_configuration_section($section, $sub_sections) {
		// Check if all include sub-sections are present
		foreach ($sub_sections as $sub_section)
			if (!isset($this->config[$section][$sub_section]) || $this->config[$section][$sub_section] == '') 
				$this->display_error("Missing required configuration file sub-section '$sub_section' for section '$section'.");
	}
	
	/**
	 * Load all include files - scripts and stylesheets
	 */
	function load_include_files() {
		// If section defined in config file
		if (isset($this->config['include'])) {
			// If script subsection exists
			if (isset($this->config['include']['scripts']) && $this->config['include']['scripts'] != '') {
				$scripts = explode(" ", $this->config['include']['scripts']);
				foreach ($scripts as $script)
					$this->scripts[] = $script;
			}
			
			// If stylesheet subsection exists
			if (isset($this->config['include']['stylesheets']) && $this->config['include']['stylesheets'] != '') {
				$stylesheets = explode(" ", $this->config['include']['stylesheets']);
				foreach ($stylesheets as $stylesheet)
					$this->stylesheets[] = $stylesheet;
			}
		}
	}
}

/**
 * The Controller class loads all modules and executes requested action
 *
 * - Object created on instantiation. 
 * - Modules added with add_module()
 * - Default action set with set_default()
 * - Exceptions added with add_exception()
 *
 * Modules are classes. Actions are the declared class methods. The
 * module constructor is called by the controller in add_module().
 *
 * The URLs sent to the browser are encoded so as to hide the internal
 * functionality of the application.
 */
class Controller {
	/**
	 * @var array Associative array of module objects
	 *
	 * modules['module_name'] = new module_name();
	 */
	var $modules;

	/**
	 * @var array The default module:action to execute if unspecified
	 *
	 * Contains a single default module:action declaration that is called
	 * if no action is specified.
	 *
	 * $default['module_name'] = 'module_action';
	 */
	var $default;

	/**
	 * @var array List of module:action exceptions
	 *
	 * Exceptions that can be called directly on the URL instead of
	 * requiring encoding.
	 *
	 * $exceptions[] = 'module_name:module_action';
	 */
	var $exceptions;

	/**
	 * Constructor for the Controller class
	 *
	 * Zero initializes members.
	 */
	function Controller() {
		$this->modules = array();
		$this->default = array();
		$this->exceptions = array();
	}

	/**
	 * Add a module to the controller
	 *
	 * - Checks if module class exists
	 * - Creates an object of the module class
	 * - Adds object to the $modules variable
	 *
	 * @param string $module_name The name of the module or class to add to this controller
	 */
	function add_module($module_name) {
		// Give module access to the global variable $__application
		global $__application;

		// Get path to the module file
		$path = $__application->config['module']['path'];

		// Check that module file exists at the specified path
		if (!file_exists("$path/$module_name.php"))
			$__application->error->display_error('ERROR_CONTROLLER_MODULE_FILE_DOES_NOT_EXIST', $module_name);

		// Include the module file
		require("$path/$module_name.php");

		// Check if class exists
		if (!class_exists($module_name))
			$__application->error->display_error('ERROR_CONTROLLER_MODULE_DOES_NOT_EXIST', $module_name);

		// Create object and add to modules associative array
		$this->modules[$module_name] = new $module_name();

		// Set the references to the application objects for easy access in the module
		if (method_exists($this->modules[$module_name], 'set_references'))
			$this->modules[$module_name]->set_references();
	
		// Initialize the module
		if (method_exists($this->modules[$module_name], 'initialize'))
			$this->modules[$module_name]->initialize();

		// Load exceptions registered by the module
		if (method_exists($this->modules[$module_name], 'get_exceptions')) {
			$exceptions = $this->modules[$module_name]->get_exceptions();
			foreach ($exceptions as $exception) $this->add_exception($exception);
		}
	}

	/**
	 * Add a module:action exception
	 *
	 * - Calls check_module_action()
	 * - Adds exception to $exceptions associative array
	 *
	 * @param string $exception The exception in module:action format to add to this controller
	 */
	function add_exception($exception) {
		// Check the module:action syntax and if it exists
		$exception_array = $this->check_module_action($exception);

		// Add to exceptions array
		$this->exceptions[] = $exception;
	}
	
	/**
	 * Set a default module:action to execute when none is specified
	 *
	 * - Checks if default is already set
	 * - Calls check_module_action()
	 * - Adds default to $default associative array
	 *
	 * @param string $default The default in module:action format for this controller
	 */
	function set_default($default) {
		// Check if default not yet set
		if (count($this->$default))
			$GLOBALS['__application']->error->display_error('ERROR_CONTROLLER_DEFAULT_MODULE_ACTION_EXISTS');

		// Check the module:action syntax and if it exists
		$default_array = $this->check_module_action($default);

		// Add to default array
		$this->default[$default_array[0]] = $default_array[1];
	}

	/**
	 * Checks the module:action syntax and if corresponding class and method exist
	 *
	 * Called by add_exception(), set_default(), Module::exec_module_action(), etc.
	 *
	 * @param string $module_action The module:action string to check for syntax and existance
	 *
	 * @return array Returns the module and action as an array('module_name', 'action_name')
	 */
	function check_module_action($module_action) {
		// Check if syntax is valid
		$module_action_array = explode(':', $module_action);
		if (count($module_action_array) != 2)
			$GLOBALS['__application']->error->display_error('ERROR_CONTROLLER_MODULE_ACTION_SYNTAX', $module_action);

		// Check if class and method exist
		if (!class_exists($module_action_array[0]))
			$GLOBALS['__application']->error->display_error('ERROR_CONTROLLER_MODULE_DOES_NOT_EXIST', $module_action_array[0]);
		$methods = get_class_methods($module_action_array[0]);
		if (!in_array($module_action_array[1], $methods))
			$GLOBALS['__application']->error->display_error('ERROR_CONTROLLER_MODULE_ACTION_DOES_NOT_EXIST', $module_action_array[1], $module_action_array[0]);

		return $module_action_array;
	}

	/**
	 * Encode a query string to be sent to the browser.
	 *
	 * Function generates a random hash to be sent to the browser as a URL. The hash is
	 * decoded by the decode_url() function.
	 *
	 * The resultant hashes are stored in $_SESSION['url_hash'].
	 *
	 * @param string $module Name of the module
	 * @param string $action Name of the action for this module
	 * @param string $params Parameters required for this module:action combination in "param=value&param=value" format. (default: 0)
	 *
	 * @return string A random hash corresponding to this URL - "?url=HASHVALUE"
	 */
	function encode_url($module, $action, $params=0) {
		// Check that this module and action exist
		$this->check_module_action("$module:$action");
		
		// Create the URL as specified
		$url = "module=$module&action=$action";
		if ($params) $url .= "&$params";
		
		// Check if hash table has been initialized
		if (isset($_SESSION['url_hash']) && is_array($_SESSION['url_hash']))
			$hash = array_search($url, $_SESSION['url_hash']);
		else
			$hash = false;

		// Check if this URL is already hashed
		if ($hash === false) {
			// Create a new hash
			$hash = md5(uniqid(rand(), true));

			// Save to hash table
			$_SESSION['url_hash'][$hash] = $url;
		}

		// Return the resultant hash
		return $_SERVER['PHP_SELF']."?url=$hash";
	}

	/**
	 * Decode a query string sent by the browser.
	 *
	 * Function looks up the query string hash in the $_SESSION['url_hash'] array and 
	 * converts it into the corresponding URL.
	 *
	 * - If there is no query string, default module:action is selected
	 * - If there is a regular query string (not a hash) then verify that it is in
	 *   the exception list. If not, select default module:action.
	 *
	 * @return string The corresponding query string for the hash
	 */
	function decode_url() {
		// Get the default query
		$default_query = '';
		foreach ($this->default as $module => $action)
			$default_query = "module=$module&action=$action";

		// Get the query string
		$query = $_SERVER['QUERY_STRING'];
		parse_str($query);

		if (!isset($url)) {
			// No hash specified
			if (!isset($module) && !isset($action)) {
				// No module or action, select default action
				$query = $default_query;
			} else {
				// Some module:action specified, check if an exception
				if (in_array("$module:$action", $this->exceptions)) {
					// Valid exception, pass through the query
				} else {
					// Invalid exception, select default action
					$query = $default_query;
				}
			}
		} else {
			// URL hash is specified
			if (!isset($_SESSION['url_hash']) || !isset($_SESSION['url_hash'][$url]))
				// No hash table or invalid hash
				$query = $default_query;
			else {
				// Valid hash
				$query = $_SESSION['url_hash'][$url];
			}
		}

		// Return the query
		return $query;
	}

	/**
	 * Execute the module:action specified, or select default action
	 */
	function execute() {
		// Decode the query string
		$query = $this->decode_url();
		parse_str($query);

		if (!isset($module) && !isset($action))
			$GLOBALS['__application']->error->display_error('ERROR_CONTROLLER_MISSING_MODULE_ACTION');

		// Execute the module action
		$this->modules[$module]->$action();
			
		// Render the output for this module
		if (method_exists($this->modules[$module], 'render'))
			$this->modules[$module]->render();
	}
}

/**
 * The Error class stores error strings and displays them as needed.
 *
 * Loads errors from file on instantiation. display_error() is called as needed.
 */
class Error {
	/**
	 * @var array Stores the error strings as an array
	 */
	var $error_strings;

	/**
	 * Constructor for the Error class
	 *
	 * @param string $error_file Load error strings this file
	 */
	function Error($error_file) {
		if (!file_exists($error_file))
			$GLOBALS['__application']->display_error("Can not find error file '$error_file'.");
		$this->error_strings = parse_ini_file($error_file, true);
		$this->check_errors();
	}

	/**
	 * Check that error file syntax is correct
	 */
	function check_errors() {
		foreach ($this->error_strings as $error_name => $error_data) {
			// Verify error text is defined
			if (!isset($error_data['text']) || $error_data['text'] == '')
				die ("Error text unspecified for error '$error_name'");

			// Verify error severity is defined
			if (!isset($error_data['severity']) || $error_data['severity'] == '')
				die ("Error severity unspecified for error '$error_name'");

			// Verify valid error severity
			$valid_severities = array('ERROR', 'STOP', 'WARNING', 'MESSAGE');
			if (!in_array($error_data['severity'], $valid_severities)) 
				die ("Invalid severity '${error_data['severity']}' for error '$error_name'");
		}
	}

	/**
	 * Display the specified error message
	 *
	 * Display the error message specified. Exit if severity is ERROR or STOP.
	 * Called as follows:
	 *
	 * display_error('ERROR_NAME_DEFINED', $param1, $param2, .., $paramN);
	 */
	function display_error() {
		$num_args = func_num_args();
		$args = func_get_args();

		// Check if no arguments
		if (!$num_args)
			$this->display_error('ERROR_ERROR_ZERO_ARGUMENTS');

		// Check if valid error name
		$error = array_shift($args);
		$num_args--;
		if (!array_key_exists($error, $this->error_strings))
			$this->display_error('ERROR_ERROR_INVALID_ERROR_NAME', $error);

		// Number of %s should match number of arguments
		$error_text = $this->error_strings[$error]['text'];
		$num_percent_s = preg_match_all("/%s/", $error_text, $matches);
		if ($num_percent_s != $num_args)
			$this->display_error('ERROR_ERROR_INSUFFICIENT_ARGUMENTS', $error);

		// Generate error string
		foreach ($args as $arg)
			$error_text = preg_replace("/%s/", $arg, $error_text, 1);

		// Display the output
		$view = new View($error_text, array("color" => "red"));
		$view->font();
		$view->center();
		$view->render();

		// Quit if severity is ERROR or STOP
		$quit = array('ERROR', 'STOP');
		if (in_array($this->error_strings[$error]['severity'], $quit))
			exit;
	}
}

/**
 * The Form class creates and processes HTML forms.
 *
 * The Form class is a wrapper of sorts to create HTML forms based on the database
 * schema and process them once submitted.
 *
 * The Form class is a good example of a user defined Module.
 * 
 * Depends on:
 * - View : Generate the HTML code
 * - Sql : Set or update the database
 * - Database, Table : Need to know the schema
 */
class Form {
	/**
	 * @var array The resulting form data array
	 *
	 * $data is an associative array and is used as the input for
	 * View::table_two_column_associative() called in the create_form()
	 * function.
	 *
	 * Format: $data("Left Column" => "Right Column") where
	 * - Left Column = Data field header
	 * - Right Column = Form element HTML code
	 */
	var $data;

	/**
	 * @var array Array of table objects to load in this form
	 */
	var $tables;

	/**
	 * @var array Array of table IDs if an update form
	 */
	var $table_ids;
	
	/**
	 * @var array Associative array of CSS class names
	 *
	 * This array is used by the View::create_form() function to specify the
	 * CSS class names of the different form elements.
	 *
	 * E.g. $css_class['table'] = 'class_name';
	 *
	 * Element classes that can be modified:
	 * - table
	 * - tr
	 * - td
	 * - input
	 * - textarea
	 * - select
	 */
	var $css_class;

	/**
	 * Constructor for the Form class
	 *
	 * Zero initializes members.
	 */
	function Form() {
		$this->data = array();
		$this->tables = array();
		$this->table_ids = array();
		$this->css_class = array();
	}

	/**
	 * Get the form data
	 *
	 * @return string The form data is returned
	 */
	function get_data() {
		return $this->data;
	}

	/**
	 * Add a table to be rendered in this form
	 *
	 * Multiple tables can be added. Function expects the name of the table and optionally the
	 * ID of the row in this table.
	 *
	 * E.g. 
	 * - Adding a single table : add_table('table_name');
	 * - Adding a single table and ID : add_table('table_name', 5);
	 * - Adding multiple tables : add_table(array('table1', 'table2'));
	 * - Adding multiple tables and IDs : add_table(array('table1', 'table2'), array(5, 2));
	 *
	 * @param mixed $table_name Name of the table to add (either a string or array of strings to add multiple tables)
	 * @param mixed $table_id Optional ID of the row in this table to load the form with (either int or array of ints to add multiple table IDs)
	 */
	function add_table($table_name, $table_id=0) {
		// Handle both cases of just a single table or a list of tables provided in a string
		if (is_array($table_name)) {
			// Multiple tables (and IDs to add)
			for ($i = 0; $i < count($table_name); $i++) {
				$table =& $GLOBALS['__application']->database->get_table_by_name($table_name[$i]);
				$this->tables[] =& $table;
				
				if (is_array($table_id)) {
					if (count($table_name) != count($table_id))
						$GLOBALS['__application']->error->display_error(
							'ERROR_FORM_INCOMPATIBLE_NUMBER_FOR_ADD_TABLE', count($table_name), count($table_id));
					$this->table_ids[] = $table_id[$i];
				} else
					$this->table_ids[] = 0;
			}
		} else if (is_string($table_name)) {
			// Just a single table
			$table =& $GLOBALS['__application']->database->get_table_by_name($table_name);
			$this->tables[] =& $table;
			$this->table_ids[] = $table_id;
		}
	}
	
	/**
	 * Update CSS class names for the form to be generated by View::create_form()
	 *
	 * @param string $element Type of HTML element
	 * @param string $class_name Name of the CSS class to set this element to
	 *
	 * See array View::$css_class for the list of HTML elements considered
	 */
	function add_css_class($element, $class_name) {
		$this->css_class[$element] = $class_name;
	}

	/**
	 * Create the form based on the specified tables and table_ids.
	 *
	 * @param string $name Name of the form
	 * @param string $module Name of the module with action to process this form
	 * @param string $action Name of the action in this module that will process this form
	 * @param string $params Parameters required for this module:action combination in "param=value&param=value" format. (default: 0)
	 * @param string $onsubmit Javascript to execute on submit - can be used to check form validity. (default: uses form.validate() in form.js)
	 * @param bool $show_name Set to false to not display the external name of a table. (Default: true)
	 */
	function create_form($name, $module, $action, $params=0, $onsubmit="javascript: return form.validate(this);", $show_name=true) {
		// For each table added to this form
		for ($i = 0; $i < count($this->tables); $i++) {
			// Reference to the table
			$table =& $this->tables[$i];
			
			// Check that this is a data table
			if ($table->get_type() != DATA)
				$GLOBAL['__application']->error->display_error('ERROR_FORM_INVALID_TABLE_IN_FORM', $table->name);
			
			// ID if specified
			if (isset($this->table_ids[$i]))
				$table_id = $this->table_ids[$i];
			else
				$table_id = 0;

			// Add title if requested
			if ($show_name && isset($table->external_name)) {
				// Create a new view
				$title = new View($table->external_name);

				// Underline, italicize and bold table name
				$title->u();
				$title->i();
				$title->b();

				// Create a new blank key for the title
				$blank = $title->get_unique_blank_key($this->data);

				// Add to form data array
				$this->data[$blank] = $title->get_data();
			}

			// Fetch data if table IDs are specified
			$row = null;
			if ($table_id) {
				$row = $table->get_table_row($table_id);
				if (!count($row))
					$GLOBALS['__application']->error->display_error('ERROR_FORM_INVALID_ID', $table_id, $table->name);
			}
			
			// Loop through each column
			foreach ($table->columns as $column) {
				// Skip primary key
				if ($column->name != $table->primary_key) {
					// Create the column label
					$view = new View($column->get_external_name().": ");
					$view->label($column->name);
					$label = $view->get_data();
					
					// Check if required field
					if ($column->get_is_required()) {
						$view->set_data("*");
						$view->set_properties(array("color" => "red"));
						$view->font();
						$required = $view->get_data();
					} else
						$required = "";
						
					// Create the form element based on column type
					$data = array();
					switch ($column->type) {
						case "I":
						case "N":
						case "C":
							// Input box
							$view->reset_data();
							$properties = array(
								"size" => 32,
								"maxlength" => $column->size);
							
							// Set the value if specified
							if (isset($row[$column->external_name]))
								$properties["value"] = $row[$column->external_name];
							
							// Set the CSS class if specified
							if (isset($this->css_class["input"]))
								$properties["class"] = $this->css_class["input"];
								
							// Set required
							if ($required != "") 
								$properties["required"] = "required";
							
							// Create the input box
							$view->set_properties($properties);
							$view->input_text($column->name);
							$this->data[$label] = $view->get_data().' '.$required;
							break;
						case "X":
							// Text Area
							$view->reset_data();
							$properties = array(
								"cols" => 50,
								"rows" => 5);
							
							// Set the value if specified
							if (isset($row[$column->external_name]))
								$view->set_data($row[$column->external_name]);

							// Set the CSS class if specified
							if (isset($this->css_class["textarea"]))
								$properties["class"] = $this->css_class["textarea"];

							// Set required
							if ($required != "") 
								$properties["required"] = "required";
							
							// Create the text area
							$view->set_properties($properties);
							$view->text_area($column->name);
							$this->data[$label] = $view->get_data().' '.$required;
							break;
						case "E":
							// Drop down box
							$options = $column->get_values();
							array_unshift($options, "  ");
							$option_data = array();
							foreach ($options as $option)
								$option_data[$option] = $option;
							
							// Set the value if specified
							if (isset($row[$column->external_name]))
								$selected = $row[$column->external_name];
							else
								$selected = null;
								
							// Create the options
							$view->set_data($option_data);
							$view->options($selected);
							$properties = array();
							
							// Set required
							if ($required != "") 
								$properties["required"] = "required";
							
							// Set the CSS class if specified
							if (isset($this->css_class["select"]))
								$properties["class"] = $this->css_class["select"];

							// Create the select
							$view->set_properties($properties);
							$view->select($column->name);
							$this->data[$label] = $view->get_data().' '.$required;
							break;
					}
				}
			}
			
			// Add an empty line with the table name and the table ID (if applicable) in a hidden element
			$view = new View();
			$blank = $view->get_unique_blank_key($this->data);
			
			// Create hidden element
			$view->input_hidden("tables[".$table->name."]", $table->name);
			if ($table_id) {
				$view->push();
				$view->input_hidden($table->name."_id", $table_id);
				$view->pop_prepend();
			}
			
			// Add to the form array
			$this->data[$blank] = $view->get_data().'&nbsp;';
		}
		
		// Add submit and cancel buttons
		$view = new View();
		
		// Set the CSS class if specified
		if (isset($this->css_class["input"]))
			$view->set_properties(array("class" => $this->css_class["input"]));

		// Create submit button
		$view->input_submit();
		$view->sp();
		$view->push();

		// Set the CSS class if specified
		if (isset($this->css_class["input"]))
			$view->set_properties(array("class" => $this->css_class["input"]));

		// Create cancel button
		$view->input_cancel('cancel');
		$view->pop_prepend();
		
		// Add to table
		$blank = $view->get_unique_blank_key($this->data);
		$this->data[$blank] = $view->get_data();
		
		// Set the CSS class if specified
		$table = $tr = $td = 0;
		if (isset($this->css_class["table"]))
			$table = $this->css_class["table"];
		if (isset($this->css_class["tr"]))
			$table = $this->css_class["tr"];
		if (isset($this->css_class["td"]))
			$table = $this->css_class["td"];
		
		// Create the form table
		$view->set_data($this->data);
		$view->table_two_column_associative($table, $tr, $td);
		
		// Create the form action
		$action = $GLOBALS['__application']->controller->encode_url($module, $action, $params);
		$view->set_properties(array("action" => $action, "method" => "post", "onsubmit" => $onsubmit));
		$view->form($name);
		$this->data = $view->get_data();
	}
	
	/**
	 * Create a form to update a table row and all its linked rows
	 *
	 * @param string $table_name Name of the table
	 * @param int $table_id ID of the row in question
	 * @param string $name Name of the form
	 * @param string $module Name of the module with action to process this form
	 * @param string $action Name of the action in this module that will process this form
	 * @param string $params Parameters required for this module:action combination in "param=value&param=value" format. (default: 0)
	 * @param string $onsubmit Javascript to execute on submit - can be used to check form validity. (default: uses form.validate() in form.js)
	 * @param bool $show_name Set to false to not display the external name of a table. (Default: true)
	 *
	 * @return string The resultant HTML code
	 */
	function update_form($table_name, $table_id, $name, $module, $action, $params=0, $onsubmit="javascript: return form.validate(this);", $show_name=true) {
		// Get the table links
		$table = $GLOBALS['__application']->database->get_table_by_name($table_name);
		$valid_links = $table->get_table_row_links($table_id);
		$valid_link_tables = $valid_links[0];
		$valid_link_table_ids = $valid_links[1];
		
		// Create the form
		$this->add_table($table_name, $table_id);
		for ($i = 0; $i < count($valid_link_tables); $i++) {
			$ids = explode(':', $valid_link_table_ids[$i]);
			foreach ($ids as $id)
				$this->add_table($valid_link_tables[$i]->name, $id);
		}
		$this->create_form($name, $module, $action, $params, $onsubmit, $show_name);	
	}
	 
	/**
	 * Process a submitted form.
	 */
	function process_form() {
		// Array of inserted IDs
		$inserted_ids = array();
	
		// Check that specified tables exist and is a data table, and get the Table object
		$tables = $_POST['tables'];
		foreach ($tables as $table_name) {
			$tables[$table_name] = $GLOBALS['__application']->database->get_table_by_name($table_name);
			
			if ($tables[$table_name]->get_type() != DATA)
				$GLOBALS['__application']->error->display_error('ERROR_FORM_INVALID_TABLE_IN_FORM', $table_name);
		}
		
		// Process each table
		foreach ($tables as $table) {
			// Used to build query
			$insert_fields = '';
			$insert_values = '';
			$update = '';
			
			// Process each column
			foreach ($table->columns as $column) {
				// Skip primary key
				if ($column->name == $table->primary_key) continue;
				
				// Skip DATE fields
				if ($column->type == "T") continue;
				
				// Check that required column has data specified
				if ((!isset($_POST[$column->name]) || $_POST[$column->name] == '') && $column->get_is_required())
					$GLOBALS['__application']->error->display_error('ERROR_FORM_REQUIRED_VARIABLE_MISSING', $column->external_name);
				
				// Append the column details for the insert/update query
				$insert_fields .= $column->name.',';
				$value = addslashes($_POST[$column->name]);
				if ($column->type == "I" || $column->type == "N") {
					$insert_values .= "$value,";
					$update .= $column->name."=$value,";
				} else {
					$insert_values .= "'$value',";
					$update .= $column->name."='$value',";
				}
			}
			
			// Update any DATE_ADDED and DATE_UPDATED fields
			if (!isset($_POST[$table->name."_id"])) {
				// An insert so set DATE_ADDED
				if (array_key_exists(DATE_ADDED, $table->columns)) {
					$insert_fields .= DATE_ADDED.',';
					$insert_values .= "'".date(DATE_FORMAT)."',";
					$update .= DATE_ADDED."='".date(DATE_FORMAT)."',";
				}
			}
			if (array_key_exists(DATE_UPDATED, $table->columns)) {
				$insert_fields .= DATE_UPDATED.',';
				$insert_values .= "'".date(DATE_FORMAT)."',";
				$update .= DATE_UPDATED."='".date(DATE_FORMAT)."',";
			}
			
			// Remove trailing commas
			$insert_fields = rtrim($insert_fields, ',');
			$insert_values = rtrim($insert_values, ',');
			$update = rtrim($update, ',');

			// Do the insert or the update
			if (isset($_POST[$table->name."_id"]))
				$GLOBALS['__application']->database->sql->update_query(
					'SQL_UPDATE', $table->name, $update, $table->primary_key."=".$_POST[$table->name."_id"], "nocheck");
			else {
				$GLOBALS['__application']->database->sql->update_query(
					'SQL_INSERT', $table->name, $insert_fields, $insert_values);
				$inserted_ids[$table->name] = $GLOBALS['__application']->database->sql->get_last_inserted_id();
			}
		}
		
		// Make links
		$completed_tables = array();
		foreach ($tables as $table) {
			// Do this only for inserts, not needed for updates
			if (!isset($_POST[$table->name."_id"])) {
				if (count($table->get_linked_to())) {
					$links =& $table->get_linked_to();
					foreach ($links as $link) {
						// Skip links already made
						if (isset($completed_tables[$link->name]) && $completed_tables[$link->name]) continue;

						// Get table linked to
						$linked_tables =& $link->get_linked_to();
						$link_tname =& $linked_tables[0];
						if ($link_tname->name == $table->name)
							$link_tname =& $linked_tables[1];

						if (array_key_exists($link_tname->name, $tables)) {
							$inserted_fields = $table->name."_id,".$link_tname->name."_id";
							$inserted_values = $inserted_ids[$table->name].','.$inserted_ids[$link_tname->name];
							$GLOBALS['__application']->database->sql->update_query(
								'SQL_INSERT', $link->name, $inserted_fields, $inserted_values);
							$completed_tables[$link->name] = true;
						}
					}
				}
			}
		}
	}
}

/**
 * The View class generates HTML output
 *
 * The class methods operates on the $data member.
 */
class View {
	/**
	 * @var mixed Contains the view data to be processed.
	 *
	 * - Usually a string for methods like u(), a(), etc.
	 * - Needs to be an array for methods like table(), table_two_column_associative(), etc.
	 */
	var $data;
	
	/**
	 * @var array Contains the HTML properties for the tag
	 *
	 * Expected format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 */
	var $properties;
	
	/**
	 * @var bool Auto clear View::$properties after being consumed (default: true)
	 *
	 * Set value using View::auto_reset_properties()
	 */
	var $reset_properties;
	
	/**
	 * @var array Contains saved HTML code.
	 *
	 * Array can be saved to using View::push() and recovered using View::pop(). The push and pop functions 
	 * save View::$data to this array for later retrieval.
	 */
	var $saved;
	
	/**
	 * @var array Array containing multiple tag calls to process.
	 *
	 * - This member is appended to using View::add_template()
	 * - Template is processed using View::compile_template()
	 */
	var $template;

	/**
	 * @var array List of special tags that need a /> at the end instead of a </tag>
	 */
	var $special_tags;

	/**
	 * Constructor for the View class
	 *
	 * Initializes the members based on the input
	 *
	 * @param mixed $data The data to be operated on
	 */
	function View($data='', $properties=array()) {
		$this->data = $data;
		$this->properties = $properties;
		$this->reset_properties = true;
		$this->saved = array();
		$this->template = array();
		$this->special_tags = array("input", "br", "hr", "link", "img");
	}

	/**
	 * Return the value of $data
	 *
	 * @return mixed The value of $data for this View
	 */
	function get_data() {
		return $this->data;
	}

	/**
	 * Set the value of $data to a new value
	 *
	 * @param mixed $data The data to be operated on
	 */
	function set_data($data) {
		$this->data = $data;
	}

	/**
	 * Reset the value of $data to 0.
	 */
	function reset_data() {
		$this->data = 0;
	}

	/**
	 * Set the value of $properties to a new value
	 *
	 * @param array $properties The properties to set
	 */
	function set_properties($properties) {
		$this->properties = $properties;
	}
	
	/**
	 * Clear the View::$properties member
	 */
	function clear_properties() {
		$this->properties = 0;
	}
	
	/**
	 * Set the View::$reset_properties variable
	 *
	 * The View::$properties variable is reset after it is consumed by default. Call this
	 * function with "false" if this is not desired.
	 * 
	 * @param bool $reset Set to true or false as needed
	 */
	function auto_reset_properties($reset=true) {
		$this->reset_properties = $reset;
	}

	/**
	 * Merge specified array with View::$properties array
	 *
	 * @param array $data The array to merge View::$properties into
	 */
	function merge_properties(&$data) {
		// Add any requested properties
		if (is_array($this->properties)) {
			$data = array_merge($data, $this->properties);
			
			//Clear out the properties array if requested by default
			if ($this->reset_properties) $this->clear_properties();
		}
	}
	
	/**
	 * Push the contents of View::$data to View::$saved
	 *
	 * Function resets the value of $data to ''.
	 */
	function push() {
		$this->saved[] = $this->data;
		$this->data = '';
	}
	
	/**
	 * Pop the last pushed data in View::$saved to View::$data
	 */
	function pop() {
		if (!count($this->saved))
			$GLOBALS['__application']->error->display_error('ERROR_VIEW_INVALID_POP');
			
		$this->data = array_pop($this->saved);
	}
	
	/**
	 * Pop the last pushed data in View::$saved and prepend to View::$data
	 *
	 * @param bool $all Set to true to pop_prepend() all the saved values. (default: false)
	 */
	function pop_prepend($all=false) {
		if (!count($this->saved))
			$GLOBALS['__application']->error->display_error('ERROR_VIEW_INVALID_POP');
		
		if ($all)
			while (count($this->saved))
				$this->data = array_pop($this->saved) . $this->data;
		else
			$this->data = array_pop($this->saved) . $this->data;
	}

	/**
	 * Pop the last pushed data in View::$saved and append to View::$data
	 *
	 * @param bool $all Set to true to pop_prepend() all the saved values. (default: false)
	 */
	function pop_append($all=false) {
		if (!count($this->saved))
			$GLOBALS['__application']->error->display_error('ERROR_VIEW_INVALID_POP');
			
		if ($all)
			while (count($this->saved))
				$this->data .= array_pop($this->saved);
		else
			$this->data .= array_pop($this->saved);
	}
	
	/**
	 * Function to add an element to process to the View::$template member
	 *
	 * The syntax is as follows:-
	 * - First parameter is the method to call - e.g. a, font, h2, table, etc. - string
	 * - Second parameter is the value to set in View::$data - array or string or 0
	 * - Third parameter is the value to set in View::$properties - array (see View::$properties format) or 0
	 * - Fourth parameter is what to append to the string once done - usually "nl" for newline or "sp" for a space or 0
	 * - Remaining parameters are the parameters sent to the above method call - mixed
	 */
	function add_element() {
		// Get the arguments
		$num_args = func_num_args();
		$args = func_get_args();
		
		// Check that at least one argument is there (the method name)
		if ($num_args < 1)
			$GLOBALS['__application']->error->display_error('ERROR_VIEW_TEMPLATE_PARAMETERS_INVALID', $num_args);
		
		// Pull out method name
		$function = array_shift($args);
		
		$data = 0;
		// If data specified, pull it out
		if (count($args))
			$data = array_shift($args);
			
		$properties = array();
		// If properties specified, pull out
		if (count($args)) {
			$properties = array_shift($args);
		}
		
		// If delimiter specified, pull out
		$delimiter = 0;
		if (count($args)) {
			$delimiter = array_shift($args);
		}
		
		// Add the command to the template
		$this->template[] = array($function, $data, $properties, $args, $delimiter);
	}
	
	/**
	 * Compile the template using the commands in View::$template
	 *
	 * This function creates each element in the View::$template array and appends all the results together.
	 */
	function compile_template() {
		// How much data is saved
		$count = 0;
		
		// Execute each command in the template
		foreach ($this->template as $command) {
			// Load the data and properties
			$this->data = $command[1];
			$this->properties = $command[2];

			// If a newline or space command, pop what was last saved
			if (($command[0] == "nl" || $command[0] == "sp") && count($this->saved)) {
				$count--;
				$this->pop();
			}

			// Execute the specified method
			call_user_func_array(array(&$this, $command[0]), $command[3]);
			
			// Call the delimiter function
			if (is_string($command[4]))
				call_user_func(array(&$this, $command[4]));

			$this->push();
			$count++;
		}
		
		// Append all contents just generated
		while ($count) {
			$this->pop_prepend();
			$count--;
		}
		
		// Reset template variable
		$this->template = array();
	}

	/**
	 * Add a new line <br/> to View::$data
	 */
	function nl() {
		$this->data .= $this->tag("br");
	}

	/**
	 * Add a space to View::$data
	 */
	function sp() {
		$this->data .= ' ';
	}

	/**
	 * Generate a unique blank string key for the $data array
	 *
	 * The input array is an associative array. This function is useful to generate
	 * blank lines in the data array which will be operated on by the table_two_column_associative()
	 * function.
	 *
	 * @param array $data An associative array for which a unique blank key is needed
	 *
	 * @return string The blank key value
	 */
	function get_unique_blank_key($data) {
		$blank = ' ';
		while (array_key_exists($blank, $data)) $blank .= ' ';
		return $blank;
	}

	/**
	 * Print the $data HTML result
	 */
	function render() {
		$this->header();
		echo $this->data;
	}
	
	/**
	 * Generate the header HTML around the main code
	 *
	 * This function uses View::$data as the default data to display within the body. Expects a string.
	 *
	 * This function uses View::$properties for the properties of the body element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 */
	function header() {
		// Enclose data in body - consume the View::$properties if defined
		$this->body();
		// Save body
		$this->push();
		
		$count = 0;
		// Render all the script files
		foreach ($GLOBALS['__application']->scripts as $script) {
			$this->script($script);
			$this->push();
			$count++;
		}
		
		// Render all the stylesheet files
		foreach ($GLOBALS['__application']->stylesheets as $stylesheet) {
			$this->link($stylesheet);
			$this->push();
			$count++;
		}
		
		if ($count != 0) {
			// Append all script and stylesheet code
			while ($count != 0) {
				$this->pop_append();
				$count--;
			}
			
			// Generate head
			$this->head();
		}
		
		// Append body
		$this->pop_append();
			
		// Generate top html
		$this->html();
	}
	/**
	 * Print an HTML tag with the supplied properties and inner HTML
	 *
	 * This function does not operate on View::$data but its own local copy.
	 *
	 * E.g.
	 * - $data['#'] = string in between tag : <b>string</b>
	 * - $data['property1'] = 'value1';
	 * - $data['property2'] = 'value2';
	 * 
	 * E.g. Properties for table
	 * - class : string
	 * - border : int
	 * - cellpadding : int
	 * - cellspacing : int
	 * - valign : string
	 * 
	 * @param string $name The name of the tag. E.g. table, tr, a, div
	 * @param mixed $data Associative array specifying contents and properties of tag (default: 0)
	 * @param bool $newline Print a new line if true (default: true)
	 */
	function tag($name, $data=0, $newline=true) {
		// Open the tag
		$out = "<$name";

		// Add the property string
		if ($data) {
			foreach ($data as $key => $value) {
				// Skip the data section
				if ($key == "#") continue;

				$out .= " $key=\"$value\"";
			}
		}

		if (in_array($name, $this->special_tags)) {
			// Check if a special tag, if so, end tag
			$out .= "/>";
		} else {
			$out .= ">";

			// Add a newline if requested
			if ($newline) $out .= "\n";

			// Add the data in between start and end tag
			if (isset($data["#"])) $out .= $data["#"];

			// Add a newline if requested
			if ($newline) $out .= "\n";

			// Close the tag
			$out .= "</$name>";
		}

		// Add a newline if requested
		if ($newline) $out .= "\n";

		// Get rid of duplicate newlines
		return preg_replace('/\n\n/', "\n", $out);
	}

	/**
	 * Format the View::$data - called by b(), u(), etc. Can be called directly with the tag name. A wrapper for the tag() function.
	 *
	 * This function uses View::$data as the default data to display within the tag. Expects a string.
	 *
	 * This function uses View::$properties for the properties of the tag element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - class : string
	 * - style : string
	 *
	 * @param string $tag The name of the tag - b, i, u, font, etc.
	 * @param string $newline Print a new line if true (default: true)
	 */
	function generate_tag($tag, $newline=true) {
		// Tag element properties
		$data = array("#" => $this->data);

		// Merge View::$properties
		$this->merge_properties($data);
		
		// Generate the tag
		$this->data = $this->tag($tag, $data, $newline);
	}

	/*
	 * ***************************************************************************
	 * <table> variations
	 * ***************************************************************************
	 */

	/**
	 * Display a table with multiple rows and columns
	 * 
	 * This function operates on View::$data. Expects following format for the table rows/columns:
	 * - $data[0] = array(row1col1, row1col2, row1col3, row1col4);
	 * - $data[1] = array(row2col1, row2col2, row2col3, row2col4);
	 * - $data[2] = ...
	 * 
	 * @param bool $header Treats the first row in the input array as the header row <th> (default: false)
	 * @param array $align Array of align values for each column e.g. array(L, R, L) (default: 0)
	 * @param array $width Array of width values for each column e.g. array("100", "20") (default: 0)
	 * @param string $tableclass CSS table class to use (default: 0)
	 * @param string $trclass CSS tr class to use (default: 0)
	 * @param string $tdclass CSS td class to use (default: 0)
	 */
	function table($header=false, $align=0, $width=0, $tableclass=0, $trclass=0, $tdclass=0) {
		$first = 1;
		$tr = "";
		foreach ($this->data as $row) {
			$td = "";
			$i = 0;
			foreach ($row as $col) {
				$td_data = array("#" => $col);
				if ($tdclass) $td_data['class'] = $tdclass;
				
				if ($width) $td_data['width'] = $width[$i];
				
				if (!$header) {
					if ($align) $td_data['align'] = $align[$i];
					$td .= $this->tag("td", $td_data);
				} else {				
					$td .= $this->tag("th", $td_data);
				}
				$i++;
			}
			if ($header) $header = 0;
			if ($first) $first = 0;
			
			$tr_data = array("#" => $td);
			if ($trclass) $tr_data['class'] = $trclass;
			$tr .= $this->tag("tr", $tr_data);
		}

		$table_data = array("#" => $tr);
		if ($tableclass) $table_data['class'] = $tableclass; 	

		$this->data = $this->tag("table", $table_data);
	}

	/**
	 * Displays table of following format:
	 * 
	 * - | r1col1 | r1col2 |
	 * - | r2col1 | r2col2 |
	 * - | r3col1 | r3col2 |
	 * - | r4col1 | r4col2 |
	 * 
	 * Expects View::$data in the following format:
	 * - $data["r1col1"] = "r1col2";
	 * - $data["r2col1"] = "r2col2";
	 * -  ...
	 *
	 * @param string $tableclass CSS table class to use (default: 0)
	 * @param string $trclass CSS tr class to use (default: 0)
	 * @param string $tdclass CSS td class to use (default: 0)
	 */
	function table_two_column_associative($tableclass=0, $trclass=0, $tdclass=0) {
		$tr = '';
		foreach ($this->data as $key => $value) {
			$view = new View($key);
			$view->b();
			$key = $view->get_data();

			$td_data = array("#" => $key, "align" => "right", "valign" => "top");
			if ($tdclass) $td_data['class'] = $tdclass;
			$td = $this->tag("td", $td_data);

			$td_data = array("#" => $value);
			if ($tdclass) $td_data['class'] = $tdclass;
			$td .= $this->tag("td", $td_data);
			
			$tr_data = array("#" => $td);
			if ($trclass) $tr_data['class'] = $trclass;
			$tr .= $this->tag("tr", $tr_data);
		}
		
		$this->data = $this->tag("table", array("#" => $tr, "cellspacing" => "4"));
	}

	/*
	 * ***************************************************************************
	 * Form components
	 * ***************************************************************************
	 */

	/**
	 * Create a label for an input element
	 *
	 * Expects View::$data to contain the string to display as the label.
	 *
	 * @param string $id ID of the input element
	 */
	function label($id) {
		$this->data = $this->tag("label", array("#" => $this->data, "for" => $id));
	}
	
	/**
	 * Generic input element function - called internally
	 *
	 * This function is used by the other input() functions to add View::$properties to the properties array and generate the tag.
	 *
	 * @param array $input_data Reference to the properties array for the input element
	 */
	function input(&$input_data) {
		// Merge View::$properties
		$this->merge_properties($input_data);
	
		// Generate the tag text
		$this->data = $this->tag("input", $input_data, 0);
	}

	/**
	 * Generate an input text box
	 *
	 * This function uses View::$properties for the properties of the input text box. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 *
	 * E.g. $properties = array('size' = 20, 'class' = 'inpBlue', 'value' = $inp_value);
	 *
	 * Common properties
	 * - size : int
	 * - maxlength : int
	 * - value : string
	 * - class : string
	 * - onfocus : string
	 * - onblur : string
	 *
	 * @param string $name Name of the input text box
	 */
	function input_text($name) {
		// Input text box properties
		$input_data = array("type" => "text", "name" => $name, "id" => $name);
		
		// Add requested properties and generate tag
		$this->input($input_data);
	}

	/**
	 * Generate a submit button
	 *
	 * This function uses View::$properties for the properties of the submit button. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 *
	 * E.g. $properties = array('value' = 'submit');
	 *
	 * Common properties
	 * - value : string
	 * - class : string
	 */
	function input_submit() {
		// Submit button properties
		$input_data = array("type" => "submit", "value" => "submit");
		
		// Add requested properties and generate tag
		$this->input($input_data);
	}

	/**
	 * Generate a cancel button which goes back to the previous page using history.back()
	 *
	 * This function uses View::$properties for the properties of the cancel button. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 *
	 * E.g. $properties = array('value' = 'cancel');
	 *
	 * Common properties
	 * - value : string
	 * - class : string
	 */
	function input_cancel() {
		// Cancel button properties
		$input_data = array("type" => "button", "onclick" => "javascript:history.back();", "value" => "cancel");
		
		// Add requested properties and generate tag
		$this->input($input_data);
	}

	/**
	 * Generate a generic button
	 *
	 * This function uses View::$properties for the properties of the button. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 *
	 * E.g. $properties = array('value' = 'display', 'onclick' = "javascript:open('url');");
	 *
	 * Common properties
	 * - value : string
	 * - class : string
	 * - onclick : string
	 */
	function input_button() {
		// Generic button properties
		$input_data = array("type" => "button");
		
		// Add requested properties and generate tag
		$this->input($input_data);
	}

	/**
	 * Create a hidden input element
	 *
	 * @param string $name Name of the hidden input element
	 * @param string $value Value of the hidden input element
	 */
	function input_hidden($name, $value) {
		$this->data = $this->tag("input", array("type" => "hidden", "name" => $name, "id" => $name, "value" => $value));
	}

	/**
	 * Generate a password input text box
	 *
	 * This function uses View::$properties for the properties of the password input text box. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 *
	 * @param string $name Name of the password input text box
	 */
	function input_password($name) {
		// Password input text box properties
		$input_data = array("type" => "password", "name" => $name, "id" => $name);
		
		// Add requested properties and generate tag
		$this->input($input_data);
	}

	/**
	 * Generate a file input element
	 *
	 * This function uses View::$properties for the properties of the file input element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 *
	 * @param string $name Name of the file input element
	 */
	function input_file($name) {
		// Input file element properties
		$input_data = array("type" => "file", "name" => $name);
		
		// Add requested properties and generate tag
		$this->input($input_data);
	}

	/**
	 * Generate a text area element
	 *
	 * This function uses View::$data as the default data to display within the text area. Expects a string. Ignored if View::$data = int(0)
	 *  
	 * This function uses View::$properties for the properties of the text area element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 *
	 * Common properties:
	 * - cols : int
	 * - rows : int
	 *
	 * @param string $name Name of the text area
	 */
	function text_area($name) {
		// Text area element properties
		$textarea_data = array("name" => $name, "id" => $name);

		// Merge View::$properties
		$this->merge_properties($textarea_data);

		// Add data section if available
		if ($this->data) $textarea_data['#'] = $this->data;

		// Generate the tag text
		$this->data = $this->tag("textarea", $textarea_data);
	}

	/**
	 * Generate a form element
	 *
	 * This function uses View::$data as the default data to display within the form. Expects a string.
	 *  
	 * This function uses View::$properties for the properties of the form element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 *
	 * Common properties:
	 * - action : string
	 * - method : string
	 * - onsubmit : string
	 * - enctype : string
	 * - target : string
	 * 
	 * @param string $name Name of the form
	 */
	function form($name) {
		// Form element properties
		$form_data = array("#" => $this->data, "name" => $name, "id" => $name);

		// Merge View::$properties
		$this->merge_properties($form_data);

		// Generate the tag text
		$this->data = $this->tag("form", $form_data);		
	}

	/**
	 * Generate an options list for <select>
	 * 
	 * This function uses View::$data for the options. Expects following format:
	 * - $data['name1] = 'value1';
	 * - $data['name2'] = 'value2';
	 * - ...
	 *
	 * @param string $selected Value to default select in the drop down (default selects name = "  " if available)
	 */
	function options($selected=null) {
		$out = "";
		
		foreach ($this->data as $key => $value) {
			$option_data = array("#" => $key);
			if (!$selected) {
				if ($key == "  ") $option_data['selected'] = "selected";
				else $option_data['value'] = $value;
			} else {
				if ($selected == $value)
					$option_data['selected'] = "selected";
				$option_data['value'] = $value;
			}
			
			$out .= $this->tag("option", $option_data);
		}
		
		// Save result
		$this->data = $out;
	}

	/**
	 * Generate a select element
	 *
	 * This function uses View::$data as the default data to display within the select. Expects a string.
	 *  
	 * This function uses View::$properties for the properties of the select element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * @param string $name Name of the select
	 */
	function select($name) {
		// Select element properties
		$select_data = array("#" => $this->data, "name" => $name, "id" => $name);
		
		// Merge View::$properties
		$this->merge_properties($select_data);

		// Generate the tag text
		$this->data = $this->tag("select", $select_data);
	}

	/*
	 * ***************************************************************************
	 * Head components
	 * ***************************************************************************
	 */
	
	/**
	 * Generate a link to stylesheet
	 *
	 * @param string $file Name of the css file including web path
	 * @param string $rel Default: "stylesheet"
	 * @param string $type Mime type of file (default: text/css)
	 */
	function link($file, $rel="stylesheet", $type="text/css") { 
		$this->data = $this->tag("link", array("rel" => $rel, "href" => $file, "type" => $type));
	}
	
	/**
	 * Generate a script file header
	 *
	 * @param string $file Name of the script file including web path
	 * @param string $type Mime type of file (default: text/javascript)
	 * @param string $language Language of the script file (default: javascript)
	 */
	function script($file, $type="text/javascript", $language="javascript") {
		$this->data = $this->tag("script", array("type" => $type, "language" => $language, "src" => $file));
	}

	/*
	 * ***************************************************************************
	 * Misc layout
	 * ***************************************************************************
	 */
	 
	/**
	 * Generate a html tag
	 *
	 * This function uses View::$data as the default data to display within the div. Expects a string.
	 *  
	 * This function uses View::$properties for the properties of the div element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 */
	function html() {
		$this->generate_tag("html");
	}
	
	/**
	 * Generate a body tag
	 *
	 * This function uses View::$data as the default data to display within the div. Expects a string.
	 *  
	 * This function uses View::$properties for the properties of the div element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 *
	 * Common properties:
	 * - onload : string
	 * - background : string
	 */
	function body() {
		$this->generate_tag("body");
	}
	
	/**
	 * Generate a head tag
	 *
	 * This function uses View::$data as the default data to display within the div. Expects a string.
	 *  
	 * This function uses View::$properties for the properties of the div element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 */
	function head() {
		$this->generate_tag("head");
	}
	
	/**
	 * Generate a div
	 *
	 * This function uses View::$data as the default data to display within the div. Expects a string.
	 *  
	 * This function uses View::$properties for the properties of the div element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - style : string
	 *
	 * @param string $name Name of the div
	 */
	function div($name) {
		// Div element properties
		$div_data = array("#" => $this->data, "id" => $name);
		
		// Merge View::$properties
		$this->merge_properties($div_data);

		// Generate the tag
		$this->data = $this->tag("div", $div_data);
	}
	
	/**
	 * Generate a URL link
	 *
	 * This function uses View::$data as the default data to display within the URL link. Expects a string.
	 *  
	 * This function uses View::$properties for the properties of the URL link element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - target : string
	 * - onclick : string
	 * - class : string
	 * - style : string
	 *
	 * @param string $link Link to point to
	 */
	function a($link) {
		// A element properties
		$a_data = array("#" => $this->data, "href" => $link);
		
		// Merge View::$properties
		$this->merge_properties($a_data);

		// Generate the tag
		$this->data = $this->tag("a", $a_data, 0);
	}
	
	/**
	 * Generate an image tag
	 *
	 * This function uses View::$properties for the properties of the image. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - border : string
	 * - onclick : string
	 * - class : string
	 * - style : string
	 *
	 * @param string $src Link to the image
	 */
	function img($src) {
		// Img element properties
		$img_data = array("src" => $src);
		
		// Merge View::$properties
		$this->merge_properties($img_data);
		
		// Generate the tag
		$this->data = $this->tag("img", $img_data, 0);
	}
	
	/**
	 * Center the View::$data
	 */
	function center() {
		$this->data = $this->tag("center", array("#" => $this->data));
	}
	
	/**
	 * Enclosure View::$data in a paragraph
	 */
	function p() {
		$this->data = $this->tag("p", array("#" => $this->data));
	}

	/**
	 * Add a line break
	 */
	function br() {
		$this->data = $this->tag("br");
	}

	/**
	 * Add a horizontal line
	 */
	function hr() {
		$this->data = $this->tag("hr");
	}

	/*
	 * ***************************************************************************
	 * Formatting
	 * ***************************************************************************
	 */
	
	/**
	 * Bold the View::$data
	 *
	 * This function uses View::$properties for the properties of the b element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - class : int
	 * - style : string
	 */
	function b() {
		$this->generate_tag("b", false);
	}
	
	/**
	 * Italicize the View::$data
	 *
	 * This function uses View::$properties for the properties of the i element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - size : int
	 * - color : string
	 */
	function i() {
		$this->generate_tag("i", false);
	}
	
	/**
	 * Underline the View::$data
	 *
	 * This function uses View::$properties for the properties of the u element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - size : int
	 * - color : string
	 */
	function u() {
		$this->generate_tag("u", false);
	}
	
	/**
	 * Enclosure View::$data in a font tag
	 *
	 * This function uses View::$properties for the properties of the font element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - size : int
	 * - color : string
	 */
	function font() {
		$this->generate_tag("font", false);
	}

	/**
	 * Enclose View::$data in a header 1
	 *
	 * This function uses View::$properties for the properties of the h1 element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - color : string
	 */
	function h1() {
		$this->generate_tag("h1", false);
	}

	/**
	 * Enclose View::$data in a header 2
	 *
	 * This function uses View::$properties for the properties of the h2 element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - color : string
	 */
	function h2() {
		$this->generate_tag("h2", false);
	}
	
	/**
	 * Enclose View::$data in a header 3
	 *
	 * This function uses View::$properties for the properties of the h3 element. Expects following format:
	 * - $properties['property_name1'] = 'property_value1';
	 * - $properties['property_name2'] = 'property_value2';
	 * 
	 * Common properties:
	 * - color : string
	 */
	function h3() {
		$this->generate_tag("h3", false);
	}
}

/**
 * The Column class contains information on a table column
 *
 * Primarily used by the Table class.
 */
class Column {
	/**
	 * @var string Name of the column
	 */
	var $name;

	/**
	 * @var string External name of the column
	 *
	 * Uses ucwords() and replaces _ with spaces
	 */
	var $external_name;

	/**
	 * @var string Type of variable
	 */
	var $type;

	/**
	 * @var array Values in case type is enumeration
	 *
	 * Default: array()
	 */
	var $values;

	/**
	 * @var int Size of column (for varchar, int, etc.)
	 *
	 * Default: 0
	 */
	var $size;

	/**
	 * @var bool Set to true if column is a primary key
	 *
	 * Default: false
	 */
	var $is_key;

	/**
	 * @var bool Set to true if the column is a required field (not null)
	 *
	 * Default: false
	 */
	var $is_required;

	/**
	 * @var bool Set to true if column is autoincrementing (primary key)
	 *
	 * Default: false
	 */
	var $is_autoincrement;

	/**
	 * Constructor for the Column class
	 *
	 * @param string $name The name of the column
	 */
	function Column($name) {
		// Set the name
		$this->name = $name;

		// Convert to external name
		$this->external_name = ucwords(str_replace('_', ' ', $name));

		// Default initialize remaining fields
		$this->type = '';
		$this->values = array();
		$this->size = 0;
		$this->is_key = false;
		$this->is_required = false;
		$this->is_autoincrement = false;
	}

	/**
	 * Get the column name
	 *
	 * @return string The name string
	 */
	function get_name() {
		return $this->name;
	}

	/**
	 * Get the external column name
	 *
	 * @return string The external name string
	 */
	function get_external_name() {
		return $this->external_name;
	}

	/**
	 * Get the column type
	 *
	 * @return string The type
	 */
	function get_type() {
		return $this->type;
	}

	/**
	 * Set the column type
	 *
	 * @param string $type The type
	 */
	function set_type($type) {
		$this->type = $type;
	}

	/**
	 * Get the column values (for enumeration)
	 *
	 * @return array Array of values
	 */
	function get_values() {
		return $this->values;
	}

	/**
	 * Set the column values (for enumeration)
	 *
	 * @param array $values Array of values
	 */
	function set_values($values) {
		$this->values = $values;
	}

	/**
	 * Get the column size
	 *
	 * @return int Size of column
	 */
	function get_size() {
		return $this->size;
	}

	/**
	 * Set the column size
	 *
	 * @param int $size Size to set
	 */
	function set_size($size) {
		$this->size = $size;
	}

	/**
	 * Get is_key
	 *
	 * @return bool True if is a key, else false
	 */
	function get_is_key() {
		return $this->is_key;
	}

	/**
	 * Set is_key
	 *
	 * @param bool $is_key True or false
	 */
	function set_is_key($is_key) {
		$this->is_key = $is_key;
	}

	/**
	 * Get is_required
	 *
	 * @return bool True if is a required field, else false
	 */
	function get_is_required() {
		return $this->is_required;
	}

	/**
	 * Set is_required
	 *
	 * @param bool $is_required True or false
	 */
	function set_is_required($is_required) {
		$this->is_required = $is_required;
	}

	/**
	 * Get is_autoincrement
	 *
	 * @return bool True if is autoinremented, else false
	 */
	function get_is_autoincrement() {
		return $this->is_autoincrement;
	}

	/**
	 * Set is_autoincrement
	 *
	 * @param bool $is_autoincrement True or false
	 */
	function set_is_autoincrement($is_autoincrement) {
		$this->is_autoincrement = $is_autoincrement;
	}
}

/**
 * The Table class contains information about a table
 *
 * Mainly used by the Database class.
 */
class Table {
	/**
	 * @var string Name of the table
	 */
	var $name;

	/**
	 * @var string External name of the table
	 *
	 * Uses ucwords() and replaces _ with spaces
	 */
	var $external_name;

	/**
	 * @var array Associative array of Column objects
	 *
	 * $columns['column_name'] = Column object
	 */
	var $columns;

	/**
	 * @var string Type of table - either 'data' or 'link'.
	 *
	 * - Data tables contain multiple columns and save data. They contain
	 *   a primary key named tablename_id.
	 * - Link tables link two tables. They are named table1_table2 and contain
	 *   two columns, table1_id and table2_id.
	 */
	var $type;

	/**
	 * @var array Associative array of references to tables.
	 *
	 * $links['tablename'] = Table object reference
	 *
	 * - If data table, this array contains a list of link tables that link this
	 *   table to another table.
	 * - If a link table, it contains a pair of data tables that the table links.
	 */
	var $links;

	/**
	 * @var string Name of the primary key field. Default is {table_name}_id.
	 *
	 * This value can be modified using the table_id section in CONFIG_FILE.
	 */
	var $primary_key;
	
	/**
	 * Constructor for the Table class
	 *
	 * @param string $name The name of the column
	 */
	function Table($name, $external_name='', $primary_key='') {
		// Set the name
		$this->name = $name;

		// Set external name
		if ($external_name == '')
			$this->external_name = ucwords(str_replace('_', ' ', $name));
		else
			$this->external_name = $external_name;

		// Set primary key
		if ($primary_key == '')
			$this->primary_key = "${name}_id";
		else
			$this->primary_key = $primary_key;

		// Default initialize remaining fields
		$this->columns = array();
		$this->type = '';
		$this->links = array();
		
		// Get the columns for the table
		$columns = $GLOBALS['__application']->database->sql->dictionary->MetaColumns($this->name);
		
		// Create table objects for these tables
		foreach ($columns as $column) {
			// Create Column object
			$this->columns[$column->name] = new Column($column->name);
			
			// Set the type
			$this->columns[$column->name]->set_type($GLOBALS['__application']->database->sql->dictionary->MetaType($column->type));
			
			// Set the size
			$this->columns[$column->name]->set_size($column->max_length);

			// Set if primary key
			$this->columns[$column->name]->set_is_key($column->primary_key);

			// Set if required
			$this->columns[$column->name]->set_is_required($column->not_null);

			// Set if auto increment
			$this->columns[$column->name]->set_is_autoincrement($column->auto_increment);

			// If an enum, set values
			if ($column->type == "enum" || $column->type == "set") {
				// Cleanup the quotes in the names
				for ($i = 0; $i < count($column->enums); $i++)
					$column->enums[$i] = trim($column->enums[$i], "'");
				$this->columns[$column->name]->set_values($column->enums);
				$this->columns[$column->name]->set_type("E");
			}
		}
	}

	/**
	 * Get a column object by name
	 *
	 * @param string $name Name of the column
	 */
	function &get_column_by_name($name) {
		if (array_key_exists($name, $this->columns))
			return $this->columns[$name];
		else
			$GLOBALS['__application']->error->display_error('ERROR_TABLE_INVALID_COLUMN_NAME', $name, $this->name);
	}

	/**
	 * Get the table name
	 *
	 * @return string The name string
	 */
	function get_name() {
		return $this->name;
	}

	/**
	 * Get the external table name
	 *
	 * @return string The external name string
	 */
	function get_external_name() {
		return $this->external_name;
	}

	/**
	 * Get the table type
	 *
	 * @return string The type
	 */
	function get_type() {
		return $this->type;
	}

	/**
	 * Set the table type
	 *
	 * @param string $type The type
	 */
	function set_type($type) {
		$this->type = $type;
	}

	/**
	 * Get the $links array
	 *
	 * @return array Returns the $links array
	 */
	function &get_linked_to() {
		return $this->links;
	}
	
	/**
	 * Get all the rows in this table
	 *
	 * @param string $options Specify options to filter or order the results. (default: "")
	 *
	 * @return array Returns an array of rows where each row is an associative array with column names as the key.
	 */
	function get_table_rows($options='') {
		// Create the table header
		$header = array();
		foreach ($this->columns as $column)
			$header[] = $column->get_external_name();

		// Perform the query
		$rows = $GLOBALS['__application']->database->sql->select_query('SQL_SELECT', $this->name, $options);
		if (count($rows)) {
			// Add the header to the result
			array_unshift($rows, $header);
			return $rows;
		} else {
			// No rows so return just the header
			return array($header);
		}
	}
	
	/**
	 * Get a specific row from this table
	 *
	 * @param int $table_id Primary key value for the row
	 *
	 * @return array Returns an associative array with column names as key or empty array if no such result.
	 */
	function get_table_row($table_id) {
		// Get the row
		$row = $this->get_table_rows("where ".$this->primary_key."=$table_id");
		
		// If insufficient rows returned, such a row does not exist, return empty array
		if (count($row) != 2)
			return array();

		// Remove the ID
		array_shift($row[0]);
		array_shift($row[1]);

		// Create an associative array from the result
		return array_combine($row[0], array_values($row[1]));
	}
	
	/**
	 * Get a specific row from this table - formatted for View::table_two_column_associative()
	 *
	 * This function appends the results of the query to $data. This is useful to generate $data using
	 * data from multiple tables and showing the result using View::table_two_column_associative().
	 *
	 * @param int $table_id Primary key value for the row
	 * @param array $data Return the data in this associative array
	 * @param bool $show_name Show the name of the table (default: true)
	 */
	function get_table_row_for_view($table_id, &$data, $show_name=true) {
		// Get the results
		$row = $this->get_table_row($table_id);
		
		// Add results if applicable
		if (count($row)) {
			// Add name if requested
			if ($show_name) {
				$view = new View($this->external_name);
				$view->u();
				$view->i();
				$view->b();
				
				$blank = $view->get_unique_blank_key($data);
				$data[$blank] = $view->get_data();
			}
			
			// Append results to the array
			$data = array_merge($data, $row);
			
			// Add a blank line at the end
			$blank = View::get_unique_blank_key($data);
			$data[$blank] = '&nbsp;';
		}
	}
	
	/**
	 * Get links to a table row.
	 *
	 * This function checks the table's $links array and searches all its link tables for
	 * the specified ID. If it exists, it means that this row has a link with the other table.
	 *
	 * @param int $table_id Primary key value for the row
	 *
	 * @return array Returns an array of tables linked to and an array of IDs in that table.
	 *
	 * Result = array(
	 *    array(Table1 Object, Table2 Object, ...),
	 *    array(Table1 ID1:Table1 ID2:Table1:ID3, Table2 ID, ...));
	 */
	function get_table_row_links($table_id) {
		$valid_linked_tables = array();
		$valid_linked_table_ids = array();
		
		// Check for each link table
		foreach ($this->links as $link_table) {
			// Search for specified ID for this table in the link table
			$rows = $GLOBALS['__application']->database->sql->select_query(
				'SQL_SELECT', $link_table->name, "where ".$this->primary_key."=$table_id");

			// If any valid results, add to returned array
			if (count($rows)) {
				// Get the object of the table linked to
				$linked_table =& $link_table->links[0];
				if ($linked_table->name == $this->name)
					$linked_table =& $link_table->links[1];
				
				// Add table object to list of valid table links
				$valid_linked_tables[] =& $linked_table;

				// Add the table IDs
				$valid_ids = '';
				foreach ($rows as $row)
					$valid_ids .= $row[$linked_table->primary_key].':';
				$valid_ids = rtrim($valid_ids, ':');
				$valid_linked_table_ids[] = $valid_ids;
			}
		}

		// Return array of arrays
		return array($valid_linked_tables, $valid_linked_table_ids);
	}
	
	/**
	 * Get all linked rows for a specific row from this table - formatted for View::table_two_column_associative()
	 *
	 * This function appends the results of the query to $data. This is useful to generate $data using
	 * data from multiple tables and showing the result using View::table_two_column_associative().
	 *
	 * @param int $table_id Primary key value for the row
	 * @param array $data Return the data in this associative array
	 * @param bool $show_name Show the name of the table (default: true)
	 */
	function get_table_row_link_rows_for_view($table_id, &$data, $show_name=true) {
		// Get the rows linked to
		$link_info = $this->get_table_row_links($table_id);
		
		// If no linked tables, return data as it is
		if (count($link_info) != 2) 
			return $data;
		
		// All the table objects
		$valid_linked_tables = $link_info[0];
		
		// All the table IDs
		$valid_linked_table_ids = $link_info[1];
		
		for ($i = 0; $i < count($valid_linked_tables); $i++) {
			// Get the table object and IDs
			$linked_table = $valid_linked_tables[$i];
			$ids = explode(':', $valid_linked_table_ids[$i]);
			
			// Loop for each ID
			foreach ($ids as $id) {
				$linked_table->get_table_row_for_view($id, $data, $show_name);
			}
		}
	}
	
	/**
	 * Get specific row and all linked rows from this table - formatted for View::table_two_column_associative()
	 *
	 * This function appends the results of the query to $data. This is useful to generate $data using
	 * data from multiple tables and showing the result using View::table_two_column_associative().
	 *
	 * @param int $table_id Primary key value for the row
	 * @param array $data Return the data in this associative array
	 * @param bool $show_name Show the name of the table (default: true)
	 */
	function get_table_row_and_link_rows_for_view($table_id, &$data, $show_name=true) {
		$this->get_table_row_for_view($table_id, $data, $show_name);
		$this->get_table_row_link_rows_for_view($table_id, $data, $show_name);
	}
	
	/**
	 * Delete a specific row from this table - for data tables
	 *
	 * @param int $table_id Primary key value for the row
	 */
	function delete_table_row($table_id) {
		$GLOBALS['__application']->database->sql->update_query(
			'SQL_DELETE', $this->name, $this->primary_key."=$table_id");
	}
	
	/**
	 * Delete a specific row from this table - for link tables
	 *
	 * @param string $column Name of the column - should be name of a data table
	 * @param int $value Value of the column - key of this data table
	 */
	function delete_link_table_row($column, $value) {
		$GLOBALS['__application']->database->sql->update_query(
			'SQL_DELETE', $this->name, "${column}_id=$value", "nocheck");
	}
	
	/**
	 * Delete all links for a table - for data tables
	 *
	 * @param int $table_id Primary key value for the row
	 */
	function delete_table_row_link_rows($table_id) {
		// Get the rows linked to
		$link_info = $this->get_table_row_links($table_id);
		
		// If no linked tables, return
		if (count($link_info) != 2) 
			return;
		
		// All the table objects
		$valid_linked_tables = $link_info[0];
		
		// All the table IDs
		$valid_linked_table_ids = $link_info[1];
		
		// Delete all the linked data tables' contents
		for ($i = 0; $i < count($valid_linked_tables); $i++) {
			// Get the table object and IDs
			$linked_table = $valid_linked_tables[$i];
			$ids = explode(':', $valid_linked_table_ids[$i]);
			
			// Loop for each ID
			foreach ($ids as $id) {
				$linked_table->delete_table_row($id);
			}
		}
		
		// Delete the entries in the linking tables
		foreach ($this->links as $link_table) {
			$link_table->delete_link_table_row($this->name, $table_id);
		}
	}
	
	/**
	 * Delete a specific row and all linked rows for this table
	 *
	 * @param int $table_id Primary key value for the row
	 */
	function delete_table_row_and_link_rows($table_id) {
		$this->delete_table_row($table_id);
		$this->delete_table_row_link_rows($table_id);
	}
}

/**
 * The Database class contains information about the specified database
 *
 * Uses the Table class.
 */
class Database {
	/**
	 * @var string Name of the database
	 */
	var $name;

	/**
	 * @var object Sql The Sql object
	 */
	var $sql;

	/**
	 * @var array Associative array of Table objects
	 *
	 * $tables['tablename'] = Table object
	 */
	var $tables;
	
	/**
	 * Constructor for the Database class
	 *
	 * @param string $adodb Path to the ADOdb or ADOdb Lite library
	 * @param string $type Type of database being used
	 * @param string $host Hostname of server where database is running
	 * @param string $user User name to connect with
	 * @param string $pass Password to connect with
	 * @param string $name Name of the database to select
	 * @param string $sql_file Path to the SQL ini file
	 */
	function Database($adodb, $type, $host, $user, $pass, $name, $sql_file) {
		// Set database name
		$this->name = $name;
		
		// Create an Sql object to make queries
		$this->sql = new Sql($adodb, $type, $host, $user, $pass, $name, $sql_file);
	}

	/**
	 * Get the schema for this database
	 *
	 * @param array $table_names Associative array of table names and requested external name
	 */
	function get_schema($table_names, $primary_keys) {
		// Get a list of tables
		$tables = $this->sql->dictionary->MetaTables('TABLES');
		
		// Create table objects for these tables
		foreach ($tables as $table) {
			// Check if external name is specified
			if (is_array($table_names) && isset($table_names[$table]) && $table_names[$table] != '')
				$table_external_name = $table_names[$table];
			else
				$table_external_name = '';

			// Check if primary key is specified
			if (is_array($primary_keys) && isset($primary_keys[$table]) && $primary_keys[$table] != '')
				$table_primary_key = $primary_keys[$table];
			else
				$table_primary_key = '';
			
			// Create table object
			$this->tables[$table] = new Table($table, $table_external_name, $table_primary_key);
		}
		
		// Check the schema
		$this->check_schema();
	}
	
	/**
	 * Check the schema of the database and make relevant associations.
	 *
	 * This function does the following:
	 * - Set the type of table : data or link
	 * - If DATE_ADDED and/or DATE_UPDATED columns exist, verify that they are of 
	 *   type datetime since Phpfw internally maintains them
	 * - If table type is a link table, update the $links array for the link table
	 *   and both the linked tables.
	 */
	function check_schema() {
		$tables = array_keys($this->tables);
		foreach ($tables as $table) {
			// Check if table primary key exists
			$primary_key = $this->tables[$table]->primary_key;
			if (	(array_key_exists($primary_key, $this->tables[$table]->columns)) &&
				($this->tables[$table]->columns[$primary_key]->get_is_key() == true)) {
				// Key exists so data table
				$this->tables[$table]->type = DATA;

				// Check the DATE_ADDED field
				if (	(array_key_exists(DATE_ADDED, $this->tables[$table]->columns)) &&
					(!in_array($this->tables[$table]->columns[DATE_ADDED]->get_type(), array("T", "DT"))))
					$GLOBALS['__application']->error->display_error('ERROR_DATABASE_DATE_FIELD_ERROR', 
						DATE_ADDED, $this->tables[$table]->name);

				// Check the DATE_UPDATED field
				if (	(array_key_exists(DATE_UPDATED, $this->tables[$table]->columns)) &&
					(!in_array($this->tables[$table]->columns[DATE_UPDATED]->get_type(), array("T", "DT"))))
					$GLOBALS['__application']->error->display_error('ERROR_DATABASE_DATE_FIELD_ERROR', 
						DATE_UPDATED, $this->tables[$table]->name);
			} else {
				// Break the table into its component names
				$tnames = explode('_', $table);

				// Check that valid link table
				if (in_array($tnames[0], $tables) && in_array($tnames[1], $tables) &&
					$this->tables[$tnames[0]]->type == DATA &&
					$this->tables[$tnames[1]]->type == DATA &&
					array_key_exists("${tnames[0]}_id", $this->tables[$table]->columns) &&
					array_key_exists("${tnames[1]}_id", $this->tables[$table]->columns)) {
					// Link table component names are valid tables
					$this->tables[$table]->type = LINK;

					// Reset primary key
					$this->tables[$table]->primary_key = '';

					// Add component tables to link table $links
					$this->tables[$table]->links[] =& $this->tables[$tnames[0]];
					$this->tables[$table]->links[] =& $this->tables[$tnames[1]];

					// Add this link table to both the component table $links
					$this->tables[$tnames[0]]->links[] =& $this->tables[$table];
					$this->tables[$tnames[1]]->links[] =& $this->tables[$table];
				} else
					$GLOBALS['__application']->error->display_error('ERROR_DATABASE_INVALID_LINK_TABLE', $table);
			}
		}
	}
	
	/**
	 * Returns a Table object by its name
	 *
	 * @param string $name The name to look up
	 */
	function &get_table_by_name($name) {
		if (array_key_exists($name, $this->tables))
			return $this->tables[$name];
		else
			$GLOBALS['__application']->error->display_error('ERROR_DATABASE_INVALID_TABLE_NAME', $name);
	}
}

/**
 * The Sql class is a wrapper for the ADOdb or ADOdb Lite library.
 *
 * This class loads all SQL queries from SQL_FILE (defined at the top). Several
 * methods are defined that simplify making queries.
 */
class Sql {
	/**
	 * @var array An associative array of queries.
	 *
	 * $queries['QUERY_NAME'] = "SQL query syntax".
	 *
	 * Queries are loaded from SQL_FILE. All instances of '%s' in the query string
	 * are replaced at run time with values specified in the _query() methods below.
	 */
	var $queries;
	
	/**
	 * @var object ADOConnection Connection to the server
	 */
	var $connection;
	
	/**
	 * @var object ADODB2_drivername Data dictionary for this table
	 */
	var $dictionary;

	/**
	 * Constructor for the Sql class
	 *
	 * - Loads queries from file
	 * - Load the ADOdb/ADOdb Lite library
	 *
	 * @param string $adodb Path to the ADOdb or ADOdb Lite library
	 * @param string $type Type of database being used
	 * @param string $host Hostname of server where database is running
	 * @param string $user User name to connect with
	 * @param string $pass Password to connect with
	 * @param string $name Name of the database to select
	 * @param string $sql_file Path to the SQL ini file
	 */
	function Sql($adodb, $type, $host, $user, $pass, $name, $sql_file) {
		// Load the ADOdb or ADOdb Lite library
		require_once("$adodb/adodb.inc.php");
		
		// Load queries from file
		if (!file_exists($sql_file))
			$GLOBALS['__application']->error->display_error('ERROR_SQL_MISSING_INI_FILE', $sql_file);
		$this->queries = parse_ini_file($sql_file);
		
		// Make a connection to the database
		$this->connection = &ADONewConnection($type);
		if ($this->connection->Connect($host, $user, $pass, $name) !== true)
			$GLOBALS['__application']->error->display_error('ERROR_SQL_CONNECTION_FAILED');
			
		// Create a data dictionary
		$this->dictionary = NewDataDictionary($this->connection);
	}
	
	/**
	 * Execute a query on this Sql connection.
	 *
	 * Function creates query based on specified query name and parameters. It then
	 * executes the query and returns the result. This function is called by the other _query()
	 * functions below.
	 *
	 * @param string $name Name of the query
	 * @param string $args Arguments for the query
	 *
	 * @return object ADORecordSet Returns an ADOdb result object
	 */
	function &execute_query($name, $args) {
		$num_args = count($args);
		
		// Check if valid query name
		if (!array_key_exists($name, $this->queries))
			$GLOBALS['__application']->error->display_error('ERROR_SQL_NO_SUCH_QUERY', $name);
		
		// Pull up the query
		$query = $this->queries[$name];
		
		// Ensure number of %s in the query equals the number of arguments specified
		$num_percent_s = preg_match_all("/%s/", $query, $matches);
		if ($num_percent_s != $num_args)
			$GLOBALS['__application']->error->display_error('ERROR_SQL_INSUFFICIENT_ARGUMENTS', $name);
		
		// Build the query with the arguments
		for ($i = 0; $i < $num_args; $i++) {
			$query = preg_replace("/%s/", $args[$i], $query, 1);
		}
		
		// Run the query and return results to caller
		$result = $this->connection->Execute($query);
		
		// Check if there was an error
		if ($result === false)
			$GLOBALS['__application']->error->display_error('ERROR_SQL_SQL_SYNTAX_ERROR', 
				$name, $query, $this->connection->ErrorMsg());
		
		// Return result object
		return $result;
	}
	
	/**
	 * Check if an insert, update or delete succeeded
	 *
	 * @param string $name Name of the query executed
	 */
	function test_update($name) {
		if ($this->connection->Affected_Rows() == 0)
			$GLOBALS['__application']->error->display_error('ERROR_SQL_NO_ROWS_AFFECTED', $name);
	}
	
	/**
	 * Perform a select query
	 *
	 * Function expects the first argument to be the name of the query to execute. Query
	 * is loaded from Sql::$queries[$query].
	 *
	 * @return array Returns an array of rows returned for the query or null if no rows.
	 */
	function select_query() {
		// Dynamically get the arguments to function
		$num_args = func_num_args();
		$args = func_get_args();
		
		// Check if no arguments
		if ($num_args == 0)
			$GLOBALS['__application']->error->display_error('ERROR_SQL_ARGUMENTS_MISSING');
		
		// Pull out the name of the query
		$name = array_shift($args);
		
		// Execute the query
		$result =& $this->execute_query($name, $args);
		
		// If no rows returned, return NULL
		if ($result->RecordCount() == 0)
			return null;
		
		// Else return an array of rows
		$values = array();
		while (($row = $result->FetchRow()) !== false) {
			$frow = array();
			foreach ($row as $key => $val) {
				if (!is_int($key))
					$frow[$key] = $val;
			}
			array_push($values, $frow);
		}

		// Cleanup the result object
		$result->Close();
		
		// Return the array of rows
		return $values;
	}
	
	/**
	 * Perform an insert, update or delete query
	 *
	 * Function expects the first argument to be the name of the query to execute. Query
	 * is loaded from Sql::$queries[$query].
	 *
	 * Set the last argument to "nocheck" if not required to check if the update worked.
	 */
	function update_query() {
		// Perform check by default
		$check = true;
		
		// Dynamically get the arguments to function
		$num_args = func_num_args();
		$args = func_get_args();
		
		// Check if no arguments
		if ($num_args == 0)
			$GLOBALS['__application']->error->display_error('ERROR_SQL_ARGUMENTS_MISSING');

		// Verify if no need to check
		if ($args[$num_args-1] == "nocheck") {
			$check = false;
			array_pop($args);
		}
		
		// Pull out the name of the query
		$name = array_shift($args);
		
		// Execute the query
		$result =& $this->execute_query($name, $args);

		// Check if insert succeeded
		if ($check) $this->test_update($name);

		// Cleanup the result object
		$result->Close();
	}
	
	/**
	 * Get the ID of the last inserted value
	 * 
	 * @return int ID of the last value inserted
	 */
	function get_last_inserted_id() {
		return $this->connection->Insert_ID();
	}
}

/**
 * The Module class - all modules are to extend this class
 *
 * All modules are to extend this class so that they can have access to all
 * the objects of the application.
 *
 * See Module::set_references() to see how these objects can be accessed
 * without needing to extending this class.
 *
 * If a module does not extend this class, it does not have easy access to
 * these objects. Hence, it is recommended to extend this class though it
 * is not enforced.
 *
 * Module output is also automatically rendered by the controller. This simplifies
 * output management. See Module::$output and Module::render().
 *
 * Any exception actions need to be registered in the Module's constructor. See
 * Module::$exceptions.
 *
 */
class Module {
	/**
	 * @var string The HTML output for this module
	 *
	 * The usage of this member depends on whether a template framework is
	 * in use in this application.
	 * - If no templating framework is in use, Module::$output is treated
	 *  as a string. Each action in the module updates this string variable 
	 *  with the HTML to be outputted. Once the called action is done, the 
	 *  controller renders this HTML using the View class.
	 * - If a templating framework is used, Module::$output is treated as
	 *  an associative array where each variable used in the template can be
	 *  assigned as $output['variable_name'] = 'value'. Once the action is
	 *  completed, the controller renders the HTML using the templating 
	 *  framework.
	 */
	var $output;
	
	/**
	 * @var array List of exception actions that can be directly called
	 *
	 * Format: array('action1', 'action2', 'action3'); where actions are class methods.
	 *
	 * This member needs to be populated in the Module's constructor. E.g.
	 * - $this->exceptions = array('action1', 'action2', ...);
	 *
	 * See Controller::$exceptions for how the exceptions are used.
	 */
	var $exceptions;

	/**
	 * @var object Application A reference to the Application object
	 *
	 * This may not be used very often.
	 *
	 * Sample usage:
	 * - Add custom configuration variables to the config ini
	 * - Use the values with $this->application->config
	 */
	var $application;

	/**
	 * @var object Error A reference to the Error object
	 *
	 * Sample usage:
	 * - Add an error string to the ini file
	 * - Display it in the module using: $this->error->display_error('ERROR_NAME', $param1, $param2, ...); 
	 *  where the string parameters replace %s in the error string. 
	 *
	 * See the default error ini file for examples.
	 */
	var $error;

	/**
	 * @var object Database A reference to the Database object
	 *
	 * This object gives access to the entire database. Data from tables can
	 * be obtained or deleted using the methods in the Table class.
	 * 
	 * Sample usage:
	 * - $table_object = $this->database->get_table_by_name($table_name);
	 * - $table_object->get_table_rows();
	 */
	var $database;

	/**
	 * @var object Sql A reference to the Sql object
	 *
	 * Execute SQL queries directly using this object.
	 *
	 * Sample usage:
	 * - Add a custom query in the sql ini file
	 * - Execute the query using Sql::select_query() or Sql::update_query()
	 *
	 * E.g.
	 * - Select query: $this->sql->select_query('QUERY_NAME', $param1, $param2, ...);
	 * - Update query: $this->sql->update_query('QUERY_NAME', $param1, $param2, ...);
	 */
	var $sql;

	/**
	 * @var object Controller A reference to the Controller object
	 *
	 * All module:action links that are not in the exception list need to be
	 * encoded using the Controller::encode_url() function.
	 *
	 * Sample usage:
	 * - $view = new View("Link text");
	 * - $view->a($this->controller->encode_url('module_name', 'action_name', 'param1=value1&param2=value2...'));
	 *
	 * To get the parameters in the clicked URL above
	 * - parse_str($this->controller->decode_url());
	 * - echo $param1; echo $param2;
	 */
	var $controller;

	/**
	 * Set the references to the application objects
	 *
	 * This function makes the objects in the application accessible to 
	 * all deriving module classes.
	 */ 
	function set_references() {
		$this->application =& $GLOBALS['__application'];
		$this->error =& $GLOBALS['__application']->error;
		$this->database =& $GLOBALS['__application']->database;
		$this->sql =& $GLOBALS['__application']->database->sql;
		$this->controller =& $GLOBALS['__application']->controller;
	}
	
	/**
	 * Get the exceptions registered by the Module
	 *
	 * The controller automatically calls this function to register the exceptions.
	 */
	function get_exceptions() {
		$exceptions = array();
		foreach ($this->exceptions as $exception)
			$exceptions[] = get_class($this).":$exception";

		return $exceptions;
	}
	
	/**
	 * Reset the Module::$output
	 *
	 * This function is called by the controller at load time
	 */
	function initialize() {
		if (isset($this->application->config['template']['framework']) &&
			$this->application->config['template']['framework'] != '')
			$this->output = array();
		else
			$this->output = '';
		
		if (!isset($this->exceptions))
			$this->exceptions = array();
	}
	
	/**
	 * Execute another loaded module's action
	 *
	 * - First parameter is the name of the module - string
	 * - Second parameter is the name of the action to execute - string
	 * - Remaining parameters (if any) are any arguments to send the method
	 *
	 * @return mixed Returns the return value of the module action
	 */
	function exec_module_action() {
		// Get argument information
		$num_args = func_num_args();
		$args = func_get_args();
		
		// Check if sufficient arguments
		if ($num_args < 2)
			$this->error->display_error('ERROR_MODULE_EXTERNAL_MODULE_CALL_ARGUMENTS', $num_args);
			
		// Pull out the module and action
		$module = array_shift($args);
		$action = array_shift($args);
		
		// Check that the module and action exist
		$this->controller->check_module_action("$module:$action");
		
		// Execute the module action
		return call_user_func_array(array($this->controller->modules[$module], $action), $args);
	}
	
	/**
	 * Render the module HTML output
	 *
	 * The functionality of this method depends on whether a templating framework
	 * is in use or not:
	 * - This function creates a View and calls the View::render() function on Module::$output
	 *  if no framework is in use. i.e. template->framework is undefined in config.ini
	 * - If a framework is in use, this function calls the appropriate *_render() function 
	 *  for the specified framework. The output is then rendered using View::render() to 
	 *  add the HTML header.
	 *
	 * The controller automatically calls this function. Do not call this function directly.
	 */
	function render() {
		if (isset($this->application->config['template']['framework'])) {
			$framework = $this->application->config['template']['framework'];

			// Check which framework
			switch ($framework) {
				case "Smarty":
				case "PHPTAL":
					break;
				default:
					$this->error->display_error('ERROR_MODULE_UNSUPPORTED_TEMPLATE_FRAMEWORK', $framework);

			}

			// Get the framework path
			if (!isset($this->application->config['template']['path']) || 
				$this->application->config['template']['path'] == '')
				$this->error->display_error('ERROR_MODULE_MISSING_TEMPLATE_FRAMEWORK_PATH');
			$path = $this->application->config['template']['path'];

			// Get the template dir
			if (!isset($this->application->config['template']['template_dir']) ||
				$this->application->config['template']['template_dir'] == '')
				$this->error->display_error('ERROR_MODULE_MISSING_TEMPLATE_DIR');
			$template_dir = $this->application->config['template']['template_dir'];

			// Get the compiled dir
			if (!isset($this->application->config['template']['compiled_dir']) ||
				$this->application->config['template']['compiled_dir'] == '')
				$this->error->display_error('ERROR_MODULE_MISSING_TEMPLATE_COMPILED_DIR');
			$compiled_dir = $this->application->config['template']['compiled_dir'];

			// Get the module and action being executed
			parse_str($this->controller->decode_url());
			$template_file = "$module#$action";

			// Call the templating framework's render method
			$render_method = "${framework}_render";
			$this->$render_method($template_file, $path, $template_dir, $compiled_dir);
		} else {
			// Generate the output using a View object
			$view = new View($this->output);
			$view->render();
		}
	}

	/**
	 * Render the module output using Smarty
	 *
	 * This function uses the associative array Module::$output populated
	 * by the called action and renders the template using Smarty.
	 *
	 * The template file loaded depends on the current action being
	 * executed by the controller. Hence, if the User::show_details() 
	 * action is being executed, the template file rendered by Smarty
	 * is User#show_details.tpl.
	 *
	 * The output of the template is then rendered using View::render() to
	 * add the HTML header.
	 *
	 * @param string $template_file The name of the template file to render
	 * @param string $path Path to the Smarty framework
	 * @param string $template_dir Directory that contains the template files
	 * @param string $compiled_dir Directory that will contain the compiled templates
	 */
	function Smarty_render($template_file, $path, $template_dir, $compiled_dir) {
		// Set up constants
		define('SMARTY_DIR', "$path/");

		// Load framework and create an object
		require_once(SMARTY_DIR . "Smarty.class.php");
		$tpl = new Smarty();

		// Set additional config options
		$tpl->template_dir = $template_dir;
		$tpl->compile_dir = $compiled_dir;

		// Check for optional config
		if (isset($this->application->config['template']['cache_dir']) &&
			$this->application->config['template']['cache_dir'] != '') {
			$tpl->caching = 1;
			$tpl->cache_dir = $this->application->config['template']['cache_dir'];
		}
		if (isset($this->application->config['template']['config_dir']) &&
			$this->application->config['template']['config_dir'] != '')
			$tpl->config_dir = $this->application->config['template']['config_dir'];

		// Add the variables defined in Module::$output to object
		if (!is_array($this->output))
			$this->error->display_error('ERROR_MODULE_INVALID_MODULE_OUTPUT_FOR_TEMPLATE');
		foreach ($this->output as $key => $value)
			$tpl->assign($key, $value);

		// Display the template
		$view = new View($tpl->fetch("$template_file.tpl"));
		$view->render();
	}

	/**
	 * Render the module output using PHPTAL
	 *
	 * This function uses the associative array Module::$output populated
	 * by the called action and renders the template using PHPTAL.
	 *
	 * The template file loaded depends on the current action being
	 * executed by the controller. Hence, if the User::show_details() 
	 * action is being executed, the template file rendered by PHPTAL
	 * is User#show_details.html.
	 *
	 * The output of the template is then rendered using View::render() to
	 * add the HTML header.
	 *
	 * @param string $template_file The name of the template file to render
	 * @param string $path Path to the PHPTAL framework
	 * @param string $template_dir Directory that contains the template files
	 * @param string $compiled_dir Directory that will contain the compiled templates
	 */
	function PHPTAL_render($template_file, $path, $template_dir, $compiled_dir) {
		// Set up constants
		define ('PHPTAL_TEMPLATE_REPOSITORY', "$template_dir/");
		define ('PHPTAL_PHP_CODE_DESTINATION', "$compiled_dir/");
		
		// Add PHPTAL to the include path
		$incl_path = ini_get('include_path');
		if (strpos($incl_path, ';') !== false)
			ini_set('include_path', "$incl_path;.\\$path");
		else
			ini_set('include_path', "$incl_path:./$path");

		// Load framework and create an object
		require_once("$path/PHPTAL.php");
		$tpl = new PHPTAL();

		// Add the variables defined in Module::$output to object
		if (!is_array($this->output))
			$this->error->display_error('ERROR_MODULE_INVALID_MODULE_OUTPUT_FOR_TEMPLATE');
		foreach ($this->output as $key => $value)
			$tpl->set($key, $value);

		// Render the template
		$tpl->setTemplate("$template_file.html");
		
		// Execute a try-catch in PHP5
		$php5 = 
		"try {".
		"	\$out = \$tpl->execute();".
		"} catch (Exception \$e) {".
		"	\$this->error->display_error('ERROR_MODULE_TEMPLATE_RENDER_FAILED', \"\$template_file.html\", \"<pre>\".\$e->__toString());".
		"}";
		
		// For PHP4, check the type of $out
		$php4 =
		"\$out = \$tpl->execute();".
		"if (!is_string(\$out))".
		"	\$this->error->display_error('ERROR_MODULE_TEMPLATE_RENDER_FAILED', \"\$template_file.html\", \"<pre>\".\$out->toString());";
		
		// Check PHP version and execute appropriate call
		if (phpversion() < 5) eval ($php4);
		else eval ($php5);

		// Display the template
		$view = new View($out);
		$view->render();
	}
}

/*
 * Implementation of array_combine() for PHP4
 */
if (!function_exists('array_combine')) {
	function array_combine($keys, $values) {
		$final = array();
		for ($i = 0; $i < count($keys); $i++)
			$final[$keys[$i]] = $values[$i];

		return $final;
	}
}

?>
