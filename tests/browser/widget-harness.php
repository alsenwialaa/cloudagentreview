<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

if (!function_exists('esc_attr')) {
    function esc_attr(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
if (!function_exists('esc_html')) {
    function esc_html(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__(string $value, string $domain = ''): string
    {
        return esc_attr($value);
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $value, string $domain = ''): string
    {
        return esc_html($value);
    }
}

$imagesEnabled = getenv('YSAI_WIDGET_IMAGES') === '1';
$presenceEnabled = getenv('YSAI_WIDGET_PRESENCE') !== '0';
$unreadEnabled = getenv('YSAI_WIDGET_UNREAD') !== '0';

$options = array(
    'widget_conversation_privacy_enabled' => true,
    'allow_images' => $imagesEnabled,
    'widget_position' => 'right',
    'widget_theme_preset' => 'yassin',
    'widget_product_layout' => 'carousel',
    'widget_launcher_style' => 'pill',
    'widget_avatar_style' => 'initial',
    'widget_message_density' => 'comfortable',
    'widget_launcher_show_label_mobile' => false,
    'widget_show_presence' => $presenceEnabled,
    'widget_show_timestamps' => true,
    'widget_show_message_actions' => true,
    'widget_show_unread_button' => $unreadEnabled,
    'widget_quick_replies_enabled' => true,
    'widget_button_text' => 'فتح المساعد',
    'widget_title' => 'مساعد ياسين',
    'widget_subtitle' => 'اختبار المتصفح',
    'widget_welcome_message' => 'مرحبًا! أنا مساعد المتجر.',
    'empty_state_hint' => 'ابدأ بسؤال عن المنتجات.',
    'widget_quick_prompt_1' => 'رشّح لي منتجات مناسبة',
    'widget_quick_prompt_2' => 'قارن بين الخيارات',
    'widget_quick_prompt_3' => 'ما سياسات الشحن والاسترجاع؟',
);
$style = '--ysai-panel-width:420px;--ysai-panel-height:700px;--ysai-panel-radius:24px;--ysai-bubble-radius:18px;--ysai-card-radius:16px;--ysai-font-size:15px;--ysai-product-ratio:1 / 1;--ysai-products-per-view:2;--ysai-products-per-view-mobile:1;--ysai-product-name-size:15px;--ysai-product-name-weight:700;--ysai-product-name-lines:2;';
$siteIconUrl = '';
$config = array(
    'restBase' => '/api/',
    'requestTimeoutMs' => 30_000,
    'storageKey' => 'ysai.browser.contract',
    'features' => array(
        'images' => $imagesEnabled,
        'cartSummary' => true,
        'privacy' => true,
        'showDescription' => true,
        'presence' => $presenceEnabled,
        'timestamps' => true,
        'messageActions' => true,
        'unreadButton' => $unreadEnabled,
        'carouselIndicator' => true,
    ),
    'limits' => array(
        'messageCharacters' => 4_000,
        'imageBytes' => 4_194_304,
        'imageDimension' => 4_096,
        'imagePixels' => 12_000_000,
    ),
    'texts' => array(
        'loading' => 'جارٍ التحميل',
        'online' => 'متصل',
        'offline' => 'غير متصل',
        'genericError' => 'حدث خطأ',
        'copy' => 'نسخ',
        'copied' => 'تم النسخ',
        'copyFailed' => 'تعذّر النسخ',
        'reply' => 'رد',
        'inStock' => 'متوفر',
        'outOfStock' => 'غير متوفر',
        'priceUnavailable' => 'السعر غير متاح',
        'askProduct' => 'اسأل عن المنتج',
        'askProductNamed' => 'اسأل عن المنتج: %s',
        'cart' => 'السلة',
        'emptyCart' => 'السلة فارغة',
        'moreCartItems' => '+%d عناصر أخرى',
        'moreCartItemsUnknown' => 'عناصر أخرى في السلة',
        'checkout' => 'الدفع',
        'thinking' => 'يكتب الآن…',
        'today' => 'اليوم',
        'yesterday' => 'أمس',
        'latest' => 'أحدث الرسائل',
        'newMessages' => 'رسائل جديدة',
        'unreadCount' => 'رسائل جديدة: %d',
        'previousProducts' => 'المنتجات السابقة',
        'nextProducts' => 'المنتجات التالية',
        'productsSuggested' => 'المنتجات المقترحة',
        'attachedImage' => 'صورة مرفقة',
        'imageDimensionsInvalid' => 'أبعاد الصورة غير مدعومة',
        'imageUnavailable' => 'تعذّر عرض الصورة',
        'verifiedReceipt' => 'إيصال موثّق من الخادم',
        'notSent' => 'لم يتم الإرسال',
        'sending' => 'جارٍ الإرسال',
        'sent' => 'تم الإرسال',
        'processing' => 'الطلب السابق ما زال قيد المعالجة.',
        'previousConversationCompleted' => 'اكتمل الطلب السابق ضمن محادثة أخرى. افحص السلة قبل متابعة التسوق.',
        'conversationRefreshRequired' => 'اكتمل الطلب، لكن تعذّر تحديث المحادثة. أعد الاتصال قبل المتابعة.',
        'imageRecoveryUnavailable' => 'أعد إرفاق الصورة ثم أرسل الطلب من جديد.',
        'deleteConfirm' => 'حذف المحادثة؟',
        'deleteNotConfirmed' => 'تعذّر تأكيد حذف المحادثة، وما زالت الجلسة السابقة متاحة.',
        'deleteOutcomeReplaced' => 'فُقد تأكيد الحذف وتعذّر استئناف المحادثة السابقة. بدأت جلسة جديدة دون افتراض أن الحذف اكتمل.',
        'deleteOutcomeUnknown' => 'فُقد تأكيد الحذف وتعذّر التحقق من حالة المحادثة. أعد الاتصال قبل أي إجراء آخر.',
    ),
);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Yassin AI browser contract</title>
    <link rel="stylesheet" href="/assets/css/widget.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/templates/widget.php'; ?>
<script>window.YSAIAssistantConfig = <?php echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); ?>;</script>
<script type="module" src="/assets/js/widget.js"></script>
</body>
</html>
