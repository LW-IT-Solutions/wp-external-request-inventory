<?php
/**
 * Remove everything the plugin stored.
 *
 * @package ExternalRequestInventory
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'erinv_server' );
delete_option( 'erinv_browser' );
delete_option( 'erinv_settings' );
