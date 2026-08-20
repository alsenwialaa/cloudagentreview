#!/usr/bin/env python3
"""Real-Chromium contract for the merchant appearance editor."""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]


def fail(message: str) -> None:
    raise AssertionError(message)


def page_html(php: str) -> str:
    rendered = subprocess.run(
        [php, str(ROOT / "tests/browser/admin-appearance-harness.php")],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
    ).stdout
    css = (ROOT / "assets/css/admin.css").read_text(encoding="utf-8")
    javascript = (ROOT / "assets/js/admin.js").read_text(encoding="utf-8")
    rendered = re.sub(
        r'<link\s+rel="stylesheet"\s+href="/assets/css/admin\.css">',
        lambda _match: f"<style>{css}</style>",
        rendered,
        count=1,
    )
    rendered = re.sub(
        r'<script\s+src="/assets/js/admin\.js"></script>',
        lambda _match: f"<script>{javascript}</script>",
        rendered,
        count=1,
    )
    if "/assets/css/admin.css" in rendered or "/assets/js/admin.js" in rendered:
        fail("The admin harness still contains external production assets.")
    return rendered


def main() -> int:
    php = shutil.which("php")
    chromium = shutil.which("chromium") or shutil.which("google-chrome")
    if not php or not chromium:
        print("PHP and Chromium are required for the admin appearance contract.", file=sys.stderr)
        return 2

    html = page_html(php)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            executable_path=chromium,
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
        )
        context = browser.new_context(locale="ar", viewport={"width": 1440, "height": 1000})
        page = context.new_page()
        errors: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.set_content(html, wait_until="load")

        workbench = page.locator("[data-ysai-appearance]")
        preview = page.locator("[data-ysai-preview]")
        stage = page.locator("[data-ysai-preview-stage]")
        if workbench.count() != 1 or preview.count() != 1 or stage.count() != 1:
            fail("The actual administration page did not render one appearance workbench and preview.")

        title = page.locator('[name="ysai_options[widget_title]"]')
        title.fill("وكيل التسوق المباشر")
        if preview.locator("[data-preview-title]").inner_text() != "وكيل التسوق المباشر":
            fail("Editing the assistant title did not update the live preview.")

        colors_summary = page.get_by_text("الألوان والمقاسات", exact=True)
        colors_summary.click()
        custom_user_bubble = page.locator('[name="ysai_options[widget_user_bubble_color]"]')
        custom_user_bubble.fill("#112233")
        custom_user_bubble.dispatch_event("input")
        preset = page.locator('[name="ysai_options[widget_theme_preset]"]')
        preset.select_option("ocean")
        brand = page.locator('[name="ysai_options[widget_brand_color]"]')
        if brand.input_value().lower() != "#0e7490":
            fail("Selecting the ocean preset did not update inherited merchant colours.")
        if custom_user_bubble.input_value().lower() != "#112233":
            fail("Selecting a preset overwrote a deliberate merchant colour override.")
        primary = preview.evaluate("node => getComputedStyle(node).getPropertyValue('--ysai-primary').trim().toLowerCase()")
        if primary != "#0e7490":
            fail("The live preview did not consume the selected preset colour token.")

        launcher = page.locator('[name="ysai_options[widget_launcher_style]"]')
        launcher.select_option("circle")
        if preview.get_attribute("data-launcher") != "circle":
            fail("The launcher-style control did not update the live preview.")

        presence = page.locator('[name="ysai_options[widget_show_presence]"]')
        presence.uncheck()
        if not preview.locator("[data-preview-presence]").is_hidden():
            fail("Disabling presence did not hide the live preview status.")

        avatar = page.locator('[name="ysai_options[widget_avatar_style]"]')
        avatar.select_option("chat")
        if preview.get_attribute("data-avatar") != "chat":
            fail("The assistant-identity control did not update the live preview avatar mode.")

        density = page.locator('[name="ysai_options[widget_message_density]"]')
        density.select_option("compact")
        if preview.get_attribute("data-density") != "compact":
            fail("The message-density control did not update the live preview.")

        position = page.locator('[name="ysai_options[widget_position]"]')
        position.select_option("left")
        if preview.get_attribute("data-position") != "left":
            fail("The launcher-position control did not update the preview alignment contract.")

        timestamps = page.locator('[name="ysai_options[widget_show_timestamps]"]')
        timestamps.uncheck()
        if preview.locator("time:visible, .ysai-admin-preview__day:visible").count() != 0:
            fail("Disabling timestamps did not remove preview times and day separators.")

        actions = page.locator('[name="ysai_options[widget_show_message_actions]"]')
        actions.uncheck()
        if not preview.locator("[data-preview-actions]").is_hidden():
            fail("Disabling message actions did not update the merchant preview.")

        quick_prompt = page.locator('[name="ysai_options[widget_quick_prompt_1]"]')
        quick_prompt.fill("اعرض أحدث المنتجات")
        if preview.locator("[data-preview-quick-1]").inner_text() != "اعرض أحدث المنتجات":
            fail("Editing a quick reply did not update the live preview chip.")

        unread = page.locator('[name="ysai_options[widget_show_unread_button]"]')
        unread.uncheck()
        if not preview.locator("[data-preview-unread]").is_hidden() or not preview.locator("[data-preview-latest]").is_hidden():
            fail("Disabling unread controls did not hide both preview indicators.")

        products_summary = page.get_by_text("المنتجات والأدوات", exact=True)
        products_summary.click()
        layout = page.locator('[name="ysai_options[widget_product_layout]"]')
        layout.select_option("list")
        if preview.get_attribute("data-layout") != "list":
            fail("The product-layout control did not update the live preview.")

        descriptions = page.locator('[name="ysai_options[widget_product_show_description]"]')
        descriptions.uncheck()
        if preview.locator("[data-preview-product-description]:visible").count() != 0:
            fail("Disabling product descriptions did not update the live preview cards.")

        ratio = page.locator('[name="ysai_options[widget_product_image_ratio]"]')
        ratio.select_option("16-9")
        preview_ratio = preview.evaluate("node => node.style.getPropertyValue('--ysai-preview-product-ratio').trim()")
        if preview_ratio != "16 / 9":
            fail("The product image-ratio control did not update the preview token.")

        mobile = page.get_by_role("button", name="هاتف", exact=True)
        mobile.click()
        if stage.get_attribute("data-device") != "mobile" or mobile.get_attribute("aria-pressed") != "true":
            fail("The mobile preview switch did not expose its visual and accessibility state.")
        if not preview.locator("[data-preview-button]").is_hidden():
            fail("The default mobile launcher preference did not hide the launcher label in preview.")
        mobile_label = page.locator('[name="ysai_options[widget_launcher_show_label_mobile]"]')
        mobile_label.check()
        if preview.locator("[data-preview-button]").is_hidden():
            fail("Enabling the mobile launcher label did not update the preview.")

        mobile_cards = page.locator('[name="ysai_options[widget_product_cards_per_view_mobile]"]')
        mobile_cards.fill("2")
        mobile_cards.dispatch_event("input")
        card_count = preview.evaluate("node => node.style.getPropertyValue('--ysai-preview-cards').trim()")
        if card_count != "2":
            fail("The mobile cards-per-view control did not update the device-specific preview token.")

        width = page.locator('[name="ysai_options[widget_panel_width]"]')
        width.fill("500")
        width.dispatch_event("input")
        panel_style = preview.locator("[data-preview-panel]").get_attribute("style") or ""
        if "500px" not in panel_style:
            fail("The panel-width range did not update the actual preview surface.")

        if errors:
            fail("Admin appearance page errors: " + " | ".join(errors))
        context.close()
        browser.close()

    print("Chromium administration appearance-editor contract: passed.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:  # noqa: BLE001 - command-line boundary
        print(f"Admin appearance contract failed: {error}", file=sys.stderr)
        raise SystemExit(1)
