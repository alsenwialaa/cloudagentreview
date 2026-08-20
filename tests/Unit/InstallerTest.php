<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Database\Installer;
use YassinStore\AiAssistant\Infrastructure\Database\Schema;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ABSPATH')) {
    $root = sys_get_temp_dir() . '/ysai-installer-tests/';
    @mkdir($root . 'wp-admin/includes', 0777, true);
    file_put_contents($root . 'wp-admin/includes/upgrade.php', "<?php\n");
    define('ABSPATH', $root);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!function_exists('dbDelta')) {
    function dbDelta(string $statement): array
    {
        $GLOBALS['ysai_test_dbdelta'][] = $statement;
        return array();
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook): int|false
    {
        assert_same(Installer::CLEANUP_HOOK, $hook);
        if ((bool) ($GLOBALS['ysai_test_cleanup_next_scheduled_exception'] ?? false)) {
            throw new RuntimeException('Cron option read failed.');
        }
        return $GLOBALS['ysai_test_cleanup_scheduled'] ?? false;
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(
        int $timestamp,
        string $recurrence,
        string $hook,
        array $args = array(),
        bool $wpError = false
    ): bool|WP_Error {
        ++$GLOBALS['ysai_test_cleanup_schedule_calls'];
        assert_true($timestamp >= time());
        assert_same('daily', $recurrence);
        assert_same(Installer::CLEANUP_HOOK, $hook);
        assert_same(array(), $args);
        assert_same(true, $wpError);
        return $GLOBALS['ysai_test_cleanup_schedule_result'] ?? true;
    }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return (bool) ($GLOBALS['ysai_test_is_admin'] ?? false);
    }
}
if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron(): bool
    {
        return (bool) ($GLOBALS['ysai_test_doing_cron'] ?? false);
    }
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool
    {
        return (bool) ($GLOBALS['ysai_test_doing_ajax'] ?? false);
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return $capability === 'activate_plugins'
            && (bool) ($GLOBALS['ysai_test_can_activate_plugins'] ?? false);
    }
}

final class FakeWpdbInstaller
{
    public string $prefix = 'wp_';
    public string $charset = 'utf8mb4';
    public string $collate = 'utf8mb4_unicode_ci';
    /** @var array<string,list<string>> */
    public array $columns = array();
    /** @var array<string,list<array<string,mixed>>> */
    public array $columnRows = array();
    /** @var array<string,list<array<string,mixed>>> */
    public array $indexes = array();
    /** @var array<string,string> */
    public array $engines = array();
    /** @var array<string,string> */
    public array $tableCollations = array();

    public function loadSchema(): void
    {
        $typeCandidates = array(
            'char(36)', 'char(64)', 'bigint(20) unsigned', 'int(10) unsigned',
            'datetime', 'longtext', 'varchar(16)', 'varchar(40)', 'varchar(64)',
        );
        foreach (Schema::requirements() as $table => $requirement) {
            $this->columns[$table] = array_keys($requirement['columns']);
            $columnRows = array();
            foreach ($requirement['columns'] as $name => $expected) {
                $type = null;
                foreach ($typeCandidates as $candidate) {
                    if (preg_match($expected['type'], $candidate) === 1) {
                        $type = $candidate;
                        break;
                    }
                }
                if ($type === null) {
                    throw new RuntimeException('Test fixture could not resolve a schema type.');
                }
                $columnRows[] = array(
                    'Field' => $name,
                    'Type' => $type,
                    'Null' => $expected['nullable'] ? 'YES' : 'NO',
                    'Default' => $expected['default'],
                    'Extra' => $expected['auto_increment'] ? 'auto_increment' : '',
                    'Collation' => $expected['textual'] ? $this->collate : null,
                );
            }
            $this->columnRows[$table] = $columnRows;

            $rows = array();
            foreach ($requirement['indexes'] as $name => $definition) {
                foreach ($definition['columns'] as $offset => $column) {
                    $rows[] = array(
                        'Key_name' => $name,
                        'Column_name' => $column,
                        'Seq_in_index' => $offset + 1,
                        'Non_unique' => $definition['unique'] ? 0 : 1,
                        'Index_type' => 'BTREE',
                        'Visible' => 'YES',
                        'Ignored' => 'NO',
                        'Sub_part' => null,
                        'Expression' => null,
                    );
                }
            }
            $this->indexes[$table] = $rows;
            $this->engines[$table] = 'InnoDB';
            $this->tableCollations[$table] = $this->collate;
        }
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET ' . $this->charset . ' COLLATE ' . $this->collate;
    }

    /** @return list<string> */
    public function get_col(string $query, int $column = 0): array
    {
        return $this->columns[$this->tableFromInspection($query)] ?? array();
    }

    /** @return list<array<string,mixed>> */
    public function get_results(string $query, string $format): array
    {
        assert_same(ARRAY_A, $format);
        $table = $this->tableFromInspection($query);
        if (str_starts_with($query, 'SHOW FULL COLUMNS')) {
            $allowed = array_fill_keys($this->columns[$table] ?? array(), true);
            return array_values(array_filter(
                $this->columnRows[$table] ?? array(),
                static fn (array $row): bool => isset($allowed[$row['Field'] ?? ''])
            ));
        }
        return $this->indexes[$table] ?? array();
    }

    public function prepare(string $query, mixed ...$args): string
    {
        return 'table:' . (string) ($args[0] ?? '');
    }

    /** @return array<string,mixed>|null */
    public function get_row(string $query, string $format): ?array
    {
        assert_same(ARRAY_A, $format);
        if (!str_starts_with($query, 'table:')) {
            return null;
        }
        $table = substr($query, 6);
        if (!isset($this->engines[$table], $this->tableCollations[$table])) {
            return null;
        }
        return array(
            'ENGINE' => $this->engines[$table],
            'TABLE_COLLATION' => $this->tableCollations[$table],
        );
    }

    private function tableFromInspection(string $query): string
    {
        if (preg_match('/`([A-Za-z0-9_]+)`/D', $query, $matches) !== 1) {
            return '';
        }
        return $matches[1];
    }
}

/** @return FakeWpdbInstaller */
function fresh_installer_database(): FakeWpdbInstaller
{
    $GLOBALS['ysai_test_options'] = array();
    $GLOBALS['ysai_test_option_write_failures'] = array();
    $GLOBALS['ysai_test_option_write_calls'] = array();
    $GLOBALS['ysai_test_option_read_exceptions'] = array();
    $GLOBALS['ysai_test_option_delete_exceptions'] = array();
    $GLOBALS['ysai_test_option_delete_calls'] = array();
    $GLOBALS['ysai_test_dbdelta'] = array();
    $GLOBALS['ysai_test_cleanup_scheduled'] = false;
    $GLOBALS['ysai_test_cleanup_schedule_calls'] = 0;
    $GLOBALS['ysai_test_cleanup_schedule_result'] = true;
    $GLOBALS['ysai_test_cleanup_next_scheduled_exception'] = false;
    $GLOBALS['ysai_test_is_admin'] = false;
    $GLOBALS['ysai_test_doing_cron'] = false;
    $GLOBALS['ysai_test_doing_ajax'] = false;
    $GLOBALS['ysai_test_can_activate_plugins'] = false;
    $wpdb = new FakeWpdbInstaller();
    $GLOBALS['wpdb'] = $wpdb;
    $wpdb->loadSchema();
    return $wpdb;
}

test('Installer treats cleanup scheduling failure as degraded instead of a schema failure', static function (): void {
    fresh_installer_database();
    $GLOBALS['ysai_test_options'][Schema::OPTION] = Schema::VERSION;
    $GLOBALS['ysai_test_cleanup_schedule_result'] = false;

    assert_same(false, Installer::maybeInstall(true));
    assert_same(1, $GLOBALS['ysai_test_cleanup_schedule_calls']);
    assert_same(Schema::VERSION, get_option(Schema::OPTION, ''));
    assert_true((int) get_option(Installer::CLEANUP_RETRY_OPTION, 0) > time());
    assert_same('schedule_rejected', Installer::cleanupFailureReason());

    // Ordinary plugin boots inside the backoff window remain degraded without
    // hammering the cron option or emitting another scheduling write.
    $GLOBALS['ysai_test_cleanup_schedule_result'] = true;
    assert_same(false, Installer::maybeInstall(true));
    assert_same(1, $GLOBALS['ysai_test_cleanup_schedule_calls']);

    $GLOBALS['ysai_test_options'][Installer::CLEANUP_RETRY_OPTION] = time() - 1;
    assert_same(true, Installer::maybeInstall(true));
    assert_same(2, $GLOBALS['ysai_test_cleanup_schedule_calls']);
    assert_same(false, array_key_exists(Installer::CLEANUP_RETRY_OPTION, $GLOBALS['ysai_test_options']));
    assert_same(false, array_key_exists(Installer::CLEANUP_REASON_OPTION, $GLOBALS['ysai_test_options']));

    $GLOBALS['ysai_test_cleanup_scheduled'] = time() + HOUR_IN_SECONDS;
    assert_same(true, Installer::maybeInstall(false));
    assert_same(2, $GLOBALS['ysai_test_cleanup_schedule_calls']);
});

test('Installer logs a persistent cleanup scheduling failure at most once per retry window', static function (): void {
    fresh_installer_database();
    $GLOBALS['ysai_test_options'][Schema::OPTION] = Schema::VERSION;
    $GLOBALS['ysai_test_cleanup_schedule_result'] = new WP_Error('cron option denied!');

    assert_same(false, Installer::maybeInstall(true));
    assert_same('wp_error:cron_option_denied_', Installer::cleanupFailureReason());
    assert_same(true, Installer::shouldLogCleanupFailure());
    assert_same(false, Installer::shouldLogCleanupFailure());

    $GLOBALS['ysai_test_options'][Installer::CLEANUP_LOG_OPTION] = time() - 1;
    assert_same(true, Installer::shouldLogCleanupFailure());
});

test('Installer never uses ordinary storefront traffic as cleanup scheduling remediation', static function (): void {
    fresh_installer_database();
    $GLOBALS['ysai_test_options'][Schema::OPTION] = Schema::VERSION;
    $GLOBALS['ysai_test_cleanup_schedule_result'] = false;
    $GLOBALS['ysai_test_option_write_failures'] = array(
        Installer::CLEANUP_RETRY_OPTION,
        Installer::CLEANUP_LOG_OPTION,
        Installer::CLEANUP_REASON_OPTION,
    );

    for ($request = 0; $request < 5; ++$request) {
        assert_same(false, Installer::maybeInstall(false));
    }
    assert_same(0, $GLOBALS['ysai_test_cleanup_schedule_calls']);

    // A privileged maintenance request may attempt remediation, but failure to
    // persist the backoff cannot turn later storefront requests into retries or
    // generate an unthrottled diagnostic log.
    assert_same(false, Installer::maybeInstall(true));
    assert_same(1, $GLOBALS['ysai_test_cleanup_schedule_calls']);
    assert_same(false, Installer::shouldLogCleanupFailure());

    for ($request = 0; $request < 5; ++$request) {
        assert_same(false, Installer::maybeInstall(false));
    }
    assert_same(1, $GLOBALS['ysai_test_cleanup_schedule_calls']);
});

test('Installer suppresses cleanup diagnostics when operational option reads fail', static function (): void {
    fresh_installer_database();
    $GLOBALS['ysai_test_options'][Schema::OPTION] = Schema::VERSION;
    $GLOBALS['ysai_test_cleanup_schedule_result'] = new WP_Error('cron option denied!');

    assert_same(false, Installer::maybeInstall(true));
    $GLOBALS['ysai_test_option_read_exceptions'] = array(
        Installer::CLEANUP_LOG_OPTION,
        Installer::CLEANUP_REASON_OPTION,
    );

    assert_same(false, Installer::shouldLogCleanupFailure());
    assert_same('wp_error:cron_option_denied_', Installer::cleanupFailureReason());
});

test('Installer treats stale cleanup-diagnostic deletion as best effort after schedule recovery', static function (): void {
    fresh_installer_database();
    $GLOBALS['ysai_test_options'][Schema::OPTION] = Schema::VERSION;
    $GLOBALS['ysai_test_cleanup_scheduled'] = time() + HOUR_IN_SECONDS;
    $GLOBALS['ysai_test_option_delete_exceptions'] = array(
        Installer::CLEANUP_RETRY_OPTION,
        Installer::CLEANUP_LOG_OPTION,
        Installer::CLEANUP_REASON_OPTION,
    );

    assert_same(true, Installer::maybeInstall(true));
    assert_same(0, $GLOBALS['ysai_test_cleanup_schedule_calls']);
    assert_same(
        array(
            Installer::CLEANUP_RETRY_OPTION,
            Installer::CLEANUP_LOG_OPTION,
            Installer::CLEANUP_REASON_OPTION,
        ),
        $GLOBALS['ysai_test_option_delete_calls']
    );
});

test('Installer restricts automatic cleanup remediation to operator and maintenance contexts', static function (): void {
    fresh_installer_database();

    assert_same(false, Installer::automaticCleanupRemediationAllowed());

    $GLOBALS['ysai_test_is_admin'] = true;
    assert_same(false, Installer::automaticCleanupRemediationAllowed());

    $GLOBALS['ysai_test_can_activate_plugins'] = true;
    assert_same(true, Installer::automaticCleanupRemediationAllowed());

    $GLOBALS['ysai_test_doing_ajax'] = true;
    assert_same(false, Installer::automaticCleanupRemediationAllowed());

    $GLOBALS['ysai_test_is_admin'] = false;
    $GLOBALS['ysai_test_doing_ajax'] = false;
    $GLOBALS['ysai_test_can_activate_plugins'] = false;
    $GLOBALS['ysai_test_doing_cron'] = true;
    assert_same(true, Installer::automaticCleanupRemediationAllowed());
});

test('Installer keeps the storefront cleanup path read-only when cron inspection throws', static function (): void {
    fresh_installer_database();
    $GLOBALS['ysai_test_options'][Schema::OPTION] = Schema::VERSION;
    $GLOBALS['ysai_test_cleanup_next_scheduled_exception'] = true;

    assert_same(false, Installer::maybeInstall(false));
    assert_same(array(), $GLOBALS['ysai_test_option_write_calls']);
    assert_same(0, $GLOBALS['ysai_test_cleanup_schedule_calls']);

    assert_same(false, Installer::maybeInstall(true));
    assert_same(
        array(Installer::CLEANUP_RETRY_OPTION, Installer::CLEANUP_REASON_OPTION),
        $GLOBALS['ysai_test_option_write_calls']
    );
});

test('Installer publishes the schema version only after verifying columns, indexes, InnoDB, and text encoding', static function (): void {
    fresh_installer_database();

    Installer::install();

    assert_same(Schema::VERSION, get_option(Schema::OPTION, ''));
    assert_count_value(4, $GLOBALS['ysai_test_dbdelta']);
    $ddl = implode("\n", $GLOBALS['ysai_test_dbdelta']);
    assert_contains('claim_version bigint(20) unsigned NOT NULL DEFAULT 1', $ddl);
    assert_contains('lease_seconds int(10) unsigned NOT NULL DEFAULT 1200', $ddl);
    assert_contains("lifecycle_state varchar(16) NOT NULL DEFAULT 'active'", $ddl);
});

test('Installer refuses an incomplete lifecycle or persisted-lease migration', static function (): void {
    $wpdb = fresh_installer_database();
    $conversationTable = Schema::conversations();
    $wpdb->columns[$conversationTable] = array_values(array_filter(
        $wpdb->columns[$conversationTable],
        static fn (string $column): bool => $column !== 'lifecycle_state'
    ));
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    $turnTable = Schema::turns();
    $wpdb->columns[$turnTable] = array_values(array_filter(
        $wpdb->columns[$turnTable],
        static fn (string $column): bool => $column !== 'lease_seconds'
    ));
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));
});

test('Installer refuses to publish an incomplete claim-fencing migration', static function (): void {
    $wpdb = fresh_installer_database();
    $turns = Schema::turns();
    $wpdb->columns[$turns] = array_values(array_filter(
        $wpdb->columns[$turns],
        static fn (string $column): bool => $column !== 'claim_version'
    ));

    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));
});

test('Installer refuses malformed uniqueness and non-transactional table engines', static function (): void {
    $wpdb = fresh_installer_database();
    $turns = Schema::turns();
    foreach ($wpdb->indexes[$turns] as &$row) {
        if (($row['Key_name'] ?? '') === 'conversation_turn') {
            $row['Non_unique'] = 1;
        }
    }
    unset($row);

    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    $wpdb->engines[Schema::messages()] = 'MyISAM';
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));
});

test('Installer accepts the MySQL utf8mb3 alias for a WordPress utf8 configuration', static function (): void {
    $wpdb = fresh_installer_database();
    $wpdb->charset = 'utf8';
    $wpdb->collate = 'utf8_general_ci';
    foreach (array_keys($wpdb->tableCollations) as $table) {
        $wpdb->tableCollations[$table] = 'utf8mb3_general_ci';
        foreach ($wpdb->columnRows[$table] as &$row) {
            if (($row['Collation'] ?? null) !== null) {
                $row['Collation'] = 'utf8mb3_general_ci';
            }
        }
        unset($row);
    }

    Installer::install();
    assert_same(Schema::VERSION, get_option(Schema::OPTION, ''));
});

test('Installer rejects table and column character-set or collation drift', static function (): void {
    $wpdb = fresh_installer_database();
    $wpdb->tableCollations[Schema::messages()] = 'latin1_swedish_ci';
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    $wpdb->tableCollations[Schema::messages()] = 'utf8mb4_general_ci';
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    foreach ($wpdb->columnRows[Schema::messages()] as &$row) {
        if (($row['Field'] ?? '') === 'content') {
            $row['Collation'] = 'utf8mb4_general_ci';
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    foreach ($wpdb->columnRows[Schema::turns()] as &$row) {
        if (($row['Field'] ?? '') === 'claim_version') {
            $row['Collation'] = 'utf8mb4_unicode_ci';
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));
});

test('Installer rejects wrong column types, signed counters, and nullability drift', static function (): void {
    $wpdb = fresh_installer_database();
    foreach ($wpdb->columnRows[Schema::turns()] as &$row) {
        if (($row['Field'] ?? '') === 'claim_version') {
            $row['Type'] = 'bigint(20)';
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    foreach ($wpdb->columnRows[Schema::messages()] as &$row) {
        if (($row['Field'] ?? '') === 'content') {
            $row['Null'] = 'YES';
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));
});

test('Installer rejects default and auto-increment drift in persistence identity columns', static function (): void {
    $wpdb = fresh_installer_database();
    foreach ($wpdb->columnRows[Schema::turns()] as &$row) {
        if (($row['Field'] ?? '') === 'claim_version') {
            $row['Default'] = '2';
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    foreach ($wpdb->columnRows[Schema::messages()] as &$row) {
        if (($row['Field'] ?? '') === 'id') {
            $row['Extra'] = '';
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));
});

test('Installer verifies operational indexes, full-width keys, uniqueness, and column order', static function (): void {
    $wpdb = fresh_installer_database();
    $wpdb->indexes[Schema::turns()] = array_values(array_filter(
        $wpdb->indexes[Schema::turns()],
        static fn (array $row): bool => ($row['Key_name'] ?? '') !== 'updated_at'
    ));
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    foreach ($wpdb->indexes[Schema::messages()] as &$row) {
        if (($row['Key_name'] ?? '') === 'conversation_id' && (int) ($row['Seq_in_index'] ?? 0) === 1) {
            $row['Sub_part'] = 12;
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    foreach ($wpdb->indexes[Schema::turns()] as &$row) {
        if (($row['Key_name'] ?? '') === 'conversation_turn') {
            $row['Seq_in_index'] = (int) $row['Seq_in_index'] === 1 ? 2 : 1;
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    foreach ($wpdb->indexes[Schema::messages()] as &$row) {
        if (($row['Key_name'] ?? '') === 'turn_id') {
            $row['Index_type'] = 'FULLTEXT';
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));

    $wpdb = fresh_installer_database();
    foreach ($wpdb->indexes[Schema::rateLimits()] as &$row) {
        if (($row['Key_name'] ?? '') === 'window_ends_at') {
            $row['Visible'] = 'NO';
        }
    }
    unset($row);
    assert_throws(RuntimeException::class, static fn (): null => (Installer::install() ?? null));
    assert_same('', get_option(Schema::OPTION, ''));
});
