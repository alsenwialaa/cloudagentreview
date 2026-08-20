<?php
/** @var array<string,mixed> $options */
/** @var string $style */
/** @var string $siteIconUrl */
if (!defined('ABSPATH')) {
    exit;
}
$privacy = (bool) $options['widget_conversation_privacy_enabled'];
$images = (bool) $options['allow_images'];
$presence = (bool) $options['widget_show_presence'];
$quickReplies = (bool) $options['widget_quick_replies_enabled'];
$avatarStyle = (string) $options['widget_avatar_style'];
$useSiteIcon = $avatarStyle === 'site_icon' && $siteIconUrl !== '';
$quickPrompts = array_filter(array(
    (string) $options['widget_quick_prompt_1'],
    (string) $options['widget_quick_prompt_2'],
    (string) $options['widget_quick_prompt_3'],
), static fn (string $value): bool => trim($value) !== '');
?>
<div
    class="ysai-widget ysai-widget--<?php echo esc_attr((string) $options['widget_position']); ?>"
    data-ysai-root
    data-theme="<?php echo esc_attr((string) $options['widget_theme_preset']); ?>"
    data-product-layout="<?php echo esc_attr((string) $options['widget_product_layout']); ?>"
    data-launcher-style="<?php echo esc_attr((string) $options['widget_launcher_style']); ?>"
    data-avatar-style="<?php echo esc_attr($avatarStyle); ?>"
    data-message-density="<?php echo esc_attr((string) $options['widget_message_density']); ?>"
    data-mobile-launcher-label="<?php echo (bool) $options['widget_launcher_show_label_mobile'] ? 'show' : 'hide'; ?>"
    dir="rtl"
    style="<?php echo esc_attr($style); ?>"
>
    <button
        class="ysai-launcher"
        type="button"
        aria-expanded="false"
        aria-controls="ysai-panel"
        data-ysai-open
        aria-label="<?php echo esc_attr((string) $options['widget_button_text']); ?>"
    >
        <span class="ysai-launcher__avatar" aria-hidden="true">
            <?php if ($useSiteIcon) : ?>
                <img src="<?php echo esc_url($siteIconUrl); ?>" alt="" width="42" height="42" data-ysai-avatar-image>
            <?php elseif ($avatarStyle === 'chat') : ?>
                <svg viewBox="0 0 24 24" focusable="false"><path d="M5 5.5h14v10H9l-4 3v-13Zm4 4h6m-6 3h4"/></svg>
            <?php else : ?>
                <span>ي</span>
            <?php endif; ?>
            <?php if ($presence) : ?><i data-ysai-presence-dot></i><?php endif; ?>
        </span>
        <span class="ysai-launcher__copy" data-ysai-launcher-label>
            <strong><?php echo esc_html((string) $options['widget_button_text']); ?></strong>
            <small><?php echo esc_html__('اسأل عن أي منتج', 'yassin-ai-assistant'); ?></small>
        </span>
        <span class="ysai-launcher__unread" hidden aria-hidden="true" data-ysai-launcher-unread>0</span>
    </button>

    <section
        class="ysai-panel"
        id="ysai-panel"
        role="dialog"
        aria-modal="false"
        aria-labelledby="ysai-title"
        aria-describedby="ysai-subtitle"
        tabindex="-1"
        hidden
        data-ysai-panel
    >
        <header class="ysai-header">
            <div class="ysai-avatar ysai-avatar--header" aria-hidden="true" data-ysai-header-avatar>
                <?php if ($useSiteIcon) : ?>
                    <img src="<?php echo esc_url($siteIconUrl); ?>" alt="" width="48" height="48" data-ysai-avatar-image>
                <?php elseif ($avatarStyle === 'chat') : ?>
                    <svg viewBox="0 0 24 24" focusable="false"><path d="M5 5.5h14v10H9l-4 3v-13Zm4 4h6m-6 3h4"/></svg>
                <?php else : ?>
                    <span>ي</span>
                <?php endif; ?>
                <?php if ($presence) : ?><i data-ysai-presence-dot></i><?php endif; ?>
            </div>
            <div class="ysai-header__copy">
                <h2 id="ysai-title"><?php echo esc_html((string) $options['widget_title']); ?></h2>
                <p id="ysai-subtitle"><?php echo esc_html((string) $options['widget_subtitle']); ?></p>
                <span class="<?php echo $presence ? 'ysai-connection' : 'screen-reader-text'; ?>" role="status" aria-live="polite" data-ysai-status>
                    <?php if ($presence) : ?><i aria-hidden="true" data-ysai-presence-dot></i><?php endif; ?>
                    <span data-ysai-status-text><?php echo esc_html__('جارٍ الاتصال…', 'yassin-ai-assistant'); ?></span>
                </span>
            </div>
            <div class="ysai-header__actions">
                <?php if ($privacy) : ?>
                    <button
                        class="ysai-icon-button"
                        type="button"
                        aria-label="<?php echo esc_attr__('خيارات المحادثة', 'yassin-ai-assistant'); ?>"
                        aria-expanded="false"
                        aria-controls="ysai-privacy-menu"
                        data-ysai-privacy-toggle
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    </button>
                    <div class="ysai-privacy-menu" id="ysai-privacy-menu" role="menu" hidden data-ysai-privacy-menu>
                        <button type="button" role="menuitem" data-ysai-export>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"/></svg>
                            <span><?php echo esc_html__('تصدير المحادثة', 'yassin-ai-assistant'); ?></span>
                        </button>
                        <button type="button" role="menuitem" class="is-danger" data-ysai-delete>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5"/></svg>
                            <span><?php echo esc_html__('حذف المحادثة', 'yassin-ai-assistant'); ?></span>
                        </button>
                    </div>
                <?php endif; ?>
                <button class="ysai-icon-button" type="button" aria-label="<?php echo esc_attr__('تصغير المساعد', 'yassin-ai-assistant'); ?>" data-ysai-close>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>
                </button>
            </div>
        </header>

        <aside class="ysai-cart" hidden data-ysai-cart aria-label="<?php echo esc_attr__('ملخص السلة', 'yassin-ai-assistant'); ?>"></aside>

        <div class="ysai-conversation">
            <div class="ysai-messages" role="log" aria-live="polite" aria-relevant="additions" data-ysai-messages>
                <div class="ysai-empty" data-ysai-empty>
                    <div class="ysai-empty__avatar" aria-hidden="true">
                        <?php if ($useSiteIcon) : ?>
                            <img src="<?php echo esc_url($siteIconUrl); ?>" alt="" width="64" height="64" data-ysai-avatar-image>
                        <?php elseif ($avatarStyle === 'chat') : ?>
                            <svg viewBox="0 0 24 24" focusable="false"><path d="M5 5.5h14v10H9l-4 3v-13Zm4 4h6m-6 3h4"/></svg>
                        <?php else : ?>
                            <span>ي</span>
                        <?php endif; ?>
                    </div>
                    <div class="ysai-empty__copy">
                        <strong><?php echo esc_html((string) $options['widget_welcome_message']); ?></strong>
                        <p><?php echo esc_html((string) $options['empty_state_hint']); ?></p>
                    </div>
                    <?php if ($quickReplies && $quickPrompts !== array()) : ?>
                        <div class="ysai-suggestions" role="group" aria-label="<?php echo esc_attr__('ردود سريعة', 'yassin-ai-assistant'); ?>">
                            <?php foreach ($quickPrompts as $prompt) : ?>
                                <button type="button" data-ysai-suggestion="<?php echo esc_attr($prompt); ?>">
                                    <span><?php echo esc_html($prompt); ?></span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ysai-typing" role="status" aria-live="polite" hidden data-ysai-typing>
                <span class="ysai-avatar ysai-avatar--message" aria-hidden="true">
                    <?php if ($useSiteIcon) : ?><img src="<?php echo esc_url($siteIconUrl); ?>" alt="" width="30" height="30" data-ysai-avatar-image><?php else : ?><span>ي</span><?php endif; ?>
                </span>
                <span class="ysai-typing__bubble">
                    <i></i><i></i><i></i>
                    <span class="screen-reader-text"><?php echo esc_html__('المساعد يكتب الآن', 'yassin-ai-assistant'); ?></span>
                </span>
            </div>

            <button class="ysai-latest" type="button" hidden data-ysai-latest>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                <span><?php echo esc_html__('أحدث الرسائل', 'yassin-ai-assistant'); ?></span>
                <b hidden data-ysai-latest-count>0</b>
            </button>
        </div>

        <div class="ysai-reply-preview" hidden data-ysai-reply-preview>
            <div class="ysai-reply-preview__media" hidden data-ysai-reply-media><img alt="" data-ysai-reply-image></div>
            <div class="ysai-reply-preview__copy">
                <strong><?php echo esc_html__('رد على المساعد', 'yassin-ai-assistant'); ?></strong>
                <span data-ysai-reply-text></span>
            </div>
            <button type="button" aria-label="<?php echo esc_attr__('إلغاء الرد', 'yassin-ai-assistant'); ?>" data-ysai-reply-cancel>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg>
            </button>
        </div>

        <div class="ysai-image-preview" hidden data-ysai-image-preview>
            <img alt="<?php echo esc_attr__('معاينة الصورة المرفقة', 'yassin-ai-assistant'); ?>" data-ysai-image-preview-img>
            <div><span data-ysai-image-name></span><button type="button" data-ysai-image-remove><?php echo esc_html__('إزالة', 'yassin-ai-assistant'); ?></button></div>
        </div>

        <form class="ysai-composer" data-ysai-form>
            <div class="ysai-composer__shell">
                <?php if ($images) : ?>
                    <label class="ysai-attach" title="<?php echo esc_attr__('إرفاق صورة', 'yassin-ai-assistant'); ?>" data-ysai-attach>
                        <input type="file" accept="image/jpeg,image/png,image/webp" aria-label="<?php echo esc_attr__('إرفاق صورة', 'yassin-ai-assistant'); ?>" data-ysai-image-input>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8.5 12.5 5.7-5.7a3 3 0 0 1 4.2 4.2l-7.3 7.3a5 5 0 0 1-7.1-7.1l7-7"/></svg>
                        <span class="screen-reader-text"><?php echo esc_html__('إرفاق صورة', 'yassin-ai-assistant'); ?></span>
                    </label>
                <?php endif; ?>
                <label class="screen-reader-text" for="ysai-message"><?php echo esc_html__('رسالتك', 'yassin-ai-assistant'); ?></label>
                <textarea id="ysai-message" rows="1" aria-describedby="ysai-composer-note ysai-character-count" placeholder="<?php echo esc_attr__('اكتب رسالتك…', 'yassin-ai-assistant'); ?>" data-ysai-input></textarea>
                <button class="ysai-send" type="submit" aria-label="<?php echo esc_attr__('إرسال', 'yassin-ai-assistant'); ?>" disabled data-ysai-send>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 16-8-5 16-3-6-8-2Zm8 2 3-3"/></svg>
                </button>
            </div>
            <div class="ysai-composer__meta">
                <span class="ysai-composer__note" id="ysai-composer-note"><?php echo esc_html__('قد يخطئ الذكاء الاصطناعي؛ السلة يؤكدها الخادم.', 'yassin-ai-assistant'); ?></span>
                <span class="ysai-character-count" id="ysai-character-count" dir="ltr" aria-live="polite" data-ysai-character-count>0 / 4000</span>
            </div>
        </form>
        <div class="ysai-error" role="alert" hidden data-ysai-error></div>
    </section>
    <div class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-ysai-announcer></div>
</div>
