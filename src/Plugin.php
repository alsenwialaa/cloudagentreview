<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant;

use YassinStore\AiAssistant\Application\Chat\AgentLoop;
use YassinStore\AiAssistant\Application\Chat\ChatService;
use YassinStore\AiAssistant\Application\Chat\IntentVerifier;
use YassinStore\AiAssistant\Application\Chat\PromptFactory;
use YassinStore\AiAssistant\Application\Tool\ShoppingMemoryPolicy;
use YassinStore\AiAssistant\Application\Tool\ToolRegistry;
use YassinStore\AiAssistant\Infrastructure\Ai\GeminiInteractionsClient;
use YassinStore\AiAssistant\Infrastructure\Database\Installer;
use YassinStore\AiAssistant\Infrastructure\Database\WpConversationRepository;
use YassinStore\AiAssistant\Infrastructure\Database\WpRateLimiter;
use YassinStore\AiAssistant\Infrastructure\Database\WpTurnRepository;
use YassinStore\AiAssistant\Infrastructure\Security\RequestIdentity;
use YassinStore\AiAssistant\Infrastructure\Security\SecretBox;
use YassinStore\AiAssistant\Infrastructure\Security\TokenHasher;
use YassinStore\AiAssistant\Infrastructure\Security\TrustedProxyResolver;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\CartLock;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\CartSessionPersistence;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\UnsupportedCartSessionPersistence;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCartGateway;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooDatabaseCartSessionPersistence;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCatalogGateway;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\SameOriginUrl;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Infrastructure\WordPress\SystemClock;
use YassinStore\AiAssistant\Infrastructure\WordPress\WpContentGateway;
use YassinStore\AiAssistant\Presentation\Admin\AdminPage;
use YassinStore\AiAssistant\Presentation\Rest\RestController;
use YassinStore\AiAssistant\Presentation\Rest\StorefrontRequestGuard;
use YassinStore\AiAssistant\Presentation\Storefront\StorefrontWidget;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;
    private bool $databaseUnavailable = false;
    private bool $cleanupUnavailable = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain('yassin-ai-assistant', false, dirname(plugin_basename(YSAI_PLUGIN_FILE)) . '/languages');
        $cleanupRemediationAllowed = Installer::automaticCleanupRemediationAllowed();
        try {
            $cleanupReady = Installer::maybeInstall($cleanupRemediationAllowed);
        } catch (\Throwable $error) {
            $this->databaseUnavailable = true;
            error_log('Yassin AI Assistant database initialization failed: ' . $error::class);
            add_action('admin_notices', array($this, 'databaseNotice'));
            return;
        }

        $clock = new SystemClock();
        $settings = new Settings(new SecretBox());
        $settings->migrateLegacySecret();
        $logger = new Logger($settings);
        if (!$cleanupReady) {
            $this->cleanupUnavailable = true;
            if ($cleanupRemediationAllowed && Installer::shouldLogCleanupFailure()) {
                $logger->error('Scheduled privacy cleanup could not be registered.', array(
                    'hook' => Installer::CLEANUP_HOOK,
                    'reason' => Installer::cleanupFailureReason(),
                ));
            }
            add_action('admin_notices', array($this, 'cleanupNotice'));
        }
        $provider = new GeminiInteractionsClient($settings, $logger);
        $conversations = new WpConversationRepository($clock, new TokenHasher());
        $turns = new WpTurnRepository($clock);
        $rateLimiter = new WpRateLimiter($clock);
        $sameOriginUrls = new SameOriginUrl(home_url('/'));
        $networkResolver = new TrustedProxyResolver($settings);
        $catalog = new WooCatalogGateway($sameOriginUrls);
        $defaultSessionPersistence = new WooDatabaseCartSessionPersistence($logger);
        /**
         * Allows a custom WooCommerce session handler to provide an adapter
         * with an equivalent separated durable-write and canonical-read
         * guarantee.
         *
         * @param CartSessionPersistence $defaultSessionPersistence
         */
        $sessionPersistence = apply_filters('ysai_cart_session_persistence', $defaultSessionPersistence);
        if (!$sessionPersistence instanceof CartSessionPersistence) {
            $logger->error('The cart-session persistence filter returned an unsupported adapter.', array(
                'adapter_type' => get_debug_type($sessionPersistence),
            ));
            $sessionPersistence = new UnsupportedCartSessionPersistence(
                'The configured cart-session persistence adapter is invalid.'
            );
        }
        $cart = new WooCartGateway(
            $catalog,
            new CartLock($logger),
            $sessionPersistence,
            $logger,
            $sameOriginUrls
        );
        $content = new WpContentGateway($settings, $sameOriginUrls);
        $intent = new IntentVerifier($provider);
        $tools = new ToolRegistry(
            $catalog,
            $cart,
            $content,
            $conversations,
            $intent,
            $settings,
            new ShoppingMemoryPolicy()
        );
        $prompts = new PromptFactory($settings);
        $agent = new AgentLoop($provider, $tools, $prompts, $conversations, $settings);
        $chat = new ChatService(
            $conversations,
            $turns,
            $rateLimiter,
            $cart,
            $agent,
            $settings,
            new RequestIdentity($networkResolver),
            $clock,
            $logger
        );

        $admin = new AdminPage(
            $settings,
            $provider,
            $conversations,
            $rateLimiter,
            $tools,
            $sessionPersistence,
            $networkResolver
        );
        $admin->register();

        $rest = new RestController($chat, new StorefrontRequestGuard($sameOriginUrls), $logger);
        add_action('rest_api_init', array($rest, 'register'));

        if ($this->woocommerceReady()) {
            $widget = new StorefrontWidget($settings);
            $widget->register();
        } else {
            add_action('admin_notices', array($this, 'requirementsNotice'));
        }

        add_action(Installer::CLEANUP_HOOK, static function () use ($conversations, $rateLimiter, $logger): void {
            try {
                $conversations->purgeExpired();
                $rateLimiter->purge();
            } catch (\Throwable $error) {
                $logger->error('Scheduled cleanup failed.', array('exception' => $error::class));
            }
        });

        add_filter('plugin_action_links_' . plugin_basename(YSAI_PLUGIN_FILE), array($this, 'actionLinks'));
    }

    /** @param list<string> $links @return list<string> */
    public function actionLinks(array $links): array
    {
        $settings = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=ysai-assistant')),
            esc_html__('Settings', 'yassin-ai-assistant')
        );
        array_unshift($links, $settings);
        return $links;
    }

    public function requirementsNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $version = defined('WC_VERSION') ? (string) WC_VERSION : __('not active', 'yassin-ai-assistant');
        echo '<div class="notice notice-error"><p>';
        echo esc_html(sprintf(
            __('Yassin AI Assistant requires WooCommerce 11.0.1 or later. Current status: %s.', 'yassin-ai-assistant'),
            $version
        ));
        echo '</p></div>';
    }

    public function databaseNotice(): void
    {
        if (!$this->databaseUnavailable || !current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Yassin AI Assistant is disabled because its required database schema could not be verified. Review the database permissions and WordPress error log, then reload this page.',
            'yassin-ai-assistant'
        );
        echo '</p></div>';
    }

    public function cleanupNotice(): void
    {
        if (!$this->cleanupUnavailable || !current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'Yassin AI Assistant is running, but WordPress could not schedule its daily privacy cleanup. Review WP-Cron and database option writes; retries are restricted to privileged administration, WP-Cron, or WP-CLI requests so storefront traffic is not used as a retry loop.',
            'yassin-ai-assistant'
        );
        echo '</p></div>';
    }

    private function woocommerceReady(): bool
    {
        return class_exists('WooCommerce')
            && defined('WC_VERSION')
            && version_compare((string) WC_VERSION, '11.0.1', '>=');
    }
}
