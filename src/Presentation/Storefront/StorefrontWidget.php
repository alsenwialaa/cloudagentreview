<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Storefront;

use YassinStore\AiAssistant\Application\Chat\TurnTimingPolicy;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Infrastructure\WordPress\WidgetAppearance;
use YassinStore\AiAssistant\Presentation\Rest\RestController;

final class StorefrontWidget
{
    private const HANDLE = 'ysai-storefront';
    private bool $rendered = false;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', array($this, 'assets'));
        add_action('wp_footer', array($this, 'autoRender'), 90);
        add_shortcode('yassin_ai_assistant', array($this, 'shortcode'));
        add_filter('script_loader_tag', array($this, 'moduleTag'), 10, 3);
    }

    public function assets(): void
    {
        if (!$this->available()) {
            return;
        }
        wp_enqueue_style(
            self::HANDLE,
            YSAI_PLUGIN_URL . 'assets/css/widget.css',
            array(),
            $this->assetVersion('assets/css/widget.css')
        );
        wp_enqueue_script(
            self::HANDLE,
            YSAI_PLUGIN_URL . 'assets/js/widget.js',
            array(),
            $this->assetVersion('assets/js/widget.js'),
            true
        );
        wp_add_inline_script(
            self::HANDLE,
            'window.YSAIAssistantConfig=' . wp_json_encode(
                $this->config(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) . ';',
            'before'
        );
    }

    public function autoRender(): void
    {
        if ($this->available() && (bool) $this->settings->get('widget_auto_insert', true)) {
            echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /** @param array<string,mixed> $attributes */
    public function shortcode(array $attributes = array()): string
    {
        return $this->available() ? $this->markup() : '';
    }

    public function moduleTag(string $tag, string $handle, string $src): string
    {
        if ($handle !== self::HANDLE) {
            return $tag;
        }
        return str_contains($tag, ' type=')
            ? preg_replace('/\stype=("|\')[^"\']*\1/', ' type="module"', $tag, 1) ?? $tag
            : str_replace('<script ', '<script type="module" ', $tag);
    }

    private function available(): bool
    {
        return !is_admin()
            && !wp_doing_ajax()
            && !is_feed()
            && (bool) $this->settings->get('enabled', true)
            && (bool) $this->settings->get('widget_enabled', true)
            && class_exists('WooCommerce');
    }

    private function markup(): string
    {
        if ($this->rendered) {
            return '';
        }
        $this->rendered = true;
        $options = $this->settings->all();
        $tokens = WidgetAppearance::cssTokens($options);
        $tokens['--ysai-panel-width'] = (int) $options['widget_panel_width'] . 'px';
        $tokens['--ysai-panel-height'] = (int) $options['widget_panel_height'] . 'px';
        $tokens['--ysai-panel-radius'] = (int) $options['widget_panel_radius'] . 'px';
        $tokens['--ysai-bubble-radius'] = (int) $options['widget_bubble_radius'] . 'px';
        $tokens['--ysai-card-radius'] = (int) $options['widget_product_card_radius'] . 'px';
        $tokens['--ysai-font-size'] = (int) $options['widget_font_size'] . 'px';
        $tokens['--ysai-product-ratio'] = $this->imageRatio((string) $options['widget_product_image_ratio']);
        $tokens['--ysai-products-per-view'] = (string) (int) $options['widget_product_cards_per_view_desktop'];
        $tokens['--ysai-products-per-view-mobile'] = (string) (int) $options['widget_product_cards_per_view_mobile'];
        $tokens['--ysai-product-name-size'] = (int) $options['widget_product_name_font_size'] . 'px';
        $tokens['--ysai-product-name-weight'] = (string) (int) $options['widget_product_name_font_weight'];
        $tokens['--ysai-product-name-lines'] = (string) (int) $options['widget_product_name_max_lines'];

        $style = '';
        foreach ($tokens as $name => $value) {
            $style .= $name . ':' . $value . ';';
        }

        $siteIconUrl = esc_url_raw((string) get_site_icon_url(96));
        $template = YSAI_PLUGIN_DIR . 'templates/widget.php';
        ob_start();
        require $template;
        return (string) ob_get_clean();
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        $options = $this->settings->all();
        $timeoutSeconds = TurnTimingPolicy::browserTimeoutSeconds(
            (int) $options['http_timeout_seconds'],
            (int) $options['max_tool_rounds']
        );
        return array(
            'restBase' => esc_url_raw(rest_url(RestController::NAMESPACE . '/')),
            'storageKey' => 'ysai.v2.' . substr(hash('sha256', home_url('/')), 0, 16),
            'requestTimeoutMs' => $timeoutSeconds * 1000,
            'features' => array(
                'images' => (bool) $options['allow_images'],
                'cartSummary' => (bool) $options['widget_cart_summary_enabled'],
                'privacy' => (bool) $options['widget_conversation_privacy_enabled'],
                'showDescription' => (bool) $options['widget_product_show_description'],
                'presence' => (bool) $options['widget_show_presence'],
                'timestamps' => (bool) $options['widget_show_timestamps'],
                'messageActions' => (bool) $options['widget_show_message_actions'],
                'unreadButton' => (bool) $options['widget_show_unread_button'],
                'carouselIndicator' => (bool) $options['widget_product_carousel_indicator_enabled'],
            ),
            'limits' => array(
                'imageBytes' => 4194304,
                'imageDimension' => 4096,
                'imagePixels' => 12000000,
                'messageCharacters' => 4000,
            ),
            'texts' => array(
                'loading' => __('جارٍ تجهيز المساعد…', 'yassin-ai-assistant'),
                'online' => __('متصل الآن', 'yassin-ai-assistant'),
                'offline' => __('غير متصل', 'yassin-ai-assistant'),
                'thinking' => __('يكتب الآن…', 'yassin-ai-assistant'),
                'genericError' => __('تعذّر إكمال الطلب. حاول مرة أخرى.', 'yassin-ai-assistant'),
                'timeout' => __('استغرق الطلب وقتًا أطول من الحد الآمن. تحقّق من نتيجته قبل إعادة الإرسال.', 'yassin-ai-assistant'),
                'processing' => __('الطلب السابق ما زال قيد المعالجة.', 'yassin-ai-assistant'),
                'unverifiedPrevious' => __('تعذّر التحقق من نتيجة الطلب السابق. افحص السلة قبل إرسال طلب جديد.', 'yassin-ai-assistant'),
                'previousConversationCompleted' => __('اكتمل الطلب السابق ضمن محادثة أخرى. افحص السلة قبل متابعة التسوق.', 'yassin-ai-assistant'),
                'conversationRefreshRequired' => __('اكتمل الطلب، لكن تعذّر تحديث المحادثة. أعد الاتصال قبل المتابعة.', 'yassin-ai-assistant'),
                'notSent' => __('لم يتم إرسال الرسالة.', 'yassin-ai-assistant'),
                'sending' => __('جارٍ الإرسال', 'yassin-ai-assistant'),
                'sent' => __('تم الإرسال', 'yassin-ai-assistant'),
                'messageTooLong' => __('الرسالة أطول من الحد المسموح.', 'yassin-ai-assistant'),
                'secureRandomUnavailable' => __('تعذّر إنشاء معرّف آمن للطلب في هذا المتصفح.', 'yassin-ai-assistant'),
                'pendingStorageUnavailable' => __('يتعذّر حفظ معرّف الطلب بأمان في هذا المتصفح، لذلك لم تُرسل الرسالة.', 'yassin-ai-assistant'),
                'imageRecoveryUnavailable' => __('لم يصل طلب الصورة السابق إلى الخادم. أعد إرفاق الصورة ثم أرسل الطلب من جديد.', 'yassin-ai-assistant'),
                'retry' => __('إعادة المحاولة', 'yassin-ai-assistant'),
                'retryNewTurn' => __('إرسال الطلب من جديد', 'yassin-ai-assistant'),
                'retryAfter' => __('يمكن إعادة المحاولة بعد %d ثانية.', 'yassin-ai-assistant'),
                'reply' => __('رد', 'yassin-ai-assistant'),
                'copy' => __('نسخ', 'yassin-ai-assistant'),
                'copied' => __('تم النسخ', 'yassin-ai-assistant'),
                'copyFailed' => __('تعذّر النسخ. حدّد النص وانسخه يدويًا.', 'yassin-ai-assistant'),
                'askProduct' => __('اسأل عن هذا المنتج', 'yassin-ai-assistant'),
                'askProductNamed' => __('اسأل عن المنتج: %s', 'yassin-ai-assistant'),
                'inStock' => __('متوفر', 'yassin-ai-assistant'),
                'outOfStock' => __('غير متوفر', 'yassin-ai-assistant'),
                'priceUnavailable' => __('السعر غير متاح', 'yassin-ai-assistant'),
                'cart' => __('السلة', 'yassin-ai-assistant'),
                'checkout' => __('الانتقال للدفع', 'yassin-ai-assistant'),
                'emptyCart' => __('السلة فارغة', 'yassin-ai-assistant'),
                'moreCartItems' => __('+%d عناصر أخرى', 'yassin-ai-assistant'),
                'moreCartItemsUnknown' => __('عناصر أخرى في السلة', 'yassin-ai-assistant'),
                'imageTooLarge' => __('الصورة أكبر من 4 ميجابايت.', 'yassin-ai-assistant'),
                'imageInvalid' => __('اختر صورة JPEG أو PNG أو WebP.', 'yassin-ai-assistant'),
                'imageDimensionsInvalid' => __('أبعاد الصورة غير مدعومة. الحد الأقصى 4096 بكسل و12 مليون بكسل إجمالًا.', 'yassin-ai-assistant'),
                'imageUnavailable' => __('تعذّر عرض الصورة', 'yassin-ai-assistant'),
                'deleteConfirm' => __('سيتم حذف المحادثة وذاكرة التسوق نهائيًا. متابعة؟', 'yassin-ai-assistant'),
                'deleteNotConfirmed' => __('تعذّر تأكيد حذف المحادثة، وما زالت الجلسة السابقة متاحة.', 'yassin-ai-assistant'),
                'deleteOutcomeReplaced' => __('فُقد تأكيد الحذف وتعذّر استئناف المحادثة السابقة. بدأت جلسة جديدة دون افتراض أن الحذف اكتمل.', 'yassin-ai-assistant'),
                'deleteOutcomeUnknown' => __('فُقد تأكيد الحذف وتعذّر التحقق من حالة المحادثة. أعد الاتصال قبل أي إجراء آخر.', 'yassin-ai-assistant'),
                'exportIncomplete' => __('تعذّر إكمال تصدير المحادثة ضمن حد الصفحات الآمن.', 'yassin-ai-assistant'),
                'exportName' => 'yassin-ai-conversation',
                'today' => __('اليوم', 'yassin-ai-assistant'),
                'yesterday' => __('أمس', 'yassin-ai-assistant'),
                'latest' => __('أحدث الرسائل', 'yassin-ai-assistant'),
                'newMessages' => __('رسائل جديدة', 'yassin-ai-assistant'),
                'unreadCount' => __('رسائل جديدة: %d', 'yassin-ai-assistant'),
                'previousProducts' => __('المنتجات السابقة', 'yassin-ai-assistant'),
                'nextProducts' => __('المنتجات التالية', 'yassin-ai-assistant'),
                'productsSuggested' => __('المنتجات المقترحة', 'yassin-ai-assistant'),
                'attachedImage' => __('صورة مرفقة', 'yassin-ai-assistant'),
                'verifiedReceipt' => __('إيصال موثّق من الخادم', 'yassin-ai-assistant'),
            ),
        );
    }

    private function imageRatio(string $ratio): string
    {
        return match ($ratio) {
            '4-3' => '4 / 3',
            '3-4' => '3 / 4',
            '16-9' => '16 / 9',
            default => '1 / 1',
        };
    }

    private function assetVersion(string $relativePath): string
    {
        $path = YSAI_PLUGIN_DIR . ltrim($relativePath, '/');
        $modified = is_file($path) ? filemtime($path) : false;
        return $modified === false ? YSAI_VERSION : YSAI_VERSION . '.' . $modified;
    }
}
