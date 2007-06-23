<?php

/**
 * Wrapper for the PEL library
 * 
 * This module simplifies usage of PEL - a PHP Exif Library.
 */ 
class PelWrap extends Module {
	/**
	 * @var string Path to Pel library
	 */
	var $path = '';
	
	/**
	 * Constructor for the Pel module
	 * 
	 * Initialize members
	 */
	function PelWrap() {
		$this->register_error('ERROR_PEL_LIBRARY_NOT_FOUND',
			"Can not find Pel library at: '%s'.", "ERROR");
	}
	
	/**
	 * Get the configuration from the config file
	 */
	function get_configuration() {
		// Load values from config file if applicable
		if (isset($this->application->config['pel'])) {
			$keys = explode(' ', 'path');
			foreach ($keys as $key)
				if (isset_and_non_empty($this->application->config['pel'][$key]))
					$this->$key = $this->application->config['pel'][$key];
		}
		
		// Check if Pel library files exist
		if (!file_exists($this->path))
			$this->error->display_error('ERROR_PEL_LIBRARY_NOT_FOUND', $this->path);
	}
	
	/**
	 * Setup the Pel module
	 * 
	 * Call this action before opening and processing files
	 */
	function setup() {
		// Load configuration
		$this->get_configuration();
		
		// Load the Pel library
		require_once($this->path . '/PelJpeg.php');
	}

	/**
	 * Open an image using PEL
	 * 
	 * @param string $image_file Path to the image file
	 */
	function open($image_file) {
		if ($this->path == '') $this->setup();
		
		return new PelJpeg($image_file);
	}
	
	/**
	 * Update an image file
	 * 
	 * @param string $image_file Path to the image file
	 */
	function update($image_file, $pelobj) {
		file_put_contents($image_file, $pelobj->getBytes());
	}
	
	/**
	 * Get or set the value of a tag
	 * 
	 * @param string $image_file Path to the image file
	 * @param string $tag An Exif tag as per the Pel documentation (http://pel.sourceforge.net/doc/PEL/PelTag.html)
	 * @param string $new_value The value to set the tag to (if skipped, the tag remains unchanged)
	 * 
	 * @return string Returns the value of the tag 
	 */
	function access($image_file, $tag, $new_value=null) {
		// Value to return
		$returned = '';
		
		// Open file if not already open
		$pelobj = $this->open($image_file);
		if ($pelobj == null) return $returned;
		
		// List of subIfds
		$sub = array(PelIfd::EXIF, PelIfd::GPS, PelIfd::INTEROPERABILITY);
		
		// Get Exif section
		$exif = $pelobj->getExif();
		if ($exif == null) return $returned;
		
		// Get Tiff chunk
		$tiff = $exif->getTiff();
		if ($tiff == null) return $returned;
		
		// Search through Ifds and subIfds
		$ifd = $tiff->getIfd();
		while ($ifd != null) {
			$entry = $ifd->getEntry(constant("PelTag::$tag"));
			if ($entry == null) {
				foreach ($sub as $s) {
					$ifds = $ifd->getSubIfd($s);
					if ($ifds != null) {
						$entry = $ifds->getEntry(constant("PelTag::$tag"));
						if ($entry != null) break;
					}
				}
				if ($entry != null) break;
			} else
				break;
			$ifd = $ifd->getNextIfd();
		}

		if ($entry == null) return $returned;
		
		// Set a value as needed
		if ($value != null) {
			$entry->setValue($new_value);
			$this->update($image_file, $pelobj);
		}		
		
		return $entry->getValue();
	}
}

?>