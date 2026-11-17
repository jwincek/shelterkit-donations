<?php
/**
 * Dependencies of edit.js, read by core's register_block_script_handle().
 *
 * There is no build step to generate this file. Without it, core registers
 * the editor script with no dependencies, prints it before wp.element exists,
 * and never loads its translations (#17).
 *
 * @package Starter_Shelter
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
	'version'      => (string) filemtime( __DIR__ . '/edit.js' ),
);
