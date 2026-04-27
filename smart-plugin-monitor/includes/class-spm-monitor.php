<?php
/**
 * Core monitoring logic for Smart Plugin Monitor.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Monitor {

    private SPM_Database $database;
    private SPM_Error_Handler $error_handler;
    private array $load_times = [];

    public function __construct( SPM_Database $database, SPM_Error_Handler $error_handler ) {
        $this->database      = $database;
        $this->error_handler = $error_handler;
    }

    /**
     * Begin monitoring.
     */
    public function start(): void {
        $this->measure_plugin_load_times();
        $this->error_handler->register();
        add_action( 'shutdown', [ $this, 'persist' ], PHP_INT_MAX );
    }

    /**
     * Estimate per-plugin load times using file complexity as a proxy.
     */
    private function measure_plugin_load_times(): void {
        $active_plugins = get_option( 'active_plugins', [] );

        if ( empty( $active_plugins ) ) {
            return;
        }

        foreach ( $active_plugins as $plugin_basename ) {
            $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_basename;

            if ( ! file_exists( $plugin_file ) ) {
                continue;
            }

            // Skip ourselves.
            if ( plugin_basename( SPM_PLUGIN_FILE ) === $plugin_basename ) {
                continue;
            }

            $start = microtime( true );
            $size  = filesize( $plugin_file );
            $end   = microtime( true );

            $complexity = $size > 0 ? $size / 1024.0 : 0.01;
            $this->load_times[ $plugin_basename ] = round(
                ( ( $end - $start ) * 1000 ) + ( $complexity * 0.1 ),
                4
            );
        }
    }

    /**
     * Persist collected data to the database on shutdown.
     */
    public function persist(): void {
        $this->error_handler->unregister();
        $errors = $this->error_handler->flush_errors();
        $rows   = [];

        foreach ( $this->load_times as $plugin => $ms ) {
            $row = [
                'plugin_name'  => $plugin,
                'load_time_ms' => $ms,
                'error_message' => null,
                'error_level'   => null,
            ];

            $folder = explode( '/', $plugin )[0];
            if ( isset( $errors[ $folder ] ) ) {
                $messages = array_column( $errors[ $folder ], 'message' );
                $levels   = array_unique( array_column( $errors[ $folder ], 'level' ) );
                $row['error_message'] = implode( "\n", $messages );
                $row['error_level']   = implode( ', ', $levels );
                unset( $errors[ $folder ] );
            }

            $rows[] = $row;
        }

        // Remaining errors from unmeasured plugins.
        foreach ( $errors as $folder => $error_list ) {
            $messages = array_column( $error_list, 'message' );
            $levels   = array_unique( array_column( $error_list, 'level' ) );
            $rows[] = [
                'plugin_name'   => $folder,
                'load_time_ms'  => 0,
                'error_message' => implode( "\n", $messages ),
                'error_level'   => implode( ', ', $levels ),
            ];
        }

        if ( ! empty( $rows ) ) {
            $this->database->insert_logs_batch( $rows );
        }

        // Auto-purge old logs ~1% of requests.
        if ( wp_rand( 1, 100 ) === 1 ) {
            $this->database->purge_old_logs( 30 );
        }
    }
}
