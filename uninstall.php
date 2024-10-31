<?php
/**
 * Plugin Uninstall
 *
 * Uninstalling Ngx Image Resizer deletes options.
 *
 * @package Ngx_Image_Resizer/Uninstaller
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options.
delete_option( 'nir_settings' );
