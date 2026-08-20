<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/Support/Fakes.php';

use YassinStore\AiAssistant\Application\Tool\ToolRegistry;
use YassinStore\AiAssistant\Infrastructure\Security\SecretBox;
use YassinStore\AiAssistant\Infrastructure\Security\TrustedProxyResolver;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\CartSessionPersistence;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Infrastructure\WordPress\WidgetAppearance;
use YassinStore\AiAssistant\Presentation\Admin\AdminPage;

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool { return $capability === 'manage_woocommerce'; }
}
if (!function_exists('wp_die')) {
    function wp_die(string $message): never { throw new RuntimeException($message); }
}
if (!function_exists('esc_attr')) {
    function esc_attr(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string { return esc_html(__($text, $domain)); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string { return esc_attr(__($text, $domain)); }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea(mixed $value): string { return htmlspecialchars((string) $value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url(mixed $value): string { return esc_attr((string) $value); }
}
if (!function_exists('get_site_icon_url')) {
    function get_site_icon_url(int $size = 512): string { return ''; }
}
if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed { return false; }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool { return true; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 1; }
}
if (!function_exists('settings_errors')) {
    function settings_errors(string $setting = ''): void {}
}
if (!function_exists('settings_fields')) {
    function settings_fields(string $group): void { echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">'; }
}
if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string
    {
        $result = (string) $checked === (string) $current ? ' checked="checked"' : '';
        if ($display) echo $result;
        return $result;
    }
}
if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
        if ($display) echo $result;
        return $result;
    }
}
if (!function_exists('submit_button')) {
    function submit_button(string $text = 'Save Changes', string $type = 'primary'): void
    {
        echo '<p class="submit"><button type="submit" class="button ' . esc_attr($type) . '">' . esc_html($text) . '</button></p>';
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string { return 'https://shop.example.test/wp-admin/' . ltrim($path, '/'); }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="test-' . esc_attr($action) . '">'; }
}

$GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = array_replace(Settings::defaults(), array(
    'widget_conversation_privacy_enabled' => 1,
    'widget_cart_summary_enabled' => 1,
));

$settings = new Settings(new SecretBox());
$conversations = new InMemoryConversationRepository(new TestClock());
$tools = (new ReflectionClass(ToolRegistry::class))->newInstanceWithoutConstructor();
$cartPersistence = new class implements CartSessionPersistence {
    public function configurationStatus(): ?bool { return true; }
    public function read(array $keys): array { return array(); }
    public function persist(): void {}
    public function invalidateCache(): void {}
};
$page = new AdminPage(
    $settings,
    new ScriptedAiProvider(),
    $conversations,
    new AllowAllRateLimiter(),
    $tools,
    $cartPersistence,
    new TrustedProxyResolver($settings)
);
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Yassin admin appearance contract</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>window.YSAIAdminAppearance=<?php echo wp_json_encode(array('presets' => WidgetAppearance::presets()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
</head>
<body>
<?php $page->render(); ?>
<script src="/assets/js/admin.js"></script>
</body>
</html>
