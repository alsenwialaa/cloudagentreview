<?php

declare(strict_types=1);

/** @return array{hooks:list<string>,queries:list<string>,deleted:list<string>} */
function run_uninstall_scenario(mixed $options, string $prefix = 'wp_'): array
{
    $uninstall = dirname(__DIR__, 2) . '/uninstall.php';
    $script = <<<'PHP'
<?php

declare(strict_types=1);

define('WP_UNINSTALL_PLUGIN', true);
$GLOBALS['test_options'] = __OPTIONS__;
$GLOBALS['test_hooks'] = array();
$GLOBALS['test_deleted'] = array();

function get_option(string $name, mixed $default = null): mixed
{
    return $name === 'ysai_options' ? $GLOBALS['test_options'] : $default;
}

function wp_clear_scheduled_hook(string $hook): void
{
    $GLOBALS['test_hooks'][] = $hook;
}

function delete_option(string $name): void
{
    $GLOBALS['test_deleted'][] = $name;
}

final class UninstallWpdb
{
    public string $prefix = __PREFIX__;
    /** @var list<string> */
    public array $queries = array();

    public function query(string $query): int
    {
        $this->queries[] = $query;
        return 1;
    }
}

$wpdb = new UninstallWpdb();
include __UNINSTALL__;
echo json_encode(array(
    'hooks' => $GLOBALS['test_hooks'],
    'queries' => $wpdb->queries,
    'deleted' => $GLOBALS['test_deleted'],
), JSON_THROW_ON_ERROR);
PHP;

    $script = str_replace(
        array('__OPTIONS__', '__PREFIX__', '__UNINSTALL__'),
        array(var_export($options, true), var_export($prefix, true), var_export($uninstall, true)),
        $script
    );

    $path = tempnam(sys_get_temp_dir(), 'ysai-uninstall-');
    if (!is_string($path)) {
        throw new RuntimeException('Unable to create an uninstall test script.');
    }
    file_put_contents($path, $script);

    $pipes = array();
    $process = proc_open(
        array(PHP_BINARY, $path),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) {
        @unlink($path);
        throw new RuntimeException('Unable to start the uninstall test process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    @unlink($path);

    if ($status !== 0 || !is_string($stdout)) {
        throw new RuntimeException('Uninstall test process failed: ' . (is_string($stderr) ? $stderr : 'unknown error'));
    }
    $decoded = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Uninstall test process returned an invalid result.');
    }
    return $decoded;
}

test('Uninstall always clears scheduled cleanup but rejects malformed destructive flags', static function (): void {
    foreach (array(
        array('delete_data_on_uninstall' => array('unexpected')),
        array('delete_data_on_uninstall' => 'yes'),
        array('delete_data_on_uninstall' => 2),
        'corrupt-option',
    ) as $options) {
        $result = run_uninstall_scenario($options);
        assert_same(array('ysai_daily_cleanup'), $result['hooks']);
        assert_same(array(), $result['queries']);
        assert_same(array(
            'ysai_cleanup_schedule_retry_after',
            'ysai_cleanup_schedule_log_after',
            'ysai_cleanup_schedule_failure_reason',
        ), $result['deleted']);
    }
});

test('Uninstall deletes only the current rewrite tables when explicitly enabled', static function (): void {
    $result = run_uninstall_scenario(array('delete_data_on_uninstall' => 1));

    assert_same(array('ysai_daily_cleanup'), $result['hooks']);
    assert_count_value(4, $result['queries']);
    assert_same(array(
        'DROP TABLE IF EXISTS `wp_ysai_v2_messages`',
        'DROP TABLE IF EXISTS `wp_ysai_v2_turns`',
        'DROP TABLE IF EXISTS `wp_ysai_v2_conversations`',
        'DROP TABLE IF EXISTS `wp_ysai_v2_rate_limits`',
    ), $result['queries']);
    assert_same(array(
        'ysai_cleanup_schedule_retry_after',
        'ysai_cleanup_schedule_log_after',
        'ysai_cleanup_schedule_failure_reason',
        'ysai_schema_version',
        'ysai_options',
    ), $result['deleted']);
});
