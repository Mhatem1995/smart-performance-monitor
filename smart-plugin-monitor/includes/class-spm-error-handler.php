<?php
/**
 * Error handler for Smart Plugin Monitor.
 *
 * Captures PHP errors via set_error_handler and maps them to plugin basenames.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Error_Handler {

    /**
     * Collected errors keyed by plugin basename.
     *
     * @var array<string, array{message: string, level: string}>
     */
    private array $errors = [];

    /**
     * Reference to the previous error handler so we can chain it.
     *
     * @var callable|null
     */
    private $previous_handler = null;

    /**
     * Map of PHP error constants to human-readable labels.
     */
    private const ERROR_LABELS = [
        E_WARNING         => 'E_WARNING',
        E_NOTICE          => 'E_NOTICE',
        E_STRICT          => 'E_STRICT',
        E_DEPRECATED      => 'E_DEPRECATED',
        E_USER_ERROR      => 'E_USER_ERROR',
        E_USER_WARNING    => 'E_USER_WARNING',
        E_USER_NOTICE     => 'E_USER_NOTICE',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];

    /**
     * Install our custom error handler.
     *
     * We only capture non-fatal errors (warnings, notices, deprecations).
     */
    public function register(): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
        $this->previous_handler = set_error_handler( [ $this, 'handle_error' ] );
    }

    /**
     * Restore the previous error handler.
     */
    public function unregister(): void {
        restore_error_handler();
    }

    /**
     * Custom error handler callback.
     *
     * @param int    $errno   Error level.
     * @param string $errstr  Error message.
     * @param string $errfile File where the error occurred.
     * @param int    $errline Line number.
     *
     * @return bool False to let PHP's internal handler continue.
     */
    public function handle_error( int $errno, string $errstr, string $errfile, int $errline ): bool {
        // Respect the current error_reporting level.
        if ( ! ( error_reporting() & $errno ) ) {
            return false;
        }

        $plugin = $this->resolve_plugin_from_path( $errfile );

        if ( $plugin ) {
            $label = self::ERROR_LABELS[ $errno ] ?? 'E_UNKNOWN(' . $errno . ')';

            $this->errors[ $plugin ][] = [
                'message' => sprintf( '[%s] %s in %s:%d', $label, $errstr, $errfile, $errline ),
                'level'   => $label,
            ];
        }

        // Chain to the previous handler if one existed.
        if ( is_callable( $this->previous_handler ) ) {
            return call_user_func( $this->previous_handler, $errno, $errstr, $errfile, $errline );
        }

        // Return false so PHP's built-in handler also runs.
        return false;
    }

    /**
     * Return all captured errors and clear the internal buffer.
     *
     * @return array<string, array{message: string, level: string}>
     */
    public function flush_errors(): array {
        $errors       = $this->errors;
        $this->errors = [];
        return $errors;
    }

    /**
     * Resolve which plugin a file path belongs to.
     */
    private function resolve_plugin_from_path( ?string $file ): ?string {
        if ( ! $file ) {
            return null;
        }
        $plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
        $file        = wp_normalize_path( $file ?? '' );

        if ( strpos( $file, $plugins_dir ) !== 0 ) {
            return null;
        }

        // Strip the plugins directory prefix.
        $relative = ltrim( substr( $file, strlen( $plugins_dir ) ), '/' );
        $parts    = explode( '/', $relative, 3 );

        if ( count( $parts ) < 2 ) {
            // Single-file plugin.
            return $parts[0] ?? null;
        }

        // Return "folder/main-file.php" style — but we only know the folder.
        // We return just the folder name; the monitor will match it to the basename.
        return $parts[0];
    }
}
