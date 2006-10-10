<?php

// Generic module
class <<<uc_modulename>>> extends Module {
	// <<<uc_modulename>>> constructor
	function <<<uc_modulename>>>() {
	
	}

	// List all <<<modulename>>>s
	function list_all() {
		// Check login
		$this->exec_module_action('Login', 'check_login');
		
		// Get the table contents
		$<<<modulename>>> = $this->database->get_table_by_name('<<<modulename>>>');
		$rows = $<<<modulename>>>->get_table_rows_with_actions('<<<uc_modulename>>>:view', '<<<uc_modulename>>>:update', '<<<uc_modulename>>>:delete');

		// Create the table output
		$view = new View();
		$view->add_element("h1", "All <<<uc_modulename>>>s", 0, "nl");
		$view->add_element("table", $rows, 0, "nl", true);
		$view->add_element("a", "Add <<<uc_modulename>>>", 
			array("href" => $this->controller->encode_url('<<<uc_modulename>>>', 'add')), "nl", true);
		$view->compile_template();
		$view->center();
		
		// Assign view output to module output
		$this->output = $view->get_data();
	}

	// Add <<<modulename>>>
	function add() {
		// Check login
		$this->exec_module_action('Login', 'check_login');
		
		// Create the add form
		$form = new Form();
		$form->add_table(array('<<<modulename>>>'));
		$form->create_form('add_<<<modulename>>>', '<<<uc_modulename>>>', 'submit');

		// Create the form view
		$view = new View("Add <<<uc_modulename>>>");
		$view->h1();
		$view->push();
		$view->set_data($form->get_data());
		$view->pop_prepend();
		$view->center();
		
		// Assign view output to module output
		$this->output = $view->get_data();
	}
	
	// View the specified <<<modulename>>>
	function view() {
		// Check login
		$this->exec_module_action('Login', 'check_login');

		// Get the <<<modulename>>> ID
		parse_str($this->controller->decode_url());

		// Get the table and the row in question
		$data = array();
		$<<<modulename>>> = $this->database->get_table_by_name('<<<modulename>>>');
		$<<<modulename>>>->get_table_row_and_link_rows_for_view($<<<modulename>>>_id, $data);

		// Create the table view
		$view = new View($data);
		$view->table_two_column_associative();
		$view->center();

		// Assign view output to module output
		$this->output = $view->get_data();
	}
	
	// Update the specified <<<modulename>>>
	function update() {
		// Check login
		$this->exec_module_action('Login', 'check_login');

		// Get the <<<modulename>>> ID
		parse_str($this->controller->decode_url());
		
		// Create the update form
		$form = new Form();
		$form->update_form('<<<modulename>>>', $<<<modulename>>>_id, 'edit_<<<modulename>>>', '<<<uc_modulename>>>', 'submit');
		
		// Create the output
		$view = new View($form->get_data());
		$view->center();

		// Assign view output to module output
		$this->output = $view->get_data();
	}
	
	// Delete the specified <<<modulename>>>
	function delete() {
		// Check login
		$this->exec_module_action('Login', 'check_login');

		// Get the <<<modulename>>> ID
		parse_str($this->controller->decode_url());
		
		// Get the table and delete the row
		$<<<modulename>>> = $this->database->get_table_by_name('<<<modulename>>>');
		$<<<modulename>>>->delete_table_row_and_link_rows($<<<modulename>>>_id);
		
		// Show all <<<modulename>>>s
		$this->list_all();
	}
	
	// Process an add or an update action
	function submit() {
		// Check login
		$this->exec_module_action('Login', 'check_login');
		
		// Process the form
		$form = new Form();
		$form->process_form();

		// Show all <<<modulename>>>s
		$this->list_all();
	}
}

?>