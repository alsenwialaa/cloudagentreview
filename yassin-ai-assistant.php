<?php
/**
 * Plugin Name: Yassin Store AI Sales Agent
 * Plugin URI: https://yassin-store.com/
 * Description: Arabic-first WooCommerce sales assistant with live catalog tools, verified cart actions, and a secure storefront chat widget.
 * Version: 2.5.4
 * Author: Yassin Store
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 11.0.1
 * Text Domain: yassin-ai-assistant
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('YSAI_VERSION', '2.5.4');
define('YSAI_PLUGIN_FILE', __FILE__);
define('YSAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YSAI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once YSAI_PLUGIN_DIR . 'src/Autoload.php';

\YassinStore\AiAssistant\Autoload::register();

register_activation_hook(
    __FILE__,
    array(\YassinStore\AiAssistant\Infrastructure\Database\Installer::class, 'activate')
);
register_deactivation_hook(
    __FILE__,
    array(\YassinStore\AiAssistant\Infrastructure\Database\Installer::class, 'deactivate')
);

add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        \YassinStore\AiAssistant\Plugin::instance()->boot();
    },
    20
);
