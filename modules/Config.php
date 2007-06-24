<?php

/**
 * Load and save configuration files
 *
 * This class currently supports the following config file formats:
 * - .INI
 *
 * This class can be used by any module as follows:-
 * - $this->exec_module_action('Config', 'read_ini_file', $filename);
 * - $this->exec_module_action('Config', 'write_ini_file', $filename, $config);
 */
class Config extends Module {
	/**
	 * This method reads configuration values in the specified filename.
	 *
	 * This method uses the PHP function parse_ini_file() to load the configuration.
	 * It always processes sections.
	 *
	 * @param string Name of the file
	 *
	 * @return array An associative array with the configuration
	 */
	function read_ini_file($filename) {
		return parse_ini_file($filename, true);
	}

	/**
	 * This method writes the configuration values specified to the filename.
	 *
	 * The file data is overwritten if the file already exists. Implementation
	 * obtained from the comments under the parse_ini_file() page on PHP.net.
	 *
	 * @param string Name of the file
	 * @param array Associative array with the configuration
	 *
	 * @return bool Returns true on success, false otherwise
	 */
	function write_ini_file($filename, $config) {
		$content = '';
		$sections = '';

		foreach ($config as $key => $item) {
			if (is_array($item)) {
				$sections .= "\n[{$key}]\n";
				foreach ($item as $key2 => $item2) {
					if (is_numeric($item2) || is_bool($item2))
						$sections .= "{$key2} = {$item2}\n";
					else
						$sections .= "{$key2} = \"{$item2}\"\n";
				}
			} else {
				if(is_numeric($item) || is_bool($item))
					$content .= "{$key} = {$item}\n";
				else
					$content .= "{$key} = \"{$item}\"\n";
			}
		}

		$content .= $sections;

		if (!$handle = fopen($filename, 'w'))
			return false;

		if (!fwrite($handle, $content))
			return false;

		fclose($handle);
		return true;
	}
}

?>
