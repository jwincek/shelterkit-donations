<?php
/**
 * Dependencies of view.js, read by core's register_block_script_module_id().
 *
 * Declaring @wordpress/interactivity is what puts it in the import map. A
 * block theme usually adds it anyway (the Navigation block depends on it),
 * which hides the omission; on a classic theme the module fails to resolve
 * and the block never hydrates (#18).
 *
 * @package Starter_Shelter
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array( '@wordpress/interactivity' ),
	'version'      => (string) filemtime( __DIR__ . '/view.js' ),
);
