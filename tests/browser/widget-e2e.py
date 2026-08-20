#!/usr/bin/env python3
"""Real-Chromium end-to-end chat, recovery, and accessibility contract."""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any

from playwright.sync_api import Browser, Page, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
CONVERSATION_ID = "123e4567-e89b-42d3-a456-426614174000"
NEW_CONVERSATION_ID = "323e4567-e89b-42d3-a456-426614174000"
REPLACEMENT_CONVERSATION_ID = "423e4567-e89b-42d3-a456-426614174000"
TOKEN = "A" * 48
NEW_TOKEN = "B" * 48
REPLACEMENT_TOKEN = "C" * 48
PENDING_KEY = "ysai.browser.contract.pending"


def fail(message: str) -> None:
    raise AssertionError(message)


def product_card() -> dict[str, Any]:
    return {
        "ref": "p_browserfixture1",
        "name": "منتج بسعر غير متاح",
        "sku": "BROWSER-1",
        "type": "simple",
        "price": None,
        "price_available": False,
        "price_kind": "unavailable",
        "regular_price": None,
        "sale_price": None,
        "price_text": "",
        "currency": "USD",
        "in_stock": True,
        "stock_status": "instock",
        "stock_quantity": None,
        "rating": 4.5,
        "review_count": 3,
        "short_description": "وصف اختباري",
        "image": "",
        "url": "",
        "purchasable": True,
        "requires_options": False,
        "categories": ["اختبار"],
        "categories_truncated": False,
        "attributes": {},
        "attributes_truncated": False,
        "variation_options": {},
        "variation_options_truncated": False,
    }


def cart_payload(
    *,
    quantity: int = 0,
    total: float = 0.0,
    total_text: str = "$0.00",
    name: str = "منتج السلة",
) -> dict[str, Any]:
    items: list[dict[str, Any]] = []
    if quantity > 0:
        items.append({
            "name": name,
            "quantity": quantity,
            "unit_price": total / quantity,
            "line_total": total,
            "line_total_text": total_text,
            "image": "",
            "variation": {},
            "sku": "CART-1",
            "ref": "l_browserline01",
        })
    return {
        "items": items,
        "line_count": len(items),
        "items_truncated": False,
        "item_count": quantity,
        "total": total,
        "total_text": total_text,
        "currency": "USD",
        "cart_url": "",
        "checkout_url": "",
        "cart_hash": f"browser-cart-{quantity}-{total_text}",
        "mutations_allowed": True,
        "mutation_notice": "",
    }


def boot_payload(
    *,
    conversation_id: str = CONVERSATION_ID,
    token: str = TOKEN,
    cart: dict[str, Any] | None = None,
) -> dict[str, Any]:
    return {
        "ok": True,
        "conversation": {
            "id": conversation_id,
            "token": token,
            "expires_at": "2026-09-13T12:00:00+00:00",
        },
        "messages": [
            {
                "id": 1,
                "turn_id": 1,
                "role": "user",
                "content": "أريد منتجًا مناسبًا",
                "created_at": "2026-08-14T12:00:00+00:00",
            },
            {
                "id": 2,
                "turn_id": 2,
                "role": "assistant",
                "content": "هذا منتج يحتاج إلى تأكيد السعر من المتجر.",
                "created_at": "2026-08-14T12:00:01+00:00",
                "kind": "answer",
                "products": [product_card()],
            },
        ],
        "cart": cart if cart is not None else cart_payload(),
        "cart_available": True,
        "cart_notice": "",
    }


def durable_pending() -> dict[str, Any]:
    return {
        "storage_version": 1,
        "createdAt": "2026-08-14T12:00:02.000Z",
        "image_unavailable": False,
        "body": {
            "conversation_id": CONVERSATION_ID,
            "token": TOKEN,
            "client_turn_id": "turn_cross_conversation_0001",
            "message": "طلب سابق من محادثة أخرى",
            "reply": None,
            "image": None,
        },
    }


def same_conversation_pending(message: str = "رسالة معلّقة بعد إعادة التحميل") -> dict[str, Any]:
    pending = durable_pending()
    pending["body"] = {
        **pending["body"],
        "client_turn_id": "turn_reload_recovery_0001",
        "message": message,
    }
    return pending


def browser_html(php: str) -> str:
    rendered = subprocess.run(
        [php, str(ROOT / "tests/browser/widget-harness.php")],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
    ).stdout
    css = (ROOT / "assets/css/widget.css").read_text(encoding="utf-8")
    utilities = (ROOT / "assets/js/client-utils.js").read_text(encoding="utf-8")
    native_cart = (ROOT / "assets/js/native-cart-sync.js").read_text(encoding="utf-8")
    widget = (ROOT / "assets/js/widget.js").read_text(encoding="utf-8")

    # Build a test-only in-memory bundle from the exact production modules. The
    # transformation removes ESM syntax only; no application statements change.
    utilities = re.sub(r"\bexport\s+(?=(?:async\s+)?(?:function|class|const|let|var)\b)", "", utilities)
    native_cart = re.sub(r"\bexport\s+(?=(?:async\s+)?(?:function|class|const|let|var)\b)", "", native_cart)
    widget = re.sub(r"\A(?:import\s*\{[\s\S]*?\}\s*from\s*['\"][^'\"]+['\"]\s*;\s*)+", "", widget, count=1)
    if widget.startswith("import ") \
            or re.search(r"^\s*export\b", utilities, re.MULTILINE) \
            or re.search(r"^\s*export\b", native_cart, re.MULTILINE):
        fail("Unable to build the browser contract bundle from the production modules.")

    rendered = re.sub(
        r'<link\s+rel="stylesheet"\s+href="/assets/css/widget\.css">',
        lambda _match: f"<style>{css}</style>",
        rendered,
        count=1,
    )
    rendered = re.sub(
        r'<script\s+type="module"\s+src="/assets/js/widget\.js"></script>',
        lambda _match: f"<script>{utilities}\n{native_cart}\n{widget}</script>",
        rendered,
        count=1,
    )
    if "/assets/js/widget.js" in rendered or "/assets/css/widget.css" in rendered:
        fail("The browser harness still contains external production assets.")
    return rendered


def fetch_stub(
    scenario: str,
    boot: dict[str, Any],
    *,
    recovery_cart: dict[str, Any] | None = None,
    preload_pending: dict[str, Any] | None = None,
) -> str:
    boot_json = json.dumps(boot, ensure_ascii=False)
    new_boot = boot_payload(
        conversation_id=NEW_CONVERSATION_ID,
        token=NEW_TOKEN,
        cart=cart_payload(),
    )
    new_boot["messages"] = []
    new_boot_json = json.dumps(new_boot, ensure_ascii=False)
    replacement_boot = boot_payload(
        conversation_id=REPLACEMENT_CONVERSATION_ID,
        token=REPLACEMENT_TOKEN,
        cart=cart_payload(quantity=4, total=44, total_text="$44.00", name="سلة المحادثة البديلة"),
    )
    replacement_boot["messages"] = []
    replacement_boot_json = json.dumps(replacement_boot, ensure_ascii=False)
    recovery_cart_json = json.dumps(recovery_cart or cart_payload(quantity=2, total=22, total_text="$22.00"), ensure_ascii=False)
    preload_json = json.dumps(preload_pending, ensure_ascii=False) if preload_pending is not None else "null"
    scenario_json = json.dumps(scenario)
    return f"""
<script>
class YSAIMemoryStorage {{
  constructor() {{ this.values = new Map(); }}
  get length() {{ return this.values.size; }}
  clear() {{ this.values.clear(); }}
  getItem(key) {{ const normalized = String(key); return this.values.has(normalized) ? this.values.get(normalized) : null; }}
  key(index) {{ return Array.from(this.values.keys())[Number(index)] ?? null; }}
  removeItem(key) {{ this.values.delete(String(key)); }}
  setItem(key, value) {{ this.values.set(String(key), String(value)); }}
}}
for (const name of ['localStorage', 'sessionStorage']) {{
  try {{
    void window[name];
  }} catch (error) {{
    Object.defineProperty(window, name, {{ value: new YSAIMemoryStorage(), configurable: true }});
  }}
}}
const YSAI_SCENARIO = {scenario_json};
const YSAI_BOOT = {boot_json};
const YSAI_NEW_BOOT = {new_boot_json};
const YSAI_REPLACEMENT_BOOT = {replacement_boot_json};
const YSAI_RECOVERY_CART = {recovery_cart_json};
const YSAI_PRELOAD_PENDING = {preload_json};
if (YSAI_PRELOAD_PENDING) {{
  sessionStorage.setItem({json.dumps(PENDING_KEY)}, JSON.stringify(YSAI_PRELOAD_PENDING));
}}
window.__ysaiCapturedBoot = [];
window.__ysaiCapturedChat = [];
window.__ysaiCapturedRecover = [];
window.__ysaiCapturedDelete = [];
window.__ysaiCapturedExport = [];
window.__ysaiCapturedHeaders = [];
window.confirm = () => true;

function ysaiSuccess(body, message = 'تم استلام رسالتك بأمان.', cart = YSAI_BOOT.cart, kind = 'answer') {{
  return {{
    ok: true,
    conversation_id: body.conversation_id,
    client_turn_id: body.client_turn_id,
    turn_id: 3,
    message_id: 4,
    message,
    kind,
    products: [],
    cart,
    receipt: null,
    turn_finalized: true
  }};
}}

window.fetch = async (input, init = {{}}) => {{
  const path = String(input || '');
  window.__ysaiCapturedHeaders.push(init.headers || {{}});
  let payload;
  let status = 200;
  const body = JSON.parse(String(init.body || '{{}}'));
  if (path.endsWith('/boot') || path === 'boot') {{
    window.__ysaiCapturedBoot.push(body);
    if (YSAI_SCENARIO === 'boot_failure_pending_recovery'
      || (['boot_failure_pending_not_found', 'boot_failure_pending_absence_sealed'].includes(YSAI_SCENARIO)
        && window.__ysaiCapturedBoot.length === 1)) {{
      status = 429;
      payload = {{ok: false, error: {{code: 'rate_limited', message: 'تعذّر بدء محادثة جديدة الآن.', retryable: true}}}};
    }} else if (YSAI_SCENARIO === 'boot_failure_unverified' && window.__ysaiCapturedBoot.length === 1) {{
      status = 401;
      payload = {{ok: false, error: {{code: 'conversation_unauthorized', message: 'انتهت المحادثة السابقة.', retryable: false}}}};
    }} else if (YSAI_SCENARIO === 'delete_reconciliation_unavailable' && window.__ysaiCapturedBoot.length === 2) {{
      throw new TypeError('simulated reconciliation network failure');
    }} else if (['delete_lost_response_committed', 'chat_unauthorized', 'export_unauthorized'].includes(YSAI_SCENARIO)
      && window.__ysaiCapturedBoot.length > 1) {{
      payload = YSAI_NEW_BOOT;
    }} else if (YSAI_SCENARIO === 'cross_conversation_processing_then_replacement' && window.__ysaiCapturedBoot.length > 1) {{
      payload = YSAI_REPLACEMENT_BOOT;
    }} else {{
      payload = YSAI_BOOT;
    }}
  }} else if (path.endsWith('/chat') || path === 'chat') {{
    window.__ysaiCapturedChat.push(body);
    if (YSAI_SCENARIO === 'chat_unauthorized' && window.__ysaiCapturedChat.length === 1) {{
      status = 401;
      payload = {{
        ok: false,
        error: {{code: 'conversation_unauthorized', message: 'انتهت جلسة المحادثة.', retryable: false}}
      }};
    }} else if (YSAI_SCENARIO === 'network_error') {{
      throw new TypeError('simulated network failure');
    }}
    else if (YSAI_SCENARIO === 'accepted_failure') {{
      status = 500;
      payload = {{
        ok: false,
        conversation_id: body.conversation_id,
        client_turn_id: body.client_turn_id,
        turn_id: 3,
        message_id: 4,
        turn_finalized: true,
        request_accepted: true,
        kind: 'safe_failure',
        error: {{
          code: 'request_failed',
          message: 'فشل محفوظ بعد قبول الرسالة.',
          retryable: false,
          retry_mode: 'none'
        }}
      }};
    }} else if (YSAI_SCENARIO === 'provider_new_turn_retry'
      && window.__ysaiCapturedChat.length === 1) {{
      status = 503;
      payload = {{
        ok: false,
        conversation_id: body.conversation_id,
        client_turn_id: body.client_turn_id,
        turn_id: 3,
        message_id: 4,
        turn_finalized: true,
        request_accepted: true,
        kind: 'safe_failure',
        error: {{
          code: 'provider_unavailable',
          message: 'خدمة الذكاء الاصطناعي غير متاحة مؤقتًا.',
          retryable: true,
          retry_mode: 'new_turn'
        }}
      }};
    }} else if (YSAI_SCENARIO === 'provider_new_turn_retry') {{
      payload = {{
        ...ysaiSuccess(body, 'نجحت المحاولة الجديدة.'),
        turn_id: 5,
        message_id: 6
      }};
    }} else if (YSAI_SCENARIO === 'rate_limit_delayed_new_turn'
      && window.__ysaiCapturedChat.length === 1) {{
      status = 429;
      payload = {{
        ok: false,
        conversation_id: body.conversation_id,
        client_turn_id: body.client_turn_id,
        turn_id: 3,
        turn_finalized: true,
        request_accepted: false,
        kind: 'safe_failure',
        error: {{
          code: 'rate_limited',
          message: 'تم الوصول إلى حد استخدام المساعد مؤقتًا.',
          retryable: true,
          retry_mode: 'new_turn',
          retry_after_seconds: 1
        }}
      }};
    }} else if (YSAI_SCENARIO === 'rate_limit_delayed_new_turn') {{
      payload = {{
        ...ysaiSuccess(body, 'نجحت المحاولة بعد انتهاء الانتظار.'),
        turn_id: 5,
        message_id: 6
      }};
    }} else if (YSAI_SCENARIO === 'unbound_422') {{
      status = 422;
      payload = {{ok: false, error: {{code: 'invalid_request', message: 'خطأ وسيط غير موثوق.', retryable: false}}}};
    }} else if (YSAI_SCENARIO === 'malformed_success') {{
      payload = {{...ysaiSuccess(body), products: [{{ref: 'p_bad'}}]}};
    }} else if (YSAI_SCENARIO === 'answer_null_cart') {{
      payload = ysaiSuccess(body, 'إجابة لا تغيّر السلة.', null, 'answer');
    }} else if (YSAI_SCENARIO === 'cart_uncertain') {{
      payload = ysaiSuccess(body, 'تعذّر تأكيد حالة السلة.', null, 'cart_uncertain');
    }} else {{
      payload = ysaiSuccess(body);
    }}
  }} else if (path.endsWith('/turn/recover') || path === 'turn/recover') {{
    window.__ysaiCapturedRecover.push(body);
    if (YSAI_SCENARIO === 'chat_unauthorized') {{
      status = 401;
      payload = {{
        ok: false,
        error: {{code: 'conversation_unauthorized', message: 'انتهت جلسة المحادثة.', retryable: false}},
        conversation_id: body.conversation_id,
        client_turn_id: body.client_turn_id,
        turn_finalized: false,
        request_disposition: 'unverified'
      }};
    }} else if (YSAI_SCENARIO === 'boot_failure_unverified') {{
      status = 401;
      payload = {{
        ok: false,
        error: {{code: 'conversation_unauthorized', message: 'تعذّر التحقق من الطلب السابق.', retryable: false}},
        conversation_id: body.conversation_id,
        client_turn_id: body.client_turn_id,
        turn_finalized: false,
        request_disposition: 'unverified'
      }};
    }} else if (['boot_failure_pending_absence_sealed', 'pending_absence_sealed_after_boot'].includes(YSAI_SCENARIO)) {{
      status = 404;
      payload = {{
        ok: false,
        error: {{
          code: 'turn_not_found',
          message: 'لم يصل هذا الطلب إلى المعالجة. أعد إرساله كطلب جديد إذا كان ما يزال مطلوبًا.',
          retryable: false
        }},
        conversation_id: body.conversation_id,
        client_turn_id: body.client_turn_id,
        turn_id: 91,
        turn_finalized: true,
        request_accepted: false,
        kind: 'safe_failure'
      }};
    }} else if (['boot_failure_pending_not_found', 'pending_not_found_after_boot'].includes(YSAI_SCENARIO)) {{
      status = 404;
      payload = {{
        ok: false,
        error: {{code: 'turn_not_found', message: 'لم يتم العثور على الطلب.', retryable: false}},
        conversation_id: body.conversation_id,
        client_turn_id: body.client_turn_id,
        turn_finalized: false,
        request_disposition: 'not_found',
        request_accepted: false
      }};
    }} else if (YSAI_SCENARIO === 'cross_conversation_processing_then_replacement' && window.__ysaiCapturedRecover.length === 1) {{
      payload = {{
        ok: true,
        status: 'processing',
        conversation_id: body.conversation_id,
        client_turn_id: body.client_turn_id,
        turn_finalized: false
      }};
    }} else {{
      payload = ysaiSuccess(body, 'تم استرداد النتيجة نفسها بأمان.', YSAI_RECOVERY_CART, 'answer');
    }}
  }} else if (path.endsWith('/conversation/delete') || path === 'conversation/delete') {{
    window.__ysaiCapturedDelete.push(body);
    if (['delete_lost_response_committed', 'delete_lost_response_not_committed', 'delete_reconciliation_unavailable'].includes(YSAI_SCENARIO)) {{
      throw new TypeError('simulated lost deletion response');
    }}
    payload = {{ok: true, deleted: true}};
  }} else if (path.endsWith('/conversation/export') || path === 'conversation/export') {{
    window.__ysaiCapturedExport.push(body);
    status = 401;
    payload = {{ok: false, error: {{code: 'conversation_unauthorized', message: 'انتهت جلسة المحادثة.', retryable: false}}}};
  }} else {{
    status = 404;
    payload = {{ok: false, error: {{code: 'not_found', message: 'Not found', retryable: false}}}};
  }}
  return new Response(JSON.stringify(payload), {{
    status,
    headers: {{'Content-Type': 'application/json; charset=utf-8'}}
  }});
}};
</script>
"""


def scenario_html(
    base_html: str,
    scenario: str,
    boot: dict[str, Any],
    *,
    recovery_cart: dict[str, Any] | None = None,
    preload_pending: dict[str, Any] | None = None,
) -> str:
    return base_html.replace(
        "</head>",
        fetch_stub(
            scenario,
            boot,
            recovery_cart=recovery_cart,
            preload_pending=preload_pending,
        ) + "</head>",
        1,
    )


def open_widget(
    browser: Browser,
    html: str,
    *,
    expected_status: str = "متصل",
) -> tuple[Any, Page, list[str]]:
    context = browser.new_context(locale="ar", reduced_motion="reduce")
    page = context.new_page()
    page_errors: list[str] = []
    page.on("pageerror", lambda error: page_errors.append(str(error)))
    page.set_content(html, wait_until="load")
    launcher = page.locator("[data-ysai-open]")
    if launcher.count() != 1 or launcher.get_attribute("aria-label") != "فتح المساعد":
        fail("The launcher did not expose its configured accessible name.")
    launcher.focus()
    if page.get_by_role("dialog", name="مساعد ياسين").is_hidden():
        launcher.press("Enter")
    page.get_by_text(expected_status, exact=True).wait_for()
    return context, page, page_errors


def submit(page: Page, message: str) -> None:
    textbox = page.get_by_role("textbox", name="رسالتك")
    textbox.fill(message)
    textbox.press("Enter")


def click_privacy_action(page: Page, name: str) -> None:
    toggle = page.get_by_role("button", name="خيارات المحادثة")
    toggle.click()
    menu = page.get_by_role("menu")
    if menu.is_hidden():
        fail("The conversation options menu did not open.")
    menu.get_by_role("menuitem", name=name, exact=True).click()


def assert_no_page_errors(errors: list[str], label: str) -> None:
    if errors:
        fail(f"{label} page errors: " + " | ".join(errors))


def run_accessibility_and_success(browser: Browser, base_html: str) -> None:
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "success", boot_payload()),
    )
    launcher = page.locator("[data-ysai-open]")
    dialog = page.get_by_role("dialog", name="مساعد ياسين")
    if not dialog.is_visible() or launcher.get_attribute("aria-expanded") != "true":
        fail("The labelled assistant dialog did not expose its open state.")
    page.wait_for_function("document.activeElement?.matches('[data-ysai-input]')")

    product_list = page.get_by_role("list", name="المنتجات المقترحة")
    if product_list.get_by_role("listitem").count() != 1:
        fail("The product carousel was not exposed as one semantic list item.")
    if product_list.locator(".ysai-product__meta strong").inner_text() != "السعر غير متاح":
        fail("An unavailable price was not rendered explicitly.")

    if page.locator(".ysai-day-separator").count() != 1:
        fail("The messaging transcript did not expose one bounded day separator.")
    if page.locator("[data-ysai-message] time").count() != 2:
        fail("The configured messaging timestamps were not rendered for public history.")
    if page.get_by_role("button", name="نسخ", exact=True).count() != 2:
        fail("The messaging transcript did not expose one copy action per public message.")
    reply_button = page.get_by_role("button", name="رد", exact=True)
    if reply_button.count() != 1:
        fail("The assistant message did not expose one reply action.")
    reply_button.click()
    reply_preview = page.locator("[data-ysai-reply-preview]")
    if reply_preview.is_hidden() or "هذا منتج يحتاج" not in reply_preview.inner_text():
        fail("The reply action did not create a visible bounded quote preview.")
    page.get_by_role("button", name="إلغاء الرد", exact=True).click()
    if not reply_preview.is_hidden():
        fail("The reply preview could not be dismissed.")

    privacy_toggle = page.get_by_role("button", name="خيارات المحادثة")
    privacy_toggle.click()
    privacy_menu = page.get_by_role("menu")
    if privacy_menu.is_hidden() or privacy_toggle.get_attribute("aria-expanded") != "true":
        fail("The conversation privacy menu did not expose its open state.")
    page.keyboard.press("Escape")
    if not privacy_menu.is_hidden() or privacy_toggle.get_attribute("aria-expanded") != "false":
        fail("Escape did not dismiss the conversation privacy menu.")
    page.wait_for_function("document.activeElement?.matches('[data-ysai-privacy-toggle]')")

    session = context.new_cdp_session(page)
    nodes = session.send("Accessibility.getFullAXTree").get("nodes", [])
    roles = [node.get("role", {}).get("value") for node in nodes]
    names = [node.get("name", {}).get("value") for node in nodes]
    if "dialog" not in roles or "مساعد ياسين" not in names or "رسالتك" not in names:
        fail("The Chromium accessibility tree omitted the labelled dialog or textbox.")

    page.keyboard.press("Escape")
    if not dialog.is_hidden():
        fail("Escape did not dismiss the dialog.")
    page.wait_for_function("document.activeElement?.matches('[data-ysai-open]')")
    if launcher.get_attribute("aria-expanded") != "false":
        fail("The launcher did not expose its collapsed state.")

    launcher.press("Enter")
    page.get_by_text("متصل", exact=True).wait_for()

    # Delay only the real chat call so the production typing and unread
    # lifecycle can be observed without replacing any widget implementation.
    page.evaluate("""
      () => {
        const originalFetch = window.fetch;
        window.fetch = async (input, init = {}) => {
          const path = String(input || '');
          if (path.endsWith('/chat') || path === 'chat') {
            await new Promise((resolve) => window.setTimeout(resolve, 300));
          }
          return originalFetch(input, init);
        };
      }
    """)
    submit(page, "اختبار إرسال آمن")
    typing = page.locator("[data-ysai-typing]")
    typing.wait_for(state="visible")
    if "المساعد يكتب الآن" not in typing.inner_text():
        fail("The in-flight message did not expose an accessible typing state.")

    page.get_by_role("button", name="تصغير المساعد", exact=True).click()
    if not dialog.is_hidden():
        fail("The shopper could not minimize the messenger while a request was in flight.")
    response_message = page.locator("[data-ysai-message]").filter(has_text="تم استلام رسالتك بأمان.")
    response_message.wait_for(state="attached")
    unread = page.locator("[data-ysai-launcher-unread]")
    unread.wait_for(state="visible")
    if unread.inner_text() != "1":
        fail("A response received while minimized did not increment the launcher unread count.")

    launcher.press("Enter")
    response_message.wait_for(state="visible")
    if not unread.is_hidden():
        fail("Opening the messenger did not clear the launcher unread count.")
    if not typing.is_hidden():
        fail("The typing indicator remained visible after the response completed.")
    captured = page.evaluate("window.__ysaiCapturedChat")
    if not isinstance(captured, list) or len(captured) != 1:
        fail("The browser did not issue exactly one chat request.")
    request = captured[0]
    if request.get("conversation_id") != CONVERSATION_ID or request.get("message") != "اختبار إرسال آمن":
        fail("The browser chat request did not preserve the authoritative conversation and message.")
    turn_id = request.get("client_turn_id")
    if not isinstance(turn_id, str) or not (16 <= len(turn_id) <= 64):
        fail("The browser did not create a bounded strong turn identifier.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail("A durably finalized success left a pending turn behind.")
    assert_no_page_errors(page_errors, "success")
    context.close()


def run_empty_state_quick_replies(browser: Browser, base_html: str) -> None:
    empty_boot = boot_payload()
    empty_boot["messages"] = []
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "success", empty_boot),
    )
    empty_state = page.locator("[data-ysai-empty]")
    if empty_state.is_hidden() or "مرحبًا! أنا مساعد المتجر." not in empty_state.inner_text():
        fail("A new shopper did not receive the configured messenger welcome state.")
    suggestions = page.get_by_role("group", name="ردود سريعة").get_by_role("button")
    if suggestions.count() != 3:
        fail("The configured messenger quick replies were not rendered as three controls.")
    suggestions.first.click()
    textbox = page.get_by_role("textbox", name="رسالتك")
    if textbox.input_value() != "رشّح لي منتجات مناسبة":
        fail("A quick reply did not populate the shopper composer without sending prematurely.")
    if page.evaluate("window.__ysaiCapturedChat.length") != 0:
        fail("Selecting a quick reply transmitted a message before shopper confirmation.")
    textbox.press("Enter")
    page.get_by_role("log").get_by_text("تم استلام رسالتك بأمان.", exact=True).wait_for()
    captured = page.evaluate("window.__ysaiCapturedChat")
    if len(captured) != 1 or captured[0].get("message") != "رشّح لي منتجات مناسبة":
        fail("The confirmed quick reply did not use the same bounded chat submission path.")
    assert_no_page_errors(page_errors, "empty-state quick replies")
    context.close()


def run_unread_feature_toggle(browser: Browser, base_html: str) -> None:
    disabled_html = base_html.replace('"unreadButton":true', '"unreadButton":false', 1)
    if disabled_html == base_html:
        fail("The browser contract could not disable the production unread feature flag.")
    context, page, page_errors = open_widget(
        browser,
        scenario_html(disabled_html, "success", boot_payload()),
    )
    page.evaluate("""
      () => {
        const originalFetch = window.fetch;
        window.fetch = async (input, init = {}) => {
          const path = String(input || '');
          if (path.endsWith('/chat') || path === 'chat') {
            await new Promise((resolve) => window.setTimeout(resolve, 250));
          }
          return originalFetch(input, init);
        };
      }
    """)
    submit(page, "اختبار تعطيل عداد الرسائل")
    page.locator("[data-ysai-typing]").wait_for(state="visible")
    page.get_by_role("button", name="تصغير المساعد", exact=True).click()
    page.locator("[data-ysai-message]").filter(has_text="تم استلام رسالتك بأمان.").wait_for(state="attached")
    if not page.locator("[data-ysai-launcher-unread]").is_hidden():
        fail("Disabling the unread control still exposed a launcher message count.")
    page.locator("[data-ysai-open]").press("Enter")
    if not page.locator("[data-ysai-latest]").is_hidden():
        fail("Disabling the unread control still exposed the latest-message button.")
    assert_no_page_errors(page_errors, "disabled unread controls")
    context.close()


def run_accepted_failure(browser: Browser, base_html: str) -> None:
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "accepted_failure", boot_payload()),
    )
    submit(page, "اختبار فشل بعد القبول")
    page.get_by_role("log").get_by_text("فشل محفوظ بعد قبول الرسالة.", exact=True).wait_for()
    if page.locator('[data-ysai-send-status="rejected"]').count() != 0:
        fail("An accepted durable failure was mislabeled as not sent.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail("An accepted durable failure did not clear its pending record.")
    if page.evaluate("window.__ysaiCapturedChat.length") != 1:
        fail("The accepted-failure scenario issued more than one chat request.")
    assert_no_page_errors(page_errors, "accepted failure")
    context.close()


def run_provider_new_turn_retry(browser: Browser, base_html: str) -> None:
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "provider_new_turn_retry", boot_payload()),
    )
    message = "اختبار إعادة طلب مزود مؤقت"
    submit(page, message)
    page.get_by_role("log").get_by_text(
        "خدمة الذكاء الاصطناعي غير متاحة مؤقتًا.",
        exact=True,
    ).wait_for()
    retry = page.get_by_role("button", name="إرسال الطلب من جديد", exact=True)
    retry.wait_for()
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail("A finalized provider failure did not clear its original pending identity.")
    first = page.evaluate("window.__ysaiCapturedChat[0]")
    first_headers = page.evaluate("window.__ysaiCapturedHeaders.find((headers) => headers['X-YSAI-Client-Contract'])")
    if not first_headers or first_headers.get("X-YSAI-Client-Contract") != "2":
        fail("The widget did not opt into the current REST error contract.")
    if page.evaluate("window.__ysaiCapturedChat.length") != 1:
        fail("The provider retry scenario sent more than one request before explicit shopper action.")

    retry.click()
    page.get_by_role("log").get_by_text("نجحت المحاولة الجديدة.", exact=True).wait_for()
    requests = page.evaluate("window.__ysaiCapturedChat")
    if len(requests) != 2:
        fail("The explicit new-turn action did not issue exactly one fresh chat request.")
    second = requests[1]
    if second.get("client_turn_id") == first.get("client_turn_id"):
        fail("The provider retry reused a durably finalized turn identity.")
    if second.get("message") != message:
        fail("The provider retry did not preserve the shopper's original message.")
    if second.get("conversation_id") != first.get("conversation_id"):
        fail("The provider retry unexpectedly changed the active conversation.")
    if page.evaluate("window.__ysaiCapturedRecover.length") != 0:
        fail("A finalized provider failure incorrectly entered same-turn recovery.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail("The successful fresh provider turn left a pending identity behind.")
    assert_no_page_errors(page_errors, "provider new-turn retry")
    context.close()


def run_delayed_rejected_rate_limit_retry(browser: Browser, base_html: str) -> None:
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "rate_limit_delayed_new_turn", boot_payload()),
    )
    message = "اختبار انتظار حد الاستخدام"
    submit(page, message)
    page.get_by_text("يمكن إعادة المحاولة بعد 1 ثانية.", exact=False).wait_for()
    if page.get_by_role("button", name="إرسال الطلب من جديد", exact=True).count() != 0:
        fail("The rate-limit retry action appeared before Retry-After elapsed.")
    page.get_by_role("button", name="إرسال الطلب من جديد", exact=True).wait_for(timeout=3000)
    if page.evaluate("window.__ysaiCapturedChat.length") != 1:
        fail("A finalized rejected rate limit retried without explicit shopper action.")
    first = page.evaluate("window.__ysaiCapturedChat[0]")
    page.get_by_role("button", name="إرسال الطلب من جديد", exact=True).click()
    page.get_by_role("log").get_by_text(
        "نجحت المحاولة بعد انتهاء الانتظار.",
        exact=True,
    ).wait_for()
    requests = page.evaluate("window.__ysaiCapturedChat")
    if len(requests) != 2:
        fail("The delayed rate-limit action did not create exactly one fresh turn.")
    if requests[1].get("client_turn_id") == first.get("client_turn_id"):
        fail("The delayed rate-limit retry reused the finalized rejected turn identity.")
    if requests[1].get("message") != message:
        fail("The delayed rate-limit retry did not preserve the original shopper message.")
    if page.evaluate("window.__ysaiCapturedRecover.length") != 0:
        fail("A finalized rejected rate limit incorrectly entered same-turn recovery.")
    assert_no_page_errors(page_errors, "delayed rejected rate-limit retry")
    context.close()


def run_ambiguous_recovery(browser: Browser, base_html: str, scenario: str) -> None:
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, scenario, boot_payload()),
    )
    submit(page, f"اختبار استعادة {scenario}")
    page.locator("[data-ysai-error] button").wait_for()
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is None:
        fail(f"{scenario} discarded the exact pending turn before recovery.")
    if page.locator('[data-ysai-send-status="rejected"]').count() != 0:
        fail(f"{scenario} mislabeled an unverified request as rejected.")
    page.locator("[data-ysai-error] button").click()
    page.get_by_text("تم استرداد النتيجة نفسها بأمان.", exact=True).wait_for()
    if page.evaluate("window.__ysaiCapturedChat.length") != 1:
        fail(f"{scenario} created a second chat request instead of recovering the same turn.")
    if page.evaluate("window.__ysaiCapturedRecover.length") != 1:
        fail(f"{scenario} did not issue exactly one same-turn recovery request.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail(f"{scenario} left the recovered turn pending.")
    assert_no_page_errors(page_errors, scenario)
    context.close()


def run_cart_contracts(browser: Browser, base_html: str) -> None:
    original = cart_payload(quantity=1, total=42, total_text="$42.00")
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "answer_null_cart", boot_payload(cart=original)),
    )
    submit(page, "إجابة فقط دون تعديل السلة")
    page.get_by_text("إجابة لا تغيّر السلة.", exact=True).wait_for()
    cart = page.locator("[data-ysai-cart]")
    if cart.is_hidden() or "$42.00" not in cart.inner_text():
        fail("A normal answer with cart:null erased the last verified cart snapshot.")
    assert_no_page_errors(page_errors, "null-cart answer")
    context.close()

    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "cart_uncertain", boot_payload(cart=original)),
    )
    submit(page, "حالة سلة غير مؤكدة")
    page.get_by_text("تعذّر تأكيد حالة السلة.", exact=True).wait_for()
    if not page.locator("[data-ysai-cart]").is_hidden():
        fail("A cart-uncertain result left a stale cart visible as authoritative.")
    assert_no_page_errors(page_errors, "cart uncertainty")
    context.close()


def run_network_status(browser: Browser, base_html: str) -> None:
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "network_error", boot_payload()),
    )
    submit(page, "اختبار انقطاع الشبكة")
    page.get_by_text("غير متصل", exact=True).wait_for()
    page.locator("[data-ysai-error] button").wait_for()
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is None:
        fail("A network failure discarded the recoverable pending turn.")
    if page.evaluate("window.__ysaiCapturedChat.length") != 1:
        fail("The network-failure scenario issued an unexpected duplicate chat request.")
    assert_no_page_errors(page_errors, "network failure")
    context.close()


def run_cross_conversation_recovery(browser: Browser, base_html: str) -> None:
    recovered_cart = cart_payload(quantity=3, total=33, total_text="$33.00", name="سلة الطلب السابق")
    boot = boot_payload(
        conversation_id=NEW_CONVERSATION_ID,
        token=NEW_TOKEN,
        cart=cart_payload(quantity=1, total=11, total_text="$11.00", name="سلة المحادثة الجديدة"),
    )
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "cross_conversation",
            boot,
            recovery_cart=recovered_cart,
            preload_pending=durable_pending(),
        ),
    )
    page.get_by_text("اكتمل الطلب السابق ضمن محادثة أخرى. افحص السلة قبل متابعة التسوق.", exact=True).wait_for()
    cart = page.locator("[data-ysai-cart]")
    if cart.is_hidden() or "$11.00" not in cart.inner_text() or "سلة المحادثة الجديدة" not in cart.inner_text():
        fail("Cross-conversation recovery did not refresh the cart through the current capability.")
    if "$33.00" in cart.inner_text() or "سلة الطلب السابق" in cart.inner_text():
        fail("Cross-conversation recovery rendered the older turn's stale stored cart snapshot.")
    if page.get_by_text("تم استرداد النتيجة نفسها بأمان.", exact=True).count() != 0:
        fail("A recovered assistant message was spliced into a different conversation capability.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail("Cross-conversation recovery left the old turn pending.")
    captured_boot = page.evaluate("window.__ysaiCapturedBoot")
    captured_recover = page.evaluate("window.__ysaiCapturedRecover")
    if len(captured_boot) != 2:
        fail("Cross-conversation recovery did not perform exactly one current-capability cart refresh.")
    if captured_boot[0].get("conversation_id") != CONVERSATION_ID:
        fail("Boot did not prioritize the exact pending conversation credentials.")
    if captured_boot[1].get("conversation_id") != NEW_CONVERSATION_ID:
        fail("Cart refresh did not use the newly booted current conversation capability.")
    if captured_recover[0].get("conversation_id") != CONVERSATION_ID:
        fail("Recovery did not use the original conversation capability.")
    assert_no_page_errors(page_errors, "cross-conversation recovery")
    context.close()


def run_pending_recovery_when_boot_fails(browser: Browser, base_html: str) -> None:
    pending = same_conversation_pending("طلب اكتمل رغم تعذّر بدء واجهة المحادثة")
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "boot_failure_pending_recovery",
            boot_payload(),
            preload_pending=pending,
        ),
        expected_status="غير متصل",
    )
    page.get_by_role("log").get_by_text("تم استرداد النتيجة نفسها بأمان.", exact=True).wait_for()
    if page.evaluate("window.__ysaiCapturedBoot.length") != 1:
        fail("The boot-failure recovery scenario issued an unexpected boot retry.")
    if page.evaluate("window.__ysaiCapturedRecover.length") != 1:
        fail("A boot failure did not fall back to exactly one exact-turn recovery request.")
    if page.evaluate("window.__ysaiCapturedChat.length") != 0:
        fail("Boot-failure recovery resent provider or cart work.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail("A finalized turn stayed pending merely because boot failed.")
    if not page.locator("[data-ysai-cart]").is_hidden():
        fail("Boot-failure recovery rendered a potentially stale stored cart snapshot.")
    if page.get_by_role("log").get_by_text(pending["body"]["message"], exact=True).count() != 1:
        fail("Boot-failure recovery did not preserve the accepted shopper message.")
    if page.get_by_role("button", name="رد", exact=True).count() != 0:
        fail("Text recovered without a booted capability exposed reply authority.")
    assert_no_page_errors(page_errors, "pending recovery after boot failure")
    context.close()


def assert_missing_turn_requires_explicit_new_submission(
    page: Page,
    pending: dict,
    *,
    expected_boot_count_before_submit: int,
    expected_reply_count_before_submit: int,
    label: str,
    expected_message: str = "لم يتم العثور على الطلب.",
) -> None:
    page.get_by_text(expected_message, exact=True).wait_for()
    if page.evaluate("window.__ysaiCapturedRecover.length") != 1:
        fail(f"{label}: recovery did not inspect the exact turn once.")
    if page.evaluate("window.__ysaiCapturedChat.length") != 0:
        fail(f"{label}: a sealed missing turn was automatically resent.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail(f"{label}: a conclusively retired turn remained pending.")
    if page.locator('[data-ysai-send-status="rejected"]').count() != 1:
        fail(f"{label}: the retired request was not shown as unsent.")
    textbox = page.get_by_role("textbox", name="رسالتك")
    if textbox.input_value() != pending["body"]["message"]:
        fail(f"{label}: the shopper draft was not restored for deliberate resubmission.")
    if page.get_by_role("button", name="رد", exact=True).count() != expected_reply_count_before_submit:
        fail(f"{label}: missing-turn recovery changed unrelated reply authority.")
    if page.evaluate("window.__ysaiCapturedBoot.length") != expected_boot_count_before_submit:
        fail(f"{label}: the pre-submit boot count was unexpected.")

    original_turn_id = pending["body"]["client_turn_id"]
    submit(page, pending["body"]["message"])
    page.get_by_role("log").get_by_text("تم استلام رسالتك بأمان.", exact=True).wait_for()
    captured_chat = page.evaluate("window.__ysaiCapturedChat")
    if len(captured_chat) != 1:
        fail(f"{label}: deliberate resubmission did not issue exactly one new request.")
    request = captured_chat[0]
    if request.get("message") != pending["body"]["message"]:
        fail(f"{label}: deliberate resubmission changed the restored message.")
    if request.get("client_turn_id") == original_turn_id:
        fail(f"{label}: deliberate resubmission reused the server-sealed turn ID.")
    if not isinstance(request.get("client_turn_id"), str) or not (16 <= len(request["client_turn_id"]) <= 64):
        fail(f"{label}: deliberate resubmission did not create a strong bounded turn ID.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail(f"{label}: the newly finalized request remained pending.")


def run_boot_failure_not_found_requires_explicit_new_turn(browser: Browser, base_html: str) -> None:
    pending = same_conversation_pending("طلب نصي لم يصل إلى الخادم أول مرة")
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "boot_failure_pending_not_found",
            boot_payload(),
            preload_pending=pending,
        ),
        expected_status="غير متصل",
    )
    assert_missing_turn_requires_explicit_new_submission(
        page,
        pending,
        expected_boot_count_before_submit=1,
        expected_reply_count_before_submit=0,
        expected_message="لم يتم العثور على الطلب.",
        label="boot-failure sealed missing turn",
    )
    if page.evaluate("window.__ysaiCapturedBoot.length") != 2:
        fail("A deliberate new submission after boot failure did not perform exactly one fresh boot.")
    assert_no_page_errors(page_errors, "boot-failure sealed missing turn")
    context.close()


def run_pending_not_found_after_boot_requires_explicit_new_turn(browser: Browser, base_html: str) -> None:
    pending = same_conversation_pending("طلب نصي مفقود بعد بدء المحادثة")
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "pending_not_found_after_boot",
            boot_payload(),
            preload_pending=pending,
        ),
    )
    assert_missing_turn_requires_explicit_new_submission(
        page,
        pending,
        expected_boot_count_before_submit=1,
        expected_reply_count_before_submit=1,
        expected_message="لم يتم العثور على الطلب.",
        label="booted sealed missing turn",
    )
    if page.evaluate("window.__ysaiCapturedBoot.length") != 1:
        fail("A deliberate new submission on a booted conversation performed an unnecessary boot.")
    assert_no_page_errors(page_errors, "booted sealed missing turn")
    context.close()


def run_boot_failure_absence_seal_requires_explicit_new_turn(browser: Browser, base_html: str) -> None:
    pending = same_conversation_pending("طلب نصي حُسم أنه لم يصل إلى المعالجة")
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "boot_failure_pending_absence_sealed",
            boot_payload(),
            preload_pending=pending,
        ),
        expected_status="غير متصل",
    )
    assert_missing_turn_requires_explicit_new_submission(
        page,
        pending,
        expected_boot_count_before_submit=1,
        expected_reply_count_before_submit=0,
        expected_message="لم يصل هذا الطلب إلى المعالجة. أعد إرساله كطلب جديد إذا كان ما يزال مطلوبًا.",
        label="boot-failure durable absence seal",
    )
    if page.evaluate("window.__ysaiCapturedBoot.length") != 2:
        fail("A deliberate submission after a durable absence seal did not perform exactly one fresh boot.")
    assert_no_page_errors(page_errors, "boot-failure durable absence seal")
    context.close()


def run_booted_absence_seal_requires_explicit_new_turn(browser: Browser, base_html: str) -> None:
    pending = same_conversation_pending("طلب نصي مفقود حُسم بعد بدء المحادثة")
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "pending_absence_sealed_after_boot",
            boot_payload(),
            preload_pending=pending,
        ),
    )
    assert_missing_turn_requires_explicit_new_submission(
        page,
        pending,
        expected_boot_count_before_submit=1,
        expected_reply_count_before_submit=1,
        expected_message="لم يصل هذا الطلب إلى المعالجة. أعد إرساله كطلب جديد إذا كان ما يزال مطلوبًا.",
        label="booted durable absence seal",
    )
    if page.evaluate("window.__ysaiCapturedBoot.length") != 1:
        fail("A deliberate submission after a booted absence seal performed an unnecessary boot.")
    assert_no_page_errors(page_errors, "booted durable absence seal")
    context.close()


def run_replacement_boot_adoption_clears_reply(browser: Browser, base_html: str) -> None:
    pending = durable_pending()
    current = boot_payload(
        conversation_id=NEW_CONVERSATION_ID,
        token=NEW_TOKEN,
        cart=cart_payload(quantity=1, total=11, total_text="$11.00", name="سلة المحادثة الحالية"),
    )
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "cross_conversation_processing_then_replacement",
            current,
            preload_pending=pending,
        ),
    )
    page.get_by_text("الطلب السابق ما زال قيد المعالجة.", exact=True).wait_for()
    reply_button = page.get_by_role("button", name="رد", exact=True)
    if reply_button.count() < 1:
        fail("The replacement-capability scenario did not expose a current reply target before refresh.")
    reply_button.first.click()
    if page.locator("[data-ysai-reply-preview]").is_hidden():
        fail("The current assistant message did not create a reply preview.")

    page.locator("[data-ysai-error] button").click()
    page.get_by_text(
        "اكتمل الطلب السابق ضمن محادثة أخرى. افحص السلة قبل متابعة التسوق.",
        exact=True,
    ).wait_for()
    if not page.locator("[data-ysai-reply-preview]").is_hidden():
        fail("A replacement boot capability retained reply authority from the previous conversation.")
    saved = page.evaluate("JSON.parse(localStorage.getItem('ysai.browser.contract'))")
    if saved.get("id") != REPLACEMENT_CONVERSATION_ID or saved.get("token") != REPLACEMENT_TOKEN:
        fail("Cart refresh discarded the validated replacement conversation capability.")
    cart = page.locator("[data-ysai-cart]")
    if cart.is_hidden() or "$44.00" not in cart.inner_text() or "سلة المحادثة البديلة" not in cart.inner_text():
        fail("The replacement boot snapshot was not adopted as the current cart authority.")

    submit(page, "رسالة بعد اعتماد المحادثة البديلة")
    page.get_by_role("log").get_by_text("تم استلام رسالتك بأمان.", exact=True).wait_for()
    captured_chat = page.evaluate("window.__ysaiCapturedChat")
    if len(captured_chat) != 1:
        fail("The replacement-capability scenario issued an unexpected chat count.")
    request = captured_chat[0]
    if request.get("conversation_id") != REPLACEMENT_CONVERSATION_ID or request.get("token") != REPLACEMENT_TOKEN:
        fail("A later chat request used the discarded conversation capability.")
    if request.get("reply") is not None:
        fail("A later chat request carried stale reply authority across a replacement capability.")
    captured_boot = page.evaluate("window.__ysaiCapturedBoot")
    if len(captured_boot) != 2:
        fail("Replacement cart refresh retried boot or consumed more than one replacement capability.")
    assert_no_page_errors(page_errors, "replacement boot adoption")
    context.close()

def run_lost_delete_response_reconciliation(browser: Browser, base_html: str) -> None:
    original = boot_payload(cart=cart_payload(quantity=1, total=11, total_text="$11.00"))

    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "delete_lost_response_committed", original),
    )
    click_privacy_action(page, "حذف المحادثة")
    page.get_by_text(
        "فُقد تأكيد الحذف وتعذّر استئناف المحادثة السابقة. بدأت جلسة جديدة دون افتراض أن الحذف اكتمل.",
        exact=True,
    ).wait_for()
    if page.evaluate("window.__ysaiCapturedDelete.length") != 1:
        fail("The lost-delete committed scenario did not issue exactly one delete request.")
    captured_boot = page.evaluate("window.__ysaiCapturedBoot")
    if len(captured_boot) != 2 or captured_boot[1] != {
        "conversation_id": CONVERSATION_ID,
        "token": TOKEN,
    }:
        fail("Deletion reconciliation did not probe the exact original capability.")
    saved = page.evaluate("JSON.parse(localStorage.getItem('ysai.browser.contract'))")
    if saved.get("id") != NEW_CONVERSATION_ID or saved.get("token") != NEW_TOKEN:
        fail("A committed ambiguous deletion did not replace stale local authority with the fresh capability.")
    if page.get_by_role("log").get_by_text("أريد منتجًا مناسبًا", exact=True).count() != 0:
        fail("A committed ambiguous deletion left the previous transcript visible.")
    if not page.locator("[data-ysai-cart]").is_hidden() and "$11.00" in page.locator("[data-ysai-cart]").inner_text():
        fail("A committed ambiguous deletion retained the previous cart projection.")
    submit(page, "رسالة بعد مصالحة الحذف")
    page.get_by_role("log").get_by_text("تم استلام رسالتك بأمان.", exact=True).wait_for()
    captured_chat = page.evaluate("window.__ysaiCapturedChat")
    if len(captured_chat) != 1 or captured_chat[0].get("conversation_id") != NEW_CONVERSATION_ID:
        fail("Chat resumed with the stale capability after deletion reconciliation.")
    assert_no_page_errors(page_errors, "lost delete response after commit")
    context.close()

    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "delete_lost_response_not_committed", original),
    )
    click_privacy_action(page, "حذف المحادثة")
    page.get_by_text("تعذّر تأكيد حذف المحادثة، وما زالت الجلسة السابقة متاحة.", exact=True).wait_for()
    if page.evaluate("window.__ysaiCapturedDelete.length") != 1:
        fail("The lost-delete non-commit scenario did not issue exactly one delete request.")
    captured_boot = page.evaluate("window.__ysaiCapturedBoot")
    if len(captured_boot) != 2 or captured_boot[1] != {
        "conversation_id": CONVERSATION_ID,
        "token": TOKEN,
    }:
        fail("A non-committed delete was not reconciled through the original capability.")
    saved = page.evaluate("JSON.parse(localStorage.getItem('ysai.browser.contract'))")
    if saved.get("id") != CONVERSATION_ID or saved.get("token") != TOKEN:
        fail("A non-committed delete discarded a still-valid conversation capability.")
    if page.get_by_role("log").get_by_text("أريد منتجًا مناسبًا", exact=True).count() != 1:
        fail("A non-committed delete did not restore the authoritative transcript.")
    if page.locator("[data-ysai-error] button").count() != 1:
        fail("A non-committed delete did not offer an explicit deletion retry.")
    assert_no_page_errors(page_errors, "lost delete response without commit")
    context.close()

    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "delete_reconciliation_unavailable", original),
    )
    click_privacy_action(page, "حذف المحادثة")
    page.get_by_text(
        "فُقد تأكيد الحذف وتعذّر التحقق من حالة المحادثة. أعد الاتصال قبل أي إجراء آخر.",
        exact=True,
    ).wait_for()
    if page.evaluate("window.__ysaiCapturedDelete.length") != 1:
        fail("The unavailable delete-reconciliation scenario did not issue exactly one delete request.")
    if page.evaluate("window.__ysaiCapturedBoot.length") != 2:
        fail("The unavailable delete-reconciliation scenario did not probe the exact original capability once.")
    saved = page.evaluate("JSON.parse(localStorage.getItem('ysai.browser.contract'))")
    if saved.get("id") != CONVERSATION_ID or saved.get("token") != TOKEN:
        fail("Unresolved deletion did not preserve the original capability solely for later reconciliation.")
    if page.get_by_role("log").locator("[data-ysai-message]").count() != 0:
        fail("Unresolved deletion left the requested-to-delete transcript visible.")
    if not page.locator("[data-ysai-cart]").is_hidden():
        fail("Unresolved deletion left the requested-to-delete cart visible.")
    if page.evaluate("window.__ysaiCapturedChat.length") != 0:
        fail("Unresolved deletion sent chat work while capability state was unknown.")
    page.locator("[data-ysai-error] button").click()
    page.get_by_text("متصل", exact=True).wait_for()
    captured_boot = page.evaluate("window.__ysaiCapturedBoot")
    if len(captured_boot) != 3 or captured_boot[2] != {
        "conversation_id": CONVERSATION_ID,
        "token": TOKEN,
    }:
        fail("Retry after unresolved deletion did not reconcile the exact original capability.")
    if page.get_by_role("log").get_by_text("أريد منتجًا مناسبًا", exact=True).count() != 1:
        fail("Successful later reconciliation did not restore the authoritative original transcript.")
    assert_no_page_errors(page_errors, "unavailable deletion reconciliation")
    context.close()


def run_stale_chat_capability_reboots_cleanly(browser: Browser, base_html: str) -> None:
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "chat_unauthorized", boot_payload()),
    )
    original_message = "رسالة على جلسة منتهية"
    submit(page, original_message)
    page.get_by_text("انتهت جلسة المحادثة.", exact=True).wait_for()
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is None:
        fail("An unbound chat authorization failure discarded the exact pending turn before recovery.")
    saved = page.evaluate("JSON.parse(localStorage.getItem('ysai.browser.contract'))")
    if saved.get("id") != CONVERSATION_ID or saved.get("token") != TOKEN:
        fail("An unbound chat authorization failure discarded authority before exact recovery.")
    if page.evaluate("window.__ysaiCapturedRecover.length") != 0:
        fail("The browser recovered the turn without an explicit retry after an unbound chat failure.")

    # The retry probes the exact pending identity. Only the recovery endpoint's
    # identity-bound unverified disposition can retire the stale capability.
    page.locator("[data-ysai-error] button").click()
    page.get_by_text("انتهت جلسة المحادثة.", exact=True).wait_for()
    if page.evaluate("window.__ysaiCapturedRecover.length") != 1:
        fail("The stale-capability retry did not recover the exact original turn once.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail("An exact unverified recovery retained the stale pending turn.")
    if page.evaluate("localStorage.getItem('ysai.browser.contract')") is not None:
        fail("An exact unverified recovery retained dead browser authority.")
    if page.get_by_role("log").locator("[data-ysai-message]").count() != 0:
        fail("An exact unverified recovery retained the obsolete transcript.")

    # The new error action boots a clean conversation; it must never reuse the
    # old request as fresh provider or cart work.
    page.locator("[data-ysai-error] button").click()
    page.get_by_text("متصل", exact=True).wait_for()
    saved = page.evaluate("JSON.parse(localStorage.getItem('ysai.browser.contract'))")
    if saved.get("id") != NEW_CONVERSATION_ID or saved.get("token") != NEW_TOKEN:
        fail("Retry after stale-capability rejection did not adopt a fresh conversation capability.")
    submit(page, "رسالة بعد جلسة جديدة")
    page.get_by_role("log").get_by_text("تم استلام رسالتك بأمان.", exact=True).wait_for()
    captured = page.evaluate("window.__ysaiCapturedChat")
    if len(captured) != 2:
        fail("The stale-capability scenario did not issue exactly one rejected and one fresh chat request.")
    if captured[0].get("conversation_id") != CONVERSATION_ID:
        fail("The initial stale-capability request did not use the original conversation.")
    if captured[1].get("conversation_id") != NEW_CONVERSATION_ID or captured[1].get("token") != NEW_TOKEN:
        fail("The later chat request reused dead authority instead of the fresh capability.")
    assert_no_page_errors(page_errors, "stale chat capability")
    context.close()


def run_stale_export_capability_reboots_cleanly(browser: Browser, base_html: str) -> None:
    context, page, page_errors = open_widget(
        browser,
        scenario_html(base_html, "export_unauthorized", boot_payload()),
    )
    click_privacy_action(page, "تصدير المحادثة")
    page.get_by_text("انتهت جلسة المحادثة.", exact=True).wait_for()
    if page.evaluate("window.__ysaiCapturedExport.length") != 1:
        fail("The stale-export scenario did not issue exactly one export request.")
    if page.evaluate("localStorage.getItem('ysai.browser.contract')") is not None:
        fail("A conclusive export authorization failure retained dead browser authority.")
    if page.get_by_role("log").locator("[data-ysai-message]").count() != 0:
        fail("A conclusive export authorization failure retained the obsolete transcript.")
    page.locator("[data-ysai-error] button").click()
    page.get_by_text("متصل", exact=True).wait_for()
    saved = page.evaluate("JSON.parse(localStorage.getItem('ysai.browser.contract'))")
    if saved.get("id") != NEW_CONVERSATION_ID or saved.get("token") != NEW_TOKEN:
        fail("Retry after export authorization failure did not adopt a fresh conversation capability.")
    assert_no_page_errors(page_errors, "stale export capability")
    context.close()


def run_unverified_boot_fallback_unblocks_new_conversation(browser: Browser, base_html: str) -> None:
    pending = same_conversation_pending("طلب قديم تعذّر التحقق منه")
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "boot_failure_unverified",
            boot_payload(conversation_id=NEW_CONVERSATION_ID, token=NEW_TOKEN),
            preload_pending=pending,
        ),
        expected_status="غير متصل",
    )
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail("An exact unverified recovery denial left the widget trapped on unusable pending credentials.")
    if page.evaluate("window.__ysaiCapturedRecover.length") != 1:
        fail("The boot-failure unverified scenario did not inspect the exact pending turn once.")
    page.get_by_text("تعذّر التحقق من الطلب السابق.", exact=True).wait_for()
    page.locator("[data-ysai-error] button").click()
    page.get_by_text("متصل", exact=True).wait_for()
    captured_boot = page.evaluate("window.__ysaiCapturedBoot")
    if len(captured_boot) != 2 or captured_boot[1] != {}:
        fail("After an exact unverified result, retry did not boot a fresh conversation without stale credentials.")
    if page.evaluate("window.__ysaiCapturedChat.length") != 0:
        fail("Unverified boot fallback resent the old turn as new provider or cart work.")
    assert_no_page_errors(page_errors, "unverified boot fallback")
    context.close()


def run_reload_transcript_recovery(browser: Browser, base_html: str) -> None:
    pending = same_conversation_pending()
    message = pending["body"]["message"]
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "reload_pending",
            boot_payload(),
            preload_pending=pending,
        ),
    )
    page.get_by_text("تم استرداد النتيجة نفسها بأمان.", exact=True).wait_for()
    if page.get_by_role("log").get_by_text(message, exact=True).count() != 1:
        fail("A pending shopper message disappeared or was duplicated after reload recovery.")
    if page.evaluate("window.__ysaiCapturedChat.length") != 0:
        fail("Reload recovery resent chat work instead of recovering the exact turn.")
    if page.evaluate("window.__ysaiCapturedRecover.length") != 1:
        fail("Reload recovery did not use exactly one recovery request.")
    if page.evaluate(f"sessionStorage.getItem({json.dumps(PENDING_KEY)})") is not None:
        fail("Reload recovery left the exact pending turn behind.")
    assert_no_page_errors(page_errors, "reload transcript recovery")
    context.close()

    boot = boot_payload()
    boot["messages"].append({
        "id": 3,
        "turn_id": 3,
        "role": "user",
        "content": message,
        "created_at": "2026-08-14T12:00:02+00:00",
        "client_turn_id": pending["body"]["client_turn_id"],
    })
    context, page, page_errors = open_widget(
        browser,
        scenario_html(
            base_html,
            "reload_pending_durable_history",
            boot,
            preload_pending=pending,
        ),
    )
    page.get_by_text("تم استرداد النتيجة نفسها بأمان.", exact=True).wait_for()
    if page.get_by_role("log").get_by_text(message, exact=True).count() != 1:
        fail("Durable pending history was duplicated by optimistic reload rendering.")
    assert_no_page_errors(page_errors, "reload durable-history deduplication")
    context.close()


def main() -> int:
    php = shutil.which("php")
    chromium = os.environ.get("YSAI_CHROMIUM_BIN") or shutil.which("chromium") or shutil.which("google-chrome")
    if not php:
        print("php is required for the browser contract.", file=sys.stderr)
        return 2
    if not chromium:
        print("A Chromium executable is required for the browser contract.", file=sys.stderr)
        return 2

    base_html = browser_html(php)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            executable_path=chromium,
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage", "--disable-web-security"],
        )
        run_accessibility_and_success(browser, base_html)
        run_empty_state_quick_replies(browser, base_html)
        run_unread_feature_toggle(browser, base_html)
        run_accepted_failure(browser, base_html)
        run_provider_new_turn_retry(browser, base_html)
        run_delayed_rejected_rate_limit_retry(browser, base_html)
        run_ambiguous_recovery(browser, base_html, "unbound_422")
        run_ambiguous_recovery(browser, base_html, "malformed_success")
        run_cart_contracts(browser, base_html)
        run_network_status(browser, base_html)
        run_cross_conversation_recovery(browser, base_html)
        run_pending_recovery_when_boot_fails(browser, base_html)
        run_boot_failure_absence_seal_requires_explicit_new_turn(browser, base_html)
        run_booted_absence_seal_requires_explicit_new_turn(browser, base_html)
        run_boot_failure_not_found_requires_explicit_new_turn(browser, base_html)
        run_pending_not_found_after_boot_requires_explicit_new_turn(browser, base_html)
        run_replacement_boot_adoption_clears_reply(browser, base_html)
        run_lost_delete_response_reconciliation(browser, base_html)
        run_stale_chat_capability_reboots_cleanly(browser, base_html)
        run_stale_export_capability_reboots_cleanly(browser, base_html)
        run_unverified_boot_fallback_unblocks_new_conversation(browser, base_html)
        run_reload_transcript_recovery(browser, base_html)
        browser.close()

    print("Chromium end-to-end chat, recovery, cart, and accessibility contract: passed.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:  # noqa: BLE001 - command-line test boundary
        print(f"Browser contract failed: {error}", file=sys.stderr)
        raise SystemExit(1)
