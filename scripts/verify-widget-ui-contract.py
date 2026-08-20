#!/usr/bin/env python3
"""Fail-closed cross-layer contract for storefront and merchant widget UI."""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def require(source: str, terms: tuple[str, ...], label: str) -> None:
    missing = [term for term in terms if term not in source]
    if missing:
        raise SystemExit(f"{label} is missing: {', '.join(missing)}")


settings = read("src/Infrastructure/WordPress/Settings.php")
appearance = read("src/Infrastructure/WordPress/WidgetAppearance.php")
storefront = read("src/Presentation/Storefront/StorefrontWidget.php")
template = read("templates/widget.php")
widget_js = read("assets/js/widget.js")
widget_css = read("assets/css/widget.css")
admin = read("src/Presentation/Admin/AdminPage.php")
admin_js = read("assets/js/admin.js")
admin_css = read("assets/css/admin.css")
widget_browser = read("tests/browser/widget-e2e.py")
widget_runtime_browser = read("tests/browser/widget-runtime-e2e.py")
admin_browser = read("tests/browser/admin-appearance-e2e.py")

appearance_keys = (
    "widget_theme_preset",
    "widget_launcher_style",
    "widget_avatar_style",
    "widget_message_density",
    "widget_show_presence",
    "widget_show_timestamps",
    "widget_show_message_actions",
    "widget_show_unread_button",
    "widget_quick_replies_enabled",
    "widget_launcher_show_label_mobile",
    "widget_welcome_message",
    "widget_quick_prompt_1",
    "widget_quick_prompt_2",
    "widget_quick_prompt_3",
    "widget_brand_color",
    "widget_brand_strong_color",
    "widget_surface_color",
    "widget_canvas_color",
    "widget_assistant_bubble_color",
    "widget_user_bubble_color",
    "widget_receipt_bubble_color",
    "widget_border_color",
    "widget_panel_width",
    "widget_panel_height",
    "widget_panel_radius",
    "widget_bubble_radius",
    "widget_product_card_radius",
    "widget_font_size",
    "widget_product_layout",
    "widget_product_image_ratio",
    "widget_product_cards_per_view_desktop",
    "widget_product_cards_per_view_mobile",
    "widget_product_carousel_indicator_enabled",
    "widget_product_name_font_size",
    "widget_product_name_font_weight",
    "widget_product_name_max_lines",
)
for key in appearance_keys:
    if f"'{key}'" not in settings:
        raise SystemExit(f"Settings do not own the appearance option: {key}")
    if f"'{key}'" not in admin:
        raise SystemExit(f"The merchant editor does not expose the appearance option: {key}")

require(
    appearance,
    (
        "public static function presets()",
        "public static function sanitizeForSave",
        "public static function cssTokens",
        "public static function contrastRatio",
        "--ysai-user-text",
        "--ysai-assistant-text",
        "--ysai-on-primary",
    ),
    "The contrast-safe appearance policy",
)
require(
    storefront,
    (
        "WidgetAppearance::cssTokens($options)",
        "--ysai-panel-width",
        "--ysai-panel-height",
        "--ysai-product-ratio",
        "--ysai-products-per-view",
        "--ysai-products-per-view-mobile",
        "--ysai-product-name-size",
        "'messageActions' =>",
        "'unreadButton' =>",
        "'carouselIndicator' =>",
    ),
    "The storefront appearance projection",
)
require(
    template,
    (
        "role=\"dialog\"",
        "role=\"log\"",
        "data-ysai-open",
        "data-ysai-header-avatar",
        "data-ysai-status",
        "data-ysai-privacy-menu",
        "data-ysai-empty",
        "data-ysai-suggestion",
        "data-ysai-typing",
        "data-ysai-latest",
        "data-ysai-reply-preview",
        "data-ysai-image-preview",
        "data-ysai-form",
        "data-mobile-launcher-label",
    ),
    "The messaging-app storefront template",
)
require(
    widget_js,
    (
        "appendDaySeparator(date)",
        "messageActions(role, text, data)",
        "productCards(products, messageId = 0)",
        "togglePrivacyMenu()",
        "setUnread(value)",
        "updateTyping()",
        "markOptimisticAccepted",
        "markOptimisticUnverified",
        "markOptimisticRejected",
        "config.features?.unreadButton !== false",
        "this.state.savePending",
        "activateModal()",
        "releaseModal()",
        "trapFocus(event)",
        "event.key === 'Tab'",
        "this.openGeneration",
        "imageDimensions(file)",
        "this.carouselControllers",
        "handlePrivacyMenuKeydown(event)",
        "config.texts?.copyFailed",
        "unicodeLength(message)",
        "refreshModalIsolation()",
        "this.imageProcessing",
        "this.imageGeneration",
        "this.lifecycleGeneration",
        "lifecycleActive(token)",
        "updateUnreadPresentation",
        "restoreTranscriptPosition",
        "revealMessageStart(article)",
        "config.texts?.askProductNamed",
        "--ysai-viewport-width",
        "--ysai-viewport-offset-left",
        "initializeWidgetCandidates(node)",
        "const widgetInsertionObserver",
    ),
    "The storefront interaction controller",
)
require(
    widget_css,
    (
        ".ysai-launcher",
        ".ysai-panel",
        ".ysai-day-separator",
        ".ysai-message--assistant",
        ".ysai-message--user",
        ".ysai-message-actions",
        ".ysai-typing",
        ".ysai-latest",
        ".ysai-products-nav",
        ".ysai-reply-preview",
        ".ysai-composer",
        "@media (max-width: 640px)",
        "env(safe-area-inset-bottom)",
        "prefers-reduced-motion:reduce",
        "forced-colors: active",
        "unicode-bidi: plaintext",
        ":focus-visible",
    ),
    "The responsive storefront stylesheet",
)
require(
    admin,
    (
        "data-ysai-appearance",
        "data-ysai-preview-device=\"desktop\"",
        "data-ysai-preview-device=\"mobile\"",
        "data-ysai-preview-device=\"compact\"",
        "data-ysai-preview",
        "data-preview-unread",
        "data-preview-cart",
        "data-preview-quick",
        "data-preview-products",
        "WidgetAppearance::presets()",
        "WidgetAppearance::cssTokens($options)",
    ),
    "The merchant appearance editor",
)
require(
    admin_js,
    (
        "window.YSAIAdminAppearance",
        "widget_theme_preset",
        "widget_launcher_style",
        "widget_avatar_style",
        "widget_message_density",
        "data-ysai-preview-device",
        "updatePreview",
        "updateAvatar",
        "activePreset",
    ),
    "The live merchant preview controller",
)
require(
    admin_css,
    (
        ".ysai-appearance-layout",
        ".ysai-live-preview",
        ".ysai-preview-stage[data-device=\"mobile\"]",
        ".ysai-preview-stage[data-device=\"compact\"]",
        ".ysai-admin-preview__panel",
        ".ysai-admin-preview__bubble",
        ".ysai-admin-preview__product",
    ),
    "The merchant preview stylesheet",
)
require(
    widget_browser,
    (
        "The configured messenger quick replies were not rendered",
        "A response received while minimized did not increment the launcher unread count",
        "The conversation privacy menu did not expose its open state",
        "The in-flight message did not expose an accessible typing state",
        "Disabling the unread control still exposed a launcher message count",
    ),
    "The storefront Chromium regressions",
)
require(
    widget_runtime_browser,
    (
        "The phone widget did not become an accessible modal dialog",
        "A delayed boot refocused or relocked a closed widget",
        "Exactly 4,000 Unicode code points were not accepted",
        "Image bytes entered durable browser storage",
        "Image decoding did not lock Send and attachment replacement",
        "A widget inserted after module startup was not initialized",
        "The compact cart did not bound visible line items",
        "The carousel Next action did not advance",
        "Disabling presence left visual presence dots",
        "The short-landscape widget was not a full usable modal",
        "A removed widget overwrote the replacement widget",
        "Minimized unread delivery was not accessible",
        "Product actions did not expose unique accessible names",
        "The mobile panel did not follow the visual viewport",
        "The latest assistant reply was hidden above its product cards",
    ),
    "The focused storefront runtime Chromium regressions",
)
require(
    admin_browser,
    (
        "The actual administration page did not render one appearance workbench and preview",
        "Selecting the ocean preset did not update inherited merchant colours",
        "Selecting a preset overwrote a deliberate merchant colour override",
        "Disabling timestamps did not remove preview times and day separators",
        "Disabling unread controls did not hide both preview indicators",
        "The mobile preview switch did not expose its visual and accessibility state",
    ),
    "The merchant Chromium regressions",
)

print("Widget storefront and administration UI contract: passed.")
