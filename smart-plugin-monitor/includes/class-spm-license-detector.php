<?php
/**
 * Plugin License & Source Detector for Smart Plugin Monitor.
 *
 * Scans installed plugins and classifies them by source / license origin:
 *   - WordPress.org verified
 *   - Premium vendor
 *   - Custom / unknown
 *   - Requires verification (suspicious patterns detected)
 *
 * Uses plugin headers, license fields, folder naming conventions,
 * and lightweight code-pattern scanning (eval, base64_decode, etc.)
 * to generate a per-plugin confidence score.
 *
 * IMPORTANT: This module does NOT label plugins as "illegal" or "pirated".
 * It uses "risk" and "verification" language only.
 *
 * This class is UI-independent — it returns structured arrays
 * for any consumer (dashboard, REST, CLI).
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_License_Detector {

    /**
     * Source classification constants.
     */
    public const SOURCE_WPORG   = 'wordpress.org';
    public const SOURCE_PREMIUM = 'premium';
    public const SOURCE_CUSTOM  = 'custom';
    public const SOURCE_UNKNOWN = 'unknown';

    /**
     * Verification status constants.
     */
    public const STATUS_VERIFIED     = 'verified';
    public const STATUS_UNVERIFIED   = 'unverified';
    public const STATUS_NEEDS_REVIEW = 'needs-review';

    /**
     * Known premium vendor identifiers.
     *
     * Plugin URI / Author URI patterns that indicate a commercial vendor.
     */
    private const PREMIUM_VENDORS = [
        'envato'          => 'Envato / ThemeForest',
        'codecanyon'      => 'CodeCanyon',
        'elegantthemes'   => 'Elegant Themes',
        'wpengine'        => 'WP Engine',
        'developer.developer'   => 'Developer',
        'yithemes'        => 'YITH',
        'woothemes'       => 'WooThemes',
        'brainstormforce' => 'Brainstorm Force',
        'developer.developer'       => 'Developer',
        'developer.developer'      => 'Developer',
    ];

    /**
     * Suspicious function patterns to scan for.
     * These may indicate obfuscation or tampered code.
     */
    private const SUSPICIOUS_PATTERNS = [
        'eval'            => 'eval() call detected',
        'base64_decode'   => 'base64_decode() usage',
        'gzinflate'       => 'gzinflate() decompression',
        'gzuncompress'    => 'gzuncompress() decompression',
        'str_rot13'       => 'str_rot13() obfuscation',
        'preg_replace.*e' => 'preg_replace with /e modifier',
        'assert'          => 'assert() call detected',
        'create_function' => 'create_function() usage',
    ];

    /**
     * WordPress.org API endpoint for plugin info.
     */
    private const WPORG_API_URL = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=%s';

    /**
     * Cached WordPress.org slug lookup results.
     *
     * @var array<string, bool|null>
     */
    private array $wporg_cache = [];

    /**
     * Run a full scan of all installed plugins.
     *
     * Results are cached in a transient for 12 hours to avoid
     * repeated file-system and API calls.
     *
     * @param bool $force_refresh Bypass the transient cache.
     *
     * @return array{
     *     scanned_at:  string,
     *     total:       int,
     *     summary:     array{verified: int, unverified: int, needs_review: int},
     *     plugins:     array<int, array{
     *         basename:          string,
     *         name:              string,
     *         version:           string,
     *         author:            string,
     *         source:            string,
     *         source_label:      string,
     *         license:           string,
     *         verification:      string,
     *         confidence:        int,
     *         flags:             string[],
     *         suspicious_count:  int,
     *         notes:             string[]
     *     }>
     * }
     */
    /**
     * Get the most recently cached scan report.
     *
     * @return array|null
     */
    public static function get_cached_scan(): ?array {
        $cached = get_transient( 'spm_license_scan' );
        return ( false !== $cached ) ? $cached : null;
    }

    public function scan( bool $force_refresh = false ): array {
        $cache_key = 'spm_license_scan';

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $results     = [];
        $counts      = [ 'verified' => 0, 'unverified' => 0, 'needs_review' => 0 ];

        foreach ( $all_plugins as $basename => $data ) {
            $result = $this->analyze_plugin( $basename, $data );
            $results[] = $result;

            // Count by verification status.
            switch ( $result['verification'] ) {
                case self::STATUS_VERIFIED:
                    $counts['verified']++;
                    break;
                case self::STATUS_NEEDS_REVIEW:
                    $counts['needs_review']++;
                    break;
                default:
                    $counts['unverified']++;
            }
        }

        // Sort: needs-review first, then unverified, then verified.
        usort( $results, function ( $a, $b ) {
            $order = [
                self::STATUS_NEEDS_REVIEW => 0,
                self::STATUS_UNVERIFIED   => 1,
                self::STATUS_VERIFIED     => 2,
            ];
            return ( $order[ $a['verification'] ] ?? 1 ) <=> ( $order[ $b['verification'] ] ?? 1 );
        });

        $report = [
            'scanned_at' => current_time( 'mysql' ),
            'total'      => count( $results ),
            'summary'    => $counts,
            'plugins'    => $results,
        ];

        set_transient( $cache_key, $report, 12 * HOUR_IN_SECONDS );

        return $report;
    }

    /**
     * Analyze a single plugin.
     *
     * @param string $basename Plugin basename (e.g. "akismet/akismet.php").
     * @param array  $data     Plugin header data from get_plugins().
     *
     * @return array Per-plugin analysis result.
     */
    private function analyze_plugin( string $basename, array $data ): array {
        $flags      = [];
        $notes      = [];
        $confidence = 100; // Start at 100 and deduct.

        $name    = $data['Name'] ?? $basename;
        $version = $data['Version'] ?? '';
        $author  = wp_strip_all_tags( $data['Author'] ?? '' );
        $license = $data['License'] ?? '';
        $uri     = $data['PluginURI'] ?? '';
        $auth_uri = $data['AuthorURI'] ?? '';

        // ── Step 1: Determine source ──
        $source       = self::SOURCE_UNKNOWN;
        $source_label = __( 'Unknown Source', 'smart-plugin-monitor' );

        // Check WordPress.org API.
        $slug = $this->extract_slug( $basename );
        if ( $this->is_on_wporg( $slug ) ) {
            $source       = self::SOURCE_WPORG;
            $source_label = __( 'WordPress.org', 'smart-plugin-monitor' );
            $notes[]      = __( 'Listed on WordPress.org plugin directory', 'smart-plugin-monitor' );
        }

        // Check premium vendor patterns.
        if ( self::SOURCE_WPORG !== $source ) {
            $vendor = $this->detect_premium_vendor( $uri, $auth_uri, $author );
            if ( $vendor ) {
                $source       = self::SOURCE_PREMIUM;
                $source_label = $vendor;
                $notes[]      = sprintf(
                    /* translators: %s: vendor name */
                    __( 'Identified as premium plugin from %s', 'smart-plugin-monitor' ),
                    $vendor
                );
            }
        }

        // Check for custom plugin indicators.
        if ( self::SOURCE_UNKNOWN === $source ) {
            if ( $this->looks_custom( $basename, $data ) ) {
                $source       = self::SOURCE_CUSTOM;
                $source_label = __( 'Custom / In-house', 'smart-plugin-monitor' );
                $notes[]      = __( 'Appears to be a custom-developed plugin', 'smart-plugin-monitor' );
            }
        }

        // ── Step 2: License analysis ──
        $license_normalized = strtolower( trim( $license ) );
        if ( empty( $license_normalized ) ) {
            $flags[]     = 'no-license';
            $confidence -= 15;
            $notes[]     = __( 'No license header declared', 'smart-plugin-monitor' );
        } elseif ( $this->is_gpl_compatible( $license_normalized ) ) {
            $notes[] = sprintf(
                /* translators: %s: license string */
                __( 'License: %s (GPL-compatible)', 'smart-plugin-monitor' ),
                $license
            );
        } else {
            $flags[] = 'non-gpl';
            $notes[] = sprintf(
                /* translators: %s: license string */
                __( 'License: %s (non-GPL)', 'smart-plugin-monitor' ),
                $license
            );
        }

        // ── Step 3: Header completeness ──
        if ( empty( $version ) ) {
            $flags[]     = 'no-version';
            $confidence -= 10;
        }
        if ( empty( $author ) ) {
            $flags[]     = 'no-author';
            $confidence -= 10;
        }
        if ( empty( $uri ) && empty( $auth_uri ) ) {
            $flags[]     = 'no-uri';
            $confidence -= 5;
        }

        // ── Step 4: Suspicious code scan ──
        $suspicious  = $this->scan_suspicious_code( $basename );
        $sus_count   = count( $suspicious );
        if ( $sus_count > 0 ) {
            $flags       = array_merge( $flags, array_map( fn( $s ) => 'suspicious:' . $s, array_keys( $suspicious ) ) );
            $confidence -= min( 40, $sus_count * 10 );

            foreach ( $suspicious as $pattern => $description ) {
                $notes[] = sprintf(
                    /* translators: %s: pattern description */
                    __( 'Code review recommended: %s', 'smart-plugin-monitor' ),
                    $description
                );
            }
        }

        // ── Step 5: Folder naming heuristics ──
        $folder_flags = $this->check_folder_naming( $basename );
        if ( ! empty( $folder_flags ) ) {
            $flags       = array_merge( $flags, $folder_flags );
            $confidence -= count( $folder_flags ) * 8;

            foreach ( $folder_flags as $ff ) {
                $notes[] = sprintf(
                    /* translators: %s: flag key */
                    __( 'Folder pattern flag: %s', 'smart-plugin-monitor' ),
                    str_replace( '-', ' ', $ff )
                );
            }
        }

        // ── Step 6: WP.org source boost ──
        if ( self::SOURCE_WPORG === $source ) {
            $confidence = min( 100, $confidence + 15 );
        }

        // Clamp confidence.
        $confidence = max( 0, min( 100, $confidence ) );

        // ── Step 7: Determine verification status ──
        $verification = self::STATUS_VERIFIED;
        if ( $confidence < 50 || $sus_count > 2 ) {
            $verification = self::STATUS_NEEDS_REVIEW;
        } elseif ( $confidence < 80 || $sus_count > 0 || self::SOURCE_UNKNOWN === $source ) {
            $verification = self::STATUS_UNVERIFIED;
        }

        return [
            'basename'         => $basename,
            'name'             => $name,
            'version'          => $version,
            'author'           => $author,
            'source'           => $source,
            'source_label'     => $source_label,
            'license'          => $license ?: __( 'Not specified', 'smart-plugin-monitor' ),
            'verification'     => $verification,
            'confidence'       => $confidence,
            'flags'            => $flags,
            'suspicious_count' => $sus_count,
            'notes'            => $notes,
        ];
    }

    /**
     * Check if a plugin slug exists on WordPress.org.
     *
     * Uses a lightweight HEAD-style request with caching.
     *
     * @param string $slug Plugin slug.
     *
     * @return bool
     */
    private function is_on_wporg( string $slug ): bool {
        if ( isset( $this->wporg_cache[ $slug ] ) ) {
            return $this->wporg_cache[ $slug ];
        }

        // Check the transient cache first.
        $transient_key = 'spm_wporg_' . md5( $slug );
        $cached        = get_transient( $transient_key );
        if ( false !== $cached ) {
            $this->wporg_cache[ $slug ] = (bool) $cached;
            return $this->wporg_cache[ $slug ];
        }

        $url      = sprintf( self::WPORG_API_URL, rawurlencode( $slug ) );
        $response = wp_remote_get( $url, [
            'timeout'   => 5,
            'sslverify' => true,
        ] );

        $found = false;
        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            // The API returns an object with 'slug' on success.
            $found = ! empty( $body['slug'] );
        }

        $this->wporg_cache[ $slug ] = $found;
        set_transient( $transient_key, $found ? 1 : 0, 24 * HOUR_IN_SECONDS );

        return $found;
    }

    /**
     * Extract the slug (folder name) from a plugin basename.
     *
     * @param string $basename e.g. "akismet/akismet.php" or "hello.php".
     *
     * @return string
     */
    private function extract_slug( string $basename ): string {
        $parts = explode( '/', $basename, 2 );
        return $parts[0];
    }

    /**
     * Detect if a plugin comes from a known premium vendor.
     *
     * @param string $plugin_uri Plugin URI header.
     * @param string $author_uri Author URI header.
     * @param string $author     Author name.
     *
     * @return string|null Vendor display name, or null.
     */
    private function detect_premium_vendor( ?string $plugin_uri, ?string $author_uri, ?string $author ): ?string {
        $haystack = strtolower( ( $plugin_uri ?? '' ) . ' ' . ( $author_uri ?? '' ) . ' ' . ( $author ?? '' ) );

        foreach ( self::PREMIUM_VENDORS as $needle => $label ) {
            if ( str_contains( $haystack, $needle ) ) {
                return $label;
            }
        }

        // Heuristic: if the plugin URI contains a commercial domain pattern.
        $uri_lower = strtolower( $plugin_uri ?? '' );
        if ( preg_match( '/\.(io|co|com|net)\b/i', $uri_lower ) && ! str_contains( $uri_lower, 'wordpress.org' ) ) {
            if ( ! empty( $author ) ) {
                return $author;
            }
        }

        return null;
    }

    /**
     * Check if a plugin looks custom / in-house developed.
     *
     * @param string $basename Plugin basename.
     * @param array  $data     Plugin headers.
     *
     * @return bool
     */
    private function looks_custom( string $basename, array $data ): bool {
        // No plugin URI + no author URI = likely custom.
        $no_uris = empty( $data['PluginURI'] ) && empty( $data['AuthorURI'] );

        // Single-file plugin in root.
        $single_file = ! str_contains( $basename, '/' );

        // Very low version number.
        $low_version = version_compare( $data['Version'] ?? '0', '1.0', '<' );

        // Missing description.
        $no_desc = empty( $data['Description'] );

        $signals = (int) $no_uris + (int) $single_file + (int) $low_version + (int) $no_desc;

        return $signals >= 2;
    }

    /**
     * Check if a license string is GPL-compatible.
     *
     * @param string $license Lowercased license string.
     *
     * @return bool
     */
    private function is_gpl_compatible( string $license ): bool {
        $gpl_patterns = [
            'gpl', 'gnu general public', 'mit', 'apache',
            'bsd', 'lgpl', 'mpl', 'artistic',
        ];

        foreach ( $gpl_patterns as $pattern ) {
            if ( str_contains( $license, $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan a plugin's main file for suspicious function patterns.
     *
     * Only scans the main plugin file (not the entire directory)
     * to keep performance acceptable.
     *
     * @param string $basename Plugin basename.
     *
     * @return array<string, string> pattern_key => description.
     */
    private function scan_suspicious_code( string $basename ): array {
        $file = WP_PLUGIN_DIR . '/' . $basename;

        if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
            return [];
        }

        // Only scan files under 500 KB to avoid memory issues.
        if ( filesize( $file ) > 512 * 1024 ) {
            return [];
        }

        $content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( false === $content ) {
            return [];
        }

        $found = [];

        foreach ( self::SUSPICIOUS_PATTERNS as $pattern => $description ) {
            // Use word-boundary matching to reduce false positives.
            $regex = '/\b' . $pattern . '\s*\(/i';
            if ( preg_match( $regex, $content ) ) {
                $found[ $pattern ] = $description;
            }
        }

        return $found;
    }

    /**
     * Check folder naming for suspicious patterns.
     *
     * Nulled / redistributed plugins often have telltale folder suffixes.
     *
     * @param string $basename Plugin basename.
     *
     * @return string[] Array of flag keys.
     */
    private function check_folder_naming( string $basename ): array {
        $slug  = $this->extract_slug( $basename );
        $lower = strtolower( $slug );
        $flags = [];

        $suspicious_suffixes = [
            '-nulled',
            '-cracked',
            '-patched',
            '-modified',
            '-free',
            '-pro-free',
            '-gpl',
            '-leaked',
        ];

        foreach ( $suspicious_suffixes as $suffix ) {
            if ( str_ends_with( $lower, $suffix ) ) {
                $flags[] = 'folder-name' . $suffix;
            }
        }

        // Random hash-like suffix (e.g. plugin-a3f8b2).
        if ( preg_match( '/-[a-f0-9]{5,}$/i', $slug ) ) {
            $flags[] = 'folder-hash-suffix';
        }

        return $flags;
    }
}
