#!/usr/bin/env python3
"""Fail-closed static contract for the direct-update Phase 1 shopping work."""
from __future__ import annotations

import hashlib
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(relative: str) -> str:
    path = ROOT / relative
    if not path.is_file():
        raise SystemExit(f"Missing Phase 1 contract file: {relative}")
    return path.read_text(encoding="utf-8")


def require(source: str, terms: tuple[str, ...], label: str) -> None:
    for term in terms:
        if term not in source:
            raise SystemExit(f"Missing {label} invariant: {term}")


tools = read("src/Application/Tool/ToolRegistry.php")
context = read("src/Application/Tool/ToolContext.php")
chat = read("src/Application/Chat/ChatService.php")
loop = read("src/Application/Chat/AgentLoop.php")
cart = read("src/Infrastructure/WooCommerce/WooCartGateway.php")
plan = read("src/Domain/Commerce/CartPlan.php")
settings = read("src/Infrastructure/WordPress/Settings.php")
widget = read("assets/js/widget.js")
native = read("assets/js/native-cart-sync.js")
php_catalog_tests = read("tests/Unit/PhaseOneCatalogTest.php")
php_cart_tests = read("tests/Unit/PhaseOneCartTest.php")
js_tests = read("tests/js/native-cart-sync.test.js")

require(tools, (
    "'continuation_ref'",
    "'browse'",
    "'exclude_product_refs'",
    "'price_low'",
    "$this->bestMatchRanker->rank",
    "$context->advanceCatalogContinuation",
), "catalog tool")
require(context, (
    "beginCatalogContinuation",
    "continuationTombstone",
    "activeCatalogContinuation",
    "setCartClarification",
    "clearCartClarification",
    "pendingCartClarification",
), "server-owned continuation/clarification")
require(plan, ("CartQuantityMode::PreserveSource", "preserve_source"), "quantity plan")
require(chat, ("_catalog_context", "_cart_clarification_context"), "cross-turn restoration")
require(loop, ("$context->activeCatalogContinuation()", "$context->catalogContextSnapshot()", "$context->cartClarificationSnapshot()"), "cross-turn model context")
require(cart, (
    "$this->lock->synchronized",
    "$resolved = $this->resolve($plan, $context, $cart);",
    "CartQuantityMode::PreserveSource",
    "$entry['quantity'] = $entry['source_quantity'];",
), "locked preserve-source execution")
if cart.index("$this->lock->synchronized") > cart.index("$resolved = $this->resolve($plan, $context, $cart);"):
    raise SystemExit("Cart replacement quantity can be resolved before the named lock.")
if chat.index("$context->restoreProducts") > chat.index("$context->restoreCartClarification"):
    raise SystemExit("Cart clarification is restored before its product authority.")
require(settings, ("'catalog_synonyms'", "CatalogSynonymMap", "CatalogTextNormalizer"), "merchant synonym settings")

revision = hashlib.sha256(native.encode("utf-8")).hexdigest()[:12]
expected_import = f"./native-cart-sync.js?ver=2.5.4.{revision}"
if expected_import not in widget:
    raise SystemExit("The native cart module cache revision does not match its content hash.")
require(widget, (
    "response.kind === 'cart_receipt'",
    "nativeCartSynchronizer.converge(response.receipt, response.cart)",
    "nativeCartSynchronizer?.destroy()",
), "verified-receipt widget convergence")
require(native, (
    "presentationKey",
    "wc_fragment_refresh",
    "update_checkout",
    "invalidateResolutionForStore",
    "readBoundedUtf8Html",
    "sanitizeImportedTree",
), "native cart presentation")
for forbidden in ("method: 'POST'", "/turn", "cart_apply", "cart_replace", "cart_clear"):
    if forbidden in native:
        raise SystemExit(f"Native presentation code contains a mutation path: {forbidden}")

for source, label, terms in (
    (php_catalog_tests, "catalog acceptance", ("one-use", "240", "best_match", "transliteration")),
    (php_cart_tests, "cart acceptance", ("preserve_source", "clarification", "45")),
    (js_tests, "native cart acceptance", ("same-origin", "sanit", "deduplicated", "method, 'GET'")),
):
    require(source.lower(), tuple(term.lower() for term in terms), label)

print("Phase 1 static contract: passed.")
