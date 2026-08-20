<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Admin;

use YassinStore\AiAssistant\Application\Contract\AiProvider;
use YassinStore\AiAssistant\Application\Contract\ConversationBusy;
use YassinStore\AiAssistant\Application\Contract\ConversationRepository;
use YassinStore\AiAssistant\Application\Contract\RateLimiter;
use YassinStore\AiAssistant\Application\Tool\ToolRegistry;
use YassinStore\AiAssistant\Infrastructure\Security\TrustedProxyResolver;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\CartSessionPersistence;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Infrastructure\WordPress\WidgetAppearance;

final class AdminPage
{
    private const SLUG = 'ysai-assistant';
    private const NOTICE_PREFIX = 'ysai_admin_notice_';

    public function __construct(
        private readonly Settings $settings,
        private readonly AiProvider $provider,
        private readonly ConversationRepository $conversations,
        private readonly RateLimiter $rateLimiter,
        private readonly ToolRegistry $tools,
        private readonly CartSessionPersistence $cartSessionPersistence,
        private readonly TrustedProxyResolver $networkResolver
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'settings'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_ysai_test_connection', array($this, 'testConnection'));
        add_action('admin_post_ysai_cleanup', array($this, 'cleanup'));
        add_action('admin_post_ysai_delete_data', array($this, 'deleteData'));
        add_filter('option_page_capability_ysai_settings', static fn (): string => 'manage_woocommerce');
    }

    public function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Yassin AI Assistant', 'yassin-ai-assistant'),
            __('AI Assistant', 'yassin-ai-assistant'),
            'manage_woocommerce',
            self::SLUG,
            array($this, 'render')
        );
    }

    public function settings(): void
    {
        register_setting('ysai_settings', Settings::OPTION_KEY, array(
            'type' => 'array',
            'sanitize_callback' => array($this->settings, 'sanitize'),
            'default' => Settings::defaults(),
        ));
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'woocommerce_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style('ysai-admin', YSAI_PLUGIN_URL . 'assets/css/admin.css', array(), YSAI_VERSION);
        wp_enqueue_script('ysai-admin', YSAI_PLUGIN_URL . 'assets/js/admin.js', array(), YSAI_VERSION, true);
        wp_add_inline_script(
            'ysai-admin',
            'window.YSAIAdminAppearance=' . wp_json_encode(
                array('presets' => WidgetAppearance::presets()),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) . ';',
            'before'
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage this assistant.', 'yassin-ai-assistant'));
        }
        $options = $this->settings->all();
        $previewTokens = WidgetAppearance::cssTokens($options);
        $previewStyle = '';
        foreach ($previewTokens as $name => $value) {
            $previewStyle .= $name . ':' . $value . ';';
        }
        $siteIconUrl = esc_url_raw((string) get_site_icon_url(96));
        $stats = array('conversations' => 0, 'messages' => 0, 'turns' => 0);
        try {
            $stats = $this->conversations->stats();
        } catch (\Throwable) {
            // The readiness card reports database state without breaking settings access.
        }
        $notice = get_transient(self::NOTICE_PREFIX . get_current_user_id());
        if (is_array($notice)) {
            delete_transient(self::NOTICE_PREFIX . get_current_user_id());
        }
        $keyConfigured = false;
        try {
            $keyConfigured = $this->settings->apiKey() !== '';
        } catch (\Throwable) {
            $notice = array('type' => 'error', 'message' => __('The stored API key could not be decrypted. Save a new key.', 'yassin-ai-assistant'));
        }
        try {
            $cartSessionCompatible = $this->cartSessionPersistence->configurationStatus();
        } catch (\Throwable $error) {
            $cartSessionCompatible = false;
            $notice = array(
                'type' => 'error',
                'message' => __('The cart-session persistence adapter failed its readiness check. Cart mutations remain disabled.', 'yassin-ai-assistant'),
            );
        }
        try {
            $networkDiagnostic = $this->networkResolver->diagnostic();
        } catch (\Throwable) {
            $networkDiagnostic = array(
                'status' => 'error',
                'code' => 'invalid_trusted_proxy_configuration',
                'configured' => false,
                'source' => 'unknown',
                'constant_override' => false,
            );
        }
        $networkDiagnosticMessage = $this->networkDiagnosticMessage((string) $networkDiagnostic['code']);
        $ready = $keyConfigured && (bool) $options['enabled'] && $cartSessionCompatible !== false;
        ?>
        <div class="wrap ysai-admin" dir="rtl">
            <header class="ysai-admin__hero">
                <div>
                    <p class="ysai-admin__eyebrow"><?php echo esc_html__('Yassin Store · Commerce AI', 'yassin-ai-assistant'); ?></p>
                    <h1><?php echo esc_html__('مساعد المبيعات الذكي', 'yassin-ai-assistant'); ?></h1>
                    <p><?php echo esc_html__('محادثة عربية أولًا، بيانات متجر مباشرة، وتعديلات سلة موثّقة من الخادم.', 'yassin-ai-assistant'); ?></p>
                </div>
                <span class="ysai-status <?php echo $ready ? 'is-ready' : 'is-warning'; ?>">
                    <?php echo esc_html($ready ? __('جاهز للإعداد النهائي', 'yassin-ai-assistant') : __('يتطلب إعدادًا', 'yassin-ai-assistant')); ?>
                </span>
            </header>

            <?php settings_errors(Settings::OPTION_KEY); ?>
            <?php if ($cartSessionCompatible === false) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html__('معالج جلسة WooCommerce النشط لا يملك محولًا يثبت الكتابة والقراءة المباشرة من التخزين الدائم. سيبقى البحث وعرض السلة متاحين، لكن تعديلات السلة عبر المساعد ستتوقف بأمان حتى استخدام المعالج المدمج لقاعدة البيانات أو محول موثّق.', 'yassin-ai-assistant'); ?></p></div>
            <?php endif; ?>
            <?php if (($networkDiagnostic['status'] ?? 'ok') !== 'ok') : ?>
                <div class="notice notice-<?php echo esc_attr(($networkDiagnostic['status'] ?? '') === 'error' ? 'error' : 'warning'); ?>"><p><?php echo esc_html($networkDiagnosticMessage); ?></p></div>
            <?php endif; ?>
            <?php if (is_array($notice)) : ?>
                <div class="notice notice-<?php echo esc_attr((string) ($notice['type'] ?? 'info')); ?> is-dismissible"><p><?php echo esc_html((string) ($notice['message'] ?? '')); ?></p></div>
            <?php endif; ?>

            <section class="ysai-metrics" aria-label="<?php echo esc_attr__('إحصاءات المحادثة', 'yassin-ai-assistant'); ?>">
                <?php $this->metric(__('المحادثات', 'yassin-ai-assistant'), (int) $stats['conversations']); ?>
                <?php $this->metric(__('الرسائل', 'yassin-ai-assistant'), (int) $stats['messages']); ?>
                <?php $this->metric(__('الطلبات', 'yassin-ai-assistant'), (int) $stats['turns']); ?>
                <?php $this->metric(__('الإصدار', 'yassin-ai-assistant'), YSAI_VERSION); ?>
            </section>

            <form method="post" action="options.php" class="ysai-settings-form">
                <?php settings_fields('ysai_settings'); ?>

                <section class="ysai-card">
                    <div class="ysai-card__heading">
                        <div><h2><?php echo esc_html__('التشغيل والاتصال', 'yassin-ai-assistant'); ?></h2><p><?php echo esc_html__('المفتاح يُشفّر قبل التخزين ولا يصل إلى المتصفح.', 'yassin-ai-assistant'); ?></p></div>
                    </div>
                    <div class="ysai-grid ysai-grid--2">
                        <?php $this->checkbox($options, 'enabled', __('تفعيل المساعد', 'yassin-ai-assistant'), __('يوقف المحادثات الجديدة دون حذف البيانات.', 'yassin-ai-assistant')); ?>
                        <?php $this->checkbox($options, 'allow_images', __('السماح بصور المنتجات', 'yassin-ai-assistant'), __('JPEG وPNG وWebP حتى 4 ميجابايت؛ لا تُحفظ الصورة.', 'yassin-ai-assistant')); ?>
                        <label class="ysai-field ysai-field--wide">
                            <span><?php echo esc_html__('Gemini API key', 'yassin-ai-assistant'); ?></span>
                            <input type="password" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[gemini_api_key]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($keyConfigured ? '••••••••••••••••' : 'AIza…'); ?>">
                            <small><?php echo esc_html($keyConfigured ? __('يوجد مفتاح محفوظ. اترك الحقل فارغًا للاحتفاظ به.', 'yassin-ai-assistant') : __('أدخل مفتاحًا صالحًا من Google AI Studio.', 'yassin-ai-assistant')); ?></small>
                        </label>
                        <label class="ysai-check ysai-field--wide">
                            <input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[clear_gemini_api_key]" value="1">
                            <span><strong><?php echo esc_html__('حذف المفتاح المحفوظ', 'yassin-ai-assistant'); ?></strong></span>
                        </label>
                        <?php $this->text($options, 'gemini_model', __('النموذج', 'yassin-ai-assistant'), 'gemini-3.7-flash'); ?>
                        <?php $this->select($options, 'gemini_thinking_level', __('مستوى التفكير', 'yassin-ai-assistant'), array('low' => 'Low', 'medium' => 'Medium', 'high' => 'High')); ?>
                        <?php $this->number($options, 'max_output_tokens', __('حد رموز الإخراج', 'yassin-ai-assistant'), 256, 8192); ?>
                        <?php $this->number($options, 'http_timeout_seconds', __('مهلة الاتصال (ثانية)', 'yassin-ai-assistant'), 10, 90); ?>
                        <?php $this->number($options, 'max_tool_rounds', __('حد جولات الأدوات', 'yassin-ai-assistant'), 2, 10); ?>
                        <label class="ysai-field ysai-field--wide">
                            <span><?php echo esc_html__('إرشادات المتجر', 'yassin-ai-assistant'); ?></span>
                            <textarea name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[store_guidance]" rows="6" maxlength="20000"><?php echo esc_textarea((string) $options['store_guidance']); ?></textarea>
                            <small><?php echo esc_html__('أضف أسلوب البيع أو معلومات العمل. لا تستخدم هذا الحقل لتجاوز قواعد الأمان أو دقة السلة.', 'yassin-ai-assistant'); ?></small>
                        </label>
                        <label class="ysai-field ysai-field--wide">
                            <span><?php echo esc_html__('مرادفات البحث في الكتالوج', 'yassin-ai-assistant'); ?></span>
                            <textarea name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[catalog_synonyms]" rows="6" maxlength="12000" dir="auto" placeholder="حذاء رياضي, sneaker, سنيكر
هاتف, جوال, mobile"><?php echo esc_textarea((string) ($options['catalog_synonyms'] ?? '')); ?></textarea>
                            <small><?php echo esc_html__('استخدم سطرًا لكل مجموعة، وافصل المصطلحات بفاصلة. تُستخدم للاسترجاع فقط ولا تغيّر حقائق WooCommerce.', 'yassin-ai-assistant'); ?></small>
                        </label>
                    </div>
                </section>

                <section class="ysai-card ysai-appearance-card" data-ysai-appearance>
                    <div class="ysai-card__heading ysai-card__heading--appearance">
                        <div>
                            <h2><?php echo esc_html__('واجهة المحادثة', 'yassin-ai-assistant'); ?></h2>
                            <p><?php echo esc_html__('صمّم تجربة تشبه تطبيقات المراسلة المألوفة، مع معاينة مباشرة لسطح المكتب والهاتف.', 'yassin-ai-assistant'); ?></p>
                        </div>
                        <div class="ysai-preview-devices" role="group" aria-label="<?php echo esc_attr__('حجم المعاينة', 'yassin-ai-assistant'); ?>">
                            <button type="button" class="is-active" aria-pressed="true" data-ysai-preview-device="desktop"><?php echo esc_html__('سطح المكتب', 'yassin-ai-assistant'); ?></button>
                            <button type="button" aria-pressed="false" data-ysai-preview-device="mobile"><?php echo esc_html__('هاتف', 'yassin-ai-assistant'); ?></button>
                            <button type="button" aria-pressed="false" data-ysai-preview-device="compact"><?php echo esc_html__('هاتف صغير', 'yassin-ai-assistant'); ?></button>
                        </div>
                    </div>

                    <div class="ysai-appearance-layout">
                        <div class="ysai-appearance-controls">
                            <details open>
                                <summary><?php echo esc_html__('التشغيل والهوية', 'yassin-ai-assistant'); ?></summary>
                                <div class="ysai-grid ysai-grid--2 ysai-control-group">
                                    <?php $this->checkbox($options, 'widget_enabled', __('تفعيل الودجت', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->checkbox($options, 'widget_auto_insert', __('إدراج تلقائي', 'yassin-ai-assistant'), __('يمكن أيضًا استخدام [yassin_ai_assistant].', 'yassin-ai-assistant')); ?>
                                    <?php $this->text($options, 'widget_button_text', __('نص زر الفتح', 'yassin-ai-assistant'), 'مساعدة ياسين'); ?>
                                    <?php $this->select($options, 'widget_launcher_style', __('شكل زر الفتح', 'yassin-ai-assistant'), array('pill' => __('زر مع نص', 'yassin-ai-assistant'), 'circle' => __('دائرة', 'yassin-ai-assistant'))); ?>
                                    <?php $this->select($options, 'widget_position', __('موضع الزر', 'yassin-ai-assistant'), array('right' => __('يمين', 'yassin-ai-assistant'), 'left' => __('يسار', 'yassin-ai-assistant'))); ?>
                                    <?php $this->select($options, 'widget_avatar_style', __('هوية المساعد', 'yassin-ai-assistant'), array('site_icon' => __('أيقونة الموقع', 'yassin-ai-assistant'), 'initial' => __('حرف ي', 'yassin-ai-assistant'), 'chat' => __('رمز محادثة', 'yassin-ai-assistant'))); ?>
                                    <?php $this->checkbox($options, 'widget_launcher_show_label_mobile', __('إظهار نص الزر على الهاتف', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->checkbox($options, 'widget_show_presence', __('إظهار حالة الاتصال', 'yassin-ai-assistant'), __('نقطة الحضور ونص متصل/يكتب الآن.', 'yassin-ai-assistant')); ?>
                                </div>
                            </details>

                            <details open>
                                <summary><?php echo esc_html__('النصوص والرسائل', 'yassin-ai-assistant'); ?></summary>
                                <div class="ysai-grid ysai-grid--2 ysai-control-group">
                                    <?php $this->text($options, 'widget_title', __('اسم المساعد', 'yassin-ai-assistant'), 'مساعدة متجر ياسين'); ?>
                                    <?php $this->text($options, 'widget_subtitle', __('وصف مختصر', 'yassin-ai-assistant'), 'اسأل عن المنتجات والأسعار والسلة'); ?>
                                    <?php $this->text($options, 'widget_welcome_message', __('رسالة الترحيب', 'yassin-ai-assistant'), 'مرحبًا! كيف أقدر أساعدك؟'); ?>
                                    <?php $this->text($options, 'empty_state_hint', __('تلميح البداية', 'yassin-ai-assistant'), 'اكتب ما تبحث عنه.'); ?>
                                    <?php $this->select($options, 'widget_message_density', __('كثافة الرسائل', 'yassin-ai-assistant'), array('comfortable' => __('مريحة', 'yassin-ai-assistant'), 'compact' => __('مدمجة', 'yassin-ai-assistant'))); ?>
                                    <?php $this->checkbox($options, 'widget_show_timestamps', __('إظهار الوقت وفواصل الأيام', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->checkbox($options, 'widget_show_message_actions', __('إظهار النسخ والرد', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->checkbox($options, 'widget_show_unread_button', __('زر أحدث الرسائل والعداد', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->checkbox($options, 'widget_quick_replies_enabled', __('إظهار الردود السريعة', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->text($options, 'widget_quick_prompt_1', __('رد سريع 1', 'yassin-ai-assistant'), 'رشّح لي منتجات مناسبة'); ?>
                                    <?php $this->text($options, 'widget_quick_prompt_2', __('رد سريع 2', 'yassin-ai-assistant'), 'قارن بين الخيارات'); ?>
                                    <?php $this->text($options, 'widget_quick_prompt_3', __('رد سريع 3', 'yassin-ai-assistant'), 'ما سياسات الشحن والاسترجاع؟'); ?>
                                </div>
                            </details>

                            <details>
                                <summary><?php echo esc_html__('الألوان والمقاسات', 'yassin-ai-assistant'); ?></summary>
                                <div class="ysai-grid ysai-grid--2 ysai-control-group">
                                    <?php
                                    $themeChoices = array();
                                    foreach (WidgetAppearance::presets() as $preset => $palette) {
                                        $themeChoices[$preset] = $palette['label'];
                                    }
                                    $this->select($options, 'widget_theme_preset', __('نمط الألوان', 'yassin-ai-assistant'), $themeChoices);
                                    ?>
                                    <div class="ysai-preset-note"><span><?php echo esc_html__('اختيار نمط يحدّث الألوان غير المعدّلة. يمكنك تخصيص كل لون بعد ذلك.', 'yassin-ai-assistant'); ?></span></div>
                                    <?php $this->color($options, 'widget_brand_color', __('لون العلامة', 'yassin-ai-assistant')); ?>
                                    <?php $this->color($options, 'widget_brand_strong_color', __('لون الرأس الداكن', 'yassin-ai-assistant')); ?>
                                    <?php $this->color($options, 'widget_surface_color', __('سطح اللوحة', 'yassin-ai-assistant')); ?>
                                    <?php $this->color($options, 'widget_canvas_color', __('خلفية المحادثة', 'yassin-ai-assistant')); ?>
                                    <?php $this->color($options, 'widget_assistant_bubble_color', __('فقاعة المساعد', 'yassin-ai-assistant')); ?>
                                    <?php $this->color($options, 'widget_user_bubble_color', __('فقاعة المتسوق', 'yassin-ai-assistant')); ?>
                                    <?php $this->color($options, 'widget_receipt_bubble_color', __('فقاعة الإيصال', 'yassin-ai-assistant')); ?>
                                    <?php $this->color($options, 'widget_border_color', __('الحدود', 'yassin-ai-assistant')); ?>
                                    <?php $this->range($options, 'widget_panel_width', __('عرض اللوحة', 'yassin-ai-assistant'), 340, 560, 'px'); ?>
                                    <?php $this->range($options, 'widget_panel_height', __('ارتفاع اللوحة', 'yassin-ai-assistant'), 520, 860, 'px'); ?>
                                    <?php $this->range($options, 'widget_font_size', __('حجم خط المحادثة', 'yassin-ai-assistant'), 13, 19, 'px'); ?>
                                    <?php $this->range($options, 'widget_panel_radius', __('استدارة اللوحة', 'yassin-ai-assistant'), 8, 40, 'px'); ?>
                                    <?php $this->range($options, 'widget_bubble_radius', __('استدارة الرسائل', 'yassin-ai-assistant'), 8, 32, 'px'); ?>
                                </div>
                            </details>

                            <details>
                                <summary><?php echo esc_html__('المنتجات والأدوات', 'yassin-ai-assistant'); ?></summary>
                                <div class="ysai-grid ysai-grid--2 ysai-control-group">
                                    <?php $this->checkbox($options, 'widget_cart_summary_enabled', __('إظهار ملخص السلة', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->checkbox($options, 'widget_conversation_privacy_enabled', __('قائمة التصدير والحذف', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->checkbox($options, 'widget_product_show_description', __('إظهار وصف المنتج', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->checkbox($options, 'widget_product_carousel_indicator_enabled', __('إظهار مؤشر الشرائح', 'yassin-ai-assistant'), ''); ?>
                                    <?php $this->select($options, 'widget_product_layout', __('تخطيط المنتجات', 'yassin-ai-assistant'), array('carousel' => __('شريط منزلق', 'yassin-ai-assistant'), 'grid' => __('شبكة', 'yassin-ai-assistant'), 'list' => __('قائمة', 'yassin-ai-assistant'))); ?>
                                    <?php $this->select($options, 'widget_product_image_ratio', __('نسبة صورة المنتج', 'yassin-ai-assistant'), array('1-1' => '1:1', '4-3' => '4:3', '3-4' => '3:4', '16-9' => '16:9')); ?>
                                    <?php $this->number($options, 'max_display_cards', __('أقصى عدد بطاقات', 'yassin-ai-assistant'), 1, 12); ?>
                                    <?php $this->number($options, 'widget_product_cards_per_view_desktop', __('بطاقات في العرض — سطح المكتب', 'yassin-ai-assistant'), 1, 3); ?>
                                    <?php $this->number($options, 'widget_product_cards_per_view_mobile', __('بطاقات في العرض — الهاتف', 'yassin-ai-assistant'), 1, 2); ?>
                                    <?php $this->range($options, 'widget_product_name_font_size', __('حجم اسم المنتج', 'yassin-ai-assistant'), 13, 20, 'px'); ?>
                                    <?php $this->select($options, 'widget_product_name_font_weight', __('سماكة اسم المنتج', 'yassin-ai-assistant'), array('400' => '400', '500' => '500', '600' => '600', '700' => '700', '800' => '800', '900' => '900')); ?>
                                    <?php $this->number($options, 'widget_product_name_max_lines', __('أسطر اسم المنتج', 'yassin-ai-assistant'), 1, 4); ?>
                                    <?php $this->range($options, 'widget_product_card_radius', __('استدارة بطاقة المنتج', 'yassin-ai-assistant'), 8, 32, 'px'); ?>
                                </div>
                            </details>
                        </div>

                        <aside class="ysai-live-preview" aria-label="<?php echo esc_attr__('معاينة واجهة المحادثة', 'yassin-ai-assistant'); ?>">
                            <div class="ysai-preview-stage" data-ysai-preview-stage data-device="desktop">
                                <div
                                    class="ysai-admin-preview"
                                    data-ysai-preview
                                    data-theme="<?php echo esc_attr((string) $options['widget_theme_preset']); ?>"
                                    data-layout="<?php echo esc_attr((string) $options['widget_product_layout']); ?>"
                                    data-density="<?php echo esc_attr((string) $options['widget_message_density']); ?>"
                                    data-position="<?php echo esc_attr((string) $options['widget_position']); ?>"
                                    data-mobile-label="<?php echo (bool) $options['widget_launcher_show_label_mobile'] ? 'show' : 'hide'; ?>"
                                    style="<?php echo esc_attr($previewStyle); ?>"
                                >
                                    <span class="ysai-admin-preview__disabled" hidden data-preview-disabled><?php echo esc_html__('الودجت متوقف', 'yassin-ai-assistant'); ?></span>
                                    <div class="ysai-admin-preview__launcher" data-preview-launcher>
                                        <span class="ysai-admin-preview__avatar">
                                            <?php if ($siteIconUrl !== '') : ?><img src="<?php echo esc_url($siteIconUrl); ?>" alt=""><?php else : ?><b>ي</b><?php endif; ?>
                                            <i></i>
                                        </span>
                                        <span data-preview-button><?php echo esc_html((string) $options['widget_button_text']); ?></span>
                                        <b class="ysai-admin-preview__unread" data-preview-unread>2</b>
                                    </div>
                                    <div class="ysai-admin-preview__panel" data-preview-panel>
                                        <header>
                                            <span class="ysai-admin-preview__avatar" data-preview-avatar>
                                                <?php if ($siteIconUrl !== '') : ?><img src="<?php echo esc_url($siteIconUrl); ?>" alt=""><?php else : ?><b>ي</b><?php endif; ?>
                                                <i></i>
                                            </span>
                                            <div><strong data-preview-title><?php echo esc_html((string) $options['widget_title']); ?></strong><small data-preview-subtitle><?php echo esc_html((string) $options['widget_subtitle']); ?></small><em data-preview-presence>● <?php echo esc_html__('متصل الآن', 'yassin-ai-assistant'); ?></em></div>
                                            <span class="ysai-admin-preview__dots" data-preview-privacy>•••</span>
                                        </header>
                                        <div class="ysai-admin-preview__cart" data-preview-cart><strong><?php echo esc_html__('السلة · منتجان', 'yassin-ai-assistant'); ?></strong><span>298 ر.س</span></div>
                                        <div class="ysai-admin-preview__messages">
                                            <span class="ysai-admin-preview__day"><?php echo esc_html__('اليوم', 'yassin-ai-assistant'); ?></span>
                                            <div class="ysai-admin-preview__row is-agent"><span class="ysai-admin-preview__mini-avatar">ي</span><div class="ysai-admin-preview__bubble is-agent"><p data-preview-welcome><?php echo esc_html((string) $options['widget_welcome_message']); ?></p><time>10:24</time></div></div>
                                            <div class="ysai-admin-preview__quick" data-preview-quick>
                                                <span data-preview-quick-1><?php echo esc_html((string) $options['widget_quick_prompt_1']); ?></span>
                                                <span data-preview-quick-2><?php echo esc_html((string) $options['widget_quick_prompt_2']); ?></span>
                                                <span data-preview-quick-3><?php echo esc_html((string) $options['widget_quick_prompt_3']); ?></span>
                                            </div>
                                            <div class="ysai-admin-preview__row is-shopper"><div class="ysai-admin-preview__bubble is-shopper"><p><?php echo esc_html__('أبحث عن منتج مناسب كهدية.', 'yassin-ai-assistant'); ?></p><time>10:25 ✓</time></div></div>
                                            <div class="ysai-admin-preview__response">
                                                <div class="ysai-admin-preview__row is-agent"><span class="ysai-admin-preview__mini-avatar">ي</span><div class="ysai-admin-preview__bubble is-agent"><p><?php echo esc_html__('بكل سرور. هذه بداية جيدة ويمكنني المقارنة بينها لك.', 'yassin-ai-assistant'); ?></p><time>10:25</time></div></div>
                                                <span class="ysai-admin-preview__actions" data-preview-actions><?php echo esc_html__('نسخ · رد', 'yassin-ai-assistant'); ?></span>
                                            </div>
                                            <div class="ysai-admin-preview__products" data-preview-products>
                                                <?php foreach (array(
                                                    array(__('منتج مقترح من المتجر', 'yassin-ai-assistant'), '149 ر.س'),
                                                    array(__('خيار بديل مناسب', 'yassin-ai-assistant'), '179 ر.س'),
                                                    array(__('منتج اقتصادي', 'yassin-ai-assistant'), '99 ر.س'),
                                                ) as $previewProduct) : ?>
                                                    <div class="ysai-admin-preview__product" data-preview-product>
                                                        <span></span><div><strong><?php echo esc_html((string) $previewProduct[0]); ?></strong><b><?php echo esc_html((string) $previewProduct[1]); ?></b><small data-preview-product-description><?php echo esc_html__('وصف موجز يساعد المتسوق على اتخاذ القرار.', 'yassin-ai-assistant'); ?></small><button type="button" tabindex="-1"><?php echo esc_html__('اسأل عن المنتج', 'yassin-ai-assistant'); ?></button></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="ysai-admin-preview__product-nav" data-preview-product-nav><button type="button" tabindex="-1">‹</button><span>1 / 3</span><button type="button" tabindex="-1">›</button></div>
                                            <span class="ysai-admin-preview__latest" data-preview-latest>↓ 2</span>
                                            <div class="ysai-admin-preview__typing"><i></i><i></i><i></i></div>
                                        </div>
                                        <footer><span>＋</span><div><?php echo esc_html__('اكتب رسالتك…', 'yassin-ai-assistant'); ?></div><b>➤</b></footer>
                                    </div>
                                </div>
                            </div>
                            <p class="ysai-preview-help"><?php echo esc_html__('المعاينة تقريبية لكنها تستخدم ألوان ومقاسات الإضافة نفسها. احفظ الإعدادات لتطبيقها على المتجر.', 'yassin-ai-assistant'); ?></p>
                        </aside>
                    </div>
                </section>


                <section class="ysai-card">
                    <div class="ysai-card__heading"><div><h2><?php echo esc_html__('روابط المتجر', 'yassin-ai-assistant'); ?></h2><p><?php echo esc_html__('لا يقبل المساعد إلا روابط من نفس نطاق المتجر.', 'yassin-ai-assistant'); ?></p></div></div>
                    <div class="ysai-grid ysai-grid--2">
                        <?php foreach (array('contact_url' => __('التواصل', 'yassin-ai-assistant'), 'about_url' => __('من نحن', 'yassin-ai-assistant'), 'shipping_url' => __('الشحن', 'yassin-ai-assistant'), 'returns_url' => __('الاسترجاع', 'yassin-ai-assistant'), 'terms_url' => __('الشروط', 'yassin-ai-assistant'), 'account_url' => __('الحساب', 'yassin-ai-assistant')) as $key => $label) { $this->url($options, $key, $label); } ?>
                    </div>
                </section>

                <section class="ysai-card">
                    <div class="ysai-card__heading"><div><h2><?php echo esc_html__('الخصوصية والحدود', 'yassin-ai-assistant'); ?></h2><p><?php echo esc_html__('البيانات القديمة من الإصدار السابق لا تُهاجر ولا تُحذف تلقائيًا.', 'yassin-ai-assistant'); ?></p></div></div>
                    <div class="ysai-grid ysai-grid--2">
                        <?php $this->number($options, 'assistant_session_minutes', __('انتهاء الجلسة بالدقائق (0 = غير مفعّل)', 'yassin-ai-assistant'), 0, 10080); ?>
                        <?php $this->number($options, 'conversation_retention_days', __('الاحتفاظ بالمحادثة (أيام)', 'yassin-ai-assistant'), 1, 365); ?>
                        <?php $this->number($options, 'rate_limit_turns', __('طلبات المحادثة لكل 5 دقائق', 'yassin-ai-assistant'), 5, 500); ?>
                        <?php $this->number($options, 'daily_ai_turn_limit', __('الحد اليومي لطلبات الذكاء الاصطناعي', 'yassin-ai-assistant'), 50, 100000); ?>
                        <?php $this->number($options, 'daily_conversation_limit', __('الحد اليومي للمحادثات الجديدة', 'yassin-ai-assistant'), 100, 100000); ?>
                        <?php $this->select($options, 'trusted_proxy_header', __('ترويسة عنوان العميل من الوكيل', 'yassin-ai-assistant'), array(
                            TrustedProxyResolver::HEADER_X_FORWARDED_FOR => 'X-Forwarded-For',
                            TrustedProxyResolver::HEADER_FORWARDED => 'Forwarded',
                        )); ?>
                        <label class="ysai-field ysai-field--wide">
                            <span><?php echo esc_html__('شبكات الوكيل الموثوق (CIDR)', 'yassin-ai-assistant'); ?></span>
                            <textarea dir="ltr" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[trusted_proxy_cidrs]" rows="5" maxlength="8192" placeholder="10.0.0.0/8&#10;2001:db8:1234::/48"><?php echo esc_textarea((string) ($options['trusted_proxy_cidrs'] ?? '')); ?></textarea>
                            <small><?php echo esc_html__('اتركها فارغة ما لم يكن WordPress خلف وكيل أو CDN موثوق. لا تُقبل الترويسة المختارة إلا عندما يطابق REMOTE_ADDR إحدى هذه الشبكات. يمكن للتعريفين YSAI_TRUSTED_PROXY_CIDRS وYSAI_TRUSTED_PROXY_HEADER تجاوز إعدادات الصفحة.', 'yassin-ai-assistant'); ?></small>
                        </label>
                        <?php $this->checkbox($options, 'diagnostic_logging', __('سجل تشخيصي محدود', 'yassin-ai-assistant'), __('لا يسجل المفاتيح أو الرموز أو الصور.', 'yassin-ai-assistant')); ?>
                        <?php $this->checkbox($options, 'delete_data_on_uninstall', __('حذف بيانات الإصدار الجديد عند إزالة الإضافة', 'yassin-ai-assistant'), __('لا يشمل جداول الإضافة القديمة.', 'yassin-ai-assistant')); ?>
                    </div>
                </section>

                <?php submit_button(__('حفظ الإعدادات', 'yassin-ai-assistant'), 'primary large'); ?>
            </form>

            <section class="ysai-card ysai-card--actions">
                <div class="ysai-card__heading"><div><h2><?php echo esc_html__('الصيانة والتحقق', 'yassin-ai-assistant'); ?></h2><p><?php echo esc_html__('نفّذ الاختبارات والصيانة يدويًا دون تعديل الإعدادات.', 'yassin-ai-assistant'); ?></p></div></div>
                <div class="ysai-actions">
                    <?php $this->actionForm('ysai_test_connection', __('اختبار اتصال Gemini', 'yassin-ai-assistant'), 'button button-secondary'); ?>
                    <?php $this->actionForm('ysai_cleanup', __('تنظيف المحادثات المنتهية', 'yassin-ai-assistant'), 'button button-secondary'); ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ysai-danger-zone" data-ysai-danger>
                        <input type="hidden" name="action" value="ysai_delete_data">
                        <?php wp_nonce_field('ysai_delete_data'); ?>
                        <label><span><?php echo esc_html__('اكتب DELETE لحذف كل محادثات الإصدار الجديد:', 'yassin-ai-assistant'); ?></span><input type="text" name="confirmation" autocomplete="off" required></label>
                        <button type="submit" class="button button-link-delete"><?php echo esc_html__('حذف بيانات المحادثة', 'yassin-ai-assistant'); ?></button>
                    </form>
                </div>
            </section>
        </div>
        <?php
    }

    public function testConnection(): void
    {
        $this->authorizeAction('ysai_test_connection');
        try {
            $result = $this->provider->readinessCheck($this->tools->schemas());
            $message = ($result['ready'] ?? false)
                ? sprintf(__('نجح اتصال النموذج %s واختبار حزمة أدوات المحادثة الفعلية.', 'yassin-ai-assistant'), (string) ($result['model'] ?? ''))
                : __('اكتمل الاتصال لكن اختبار الجاهزية لم ينجح.', 'yassin-ai-assistant');
            $this->notice(($result['ready'] ?? false) ? 'success' : 'warning', $message);
        } catch (\Throwable $error) {
            $this->notice('error', sprintf(__('فشل اختبار الاتصال: %s', 'yassin-ai-assistant'), $error->getMessage()));
        }
        $this->redirect();
    }

    public function cleanup(): void
    {
        $this->authorizeAction('ysai_cleanup');
        try {
            $conversations = $this->conversations->purgeExpired();
            $buckets = $this->rateLimiter->purge();
            $this->notice('success', sprintf(__('حُذفت %1$d محادثة منتهية و%2$d سجل حدود.', 'yassin-ai-assistant'), $conversations, $buckets));
        } catch (\Throwable $error) {
            $this->notice('error', sprintf(__('فشل التنظيف: %s', 'yassin-ai-assistant'), $error->getMessage()));
        }
        $this->redirect();
    }

    public function deleteData(): void
    {
        $this->authorizeAction('ysai_delete_data');
        if (!hash_equals('DELETE', strtoupper(trim((string) ($_POST['confirmation'] ?? ''))))) {
            $this->notice('error', __('لم يتم الحذف لأن عبارة التأكيد غير صحيحة.', 'yassin-ai-assistant'));
            $this->redirect();
        }
        try {
            $this->conversations->deleteAll();
            $this->rateLimiter->clear();
            $this->notice('success', __('حُذفت جميع محادثات الإصدار الجديد وسجلات حدود الاستخدام.', 'yassin-ai-assistant'));
        } catch (ConversationBusy) {
            $this->notice(
                'warning',
                __('تعذّر حذف البيانات لأن طلبًا واحدًا على الأقل ما زال قيد المعالجة. عطّل المساعد مؤقتًا، تحقّق من الطلبات المعلّقة، ثم أعد المحاولة.', 'yassin-ai-assistant')
            );
        } catch (\Throwable $error) {
            $this->notice('error', sprintf(__('فشل حذف البيانات: %s', 'yassin-ai-assistant'), $error->getMessage()));
        }
        $this->redirect();
    }


    private function authorizeAction(string $nonce): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'yassin-ai-assistant'));
        }
        check_admin_referer($nonce);
    }

    private function notice(string $type, string $message): void
    {
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('type' => $type, 'message' => $message), 60);
    }

    private function redirect(): never
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private function metric(string $label, string|int $value): void
    {
        echo '<article class="ysai-metric"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong></article>';
    }

    /** @param array<string,mixed> $options */
    private function checkbox(array $options, string $key, string $label, string $help): void
    {
        ?>
        <label class="ysai-check">
            <input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_KEY . '[' . $key . ']'); ?>" value="1" <?php checked((bool) ($options[$key] ?? false)); ?>>
            <span><strong><?php echo esc_html($label); ?></strong><?php if ($help !== '') : ?><small><?php echo esc_html($help); ?></small><?php endif; ?></span>
        </label>
        <?php
    }

    /** @param array<string,mixed> $options */
    private function text(array $options, string $key, string $label, string $placeholder): void
    {
        ?><label class="ysai-field"><span><?php echo esc_html($label); ?></span><input type="text" name="<?php echo esc_attr(Settings::OPTION_KEY . '[' . $key . ']'); ?>" value="<?php echo esc_attr((string) ($options[$key] ?? '')); ?>" placeholder="<?php echo esc_attr($placeholder); ?>"></label><?php
    }

    /** @param array<string,mixed> $options */
    private function url(array $options, string $key, string $label): void
    {
        ?><label class="ysai-field"><span><?php echo esc_html($label); ?></span><input type="url" dir="ltr" name="<?php echo esc_attr(Settings::OPTION_KEY . '[' . $key . ']'); ?>" value="<?php echo esc_attr((string) ($options[$key] ?? '')); ?>" placeholder="<?php echo esc_attr(home_url('/')); ?>"></label><?php
    }

    /** @param array<string,mixed> $options */
    private function number(array $options, string $key, string $label, int $min, int $max): void
    {
        ?><label class="ysai-field"><span><?php echo esc_html($label); ?></span><input type="number" min="<?php echo esc_attr((string) $min); ?>" max="<?php echo esc_attr((string) $max); ?>" name="<?php echo esc_attr(Settings::OPTION_KEY . '[' . $key . ']'); ?>" value="<?php echo esc_attr((string) ($options[$key] ?? '')); ?>"></label><?php
    }

    /** @param array<string,mixed> $options */
    private function color(array $options, string $key, string $label): void
    {
        $value = (string) ($options[$key] ?? '#000000');
        ?>
        <label class="ysai-field ysai-color-field">
            <span><?php echo esc_html($label); ?></span>
            <span class="ysai-color-control">
                <input type="color" name="<?php echo esc_attr(Settings::OPTION_KEY . '[' . $key . ']'); ?>" value="<?php echo esc_attr($value); ?>" data-ysai-color>
                <code data-ysai-color-value><?php echo esc_html($value); ?></code>
            </span>
        </label>
        <?php
    }

    /** @param array<string,mixed> $options */
    private function range(array $options, string $key, string $label, int $min, int $max, string $unit = ''): void
    {
        $value = (int) ($options[$key] ?? $min);
        ?>
        <label class="ysai-field ysai-range-field">
            <span><?php echo esc_html($label); ?></span>
            <span class="ysai-range-control">
                <input type="range" min="<?php echo esc_attr((string) $min); ?>" max="<?php echo esc_attr((string) $max); ?>" name="<?php echo esc_attr(Settings::OPTION_KEY . '[' . $key . ']'); ?>" value="<?php echo esc_attr((string) $value); ?>" data-ysai-range>
                <output data-ysai-range-output data-unit="<?php echo esc_attr($unit); ?>"><?php echo esc_html((string) $value . $unit); ?></output>
            </span>
        </label>
        <?php
    }

    /** @param array<string,mixed> $options @param array<string,string> $choices */
    private function select(array $options, string $key, string $label, array $choices): void
    {
        ?><label class="ysai-field"><span><?php echo esc_html($label); ?></span><select name="<?php echo esc_attr(Settings::OPTION_KEY . '[' . $key . ']'); ?>"><?php foreach ($choices as $value => $choice) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected((string) ($options[$key] ?? ''), $value); ?>><?php echo esc_html($choice); ?></option><?php endforeach; ?></select></label><?php
    }

    private function actionForm(string $action, string $label, string $class): void
    {
        ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo esc_attr($action); ?>"><?php wp_nonce_field($action); ?><button type="submit" class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button></form><?php
    }

    private function networkDiagnosticMessage(string $code): string
    {
        return match ($code) {
            'forwarding_headers_unconfigured' => __('اكتشف WordPress ترويسة عنوان عميل من وكيل، لكن لا توجد شبكات وكيل موثوقة. تُهمل الترويسة بأمان، وقد يشترك جميع الزوار خلف الوكيل في هوية حد طلبات واحدة.', 'yassin-ai-assistant'),
            'untrusted_peer_forwarding_ignored' => __('وصلت ترويسة عنوان عميل من نظير لا يطابق شبكات الوكيل الموثوقة، لذلك تم تجاهلها. راجع عناوين CIDR للوكيل الفعلي.', 'yassin-ai-assistant'),
            'trusted_proxy_header_missing' => __('يطابق REMOTE_ADDR شبكة وكيل موثوقة، لكن الترويسة المختارة غير موجودة. قد يشترك الزوار في هوية حد طلبات واحدة حتى يصحح الوكيل الإعداد.', 'yassin-ai-assistant'),
            'trusted_proxy_header_mismatch' => __('وصلت ترويسة وكيل مختلفة عن الترويسة التي اخترتها. تم تجاهل جميع القيم المحولة؛ وحّد إعداد الوكيل مع إعداد الإضافة.', 'yassin-ai-assistant'),
            'invalid_forwarding_header' => __('ترويسة عنوان العميل من الوكيل غير صالحة أو تتجاوز الحدود الآمنة، ولذلك تم تجاهلها.', 'yassin-ai-assistant'),
            'invalid_trusted_proxy_configuration' => __('إعداد شبكات الوكيل الموثوق غير صالح. لا تثق الإضافة بأي ترويسة محولة حتى تصحيح CIDR أو قيمة الترويسة.', 'yassin-ai-assistant'),
            'remote_address_invalid' => __('لا يستطيع الخادم التحقق من REMOTE_ADDR. ستستخدم الطلبات هوية محافظة مشتركة حتى يُصلح إعداد خادم الويب.', 'yassin-ai-assistant'),
            default => __('هوية الشبكة تعمل بالإعداد الآمن المباشر.', 'yassin-ai-assistant'),
        };
    }
}
