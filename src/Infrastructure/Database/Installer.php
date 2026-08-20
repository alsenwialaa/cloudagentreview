<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

final class Installer
{
    public const CLEANUP_HOOK = 'ysai_daily_cleanup';
    public const CLEANUP_RETRY_OPTION = 'ysai_cleanup_schedule_retry_after';
    public const CLEANUP_LOG_OPTION = 'ysai_cleanup_schedule_log_after';
    public const CLEANUP_REASON_OPTION = 'ysai_cleanup_schedule_failure_reason';
    public const CLEANUP_RETRY_SECONDS = 3600;

    private static string $cleanupFailureReason = 'schedule_rejected';

    public static function activate(): void
    {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (PHP_VERSION_ID < 80300) {
            deactivate_plugins(plugin_basename(YSAI_PLUGIN_FILE));
            wp_die(esc_html__('Yassin AI Assistant requires PHP 8.3 or later.', 'yassin-ai-assistant'));
        }
        if (is_multisite()) {
            deactivate_plugins(plugin_basename(YSAI_PLUGIN_FILE));
            wp_die(esc_html__('Yassin AI Assistant supports single-site WordPress installations only.', 'yassin-ai-assistant'));
        }
        if (!class_exists('WooCommerce') || !defined('WC_VERSION') || version_compare((string) WC_VERSION, '11.0.1', '<')) {
            deactivate_plugins(plugin_basename(YSAI_PLUGIN_FILE));
            wp_die(esc_html__('Yassin AI Assistant requires WooCommerce 11.0.1 or later.', 'yassin-ai-assistant'));
        }

        try {
            self::install();
        } catch (\Throwable) {
            deactivate_plugins(plugin_basename(YSAI_PLUGIN_FILE));
            wp_die(esc_html__(
                'Yassin AI Assistant could not create or verify its required database schema. Review the database permissions and error log, then activate the plugin again.',
                'yassin-ai-assistant'
            ));
        }

        // Cleanup scheduling is important but not a schema prerequisite. A
        // transient cron-option failure must not deactivate an otherwise
        // usable plugin; operator and maintenance contexts retry while the
        // administration interface surfaces a degraded-mode warning.
        self::ensureCleanupScheduled(true, true);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        self::clearCleanupFailureState();
    }

    public static function maybeInstall(bool $attemptCleanupScheduling = false): bool
    {
        if ((string) get_option(Schema::OPTION, '') !== Schema::VERSION) {
            self::install();
        }
        return self::ensureCleanupScheduled($attemptCleanupScheduling);
    }

    /**
     * Automatic cleanup remediation is deliberately restricted to operator or
     * maintenance contexts. A failed WordPress option write cannot provide a
     * durable backoff marker, so ordinary storefront traffic must never become
     * the retry loop for WP-Cron registration.
     */
    public static function automaticCleanupRemediationAllowed(): bool
    {
        if (defined('WP_CLI') && WP_CLI === true) {
            return true;
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }
        if (!function_exists('is_admin') || !is_admin()) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        return function_exists('current_user_can') && current_user_can('activate_plugins');
    }

    public static function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (Schema::statements() as $statement) {
            dbDelta($statement);
        }

        self::verifySchema();
        update_option(Schema::OPTION, Schema::VERSION, false);
        if ((string) get_option(Schema::OPTION, '') !== Schema::VERSION) {
            throw new \RuntimeException('Unable to persist the verified schema version.');
        }
    }

    public static function verifySchema(): void
    {
        global $wpdb;
        [$expectedCharset, $expectedCollation] = self::expectedTextEncoding();

        foreach (Schema::requirements() as $table => $requirement) {
            $identifier = self::identifier($table);
            $metadata = $wpdb->get_row($wpdb->prepare(
                'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ), ARRAY_A);
            if (!is_array($metadata)
                || !is_string($metadata['ENGINE'] ?? null)
                || strcasecmp($metadata['ENGINE'], 'InnoDB') !== 0
                || !is_string($metadata['TABLE_COLLATION'] ?? null)) {
                throw new \RuntimeException('A required plugin table has invalid storage metadata.');
            }
            $tableCollation = self::normalizeCollation($metadata['TABLE_COLLATION']);
            if ($tableCollation === ''
                || self::charsetFromCollation($tableCollation) !== $expectedCharset
                || ($expectedCollation !== null && $tableCollation !== $expectedCollation)) {
                throw new \RuntimeException('A required plugin table has an incompatible character set or collation.');
            }

            $columnRows = $wpdb->get_results("SHOW FULL COLUMNS FROM {$identifier}", ARRAY_A);
            if (!is_array($columnRows)) {
                throw new \RuntimeException('Unable to inspect a required plugin table.');
            }
            $columns = self::columns($columnRows);
            foreach ($requirement['columns'] as $name => $expected) {
                $actual = $columns[$name] ?? null;
                if (!is_array($actual)) {
                    throw new \RuntimeException('A required plugin database column is missing.');
                }
                $collationValid = $expected['textual']
                    ? $actual['collation'] === $tableCollation
                    : $actual['collation'] === null;
                if (preg_match($expected['type'], $actual['type']) !== 1
                    || $actual['nullable'] !== $expected['nullable']
                    || $actual['default'] !== $expected['default']
                    || $actual['auto_increment'] !== $expected['auto_increment']
                    || !$collationValid) {
                    throw new \RuntimeException('A required plugin database column is malformed.');
                }
            }

            $rows = $wpdb->get_results("SHOW INDEX FROM {$identifier}", ARRAY_A);
            if (!is_array($rows)) {
                throw new \RuntimeException('Unable to inspect required plugin indexes.');
            }
            $indexes = self::indexes($rows);
            foreach ($requirement['indexes'] as $name => $expected) {
                if (($indexes[$name] ?? null) !== $expected) {
                    throw new \RuntimeException('A required plugin index is missing or malformed.');
                }
            }
        }
    }

    public static function cleanupFailureReason(): string
    {
        try {
            $reason = get_option(self::CLEANUP_REASON_OPTION, self::$cleanupFailureReason);
        } catch (\Throwable) {
            return self::$cleanupFailureReason;
        }
        if (!is_string($reason) || preg_match('/^[a-z0-9_:-]{1,80}$/D', $reason) !== 1) {
            return self::$cleanupFailureReason;
        }
        return $reason;
    }

    public static function shouldLogCleanupFailure(): bool
    {
        $now = time();
        try {
            $nextLog = get_option(self::CLEANUP_LOG_OPTION, 0);
        } catch (\Throwable) {
            // Without a readable throttle there is no proof that a later
            // request will be suppressed. Keep the administration notice and
            // omit the diagnostic entry rather than risk a log storm.
            return false;
        }
        if ((is_int($nextLog) || (is_string($nextLog) && ctype_digit($nextLog)))
            && (int) $nextLog > $now) {
            return false;
        }

        // A log throttle that was not durably stored is not a throttle. In
        // that condition the administration notice remains available, but we
        // suppress the diagnostic entry instead of producing an unbounded log
        // storm on every eligible request.
        return self::persistOperationalOption(
            self::CLEANUP_LOG_OPTION,
            $now + self::CLEANUP_RETRY_SECONDS
        );
    }

    private static function ensureCleanupScheduled(
        bool $attemptScheduling,
        bool $ignoreBackoff = false
    ): bool
    {
        try {
            if (wp_next_scheduled(self::CLEANUP_HOOK)) {
                if ($attemptScheduling) {
                    self::clearCleanupFailureState();
                }
                return true;
            }

            if (!$attemptScheduling) {
                return false;
            }

            $now = time();
            $retryAfter = get_option(self::CLEANUP_RETRY_OPTION, 0);
            if (!$ignoreBackoff
                && (is_int($retryAfter) || (is_string($retryAfter) && ctype_digit($retryAfter)))
                && (int) $retryAfter > $now) {
                return false;
            }

            $scheduled = wp_schedule_event(
                $now + HOUR_IN_SECONDS,
                'daily',
                self::CLEANUP_HOOK,
                array(),
                true
            );
            if ($scheduled !== false
                && !(function_exists('is_wp_error') && is_wp_error($scheduled))) {
                self::clearCleanupFailureState();
                return true;
            }

            $reason = 'schedule_rejected';
            if (function_exists('is_wp_error') && is_wp_error($scheduled)) {
                $code = method_exists($scheduled, 'get_error_code')
                    ? strtolower((string) $scheduled->get_error_code())
                    : '';
                $code = preg_replace('/[^a-z0-9_-]+/', '_', $code);
                if (is_string($code) && $code !== '') {
                    $reason = 'wp_error:' . substr($code, 0, 60);
                }
            }
            self::recordCleanupFailure($now, $reason);
            return false;
        } catch (\Throwable) {
            self::$cleanupFailureReason = 'exception';
            if ($attemptScheduling) {
                self::recordCleanupFailure(time(), 'exception');
            }
            return false;
        }
    }

    private static function recordCleanupFailure(int $now, string $reason): void
    {
        self::$cleanupFailureReason = $reason;
        self::persistOperationalOption(
            self::CLEANUP_RETRY_OPTION,
            $now + self::CLEANUP_RETRY_SECONDS
        );
        self::persistOperationalOption(self::CLEANUP_REASON_OPTION, $reason);
    }

    private static function persistOperationalOption(string $name, int|string $value): bool
    {
        try {
            update_option($name, $value, false);
            $stored = get_option($name, null);
        } catch (\Throwable) {
            return false;
        }

        if (is_int($value)) {
            return (is_int($stored) || (is_string($stored) && ctype_digit($stored)))
                && (int) $stored === $value;
        }
        return is_string($stored) && hash_equals($value, $stored);
    }

    private static function clearCleanupFailureState(): void
    {
        self::$cleanupFailureReason = 'schedule_rejected';
        foreach (array(
            self::CLEANUP_RETRY_OPTION,
            self::CLEANUP_LOG_OPTION,
            self::CLEANUP_REASON_OPTION,
        ) as $option) {
            try {
                if (function_exists('delete_option')) {
                    delete_option($option);
                } else {
                    update_option($option, $option === self::CLEANUP_REASON_OPTION ? '' : 0, false);
                }
            } catch (\Throwable) {
                // Stale operational diagnostics must never turn an already
                // registered cleanup event into a runtime availability failure.
            }
        }
    }

    private static function identifier(string $table): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new \RuntimeException('The WordPress table prefix is not supported.');
        }
        return '`' . $table . '`';
    }

    /**
     * @param list<mixed> $rows
     * @return array<string,array{type:string,nullable:bool,default:string|null,auto_increment:bool,collation:string|null}>
     */
    private static function columns(array $rows): array
    {
        $columns = array();
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['Field'] ?? null)
                || !is_string($row['Type'] ?? null)
                || !is_string($row['Null'] ?? null)
                || !is_string($row['Extra'] ?? null)
                || !array_key_exists('Collation', $row)
                || ($row['Collation'] !== null && !is_string($row['Collation']))) {
                continue;
            }
            $name = $row['Field'];
            $nullability = strtoupper(trim($row['Null']));
            if ($name === '' || isset($columns[$name]) || !in_array($nullability, array('YES', 'NO'), true)) {
                continue;
            }
            $type = preg_replace('/\s+/', ' ', strtolower(trim($row['Type'])));
            if (!is_string($type) || $type === '') {
                continue;
            }
            $default = $row['Default'] ?? null;
            if ($default !== null && !is_scalar($default)) {
                continue;
            }
            $extra = strtolower(trim($row['Extra']));
            $collation = $row['Collation'] === null
                ? null
                : self::normalizeCollation($row['Collation']);
            if ($row['Collation'] !== null && $collation === '') {
                continue;
            }
            $columns[$name] = array(
                'type' => $type,
                'nullable' => $nullability === 'YES',
                'default' => $default === null ? null : (string) $default,
                'auto_increment' => preg_match('/(?:^|\s)auto_increment(?:\s|$)/D', $extra) === 1,
                'collation' => $collation,
            );
        }
        return $columns;
    }

    /** @return array{0:string,1:string|null} */
    private static function expectedTextEncoding(): array
    {
        global $wpdb;
        $charset = is_string($wpdb->charset ?? null) ? trim($wpdb->charset) : '';
        $collation = is_string($wpdb->collate ?? null) ? trim($wpdb->collate) : '';
        $clause = is_callable(array($wpdb, 'get_charset_collate'))
            ? (string) $wpdb->get_charset_collate()
            : '';

        if ($charset === ''
            && preg_match('/(?:DEFAULT\s+)?CHARACTER\s+SET\s+([A-Za-z0-9_]+)/i', $clause, $match) === 1) {
            $charset = $match[1];
        }
        if ($collation === ''
            && preg_match('/\bCOLLATE\s+([A-Za-z0-9_]+)/i', $clause, $match) === 1) {
            $collation = $match[1];
        }

        $charset = self::normalizeCharset($charset);
        $collation = $collation === '' ? null : self::normalizeCollation($collation);
        if ($charset === ''
            || ($collation !== null && self::charsetFromCollation($collation) !== $charset)) {
            throw new \RuntimeException('The WordPress database character set configuration is invalid.');
        }
        return array($charset, $collation);
    }

    private static function normalizeCharset(string $charset): string
    {
        $charset = strtolower(trim($charset));
        if (preg_match('/^[a-z0-9_]+$/D', $charset) !== 1) {
            return '';
        }
        return $charset === 'utf8' ? 'utf8mb3' : $charset;
    }

    private static function normalizeCollation(string $collation): string
    {
        $collation = strtolower(trim($collation));
        if (preg_match('/^[a-z0-9_]+$/D', $collation) !== 1) {
            return '';
        }
        return str_starts_with($collation, 'utf8_')
            ? 'utf8mb3_' . substr($collation, 5)
            : $collation;
    }

    private static function charsetFromCollation(string $collation): string
    {
        $separator = strpos($collation, '_');
        if ($separator === false || $separator < 1) {
            return '';
        }
        return self::normalizeCharset(substr($collation, 0, $separator));
    }

    /**
     * @param list<mixed> $rows
     * @return array<string,array{unique:bool,columns:list<string>}>
     */
    private static function indexes(array $rows): array
    {
        $grouped = array();
        $invalid = array();
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['Key_name'] ?? null)
                || !is_string($row['Column_name'] ?? null)
                || !is_string($row['Index_type'] ?? null)) {
                continue;
            }
            $name = $row['Key_name'];
            $sequence = filter_var($row['Seq_in_index'] ?? null, FILTER_VALIDATE_INT);
            $nonUnique = filter_var($row['Non_unique'] ?? null, FILTER_VALIDATE_INT);
            $visible = strtoupper(trim((string) ($row['Visible'] ?? 'YES')));
            $ignored = strtoupper(trim((string) ($row['Ignored'] ?? 'NO')));
            if ($name === ''
                || !is_int($sequence)
                || $sequence < 1
                || !is_int($nonUnique)
                || !in_array($nonUnique, array(0, 1), true)
                || strtoupper(trim($row['Index_type'])) !== 'BTREE'
                || !in_array($visible, array('YES', ''), true)
                || !in_array($ignored, array('NO', ''), true)
                || ($row['Sub_part'] ?? null) !== null
                || (($row['Expression'] ?? null) !== null && ($row['Expression'] ?? '') !== '')) {
                if ($name !== '') {
                    $invalid[$name] = true;
                }
                continue;
            }
            if (isset($grouped[$name]['columns'][$sequence])) {
                $invalid[$name] = true;
                continue;
            }
            if (isset($grouped[$name]['unique']) && $grouped[$name]['unique'] !== ($nonUnique === 0)) {
                $invalid[$name] = true;
                continue;
            }
            $grouped[$name]['unique'] = $nonUnique === 0;
            $grouped[$name]['columns'][$sequence] = $row['Column_name'];
        }

        $indexes = array();
        foreach ($grouped as $name => $definition) {
            if (isset($invalid[$name]) || !isset($definition['unique'], $definition['columns'])) {
                continue;
            }
            $columns = $definition['columns'];
            ksort($columns, SORT_NUMERIC);
            if (array_keys($columns) !== range(1, count($columns))) {
                continue;
            }
            $indexes[(string) $name] = array(
                'unique' => $definition['unique'],
                'columns' => array_values($columns),
            );
        }
        return $indexes;
    }

}
