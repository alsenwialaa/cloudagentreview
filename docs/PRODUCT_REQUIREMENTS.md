# Product requirements

## Purpose

Give WooCommerce shoppers a trustworthy conversational route to discover products, understand the store, adjust the current cart, and continue to native checkout. Arabic is the default experience, but the assistant should follow the shopper's language where practical.

The rewrite preserves necessary product behavior. Legacy architecture, implementation, source layout, and technical choices are not product requirements.

## Required shopper workflows

### Messaging experience

The widget must read as a conversation between a shopper and a store agent, not as a generic form. It provides a recognizable launcher, agent identity and availability, grouped role-specific bubbles, optional timestamps and day separators, typing feedback, quick prompts, copy and reply actions, unread/latest-message navigation, reply and image previews, privacy actions, cart context, and product cards bound to the assistant message that displayed them.

The open widget is responsive and becomes a safe-area-aware full-screen experience on narrow phones. It preserves the same idempotent request identity when minimized, reopened, timed out, reloaded, or recovered. Visual presentation must not create new cart or product authority. Keyboard, screen-reader, reduced-motion, forced-color/high-contrast, 200% zoom, RTL, and mixed-direction content remain supported.

### Product discovery

The shopper can describe a need, budget, category, attributes, or use case. The system searches the live catalog through bounded WooCommerce-native retrieval and returns a concise shortlist with current names, price availability, stock state, product links, images, ratings, and relevant trade-offs. Concrete product claims come from live catalog tools. An unavailable numeric price is represented explicitly and must never be presented or ranked as a free product; a legitimate zero price remains available. When a candidate ceiling or requested result limit prevents source exhaustion, the assistant must describe the result as a bounded shortlist rather than an exhaustive catalog answer.

The discovery workflow supports explicit queryless browsing, relevance/price/newest/best-selling/rating sorting, explicit product exclusions, and a one-use opaque continuation reference for non-repeating “show more” turns. Continuation expires after 30 minutes, is invalidated by a new traversal, and stops at a combined 240-product seen/excluded budget. Arabic, English, and bounded transliteration variants may be combined with merchant-managed synonym groups, but all returned product facts remain live WooCommerce projections.

### Product detail, comparison, and ranking

The shopper can ask about one product, compare two to five options, rank a known set, request related items or alternatives, browse categories, or locate a SKU. Ranking explains the criterion rather than presenting unsupported certainty.

### Variable products

The system must not add a variable parent product. It asks a focused question when required attributes are missing, resolves one exact published and purchasable variation, and uses that variation for cart operations. Ambiguous, duplicate, incomplete, or unverifiable choices fail closed.

### Store information

The shopper can ask for published page or post content and configured same-origin links for contact, about, account, shipping, returns, and terms. The system does not invent policies.

### Cart visibility

The assistant can show current cart lines, quantities, totals, mutation availability, and the native checkout URL. Cart-line references are temporary opaque values generated from the current verified cart view.

### Cart changes

Supported actions are add, set quantity, increment, decrement, remove, replace, and clear. A batch may contain one to twelve independent commands. Clear must be the only command. The same source or destination cannot be changed twice in one plan.

Cart changes execute only when the shopper's current message clearly authorizes the exact action. Product-card clicks, assistant text, prior turns, quoted reply context, and inferred preferences are not authorization.

A write requires a fresh logical cart view, a cache-bypassing canonical session read that matches the authorized cart snapshot, exact current-message evidence, a separate structured authorization decision, current product/cart fingerprints, WooCommerce quantity and stock validation, serialized execution, direct durable post-state verification, and a replay receipt. Reconstructing the request-local PHP cart object or reading an object-cache entry is never sufficient proof that a commit or rollback reached the durable session store.

After a successful change, the response contains a server-generated receipt and fresh cart projection. A model-written claim is never proof of mutation. An ambiguous journal, rollback, or durable-state result instructs the shopper to inspect the cart and is never automatically retried.

When a cart request is incomplete, the server may retain one typed clarification record for 30 minutes. It contains only the unresolved action, bounded missing fields, optional opaque product authority, and quantity mode. It is not authorization and must be cleared on terminal completion, failure, uncertainty, expiry, or loss of its product authority. Replacement may preserve the source line quantity only when that quantity is read from the fresh canonical line while the cart lock is held.

After a verified receipt reaches the widget, presentation code should converge classic fragments, mini-cart, cart page, checkout, and WooCommerce Blocks with bounded retries. That convergence performs no cart mutation and must not be treated as proof of the write.

### Checkout handoff

The assistant may show only the native same-origin WooCommerce checkout URL returned by the cart tool. It does not fill checkout fields, collect payment data, place orders, or manage payments, refunds, or order state.

### Conversation continuity and recovery

The browser can resume a valid conversation with a conversation ID and capability token. History and non-sensitive shopping preferences are retained for the configured period. Messages are limited to 4000 Unicode code points. A cryptographically secure client turn ID supports deterministic retry and recovery.

Before transmission, the widget durably stores a bounded recovery record for the exact turn. Only one unresolved turn may proceed through the widget at a time; later messages and conversation deletion remain blocked until recovery is conclusive.

If a response is lost, the exact retained turn remains recoverable after the ordinary inactivity window without extending that window or reopening general history, new chat, export, or deletion. Generic HTTP failures never authorize a replacement turn. The browser must preserve the original identity until an exact finalized, rejected, conflict, not-found, or unverified result is validated. When the exact turn is absent, recovery must atomically reserve that ID as a durable pre-acceptance rejection under the same lock used by claims. The browser must not automatically resend a sealed request; it may restore text for deliberate submission under a new ID, while a lost image request requires reattachment.

The server also enforces one processing turn per conversation. Each turn stores its issued lease duration and claim generation. Ownership is renewed before provider, tool, authorization, and cart-write boundaries. Exact terminal/checkpointed turns remain replayable. A stale checkpoint is finalized without repeating work. A stale uncheckpointed turn is durably abandoned; the shopper must inspect the cart before explicitly resending.

A public success is complete only when the assistant message is durably addressable by a positive message ID. Persistence or finalization ambiguity preserves the original turn identity.

### Images

The shopper may attach one JPEG, PNG, or WebP image up to 4 MiB, 4096 pixels per side, and 12 million total pixels. The image is available only to the current model interaction. History and browser recovery storage record no image bytes. If a page reload loses an image request that never reached the server, the shopper must reattach it rather than silently resend a changed request.

### Privacy controls

When enabled, the shopper can export the authenticated conversation as JSON or permanently delete the conversation and shopping memory. Deletion must fail while a fresh turn is active and must not race new claims or writes. Administrators can purge expired conversations or delete all data owned by the rewrite, subject to the same lifecycle safety.

## Administrative requirements

- Enable or disable the assistant and widget independently.
- Configure Gemini key, model, thinking level, output limit, timeout, and tool-round limit.
- Configure image support, merchant guidance, identity and launcher behavior, position, presence, welcome text, message density, timestamps, copy/reply actions, unread navigation, quick prompts, theme presets, independent appearance colors, panel and bubble dimensions, typography, product carousel/grid/list layout, image ratio, cards per view, descriptions, indicators, and optional cart summary through a live desktop/phone/compact preview.
- Configure retention, session inactivity, browser/conversation turn limits, global daily conversation creation, global daily AI turns, logging, and uninstall deletion.
- Configure same-origin store links.
- Configure bounded merchant-managed catalog synonym groups.
- Test provider readiness, run cleanup, inspect counts, and explicitly delete rewrite-owned conversation data.
- Require WooCommerce's exact built-in database session handler or a reviewed `CartSessionPersistence` adapter whose write returns no proof and whose separate canonical read provides cache-bypassing durability verification. Unsupported session handlers remain read-only for assistant-driven cart changes rather than weakening commit or rollback checks.

## Non-functional requirements

- Clean application/domain/infrastructure/presentation boundaries.
- Strict types and bounded inputs at every external and persistence boundary.
- Duplicate-key-safe JSON parsing.
- Authenticated secret storage and redacted logging.
- Durable idempotency and explicit uncertainty semantics.
- Database-backed lifecycle and lease ownership, not browser-only coordination.
- Accessible RTL-first browser UI with safe DOM construction.
- Automated behavior and adapter-level regression tests.
- Complete architecture, API, security, migration, operations, testing, and release documentation.
- Direct file updates without version-control artifacts in the package.

## Explicit non-goals

- Reproducing any previous code structure or implementation decision.
- Migrating previous conversations.
- Order lookup or management.
- Payment, refund, credential, or checkout-field handling.
- Discount or coupon creation.
- Cross-origin policy or checkout links.
- Autonomous actions without current-turn shopper authorization.
- Multisite operation in this release.
