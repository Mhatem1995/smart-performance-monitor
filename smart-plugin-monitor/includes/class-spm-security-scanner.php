<?php
/**
 * Deep Security Scanner for Smart Plugin Monitor.
 *
 * Recursively scans plugin files for dangerous function calls,
 * suspicious remote URLs, obfuscation patterns, and file-system
 * operations. Generates a per-plugin security score (0–100).
 *
 * Results are cached in a transient keyed per plugin to avoid
 * repeated filesystem scans.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Security_Scanner {

    /**
     * Maximum file size to scan (1 MB).
     */
    private const MAX_FILE_SIZE = 1048576;

    /**
     * Maximum total files to scan per plugin.
     */
    private const MAX_FILES = 500;

    /**
     * File extensions to scan.
     */
    private const SCAN_EXTENSIONS = [ 'php', 'inc', 'module' ];

    /**
     * Transient TTL: 6 hours.
     */
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /**
     * Dangerous function patterns.
     *
     * Each entry: regex pattern => [ label, severity, description ].
     * Severity: critical (10pts), high (7pts), medium (4pts), low (2pts).
     */
    private const FUNCTION_PATTERNS = [
        // ── Code execution ──
        'eval'            => [ 'eval()',            'critical', 'Executes arbitrary PHP code at runtime' ],
        'assert'          => [ 'assert()',          'high',     'Can execute code if string argument is passed' ],
        'create_function' => [ 'create_function()', 'critical', 'Creates anonymous function from string (deprecated)' ],
        'call_user_func'  => [ 'call_user_func()',  'low',      'Indirect function call — review for dynamic input' ],
        'preg_replace.*e' => [ 'preg_replace /e',   'critical', 'Executes replacement as PHP code via /e modifier' ],

        // ── Encoding / obfuscation ──
        'base64_decode'   => [ 'base64_decode()',   'high',     'Decodes base64 data — often used to hide payloads' ],
        'base64_encode'   => [ 'base64_encode()',   'low',      'Encodes data to base64' ],
        'gzinflate'       => [ 'gzinflate()',       'high',     'Decompresses data — common in obfuscated code' ],
        'gzuncompress'    => [ 'gzuncompress()',    'high',     'Decompresses data — common in obfuscated code' ],
        'gzdecode'        => [ 'gzdecode()',        'medium',   'Decompresses gzip data' ],
        'str_rot13'       => [ 'str_rot13()',       'high',     'ROT13 obfuscation — frequently used to hide strings' ],
        'convert_uudecode'=> [ 'convert_uudecode()','high',    'UU-decodes data — obfuscation technique' ],
        'hex2bin'         => [ 'hex2bin()',          'medium',   'Converts hex string to binary — review context' ],

        // ── System / shell ──
        'shell_exec'      => [ 'shell_exec()',      'critical', 'Executes system commands via shell' ],
        'exec'            => [ 'exec()',            'critical', 'Executes an external program' ],
        'system'          => [ 'system()',          'critical', 'Executes external program and displays output' ],
        'passthru'        => [ 'passthru()',        'critical', 'Executes program and passes raw output' ],
        'proc_open'       => [ 'proc_open()',       'critical', 'Opens process file pointer — full shell access' ],
        'popen'           => [ 'popen()',           'high',     'Opens a pipe to a process' ],
        'pcntl_exec'      => [ 'pcntl_exec()',      'critical', 'Executes program in current process space' ],

        // ── File operations ──
        'file_put_contents' => [ 'file_put_contents()', 'medium', 'Writes data to file — verify target path' ],
        'fwrite'            => [ 'fwrite()',            'low',     'Writes to file handle — common but review context' ],
        'file_get_contents' => [ 'file_get_contents()', 'low',     'Reads file or URL — check for remote usage' ],
        'curl_exec'         => [ 'curl_exec()',         'low',     'Executes cURL session — verify remote targets' ],

        // ── Misc ──
        'unserialize'     => [ 'unserialize()',     'medium',   'Deserializes data — potential object injection risk' ],
        'extract'         => [ 'extract()',         'medium',   'Imports variables — can overwrite existing scope' ],
    ];

    /**
     * Suspicious URL patterns (remote communication).
     */
    private const URL_PATTERNS = [
        '/https?:\/\/[^\s\'"]+\.ru\b/i'                      => 'Russian domain (.ru)',
        '/https?:\/\/[^\s\'"]+\.cn\b/i'                      => 'Chinese domain (.cn)',
        '/https?:\/\/[^\s\'"]+\.tk\b/i'                      => 'Tokelau domain (.tk) — commonly abused',
        '/https?:\/\/[^\s\'"]+\.xyz\b/i'                      => 'XYZ domain — commonly abused',
        '/https?:\/\/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/i'  => 'Direct IP address URL',
        '/https?:\/\/[^\s\'"]*pastebin\.com/i'                => 'Pastebin URL — external payload source',
        '/https?:\/\/[^\s\'"]*bit\.ly/i'                      => 'URL shortener (bit.ly)',
        '/https?:\/\/[^\s\'"]*tinyurl\.com/i'                 => 'URL shortener (tinyurl)',
    ];

    /**
     * Severity point values (deducted from 100).
     */
    private const SEVERITY_POINTS = [
        'critical' => 10,
        'high'     => 7,
        'medium'   => 4,
        'low'      => 2,
    ];

    /**
     * Run a deep security scan on a single plugin.
     *
     * @param string $basename  Plugin basename (e.g. "akismet/akismet.php").
     * @param bool   $force     Bypass transient cache.
     *
     * @return array Scan report.
     */
    public function scan_plugin( string $basename, bool $force = false ): array {
        $cache_key = 'spm_sec_' . md5( $basename );

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $basename );

        // Single-file plugin (no folder).
        if ( dirname( $basename ) === '.' ) {
            $plugin_dir = WP_PLUGIN_DIR;
        }

        if ( ! is_dir( $plugin_dir ) ) {
            return $this->empty_report( $basename, 'Plugin directory not found.' );
        }

        $files    = $this->collect_files( $plugin_dir );
        $findings = [];
        $stats    = [
            'files_scanned'    => 0,
            'total_lines'      => 0,
            'functions_found'  => 0,
            'urls_found'       => 0,
            'critical_count'   => 0,
            'high_count'       => 0,
            'medium_count'     => 0,
            'low_count'        => 0,
        ];

        foreach ( $files as $file_path ) {
            $content = @file_get_contents( $file_path ); // phpcs:ignore
            if ( false === $content ) {
                continue;
            }

            $stats['files_scanned']++;
            $line_count = substr_count( $content, "\n" ) + 1;
            $stats['total_lines'] += $line_count;

            $relative = str_replace( wp_normalize_path( WP_PLUGIN_DIR ) . '/', '', wp_normalize_path( $file_path ?? '' ) );

            // ── Scan for dangerous functions ──
            foreach ( self::FUNCTION_PATTERNS as $pattern => $meta ) {
                $regex = '/\b' . $pattern . '\s*\(/i';
                if ( preg_match_all( $regex, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
                    foreach ( $matches[0] as $match ) {
                        $line_num = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;

                        // Extract context (the line).
                        $lines   = explode( "\n", $content );
                        $context = isset( $lines[ $line_num - 1 ] ) ? trim( $lines[ $line_num - 1 ] ) : '';

                        $findings[] = [
                            'type'        => 'function',
                            'label'       => $meta[0],
                            'severity'    => $meta[1],
                            'description' => $meta[2],
                            'file'        => $relative,
                            'line'        => $line_num,
                            'context'     => mb_substr( $context, 0, 200 ),
                        ];

                        $stats['functions_found']++;
                        $stats[ $meta[1] . '_count' ]++;
                    }
                }
            }

            // ── Scan for suspicious URLs ──
            foreach ( self::URL_PATTERNS as $regex => $label ) {
                if ( preg_match_all( $regex, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
                    foreach ( $matches[0] as $match ) {
                        $line_num = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;
                        $url_text = mb_substr( $match[0], 0, 120 );

                        $findings[] = [
                            'type'        => 'url',
                            'label'       => $label,
                            'severity'    => 'medium',
                            'description' => 'Suspicious remote URL detected',
                            'file'        => $relative,
                            'line'        => $line_num,
                            'context'     => $url_text,
                        ];

                        $stats['urls_found']++;
                        $stats['medium_count']++;
                    }
                }
            }

            // ── Detect long encoded strings (obfuscation indicator) ──
            if ( preg_match( '/[A-Za-z0-9+\/=]{200,}/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
                $line_num = substr_count( substr( $content, 0, $m[0][1] ), "\n" ) + 1;

                $findings[] = [
                    'type'        => 'obfuscation',
                    'label'       => 'Long encoded string',
                    'severity'    => 'high',
                    'description' => 'A string of 200+ base64-like characters — may contain hidden payload',
                    'file'        => $relative,
                    'line'        => $line_num,
                    'context'     => mb_substr( $m[0][0], 0, 80 ) . '…',
                ];

                $stats['high_count']++;
            }
        }

        // ── Calculate security score ──
        $deduction = 0;
        foreach ( $findings as $f ) {
            $deduction += self::SEVERITY_POINTS[ $f['severity'] ] ?? 2;
        }

        $score = max( 0, min( 100, 100 - $deduction ) );

        // Determine risk level.
        if ( $score >= 90 ) {
            $risk_level = 'clean';
        } elseif ( $score >= 70 ) {
            $risk_level = 'low';
        } elseif ( $score >= 40 ) {
            $risk_level = 'medium';
        } else {
            $risk_level = 'high';
        }

        // Sort findings: critical first, then high, medium, low.
        $order = [ 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3 ];
        usort( $findings, fn( $a, $b ) =>
            ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 )
        );

        $report = [
            'basename'       => $basename,
            'scanned_at'     => current_time( 'mysql' ),
            'score'          => $score,
            'risk_level'     => $risk_level,
            'stats'          => $stats,
            'findings'       => $findings,
            'findings_count' => count( $findings ),
        ];

        set_transient( $cache_key, $report, self::CACHE_TTL );

        return $report;
    }

    /**
     * Collect scannable PHP files recursively.
     *
     * @param string $dir Root directory.
     *
     * @return string[] Absolute file paths.
     */
    private function collect_files( string $dir ): array {
        $files = [];
        $count = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $count >= self::MAX_FILES ) {
                break;
            }

            if ( ! $file->isFile() ) {
                continue;
            }

            $ext = strtolower( $file->getExtension() );
            if ( ! in_array( $ext, self::SCAN_EXTENSIONS, true ) ) {
                continue;
            }

            if ( $file->getSize() > self::MAX_FILE_SIZE ) {
                continue;
            }

            $files[] = $file->getPathname();
            $count++;
        }

        return $files;
    }

    /**
     * Return an empty report for edge cases.
     *
     * @param string $basename Plugin basename.
     * @param string $reason   Reason for empty report.
     *
     * @return array
     */
    private function empty_report( string $basename, string $reason ): array {
        return [
            'basename'       => $basename,
            'scanned_at'     => current_time( 'mysql' ),
            'score'          => 100,
            'risk_level'     => 'clean',
            'stats'          => [
                'files_scanned'   => 0,
                'total_lines'     => 0,
                'functions_found' => 0,
                'urls_found'      => 0,
                'critical_count'  => 0,
                'high_count'      => 0,
                'medium_count'    => 0,
                'low_count'       => 0,
            ],
            'findings'       => [],
            'findings_count' => 0,
            'note'           => $reason,
        ];
    }
}
