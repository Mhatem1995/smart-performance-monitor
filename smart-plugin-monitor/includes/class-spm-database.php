<?php
/**
 * Database operations for Smart Plugin Monitor.
 *
 * Handles table creation, log insertion, retrieval, and cleanup.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Database {

    /**
     * Full table name including the WP prefix.
     *
     * @return string
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . SPM_DB_TABLE;
    }

    /**
     * Create the custom log table (runs on activation).
     *
     * Uses dbDelta() so the schema is safely upgradeable.
     */
    public static function create_table(): void {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_name    VARCHAR(255)        NOT NULL DEFAULT '',
            plugin_version VARCHAR(50)         NOT NULL DEFAULT '',
            load_time_ms   FLOAT               NOT NULL DEFAULT 0,
            error_message  TEXT                NULL,
            error_level    VARCHAR(50)         NULL,
            created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_plugin_name (plugin_name(191)),
            KEY idx_created_at  (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'spm_db_version', SPM_VERSION );

        $logs_table = $wpdb->prefix . 'spm_action_logs';
        $sql_logs = "CREATE TABLE {$logs_table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_name VARCHAR(255)        NOT NULL,
            action      VARCHAR(50)         NOT NULL,
            user_id     BIGINT(20) UNSIGNED NOT NULL,
            result      VARCHAR(50)         NOT NULL,
            details     TEXT                NULL,
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_plugin (plugin_name(191)),
            KEY idx_user   (user_id)
        ) {$charset};";

        dbDelta( $sql_logs );
    }

    /**
     * Check if the schema needs an update and run it if so.
     * This ensures the 'plugin_version' column exists even if activation was skipped.
     */
    public function maybe_update_schema(): void {
        global $wpdb;
        $table = self::table_name();
        
        // Lightweight check for 'plugin_version' column.
        $row = $wpdb->get_row( "SELECT * FROM {$table} LIMIT 1" );
        if ( $row && ! isset( $row->plugin_version ) ) {
            self::create_table();
        }
    }

    /**
     * Insert a single log row.
     *
     * @param string      $plugin_name  Plugin basename (e.g. "akismet/akismet.php").
     * @param float       $load_time_ms Load time in milliseconds.
     * @param string|null $error_msg    Error message, if any.
     * @param string|null $error_level  Error level label (e.g. "E_WARNING").
     *
     * @return bool True on success.
     */
    public function insert_log(
        string $plugin_name,
        float $load_time_ms,
        ?string $error_msg = null,
        ?string $error_level = null
    ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->insert(
            self::table_name(),
            [
                'plugin_name'    => sanitize_text_field( $plugin_name ?? '' ),
                'plugin_version' => sanitize_text_field( $this->get_plugin_version( $plugin_name ?? '' ) ),
                'load_time_ms'   => round( (float) $load_time_ms, 4 ),
                'error_message'  => $error_msg ? sanitize_textarea_field( $error_msg ) : '',
                'error_level'    => $error_level ? sanitize_text_field( $error_level ) : '',
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%f', '%s', '%s', '%s' ]
        );

        return false !== $result;
    }

    /**
     * Insert multiple log rows in a single query (batch insert).
     *
     * @param array $rows Each element: [ plugin_name, load_time_ms, error_msg|null, error_level|null ].
     *
     * @return bool True on success.
     */
    public function insert_logs_batch( array $rows ): bool {
        global $wpdb;

        if ( empty( $rows ) ) {
            return true;
        }

        $table        = self::table_name();
        $now          = current_time( 'mysql' );
        $placeholders = [];
        $values       = [];

        foreach ( $rows as $row ) {
            $plugin_name    = $row['plugin_name'] ?? '';
            $version        = $this->get_plugin_version( $plugin_name );
            $placeholders[] = '(%s, %s, %f, %s, %s, %s)';
            $values[]       = sanitize_text_field( $plugin_name );
            $values[]       = sanitize_text_field( $version );
            $values[]       = round( (float) ( $row['load_time_ms'] ?? 0 ), 4 );
            $values[]       = isset( $row['error_message'] ) ? sanitize_textarea_field( (string) $row['error_message'] ) : '';
            $values[]       = isset( $row['error_level'] ) ? sanitize_text_field( (string) $row['error_level'] ) : '';
            $values[]       = $now;
        }

        $placeholder_string = implode( ', ', $placeholders );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (plugin_name, plugin_version, load_time_ms, error_message, error_level, created_at) VALUES {$placeholder_string}",
                $values
            )
        );

        return false !== $result;
    }

    /**
     * Retrieve recent log entries.
     *
     * @param int $limit  Number of rows to fetch.
     * @param int $offset Pagination offset.
     *
     * @return array
     */
    public function get_logs( int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Count total log entries.
     *
     * @return int
     */
    public function count_logs(): int {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Count log entries within a date window.
     *
     * @param int $days Only count logs from the last N days. 0 = all time.
     *
     * @return int
     */
    public function count_logs_since( int $days = 7 ): int {
        global $wpdb;

        $table = self::table_name();

        if ( $days <= 0 ) {
            return $this->count_logs();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    /**
     * Retrieve recent error-only log entries within a date window.
     *
     * @param int $days  Only consider logs from the last N days. 0 = all time.
     * @param int $limit Max rows to return.
     *
     * @return array
     */
    public function get_recent_errors( int $days = 7, int $limit = 10 ): array {
        global $wpdb;

        $table     = self::table_name();
        $where_sql = 'WHERE error_message IS NOT NULL AND error_message != %s';
        $values    = [ '' ];

        if ( $days > 0 ) {
            $where_sql .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $values[]   = $days;
        }

        $values[] = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d",
                $values
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Count total error entries within a date window.
     *
     * @param int $days Only count from the last N days. 0 = all time.
     *
     * @return int
     */
    public function count_errors_since( int $days = 7 ): int {
        global $wpdb;

        $table     = self::table_name();
        $where_sql = 'WHERE error_message IS NOT NULL AND error_message != %s';
        $values    = [ '' ];

        if ( $days > 0 ) {
            $where_sql .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $values[]   = $days;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} {$where_sql}",
                $values
            )
        );
    }

    /**
     * Delete logs older than a given number of days.
     *
     * @param int $days Retention period.
     *
     * @return int Number of rows deleted.
     */
    public function purge_old_logs( int $days = 30 ): int {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    /**
     * Clear error logs, optionally for a specific plugin.
     * This sets error fields to NULL without deleting the row so performance data is retained.
     *
     * @param string $plugin_name Optional plugin basename.
     * @return int Number of rows modified.
     */
    public function clear_error_logs( string $plugin_name = '' ): int {
        global $wpdb;
        $table = self::table_name();

        if ( ! empty( $plugin_name ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            return (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET error_message = NULL, error_level = NULL WHERE plugin_name = %s",
                $plugin_name
            ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return (int) $wpdb->query( "UPDATE {$table} SET error_message = NULL, error_level = NULL" );
    }

    /**
     * Get aggregated per-plugin statistics.
     *
     * Returns avg, min, max load times and total log count per plugin,
     * optionally limited to a recent time window.
     *
     * @param int $days Only consider logs from the last N days. 0 = all time.
     *
     * @return array Each row: { plugin_name, avg_ms, min_ms, max_ms, sample_count }
     */
    public function get_plugin_averages( int $days = 7 ): array {
        global $wpdb;

        $table     = self::table_name();
        $where_sql = '';
        $values    = [];

        if ( $days > 0 ) {
            $where_sql = 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $values[]  = $days;
        }

        $sql = "SELECT
                    plugin_name,
                    AVG(load_time_ms)   AS avg_ms,
                    MIN(load_time_ms)   AS min_ms,
                    MAX(load_time_ms)   AS max_ms,
                    COUNT(*)            AS sample_count
                FROM {$table}
                {$where_sql}
                GROUP BY plugin_name
                ORDER BY avg_ms DESC";

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $results = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $results = $wpdb->get_results( $sql, ARRAY_A );
        }

        return $results ?: [];
    }

    /**
     * Get error counts grouped by plugin.
     *
     * @param int $days Only consider logs from the last N days. 0 = all time.
     *
     * @return array Each row: { plugin_name, error_count }
     */
    public function get_error_counts_by_plugin( int $days = 7 ): array {
        global $wpdb;

        $table     = self::table_name();
        $where_sql = 'WHERE error_message IS NOT NULL';
        $values    = [];

        if ( $days > 0 ) {
            $where_sql .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $values[]   = $days;
        }

        $sql = "SELECT
                    plugin_name,
                    COUNT(*) AS error_count
                FROM {$table}
                {$where_sql}
                GROUP BY plugin_name
                ORDER BY error_count DESC";

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $results = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $results = $wpdb->get_results( $sql, ARRAY_A );
        }

        return $results ?: [];
    }

    /**
     * Get daily averages and error counts for a specific plugin.
     *
     * @param string $plugin_name Plugin basename.
     * @param int    $days        Number of days to look back.
     *
     * @return array Array of [date, avg_ms, error_count]
     */
    public function get_daily_averages_for_plugin( string $plugin_name, int $days = 7 ): array {
        global $wpdb;

        $table = self::table_name();

        $sql = "SELECT
                    DATE(created_at)    AS date,
                    AVG(load_time_ms)   AS avg_ms,
                    COUNT(CASE WHEN error_message IS NOT NULL AND error_message != '' THEN 1 END) AS error_count
                FROM {$table}
                WHERE plugin_name = %s
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $results = $wpdb->get_results( $wpdb->prepare( $sql, $plugin_name, $days ), ARRAY_A );

        return $results ?: [];
    }

    /**
     * Helper to get plugin version.
     */
    private function get_plugin_version( string $basename ): string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        return isset( $plugins[ $basename ]['Version'] ) ? $plugins[ $basename ]['Version'] : '0.0.0';
    }

    /**
     * Log an administrative action.
     */
    public function log_action( string $plugin, string $action, string $result, string $details = '' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'spm_action_logs';

        return false !== $wpdb->insert(
            $table,
            [
                'plugin_name' => sanitize_text_field( $plugin ?? '' ),
                'action'      => sanitize_text_field( $action ?? '' ),
                'user_id'     => get_current_user_id(),
                'result'      => sanitize_text_field( $result ?? '' ),
                'details'     => sanitize_textarea_field( $details ?? '' ),
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * Drop the table entirely (used on uninstall).
     */
    public static function drop_table(): void {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );

        delete_option( 'spm_db_version' );
    }
}
