<?php
/**
 * Uninstall handler for Smart Plugin Monitor.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Drops the custom table and removes all stored options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-spm-database.php';

// Define constant required by the database class.
if ( ! defined( 'SPM_DB_TABLE' ) ) {
    define( 'SPM_DB_TABLE', 'spm_plugin_logs' );
}

if ( ! defined( 'SPM_VERSION' ) ) {
    define( 'SPM_VERSION', '1.0.0' );
}

SPM_Database::drop_table();
