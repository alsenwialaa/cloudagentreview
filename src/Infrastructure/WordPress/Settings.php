<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

use YassinStore\AiAssistant\Application\Catalog\CatalogSynonymMap;
use YassinStore\AiAssistant\Application\Catalog\CatalogTextNormalizer;
use YassinStore\AiAssistant\Application\Contract\RuntimeSettings;
use YassinStore\AiAssistant\Application\Support\Text;
use YassinStore\AiAssistant\Infrastructure\Security\SecretBox;
use YassinStore\AiAssistant\Infrastructure\Security\TrustedProxyResolver;

final class Settings implements RuntimeSettings
{
    public const OPTION_KEY = 'ysai_options';

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly SecretBox $secretBox)
    {
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $palette = WidgetAppearance::palette(WidgetAppearance::DEFAULT_PRESET);
        return array(
            'enabled' => 1,
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-3.7-flash',
            'gemini_thinking_level' => 'medium',
            'max_output_tokens' => 2048,
            'http_timeout_seconds' => 35,
            'max_tool_rounds' => 6,
            'allow_images' => 1,
            'store_guidance' => '',
            'catalog_synonyms' => '',
            'widget_enabled' => 1,
            'widget_auto_insert' => 1,
            'widget_cart_summary_enabled' => 0,
            'widget_conversation_privacy_enabled' => 0,
            'widget_position' => 'right',
            'widget_button_text' => 'مساعدة ياسين',
            'widget_title' => 'مساعدة متجر ياسين',
            'widget_subtitle' => 'اسأل عن المنتجات والأسعار والسلة وسياسات المتجر',
            'empty_state_hint' => 'اكتب ما تبحث عنه أو اطلب مساعدة في اختيار منتج.',
            'widget_theme_preset' => WidgetAppearance::DEFAULT_PRESET,
            'widget_launcher_style' => 'pill',
            'widget_avatar_style' => 'site_icon',
            'widget_message_density' => 'comfortable',
            'widget_show_presence' => 1,
            'widget_show_timestamps' => 1,
            'widget_show_message_actions' => 1,
            'widget_show_unread_button' => 1,
            'widget_quick_replies_enabled' => 1,
            'widget_launcher_show_label_mobile' => 0,
            'widget_welcome_message' => 'مرحبًا! أنا مساعد المتجر. كيف أقدر أساعدك اليوم؟',
            'widget_quick_prompt_1' => 'رشّح لي منتجات مناسبة',
            'widget_quick_prompt_2' => 'قارن بين الخيارات',
            'widget_quick_prompt_3' => 'ما سياسات الشحن والاسترجاع؟',
            'widget_brand_color' => $palette['brand'],
            'widget_brand_strong_color' => $palette['brand_strong'],
            'widget_surface_color' => $palette['surface'],
            'widget_canvas_color' => $palette['canvas'],
            'widget_assistant_bubble_color' => $palette['assistant'],
            'widget_user_bubble_color' => $palette['user'],
            'widget_receipt_bubble_color' => $palette['receipt'],
            'widget_border_color' => $palette['border'],
            'widget_panel_width' => 420,
            'widget_panel_height' => 700,
            'widget_panel_radius' => 24,
            'widget_bubble_radius' => 18,
            'widget_product_card_radius' => 16,
            'widget_font_size' => 15,
            'widget_product_layout' => 'carousel',
            'widget_product_image_ratio' => '1-1',
            'widget_product_cards_per_view_desktop' => 2,
            'widget_product_cards_per_view_mobile' => 1,
            'widget_product_carousel_indicator_enabled' => 1,
            'widget_product_name_font_size' => 15,
            'widget_product_name_font_weight' => 700,
            'widget_product_name_max_lines' => 2,
            'max_display_cards' => 6,
            'widget_product_show_description' => 1,
            'assistant_session_minutes' => 0,
            'conversation_retention_days' => 45,
            'rate_limit_turns' => 40,
            'daily_ai_turn_limit' => 1200,
            'daily_conversation_limit' => 5000,
            'trusted_proxy_cidrs' => '',
            'trusted_proxy_header' => TrustedProxyResolver::HEADER_X_FORWARDED_FOR,
            'diagnostic_logging' => 0,
            'delete_data_on_uninstall' => 0,
            'contact_url' => '',
            'about_url' => '',
            'shipping_url' => '',
            'returns_url' => '',
            'terms_url' => '',
            'account_url' => '',
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = get_option(self::OPTION_KEY, array());
        $known = is_array($stored) ? array_intersect_key($stored, self::defaults()) : array();
        $this->cache = $this->normalizeStored($known);
        return $this->cache;
    }

    public function apiKey(): string
    {
        if (defined('YSAI_GEMINI_API_KEY') && trim((string) YSAI_GEMINI_API_KEY) !== '') {
            return trim((string) YSAI_GEMINI_API_KEY);
        }

        $stored = $this->get('gemini_api_key', '');
        if ($stored === '') {
            return '';
        }
        if (!is_string($stored)) {
            throw new \RuntimeException('The stored Gemini API key has an invalid type. Re-save the assistant settings.');
        }
        if (!$this->secretBox->isEncrypted($stored)) {
            throw new \RuntimeException('The stored Gemini API key is not encrypted. Re-save it in the assistant settings.');
        }

        return trim($this->secretBox->decrypt($stored));
    }

    /** @param mixed $input @return array<string,mixed> */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : array();
        $current = $this->all();
        $out = self::defaults();

        foreach (array(
            'enabled', 'allow_images', 'widget_enabled', 'widget_auto_insert',
            'widget_cart_summary_enabled', 'widget_conversation_privacy_enabled',
            'widget_product_show_description', 'widget_show_presence', 'widget_show_timestamps',
            'widget_show_message_actions', 'widget_show_unread_button', 'widget_quick_replies_enabled',
            'widget_launcher_show_label_mobile', 'widget_product_carousel_indicator_enabled',
            'diagnostic_logging', 'delete_data_on_uninstall',
        ) as $checkbox) {
            $out[$checkbox] = $this->checked($input[$checkbox] ?? null) ? 1 : 0;
        }

        $newKey = trim($this->stringValue($input['gemini_api_key'] ?? ''));
        $clearKey = $this->checked($input['clear_gemini_api_key'] ?? null);
        if ($clearKey) {
            $out['gemini_api_key'] = '';
        } elseif ($newKey !== '') {
            if (strlen($newKey) > 500 || preg_match('/[\x00-\x1F\x7F]/', $newKey) === 1) {
                add_settings_error(self::OPTION_KEY, 'ysai_api_key_invalid', __('مفتاح API غير صالح.', 'yassin-ai-assistant'));
                $out['gemini_api_key'] = $this->stringValue($current['gemini_api_key'] ?? '');
            } else {
                try {
                    $out['gemini_api_key'] = $this->secretBox->encrypt($newKey);
                } catch (\Throwable $error) {
                    add_settings_error(self::OPTION_KEY, 'ysai_api_key_encrypt', esc_html($error->getMessage()));
                    $out['gemini_api_key'] = $this->stringValue($current['gemini_api_key'] ?? '');
                }
            }
        } else {
            $out['gemini_api_key'] = $this->stringValue($current['gemini_api_key'] ?? '');
        }

        $model = $this->stringValue(
            $input['gemini_model'] ?? null,
            $this->stringValue($current['gemini_model'] ?? null, 'gemini-3.7-flash')
        );
        $out['gemini_model'] = $this->model($model);
        $thinkingLevel = $this->stringValue(
            $input['gemini_thinking_level'] ?? null,
            $this->stringValue($current['gemini_thinking_level'] ?? null, 'medium')
        );
        $out['gemini_thinking_level'] = $thinkingLevel === 'minimal'
            ? 'low'
            : (in_array($thinkingLevel, array('low', 'medium', 'high'), true) ? (string) $thinkingLevel : 'medium');
        $out['max_output_tokens'] = $this->int($input, 'max_output_tokens', 256, 8192, 2048);
        $out['http_timeout_seconds'] = $this->int($input, 'http_timeout_seconds', 10, 90, 35);
        $out['max_tool_rounds'] = $this->int($input, 'max_tool_rounds', 2, 10, 6);
        $out['assistant_session_minutes'] = $this->int($input, 'assistant_session_minutes', 0, 10080, 0);
        $out['conversation_retention_days'] = $this->int($input, 'conversation_retention_days', 1, 365, 45);
        $out['rate_limit_turns'] = $this->int($input, 'rate_limit_turns', 5, 500, 40);
        $out['daily_ai_turn_limit'] = $this->int($input, 'daily_ai_turn_limit', 50, 100000, 1200);
        $out['daily_conversation_limit'] = $this->int($input, 'daily_conversation_limit', 100, 100000, 5000);

        $currentProxyHeader = $this->stringValue(
            $current['trusted_proxy_header'] ?? null,
            TrustedProxyResolver::HEADER_X_FORWARDED_FOR
        );
        if (!in_array(
            $currentProxyHeader,
            array(TrustedProxyResolver::HEADER_FORWARDED, TrustedProxyResolver::HEADER_X_FORWARDED_FOR),
            true
        )) {
            $currentProxyHeader = TrustedProxyResolver::HEADER_X_FORWARDED_FOR;
        }
        $submittedProxyHeader = $input['trusted_proxy_header'] ?? $currentProxyHeader;
        if (!is_string($submittedProxyHeader)
            || !in_array(
                $submittedProxyHeader,
                array(TrustedProxyResolver::HEADER_FORWARDED, TrustedProxyResolver::HEADER_X_FORWARDED_FOR),
                true
            )) {
            add_settings_error(
                self::OPTION_KEY,
                'ysai_trusted_proxy_header_invalid',
                __('ترويسة الوكيل الموثوق غير صالحة. تم الاحتفاظ بالقيمة السابقة.', 'yassin-ai-assistant')
            );
            $out['trusted_proxy_header'] = $currentProxyHeader;
        } else {
            $out['trusted_proxy_header'] = $submittedProxyHeader;
        }
        try {
            $out['trusted_proxy_cidrs'] = TrustedProxyResolver::normalizeCidrText(
                $input['trusted_proxy_cidrs'] ?? ''
            );
        } catch (\InvalidArgumentException) {
            add_settings_error(
                self::OPTION_KEY,
                'ysai_trusted_proxy_invalid',
                __('قائمة شبكات الوكيل الموثوق غير صالحة. استخدم عنوان IP أو CIDR واحدًا في كل سطر.', 'yassin-ai-assistant')
            );
            $out['trusted_proxy_cidrs'] = $this->stringValue($current['trusted_proxy_cidrs'] ?? '');
        }

        $out['widget_panel_width'] = $this->int($input, 'widget_panel_width', 340, 560, 420);
        $out['widget_panel_height'] = $this->int($input, 'widget_panel_height', 520, 860, 700);
        $out['widget_panel_radius'] = $this->int($input, 'widget_panel_radius', 8, 40, 24);
        $out['widget_bubble_radius'] = $this->int($input, 'widget_bubble_radius', 8, 32, 18);
        $out['widget_product_card_radius'] = $this->int($input, 'widget_product_card_radius', 8, 32, 16);
        $out['widget_font_size'] = $this->int($input, 'widget_font_size', 13, 19, 15);
        $out['widget_product_cards_per_view_desktop'] = $this->int($input, 'widget_product_cards_per_view_desktop', 1, 3, 2);
        $out['widget_product_cards_per_view_mobile'] = $this->int($input, 'widget_product_cards_per_view_mobile', 1, 2, 1);
        $out['widget_product_name_font_size'] = $this->int($input, 'widget_product_name_font_size', 13, 20, 15);
        $out['widget_product_name_font_weight'] = $this->int($input, 'widget_product_name_font_weight', 400, 900, 700);
        $out['widget_product_name_max_lines'] = $this->int($input, 'widget_product_name_max_lines', 1, 4, 2);
        $out['max_display_cards'] = $this->int($input, 'max_display_cards', 1, 12, 6);

        $position = $this->stringValue($input['widget_position'] ?? null);
        $theme = WidgetAppearance::normalizePreset($input['widget_theme_preset'] ?? null);
        $layout = $this->stringValue($input['widget_product_layout'] ?? null);
        $launcher = $this->stringValue($input['widget_launcher_style'] ?? null);
        $avatar = $this->stringValue($input['widget_avatar_style'] ?? null);
        $density = $this->stringValue($input['widget_message_density'] ?? null);
        $imageRatio = $this->stringValue($input['widget_product_image_ratio'] ?? null);
        $out['widget_position'] = $position === 'left' ? 'left' : 'right';
        $out['widget_theme_preset'] = $theme;
        $out['widget_product_layout'] = in_array($layout, array('carousel', 'grid', 'list'), true) ? $layout : 'carousel';
        $out['widget_launcher_style'] = in_array($launcher, array('pill', 'circle'), true) ? $launcher : 'pill';
        $out['widget_avatar_style'] = in_array($avatar, array('site_icon', 'initial', 'chat'), true) ? $avatar : 'site_icon';
        $out['widget_message_density'] = $density === 'compact' ? 'compact' : 'comfortable';
        $out['widget_product_image_ratio'] = in_array($imageRatio, array('1-1', '4-3', '3-4', '16-9'), true)
            ? $imageRatio
            : '1-1';

        $appearance = WidgetAppearance::sanitizeForSave($input, $current, $theme);
        foreach ($appearance['values'] as $key => $value) {
            $out[$key] = $value;
        }
        if ($appearance['invalid_fields'] !== array()) {
            add_settings_error(
                self::OPTION_KEY,
                'ysai_widget_color_invalid',
                __('تم تجاهل لون واجهة غير صالح والاحتفاظ بالقيمة السابقة.', 'yassin-ai-assistant')
            );
        }

        $out['widget_button_text'] = $this->text($input, 'widget_button_text', 80, 'مساعدة ياسين');
        $out['widget_title'] = $this->text($input, 'widget_title', 100, 'مساعدة متجر ياسين');
        $out['widget_subtitle'] = $this->text($input, 'widget_subtitle', 180, 'اسأل عن المنتجات والأسعار والسلة وسياسات المتجر');
        $out['empty_state_hint'] = $this->text($input, 'empty_state_hint', 220, 'اكتب ما تبحث عنه أو اطلب مساعدة في اختيار منتج.');
        $out['widget_welcome_message'] = $this->text($input, 'widget_welcome_message', 240, 'مرحبًا! أنا مساعد المتجر. كيف أقدر أساعدك اليوم؟');
        $out['widget_quick_prompt_1'] = $this->text($input, 'widget_quick_prompt_1', 120, 'رشّح لي منتجات مناسبة');
        $out['widget_quick_prompt_2'] = $this->text($input, 'widget_quick_prompt_2', 120, 'قارن بين الخيارات');
        $out['widget_quick_prompt_3'] = $this->text($input, 'widget_quick_prompt_3', 120, 'ما سياسات الشحن والاسترجاع؟');
        $out['store_guidance'] = sanitize_textarea_field(Text::slice(
            $this->stringValue($input['store_guidance'] ?? ''),
            0,
            20000
        ));

        try {
            $rawSynonyms = $this->stringValue($input['catalog_synonyms'] ?? '');
            if (Text::length($rawSynonyms) > 12000) {
                throw new \InvalidArgumentException('Catalog synonyms exceed the safe size limit.');
            }
            $out['catalog_synonyms'] = (new CatalogSynonymMap(
                $rawSynonyms,
                new CatalogTextNormalizer(),
                true
            ))->canonicalText();
        } catch (\InvalidArgumentException) {
            add_settings_error(
                self::OPTION_KEY,
                'ysai_catalog_synonyms_invalid',
                __('مرادفات البحث غير صالحة. استخدم سطرًا لكل مجموعة ومصطلحين مختلفين على الأقل.', 'yassin-ai-assistant')
            );
            $currentSynonyms = is_string($current['catalog_synonyms'] ?? null)
                ? $current['catalog_synonyms']
                : '';
            $out['catalog_synonyms'] = (new CatalogSynonymMap(
                $currentSynonyms,
                new CatalogTextNormalizer(),
                false
            ))->canonicalText();
        }

        $sameOriginUrls = new SameOriginUrl(home_url('/'));
        foreach (array('contact_url', 'about_url', 'shipping_url', 'returns_url', 'terms_url', 'account_url') as $url) {
            $candidate = $this->stringValue($input[$url] ?? '');
            $out[$url] = $sameOriginUrls->sanitize(esc_url_raw($candidate, array('http', 'https')));
        }

        $this->cache = $out;
        return $out;
    }

    /**
     * Stored options are an untrusted runtime boundary. A compromised import,
     * malformed direct database edit, or old release must not inject arrays,
     * out-of-range limits, foreign links, or unsupported provider values into
     * application code merely because the normal settings form was bypassed.
     *
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private function normalizeStored(array $stored): array
    {
        $defaults = self::defaults();
        $out = $defaults;

        foreach (array(
            'enabled', 'allow_images', 'widget_enabled', 'widget_auto_insert',
            'widget_cart_summary_enabled', 'widget_conversation_privacy_enabled',
            'widget_product_show_description', 'widget_show_presence', 'widget_show_timestamps',
            'widget_show_message_actions', 'widget_show_unread_button', 'widget_quick_replies_enabled',
            'widget_launcher_show_label_mobile', 'widget_product_carousel_indicator_enabled',
            'diagnostic_logging', 'delete_data_on_uninstall',
        ) as $key) {
            $out[$key] = $this->storedBoolean($stored[$key] ?? $defaults[$key], (int) $defaults[$key]);
        }

        foreach (array(
            'max_output_tokens' => array(256, 8192),
            'http_timeout_seconds' => array(10, 90),
            'max_tool_rounds' => array(2, 10),
            'assistant_session_minutes' => array(0, 10080),
            'conversation_retention_days' => array(1, 365),
            'rate_limit_turns' => array(5, 500),
            'daily_ai_turn_limit' => array(50, 100000),
            'daily_conversation_limit' => array(100, 100000),
            'widget_panel_width' => array(340, 560),
            'widget_panel_height' => array(520, 860),
            'widget_panel_radius' => array(8, 40),
            'widget_bubble_radius' => array(8, 32),
            'widget_product_card_radius' => array(8, 32),
            'widget_font_size' => array(13, 19),
            'widget_product_cards_per_view_desktop' => array(1, 3),
            'widget_product_cards_per_view_mobile' => array(1, 2),
            'widget_product_name_font_size' => array(13, 20),
            'widget_product_name_font_weight' => array(400, 900),
            'widget_product_name_max_lines' => array(1, 4),
            'max_display_cards' => array(1, 12),
        ) as $key => $range) {
            $out[$key] = $this->storedInteger(
                $stored[$key] ?? $defaults[$key],
                (int) $range[0],
                (int) $range[1],
                (int) $defaults[$key]
            );
        }

        $apiKey = $stored['gemini_api_key'] ?? '';
        if (is_string($apiKey)
            && strlen($apiKey) <= 2000
            && preg_match('/[\\x00-\\x1F\\x7F]/', $apiKey) !== 1) {
            // Preserve a plausible plaintext legacy value so apiKey() fails loudly
            // instead of silently pretending that credentials are configured.
            $out['gemini_api_key'] = trim($apiKey);
        }

        $model = is_string($stored['gemini_model'] ?? null)
            ? (string) $stored['gemini_model']
            : (string) $defaults['gemini_model'];
        $out['gemini_model'] = $this->model($model);

        $thinking = is_string($stored['gemini_thinking_level'] ?? null)
            ? (string) $stored['gemini_thinking_level']
            : (string) $defaults['gemini_thinking_level'];
        if ($thinking === 'minimal') {
            $thinking = 'low';
        }
        $out['gemini_thinking_level'] = in_array($thinking, array('low', 'medium', 'high'), true)
            ? $thinking
            : (string) $defaults['gemini_thinking_level'];

        $proxyHeader = is_string($stored['trusted_proxy_header'] ?? null)
            ? (string) $stored['trusted_proxy_header']
            : (string) $defaults['trusted_proxy_header'];
        $out['trusted_proxy_header'] = in_array(
            $proxyHeader,
            array(TrustedProxyResolver::HEADER_FORWARDED, TrustedProxyResolver::HEADER_X_FORWARDED_FOR),
            true
        ) ? $proxyHeader : (string) $defaults['trusted_proxy_header'];
        try {
            $out['trusted_proxy_cidrs'] = TrustedProxyResolver::normalizeCidrText(
                $stored['trusted_proxy_cidrs'] ?? ''
            );
        } catch (\InvalidArgumentException) {
            $out['trusted_proxy_cidrs'] = '';
        }

        $position = is_string($stored['widget_position'] ?? null) ? $stored['widget_position'] : '';
        $theme = WidgetAppearance::normalizePreset($stored['widget_theme_preset'] ?? null);
        $layout = is_string($stored['widget_product_layout'] ?? null) ? $stored['widget_product_layout'] : '';
        $launcher = is_string($stored['widget_launcher_style'] ?? null) ? $stored['widget_launcher_style'] : '';
        $avatar = is_string($stored['widget_avatar_style'] ?? null) ? $stored['widget_avatar_style'] : '';
        $density = is_string($stored['widget_message_density'] ?? null) ? $stored['widget_message_density'] : '';
        $imageRatio = is_string($stored['widget_product_image_ratio'] ?? null) ? $stored['widget_product_image_ratio'] : '';
        $out['widget_position'] = in_array($position, array('left', 'right'), true)
            ? $position
            : (string) $defaults['widget_position'];
        $out['widget_theme_preset'] = $theme;
        $out['widget_product_layout'] = in_array($layout, array('carousel', 'grid', 'list'), true)
            ? $layout
            : (string) $defaults['widget_product_layout'];
        $out['widget_launcher_style'] = in_array($launcher, array('pill', 'circle'), true)
            ? $launcher
            : (string) $defaults['widget_launcher_style'];
        $out['widget_avatar_style'] = in_array($avatar, array('site_icon', 'initial', 'chat'), true)
            ? $avatar
            : (string) $defaults['widget_avatar_style'];
        $out['widget_message_density'] = in_array($density, array('comfortable', 'compact'), true)
            ? $density
            : (string) $defaults['widget_message_density'];
        $out['widget_product_image_ratio'] = in_array($imageRatio, array('1-1', '4-3', '3-4', '16-9'), true)
            ? $imageRatio
            : (string) $defaults['widget_product_image_ratio'];

        $palette = WidgetAppearance::palette($theme);
        foreach (WidgetAppearance::colorSettings() as $setting => $paletteKey) {
            $out[$setting] = WidgetAppearance::normalizeColor(
                $stored[$setting] ?? null,
                $palette[$paletteKey]
            );
        }

        foreach (array(
            'widget_button_text' => 80,
            'widget_title' => 100,
            'widget_subtitle' => 180,
            'empty_state_hint' => 220,
            'widget_welcome_message' => 240,
            'widget_quick_prompt_1' => 120,
            'widget_quick_prompt_2' => 120,
            'widget_quick_prompt_3' => 120,
        ) as $key => $maxLength) {
            $candidate = is_string($stored[$key] ?? null)
                ? sanitize_text_field((string) $stored[$key])
                : '';
            $candidate = Text::slice($candidate, 0, $maxLength);
            $out[$key] = $candidate !== '' ? $candidate : (string) $defaults[$key];
        }

        $guidance = is_string($stored['store_guidance'] ?? null)
            ? (string) $stored['store_guidance']
            : '';
        $out['store_guidance'] = sanitize_textarea_field(Text::slice($guidance, 0, 20000));

        $storedSynonyms = is_string($stored['catalog_synonyms'] ?? null)
            ? (string) $stored['catalog_synonyms']
            : '';
        $out['catalog_synonyms'] = (new CatalogSynonymMap(
            $storedSynonyms,
            new CatalogTextNormalizer(),
            false
        ))->canonicalText();

        $sameOriginUrls = new SameOriginUrl(home_url('/'));
        foreach (array('contact_url', 'about_url', 'shipping_url', 'returns_url', 'terms_url', 'account_url') as $key) {
            $candidate = is_string($stored[$key] ?? null) ? (string) $stored[$key] : '';
            $out[$key] = $sameOriginUrls->sanitize(esc_url_raw($candidate, array('http', 'https')));
        }

        return $out;
    }

    private function storedBoolean(mixed $value, int $default): int
    {
        if ($value === true || $value === 1 || $value === '1') {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return 0;
        }
        return $default;
    }

    private function storedInteger(mixed $value, int $min, int $max, int $default): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) === 1) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($validated)) {
                return $default;
            }
            $integer = $validated;
        } else {
            return $default;
        }

        return $integer >= $min && $integer <= $max ? $integer : $default;
    }

    public function migrateLegacySecret(): void
    {
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            return;
        }
        $key = $stored['gemini_api_key'] ?? '';
        if (!is_string($key) || $key === '' || $this->secretBox->isEncrypted($key)) {
            return;
        }
        try {
            $stored['gemini_api_key'] = $this->secretBox->encrypt($key);
            update_option(self::OPTION_KEY, $stored, false);
            $this->cache = null;
        } catch (\Throwable) {
            // The admin page will expose the secure-storage error on the next save.
        }
    }

    /** @param array<string,mixed> $input */
    private function int(array $input, string $key, int $min, int $max, int $default): int
    {
        $value = filter_var($input[$key] ?? $default, FILTER_VALIDATE_INT);
        return is_int($value) ? max($min, min($max, $value)) : $default;
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key, int $maxLength, string $default): string
    {
        $value = sanitize_text_field($this->stringValue($input[$key] ?? null, $default));
        $value = $value === '' ? $default : $value;
        return Text::slice($value, 0, $maxLength);
    }

    private function checked(mixed $value): bool
    {
        return in_array($value, array(1, '1', true, 'on'), true);
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private function model(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,100}$/', $value) === 1
            ? $value
            : 'gemini-3.7-flash';
    }
}
