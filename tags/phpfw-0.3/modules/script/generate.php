<?php

// Check arguments
$args = $GLOBALS['argv'];
if (count($args) < 2) {
	echo "Generates a module file in the current directory\n\n";
	echo "  Assumes the module talks to a table of the same name as the module\n";
	echo "  e.g. Module 'Person' talks to table 'person'\n\n";
	echo "  Usage: php script/generate.php module1 module2 ...\n";
	exit;
}

// Get the path to scaffold.php
$scaffold_file = str_replace("generate.php", "scaffold.php", $args[0]);

// Get the scaffold code
$scaffold = file_get_contents($scaffold_file);

// Process each module specified
for ($i = 1; $i < count($args); $i++) {
	// Set module name
	$modulename = strtolower($args[$i]);
	$uc_modulename = ucwords($modulename);

	// Check if module file already exists
	if (file_exists($uc_modulename.".php")) {
		echo "- Skipping module '$uc_modulename' since it already exists\n";
		continue;
	}

	// Replace module name in scaffold code
	$output = str_replace("<<<modulename>>>", $modulename, $scaffold);
	$output = str_replace("<<<uc_modulename>>>", $uc_modulename, $output);
	
	// Save the output to file
	file_put_contents($uc_modulename.".php", $output);
	
	// Message
	echo "+ Generated module '$uc_modulename'\n";
}

?>