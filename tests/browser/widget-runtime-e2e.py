#!/usr/bin/env python3
"""Focused real-Chromium contract for widget modality, composer, media, and carousel behavior."""

from __future__ import annotations

import base64
import importlib.util
import json
import os
import shutil
import sys
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Iterator

sys.dont_write_bytecode = True

from playwright.sync_api import Browser, Page, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
BASE_TEST = ROOT / "tests/browser/widget-e2e.py"

spec = importlib.util.spec_from_file_location("ysai_widget_base_contract", BASE_TEST)
if spec is None or spec.loader is None:
    raise RuntimeError("Unable to load the production widget browser helpers.")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


def fail(message: str) -> None:
    raise AssertionError(message)


@contextmanager
def environment(**values: str | None) -> Iterator[None]:
    previous = {name: os.environ.get(name) for name in values}
    try:
        for name, value in values.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value
        yield
    finally:
        for name, value in previous.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value


def render_base(php: str, *, images: bool = False, presence: bool = True, unread: bool = True) -> str:
    with environment(
        YSAI_WIDGET_IMAGES="1" if images else "0",
        YSAI_WIDGET_PRESENCE="1" if presence else "0",
        YSAI_WIDGET_UNREAD="1" if unread else "0",
    ):
        return base.browser_html(php)


def inject_background(html: str) -> str:
    return html.replace(
        "<body>",
        '<body><main id="storefront-content"><button id="background-control" type="button">Outside control</button></main>',
        1,
    )


def delay_endpoint(html: str, suffix: str, milliseconds: int) -> str:
    wrapper = f"""
<script>
(() => {{
  const originalFetch = window.fetch;
  window.fetch = async (input, init = {{}}) => {{
    const path = String(input || '');
    if (path.endsWith({json.dumps(suffix)}) || path === {json.dumps(suffix.strip('/'))}) {{
      await new Promise((resolve) => window.setTimeout(resolve, {milliseconds}));
    }}
    return originalFetch(input, init);
  }};
}})();
</script>
"""
    return html.replace("</head>", wrapper + "</head>", 1)


def inject_fake_storage(html: str) -> str:
    script = """
<script>
(() => {
  const createStore = () => {
    const values = new Map();
    return {
      get length() { return values.size; },
      key(index) { return Array.from(values.keys())[Number(index)] ?? null; },
      getItem(key) { key = String(key); return values.has(key) ? values.get(key) : null; },
      setItem(key, value) { values.set(String(key), String(value)); },
      removeItem(key) { values.delete(String(key)); },
      clear() { values.clear(); }
    };
  };
  Object.defineProperty(window, 'localStorage', {value: createStore(), configurable: true});
  Object.defineProperty(window, 'sessionStorage', {value: createStore(), configurable: true});
})();
</script>
"""
    return html.replace("<head>", "<head>" + script, 1)


def replacement_boot_race_html(base_html: str) -> str:
    first = empty_boot()
    second = base.boot_payload(conversation_id=base.NEW_CONVERSATION_ID, token=base.NEW_TOKEN)
    second["messages"] = []
    script = f"""
<script>
window.__ysaiBootCalls = 0;
window.fetch = async (input, init = {{}}) => {{
  const path = String(input || '');
  if (path.endsWith('/boot') || path === 'boot') {{
    window.__ysaiBootCalls += 1;
    const call = window.__ysaiBootCalls;
    await new Promise((resolve) => window.setTimeout(resolve, call === 1 ? 1400 : 60));
    const payload = call === 1
      ? {json.dumps(first, ensure_ascii=False)}
      : {json.dumps(second, ensure_ascii=False)};
    return new Response(JSON.stringify(payload), {{status: 200, headers: {{'Content-Type': 'application/json'}}}});
  }}
  return new Response(JSON.stringify({{ok: false, error: {{code: 'not_found', message: 'Not found', retryable: false}}}}), {{
    status: 404,
    headers: {{'Content-Type': 'application/json'}}
  }});
}};
</script>
"""
    return inject_fake_storage(base_html).replace("</head>", script + "</head>", 1)


def mock_visual_viewport(html: str) -> str:
    script = """
<script>
(() => {
  const listeners = new Map();
  const viewport = {
    width: 320,
    height: 600,
    offsetTop: 11,
    offsetLeft: 23,
    addEventListener(type, callback) { listeners.set(type, callback); },
    removeEventListener(type, callback) { if (listeners.get(type) === callback) listeners.delete(type); }
  };
  Object.defineProperty(window, 'visualViewport', {value: viewport, configurable: true});
})();
</script>
"""
    return html.replace("<head>", "<head>" + script, 1)


def empty_boot() -> dict[str, Any]:
    payload = base.boot_payload()
    payload["messages"] = []
    return payload


def product(index: int, *, image: str = "") -> dict[str, Any]:
    value = dict(base.product_card())
    value.update({
        "ref": f"p_widgetruntime{index:02d}",
        "name": f"منتج الاختبار {index}",
        "sku": f"WIDGET-{index}",
        "price": float(index * 10),
        "price_available": True,
        "price_kind": "fixed",
        "regular_price": float(index * 10),
        "price_text": f"${index * 10}.00",
        "image": image,
    })
    return value


def cart_with_lines(count: int) -> dict[str, Any]:
    items = []
    total = 0.0
    for index in range(1, count + 1):
        total += index * 10
        items.append({
            "name": f"عنصر السلة {index}",
            "quantity": 1,
            "unit_price": float(index * 10),
            "line_total": float(index * 10),
            "line_total_text": f"${index * 10}.00",
            "image": "",
            "variation": {},
            "sku": f"CART-{index}",
            "ref": f"l_widgetline{index:02d}",
        })
    return {
        "items": items,
        "line_count": count,
        "items_truncated": False,
        "item_count": count,
        "total": total,
        "total_text": f"${int(total)}.00",
        "currency": "USD",
        "cart_url": "",
        "checkout_url": "",
        "cart_hash": f"widget-cart-{count}",
        "mutations_allowed": True,
        "mutation_notice": "",
    }


def open_page(browser: Browser, html: str, *, viewport: dict[str, int] | None = None):
    context = browser.new_context(
        locale="ar",
        reduced_motion="reduce",
        viewport=viewport or {"width": 1280, "height": 900},
    )
    page = context.new_page()
    errors: list[str] = []
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.set_content(html, wait_until="load")
    return context, page, errors


def assert_no_errors(errors: list[str], label: str) -> None:
    if errors:
        fail(f"{label} page errors: {' | '.join(errors)}")


def run_mobile_modal(browser: Browser, base_html: str) -> None:
    html = inject_background(base.scenario_html(base_html, "success", empty_boot()))
    context, page, errors = open_page(browser, html, viewport={"width": 390, "height": 844})
    outside = page.locator("#background-control")
    launcher = page.locator("[data-ysai-open]")
    closed_root_markup = page.locator("[data-ysai-root]").evaluate("el => el.outerHTML")
    launcher.click()
    page.get_by_text("متصل", exact=True).wait_for()
    panel = page.locator("[data-ysai-panel]")
    if panel.get_attribute("aria-modal") != "true":
        fail("The phone widget did not become an accessible modal dialog.")
    lock = page.evaluate("""() => ({
      bodyPosition: document.body.style.position,
      bodyOverflow: document.body.style.overflow,
      htmlOverflow: document.documentElement.style.overflow,
      inert: document.querySelector('#storefront-content').hasAttribute('inert'),
      ariaHidden: document.querySelector('#storefront-content').getAttribute('aria-hidden')
    })""")
    if lock != {"bodyPosition": "fixed", "bodyOverflow": "hidden", "htmlOverflow": "hidden", "inert": True, "ariaHidden": "true"}:
        fail(f"The phone widget did not own the page modality boundary: {lock}")

    page.evaluate("document.querySelector('#background-control').focus()")
    page.wait_for_function("document.querySelector('[data-ysai-panel]').contains(document.activeElement)")

    # Background nodes inserted after the modal opens inherit the same isolation.
    page.evaluate("document.body.insertAdjacentHTML('afterbegin', '<aside id=\"late-background\"><button>Late control</button></aside>')")
    page.wait_for_function("document.querySelector('#late-background').hasAttribute('inert') && document.querySelector('#late-background').getAttribute('aria-hidden') === 'true'")

    first_and_last = page.evaluate("""() => {
      const panel = document.querySelector('[data-ysai-panel]');
      const selector = 'a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),[tabindex]:not([tabindex="-1"])';
      const items = [...panel.querySelectorAll(selector)].filter((el) => {
        if (el.closest('[hidden]')) return false;
        const style = getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden' && el.getClientRects().length > 0;
      });
      items.at(-1)?.focus();
      return {first: items[0]?.getAttribute('data-ysai-privacy-toggle') !== null ? 'privacy' : items[0]?.getAttribute('data-ysai-close') !== null ? 'close' : items[0]?.tagName, last: items.at(-1)?.tagName, count: items.length};
    }""")
    if first_and_last["count"] < 2:
        fail("The modal focus contract did not expose enough interactive controls.")
    page.keyboard.press("Tab")
    page.wait_for_function("document.querySelector('[data-ysai-panel]').contains(document.activeElement)")

    page.keyboard.press("Escape")
    panel.wait_for(state="hidden")
    page.wait_for_function("document.activeElement === document.querySelector('[data-ysai-open]')")
    released = page.evaluate("""() => ({
      bodyPosition: document.body.style.position,
      bodyOverflow: document.body.style.overflow,
      htmlOverflow: document.documentElement.style.overflow,
      inert: document.querySelector('#storefront-content').hasAttribute('inert'),
      ariaHidden: document.querySelector('#storefront-content').getAttribute('aria-hidden')
    })""")
    if released != {"bodyPosition": "", "bodyOverflow": "", "htmlOverflow": "", "inert": False, "ariaHidden": None}:
        fail(f"Closing the phone widget did not restore the page: {released}")

    # Removing the open widget must also release page ownership. A later DOM
    # insertion must initialize a fresh instance without reloading the module.
    launcher.click()
    page.get_by_text("متصل", exact=True).wait_for()
    page.evaluate("document.querySelector('[data-ysai-root]').remove()")
    page.wait_for_function("document.body.style.position === '' && !document.querySelector('#storefront-content').hasAttribute('inert')")
    page.evaluate("markup => document.body.insertAdjacentHTML('beforeend', markup)", closed_root_markup)
    page.wait_for_function("document.querySelectorAll('[data-ysai-root]').length === 1")
    page.locator("[data-ysai-open]").click()
    page.get_by_text("متصل", exact=True).wait_for()
    if page.locator("[data-ysai-panel]").is_hidden():
        fail("A widget inserted after module startup was not initialized.")
    page.keyboard.press("Escape")
    assert_no_errors(errors, "mobile modality")
    context.close()


def run_open_close_race(browser: Browser, base_html: str) -> None:
    html = inject_background(base.scenario_html(base_html, "success", empty_boot()))
    html = delay_endpoint(html, "/boot", 350)
    context, page, errors = open_page(browser, html, viewport={"width": 390, "height": 844})
    launcher = page.locator("[data-ysai-open]")
    launcher.click()
    page.locator("[data-ysai-close]").click()
    page.wait_for_timeout(600)
    result = page.evaluate("""() => ({
      hidden: document.querySelector('[data-ysai-panel]').hidden,
      focusLauncher: document.activeElement === document.querySelector('[data-ysai-open]'),
      bodyPosition: document.body.style.position,
      outsideInert: document.querySelector('#storefront-content').hasAttribute('inert')
    })""")
    if result != {"hidden": True, "focusLauncher": True, "bodyPosition": "", "outsideInert": False}:
        fail(f"A delayed boot refocused or relocked a closed widget: {result}")
    assert_no_errors(errors, "open-close race")
    context.close()


def run_removed_instance_boot_race(browser: Browser, base_html: str) -> None:
    context, page, errors = open_page(
        browser,
        replacement_boot_race_html(base_html),
        viewport={"width": 390, "height": 844},
    )
    closed_markup = page.locator("[data-ysai-root]").evaluate("el => el.outerHTML")
    page.locator("[data-ysai-open]").click()
    page.wait_for_function("window.__ysaiBootCalls === 1")
    page.evaluate("document.querySelector('[data-ysai-root]').remove()")
    page.wait_for_timeout(40)
    page.evaluate("markup => document.body.insertAdjacentHTML('beforeend', markup)", closed_markup)
    page.wait_for_function("document.querySelectorAll('[data-ysai-root]').length === 1")
    page.locator("[data-ysai-open]").click()
    page.wait_for_function("window.__ysaiBootCalls === 2")
    page.get_by_text("متصل", exact=True).wait_for()
    page.wait_for_function(
        "JSON.parse(localStorage.getItem('ysai.browser.contract') || 'null')?.id === "
        + json.dumps(base.NEW_CONVERSATION_ID)
    )
    page.wait_for_timeout(1550)
    stored_id = page.evaluate(
        "JSON.parse(localStorage.getItem('ysai.browser.contract') || 'null')?.id || ''"
    )
    if stored_id != base.NEW_CONVERSATION_ID:
        fail("A removed widget overwrote the replacement widget with its stale boot response.")
    assert_no_errors(errors, "removed-instance boot race")
    context.close()


def run_visual_viewport_geometry(browser: Browser, base_html: str) -> None:
    html = mock_visual_viewport(base.scenario_html(base_html, "success", empty_boot()))
    context, page, errors = open_page(browser, html, viewport={"width": 390, "height": 844})
    page.locator("[data-ysai-open]").click()
    page.get_by_text("متصل", exact=True).wait_for()
    box = page.locator("[data-ysai-panel]").bounding_box()
    if not box:
        fail("The visual-viewport test could not measure the open panel.")
    measured = {name: round(float(box[name])) for name in ("x", "y", "width", "height")}
    expected = {"x": 23, "y": 11, "width": 320, "height": 600}
    if measured != expected:
        fail(f"The mobile panel did not follow the visual viewport: {measured}")
    assert_no_errors(errors, "visual viewport")
    context.close()


def run_composer_and_network(browser: Browser, base_html: str) -> None:
    html = delay_endpoint(base.scenario_html(base_html, "success", empty_boot()), "/chat", 400)
    context, page, errors = open_page(browser, html, viewport={"width": 1100, "height": 800})
    page.locator("[data-ysai-open]").click()
    page.get_by_text("متصل", exact=True).wait_for()
    textbox = page.get_by_role("textbox", name="رسالتك")
    send = page.get_by_role("button", name="إرسال")
    if not send.is_disabled():
        fail("The empty composer exposed an enabled Send control.")
    textbox.fill("   ")
    if not send.is_disabled():
        fail("Whitespace enabled the Send control.")

    emoji = "😀"
    textbox.fill(emoji * 4000)
    if send.is_disabled() or textbox.get_attribute("aria-invalid") != "false":
        fail("Exactly 4,000 Unicode code points were not accepted.")
    if page.locator("[data-ysai-character-count]").inner_text() != "4000 / 4000":
        fail("The composer counted UTF-16 units instead of Unicode code points.")
    textbox.fill(emoji * 4001)
    if not send.is_disabled() or textbox.get_attribute("aria-invalid") != "true":
        fail("A 4,001-code-point draft was not blocked.")

    textbox.fill("رسالة متصلة")
    context.set_offline(True)
    page.get_by_text("غير متصل", exact=True).wait_for()
    if not send.is_disabled():
        fail("The offline composer remained sendable.")
    context.set_offline(False)
    page.get_by_text("متصل", exact=True).wait_for()
    page.wait_for_function("!document.querySelector('[data-ysai-send]').disabled")

    send.click()
    page.locator("[data-ysai-typing]").wait_for(state="visible")
    if not textbox.is_disabled() or not send.is_disabled():
        fail("An in-flight turn did not lock the composer.")
    if not page.locator("[data-ysai-root]").evaluate("el => el.classList.contains('is-connected')"):
        fail("The typing state incorrectly removed the connected presence state.")
    page.get_by_role("log").get_by_text("تم استلام رسالتك بأمان.", exact=True).wait_for()
    page.wait_for_function("!document.querySelector('[data-ysai-input]').disabled")

    # Privacy menu follows standard keyboard movement and closes on Tab.
    page.get_by_role("button", name="خيارات المحادثة").click()
    menu = page.get_by_role("menu")
    menu.wait_for(state="visible")
    page.wait_for_function("document.activeElement?.matches('[data-ysai-export]')")
    page.keyboard.press("ArrowDown")
    if page.evaluate("document.activeElement?.matches('[data-ysai-delete]')") is not True:
        fail("ArrowDown did not move through the privacy menu.")
    page.keyboard.press("Home")
    if page.evaluate("document.activeElement?.matches('[data-ysai-export]')") is not True:
        fail("Home did not move to the first privacy action.")
    page.keyboard.press("End")
    if page.evaluate("document.activeElement?.matches('[data-ysai-delete]')") is not True:
        fail("End did not move to the final privacy action.")
    page.keyboard.press("Tab")
    if not menu.is_hidden():
        fail("Tab did not dismiss the privacy menu.")

    # Clipboard failure must not be announced as a successful copy.
    page.evaluate("""() => {
      Object.defineProperty(navigator, 'clipboard', {value: {writeText: async () => { throw new Error('denied'); }}, configurable: true});
      document.execCommand = () => false;
    }""")
    page.get_by_role("button", name="نسخ", exact=True).first.click()
    page.wait_for_function("document.querySelector('[data-ysai-announcer]').textContent.includes('تعذّر النسخ')")
    assert_no_errors(errors, "composer and network")
    context.close()


def run_minimized_unread_accessibility(browser: Browser, base_html: str) -> None:
    html = delay_endpoint(base.scenario_html(base_html, "success", empty_boot()), "/chat", 350)
    context, page, errors = open_page(browser, html, viewport={"width": 1100, "height": 800})
    launcher = page.locator("[data-ysai-open]")
    launcher.click()
    page.get_by_text("متصل", exact=True).wait_for()
    page.get_by_role("textbox", name="رسالتك").fill("اختبار الرسائل غير المقروءة")
    page.get_by_role("button", name="إرسال").click()
    page.locator("[data-ysai-close]").click()
    page.wait_for_function("document.querySelector('[data-ysai-launcher-unread]').textContent === '1'")
    page.wait_for_function("document.querySelector('[data-ysai-announcer]').textContent === 'رسائل جديدة'")
    state = page.evaluate("""() => {
      const launcher = document.querySelector('[data-ysai-open]');
      const badge = document.querySelector('[data-ysai-launcher-unread]');
      const announcer = document.querySelector('[data-ysai-announcer]');
      return {
        label: launcher?.getAttribute('aria-label') || '',
        badgeHidden: badge?.hidden !== false,
        announcer: announcer?.textContent || '',
        announcerInsidePanel: Boolean(announcer?.closest('[data-ysai-panel]')),
        announcerInsideHidden: Boolean(announcer?.closest('[hidden]'))
      };
    }""")
    if state != {
        "label": "فتح المساعد، رسائل جديدة: 1",
        "badgeHidden": False,
        "announcer": "رسائل جديدة",
        "announcerInsidePanel": False,
        "announcerInsideHidden": False,
    }:
        fail(f"Minimized unread delivery was not accessible: {state}")
    launcher.click()
    page.wait_for_function(
        "document.querySelector('[data-ysai-open]').getAttribute('aria-label') === 'فتح المساعد'"
    )
    if not page.locator("[data-ysai-launcher-unread]").is_hidden():
        fail("Opening the conversation did not clear the accessible unread state.")
    assert_no_errors(errors, "minimized unread accessibility")
    context.close()


def run_image_flow(browser: Browser, base_html: str) -> None:
    html = delay_endpoint(base.scenario_html(base_html, "success", empty_boot()), "/chat", 450)
    context, page, errors = open_page(browser, html, viewport={"width": 1100, "height": 800})
    page.locator("[data-ysai-open]").click()
    page.get_by_text("متصل", exact=True).wait_for()
    file_input = page.locator("[data-ysai-image-input]")
    if file_input.count() != 1:
        fail("Image-enabled appearance did not expose the attachment control.")

    png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR4nGP8z8Dwn4GBgYGJAQoAHxcCAk+Uzr4AAAAASUVORK5CYII=")
    page.get_by_role("textbox", name="رسالتك").fill("لا ترسل قبل تجهيز الصورة")
    page.evaluate("""() => {
      const original = window.createImageBitmap.bind(window);
      window.createImageBitmap = async (...args) => {
        await new Promise((resolve) => setTimeout(resolve, 300));
        return original(...args);
      };
    }""")
    file_input.set_input_files({"name": "shopper.png", "mimeType": "image/png", "buffer": png})
    page.wait_for_function("document.querySelector('[data-ysai-form]').getAttribute('aria-busy') === 'true'")
    if not page.get_by_role("button", name="إرسال").is_disabled() or not file_input.is_disabled():
        fail("Image decoding did not lock Send and attachment replacement.")
    page.locator("[data-ysai-image-preview]").wait_for(state="visible")
    page.get_by_role("textbox", name="رسالتك").fill("")
    if page.get_by_role("button", name="إرسال").is_disabled():
        fail("A valid image-only draft did not enable Send.")
    page.get_by_role("button", name="إرسال").click()
    message_image = page.locator(".ysai-message--user .ysai-message-image img")
    message_image.wait_for(state="attached")
    stored = page.evaluate("sessionStorage.getItem('ysai.browser.contract.pending')")
    if not isinstance(stored, str) or "data" in stored or "iVBOR" in stored:
        fail("Image bytes entered durable browser storage.")
    page.get_by_role("log").get_by_text("تم استلام رسالتك بأمان.", exact=True).wait_for()

    # A rejected replacement cannot leave the previously accepted image active.
    file_input.set_input_files({"name": "valid.png", "mimeType": "image/png", "buffer": png})
    page.locator("[data-ysai-image-preview]").wait_for(state="visible")
    file_input.set_input_files({"name": "broken.png", "mimeType": "image/png", "buffer": b"not-an-image"})
    page.locator("[data-ysai-image-preview]").wait_for(state="hidden")
    page.get_by_text("أبعاد الصورة غير مدعومة", exact=True).wait_for()
    if not page.get_by_role("button", name="إرسال").is_disabled():
        fail("An invalid replacement left the old image sendable.")
    assert_no_errors(errors, "image flow")
    context.close()


def run_presence_cart_and_carousel(browser: Browser, base_html: str, no_presence_html: str) -> None:
    boot = base.boot_payload(cart=cart_with_lines(5))
    boot["messages"][1]["products"] = [product(index, image="https://example.invalid/missing.png" if index == 1 else "") for index in range(1, 6)]
    html = base.scenario_html(base_html, "success", boot)
    context, page, errors = open_page(browser, html, viewport={"width": 1280, "height": 900})
    page.locator("[data-ysai-open]").click()
    page.get_by_text("متصل", exact=True).wait_for()
    page.wait_for_timeout(120)

    latest_reply = page.evaluate("""() => {
      const messages = document.querySelector('[data-ysai-messages]');
      const assistant = [...document.querySelectorAll('.ysai-message--assistant')].at(-1);
      const bubble = assistant?.querySelector('.ysai-bubble');
      if (!messages || !bubble) return null;
      const viewport = messages.getBoundingClientRect();
      const rect = bubble.getBoundingClientRect();
      return {viewportTop: viewport.top, viewportBottom: viewport.bottom, top: rect.top, bottom: rect.bottom};
    }""")
    if (not latest_reply
        or latest_reply["bottom"] <= latest_reply["viewportTop"]
        or latest_reply["top"] < latest_reply["viewportTop"] - 2):
        fail(f"The latest assistant reply was hidden above its product cards: {latest_reply}")

    cart = page.locator("[data-ysai-cart]")
    if cart.locator("li:not(.ysai-cart__more)").count() != 3 or cart.locator(".ysai-cart__more").count() != 1:
        fail("The compact cart did not bound visible line items to three plus a summary.")

    ask_labels = page.locator(".ysai-product__ask").evaluate_all(
        "buttons => buttons.map(button => button.getAttribute('aria-label'))"
    )
    expected_labels = [f"اسأل عن المنتج: منتج الاختبار {index}" for index in range(1, 6)]
    if ask_labels != expected_labels:
        fail(f"Product actions did not expose unique accessible names: {ask_labels}")

    product_list = page.locator(".ysai-products").first
    page.wait_for_function("document.querySelector('.ysai-products')?.dataset.ysaiVisibleEnd !== undefined")
    initial = product_list.evaluate("el => ({start:Number(el.dataset.ysaiVisibleStart), end:Number(el.dataset.ysaiVisibleEnd)})")
    if initial["start"] != 0 or initial["end"] < initial["start"]:
        fail(f"The carousel did not derive its initial range from rendered visibility: {initial}")
    indicator = page.locator(".ysai-products-indicator")
    if indicator.get_attribute("dir") != "ltr":
        fail("The RTL carousel reordered its numeric range visually.")
    next_button = page.get_by_role("button", name="المنتجات التالية")
    next_button.click()
    page.wait_for_function("Number(document.querySelector('.ysai-products').dataset.ysaiVisibleStart) > 0")
    advanced = product_list.evaluate("el => ({start:Number(el.dataset.ysaiVisibleStart), end:Number(el.dataset.ysaiVisibleEnd)})")
    if advanced["start"] <= initial["start"]:
        fail("The carousel Next action did not advance to a newly visible product.")

    # Resize changes the actual range rather than retaining configured assumptions.
    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(300)
    resized = product_list.evaluate("el => ({start:Number(el.dataset.ysaiVisibleStart), end:Number(el.dataset.ysaiVisibleEnd)})")
    if resized["end"] - resized["start"] > 1:
        fail(f"The phone carousel reported products that were not visibly present: {resized}")

    image = page.locator(".ysai-product img").first
    if image.count() == 1:
        image.dispatch_event("error")
        if page.locator(".ysai-image-fallback--product").count() != 1:
            fail("A broken product image did not degrade to an accessible placeholder.")
    assert_no_errors(errors, "cart and carousel")
    context.close()

    no_presence = base.scenario_html(no_presence_html, "success", empty_boot())
    context, page, errors = open_page(browser, no_presence)
    page.locator("[data-ysai-open]").click()
    page.get_by_text("متصل", exact=True).wait_for()
    if page.locator("[data-ysai-presence-dot]").count() != 0:
        fail("Disabling presence left visual presence dots in the widget.")
    if "screen-reader-text" not in (page.locator("[data-ysai-status]").get_attribute("class") or ""):
        fail("Disabling visual presence removed the accessible connection announcement contract.")
    assert_no_errors(errors, "presence disabled")
    context.close()


def run_short_landscape(browser: Browser, base_html: str) -> None:
    html = base.scenario_html(base_html, "success", empty_boot())
    context, page, errors = open_page(browser, html, viewport={"width": 844, "height": 390})
    page.locator("[data-ysai-open]").click()
    page.get_by_text("متصل", exact=True).wait_for()
    panel = page.locator("[data-ysai-panel]")
    box = panel.bounding_box()
    if not box or box["width"] < 820 or box["height"] < 370 or panel.get_attribute("aria-modal") != "true":
        fail(f"The short-landscape widget was not a full usable modal: {box}")
    assert_no_errors(errors, "short landscape")
    context.close()


def main() -> int:
    php = shutil.which("php")
    chromium = os.environ.get("YSAI_CHROMIUM_BIN") or shutil.which("chromium") or shutil.which("google-chrome")
    if not php:
        print("php is required for the widget runtime contract.", file=sys.stderr)
        return 2
    if not chromium:
        print("A Chromium executable is required for the widget runtime contract.", file=sys.stderr)
        return 2

    default_html = render_base(php)
    image_html = render_base(php, images=True)
    no_presence_html = render_base(php, presence=False)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            executable_path=chromium,
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage", "--disable-web-security"],
        )
        run_mobile_modal(browser, default_html)
        run_open_close_race(browser, default_html)
        run_removed_instance_boot_race(browser, default_html)
        run_visual_viewport_geometry(browser, default_html)
        run_composer_and_network(browser, default_html)
        run_minimized_unread_accessibility(browser, default_html)
        run_image_flow(browser, image_html)
        run_presence_cart_and_carousel(browser, default_html, no_presence_html)
        run_short_landscape(browser, default_html)
        browser.close()

    print("Chromium widget runtime, modality, composer, media, and carousel contract: passed.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:  # noqa: BLE001 - command-line test boundary
        print(f"Widget runtime contract failed: {error}", file=sys.stderr)
        raise SystemExit(1)
