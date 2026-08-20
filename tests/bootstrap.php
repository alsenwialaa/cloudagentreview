<?php

declare(strict_types=1);

const YSAI_TEST_ROOT = __DIR__ . '/..';

if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'ysai-test-auth-key-with-sufficient-entropy');
}
if (!defined('YSAI_VERSION')) {
    define('YSAI_VERSION', '2.5.4-test');
}

$GLOBALS['ysai_test_options'] = array();
$GLOBALS['ysai_test_failures'] = array();
$GLOBALS['ysai_test_cases'] = array();
$GLOBALS['ysai_test_http_handler'] = null;
$GLOBALS['ysai_test_filters'] = array();
$GLOBALS['ysai_test_option_write_failures'] = array();
$GLOBALS['ysai_test_option_write_calls'] = array();
$GLOBALS['ysai_test_option_read_exceptions'] = array();
$GLOBALS['ysai_test_option_delete_exceptions'] = array();
$GLOBALS['ysai_test_option_delete_calls'] = array();

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed
    {
        foreach ((array) ($GLOBALS['ysai_test_filters'][$hook] ?? array()) as $callback) {
            if (!is_callable($callback)) {
                throw new RuntimeException('A configured test filter is not callable.');
            }
            $value = $callback($value, ...$arguments);
        }
        return $value;
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return hash('sha256', 'ysai-test-salt|' . $scheme);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $value): string
    {
        return strip_tags($value);
    }
}
if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        if (in_array($name, (array) ($GLOBALS['ysai_test_option_read_exceptions'] ?? array()), true)) {
            throw new RuntimeException('Configured option read failure.');
        }
        return $GLOBALS['ysai_test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, bool $autoload = false): bool
    {
        $GLOBALS['ysai_test_option_write_calls'][] = $name;
        if (in_array($name, (array) ($GLOBALS['ysai_test_option_write_failures'] ?? array()), true)) {
            return false;
        }
        $GLOBALS['ysai_test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        $GLOBALS['ysai_test_option_delete_calls'][] = $name;
        if (in_array($name, (array) ($GLOBALS['ysai_test_option_delete_exceptions'] ?? array()), true)) {
            throw new RuntimeException('Configured option deletion failure.');
        }
        unset($GLOBALS['ysai_test_options'][$name]);
        return true;
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://shop.example.test' . '/' . ltrim($path, '/');
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $value, ?array $protocols = null): string
    {
        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): array|string|int|null|false
    {
        return parse_url($url, $component);
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string,string> */
        private array $headers = array();

        /** @param array<string,string> $headers */
        public function __construct(private string $body = '', array $headers = array())
        {
            foreach ($headers as $name => $value) {
                $this->set_header((string) $name, (string) $value);
            }
        }

        public function set_header(string $name, string $value): void
        {
            $this->headers[strtolower($name)] = $value;
        }

        public function get_header(string $name): string
        {
            return $this->headers[strtolower($name)] ?? '';
        }

        public function set_body(string $body): void
        {
            $this->body = $body;
        }

        public function get_body(): string
        {
            return $this->body;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var array<string,string> */
        private array $headers = array();

        public function __construct(public mixed $data = null, public int $status = 200)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function header(string $key, string $value, bool $replace = true): void
        {
            $normalized = strtolower($key);
            if (!$replace && isset($this->headers[$normalized])) {
                $this->headers[$normalized] .= ', ' . $value;
                return;
            }
            $this->headers[$normalized] = $value;
        }

        /** @return array<string,string> */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}
if (!function_exists('add_settings_error')) {
    function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
    {
    }
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (!function_exists('esc_html')) {
    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}


if (!class_exists('WP_REST_Server')) {
    final class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
    }
}

if (!class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(private readonly string $code, private readonly string $message = '')
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $arguments = array()): array|WP_Error
    {
        $handler = $GLOBALS['ysai_test_http_handler'] ?? null;
        if (!is_callable($handler)) {
            throw new RuntimeException('No test HTTP handler is configured.');
        }
        $response = $handler($url, $arguments);
        if (!is_array($response) && !$response instanceof WP_Error) {
            throw new RuntimeException('The test HTTP handler returned an invalid response.');
        }
        return $response;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $value): bool
    {
        return $value instanceof WP_Error;
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array|WP_Error $response): int
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array|WP_Error $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}
if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header(array|WP_Error $response, string $header): string
    {
        if (!is_array($response)) {
            return '';
        }
        foreach ((array) ($response['headers'] ?? array()) as $name => $value) {
            if (is_string($name) && strcasecmp($name, $header) === 0 && is_scalar($value)) {
                return trim((string) $value);
            }
        }
        return '';
    }
}

require_once YSAI_TEST_ROOT . '/src/Autoload.php';
\YassinStore\AiAssistant\Autoload::register();

/** @param callable():void $case */
function test(string $name, callable $case): void
{
    $GLOBALS['ysai_test_cases'][] = array($name, $case);
}

function fail_test(string $message): never
{
    throw new RuntimeException($message);
}

function assert_true(bool $condition, string $message = 'Expected condition to be true.'): void
{
    if (!$condition) {
        fail_test($message);
    }
}

function assert_false(bool $condition, string $message = 'Expected condition to be false.'): void
{
    if ($condition) {
        fail_test($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = $message === '' ? 'Values are not identical.' : $message;
        fail_test($detail . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assert_count_value(int $expected, Countable|array $actual, string $message = ''): void
{
    assert_same($expected, count($actual), $message === '' ? 'Unexpected item count.' : $message);
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail_test(($message === '' ? 'Expected substring was not found.' : $message) . "\nNeedle: {$needle}\nHaystack: {$haystack}");
    }
}

/** @param class-string<Throwable> $class */
function assert_throws(string $class, callable $operation, ?string $messageContains = null): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            fail_test('Expected ' . $class . ', got ' . $error::class . ': ' . $error->getMessage());
        }
        if ($messageContains !== null && !str_contains($error->getMessage(), $messageContains)) {
            fail_test('Exception message did not contain: ' . $messageContains . '. Actual: ' . $error->getMessage());
        }
        return $error;
    }
    fail_test('Expected exception ' . $class . ' was not thrown.');
}
