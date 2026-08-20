# Yassin Store AI Sales Agent

A clean-room rewrite of the Yassin storefront assistant for WordPress and WooCommerce. The plugin keeps the product behavior that matters to shoppers—Arabic-first product help, store information, controlled cart updates, conversation continuity, optional image input, and native checkout handoff—without carrying forward the previous implementation or architecture.

## What the plugin does

- Answers storefront questions in Arabic by default and follows the shopper's language when appropriate.
- Searches, filters, compares, ranks, and explains live WooCommerce products through bounded WooCommerce-native retrieval; unavailable prices remain distinct from legitimate free products.
- Resolves exact purchasable variations before proposing a cart addition.
- Reads published store pages and configured policy links.
- Reads the shopper's current WooCommerce cart.
- Applies verified add, quantity, increment, decrement, remove, replace, and clear actions.
- Returns a server-generated receipt after a successful cart mutation.
- Preserves conversation history and non-sensitive shopping preferences for a configurable period.
- Accepts an optional JPEG, PNG, or WebP image for the current turn without storing the image bytes.
- Exports or permanently deletes a conversation when the privacy controls are enabled.
- Presents the assistant as an accessible RTL messaging experience with grouped bubbles, presence, timestamps, typing feedback, unread navigation, quick replies, message actions, responsive product cards, and a full-screen mobile mode.
- Sends shoppers to the native WooCommerce checkout; it does not manage orders, payments, refunds, or checkout fields.

## Phase 1 shopping completion

The direct-update Phase 1 implementation adds bounded multilingual catalog recall, merchant synonym groups, deterministic best-match ranking, opaque one-use continuation for “show more,” typed cart clarification, quantity-preserving replacement, and verified-receipt-driven convergence of the assistant cart with classic WooCommerce and Blocks surfaces. These behaviors were implemented inside the rewrite’s existing boundaries; no legacy architecture or source structure was copied.

Catalog continuation and clarification are expiring server-owned context, not authority. Every cart write still requires exact evidence from the current shopper message, a fresh authoritative cart view, current product/cart validation, serialized execution, canonical durable verification, and a verified receipt. Native cart synchronization is presentation-only and cannot issue a cart mutation.

Merchant synonyms are configured in the assistant settings as one group per line, using `|`, `=`, an English comma, or an Arabic comma between terms. Input is canonicalized and bounded to 50 groups, 12 terms per group, 80 Unicode characters per term, and 12,000 Unicode characters overall. Invalid writes preserve the last valid canonical value.

## Requirements

- WordPress 7.0 or later.
- WooCommerce 11.0.1 or later.
- PHP 8.3 or later.
- MySQL or MariaDB with InnoDB, a valid WordPress character set/collation, and named-lock support for serialized cart mutations.
- PHP Sodium or OpenSSL for encrypted API-key storage.
- A Gemini API key with access to the configured model. The default model is `gemini-3.7-flash`.
- A single-site WordPress installation. Multisite activation is intentionally blocked in this release.

## Installation

1. Back up the WordPress database and `wp-content/plugins` directory.
2. Upload the live-gate-approved `yassin-ai-assistant-2.5.4-rebuilt.zip` from **Plugins → Add New → Upload Plugin**.
3. Activate **Yassin Store AI Sales Agent**.
4. Open **WooCommerce → AI Assistant**.
5. Save the Gemini API key, review the model and limits, then run **Test Gemini connection**.
6. Enable the widget and configure its live appearance preview, messaging features, product-card layout, retention, rate limits, and policy links.
7. When WordPress is behind a reverse proxy or CDN, configure the exact trusted proxy CIDRs and the one forwarding header that edge controls. Leave the setting empty for direct deployments.
8. Test product discovery, a variable product, a cart change, retry/recovery, checkout handoff, export, and deletion on staging before production rollout.

The plugin can also read a key from `YSAI_GEMINI_API_KEY` in `wp-config.php`. That constant takes precedence over the encrypted database setting.

## Troubleshooting AI chat failures

When every shopper message fails, open **WooCommerce → AI Assistant**, save the key and model, and run **Test Gemini connection**. In 2.5.4 that action performs two independent checks: strict structured output and the exact production function-tool bundle. A structured-output-only probe is insufficient because normal chat sends every declared tool and requires a terminal function call.

The provider boundary now distinguishes configuration, credentials, project access, geographic restriction, quota exhaustion, model availability, request-contract rejection, request size, temporary unavailability, protocol errors, and incomplete interactions. Shopper messages remain bounded and localized; provider details are represented only by safe categories and diagnostic fingerprints. Review WordPress logs with diagnostics enabled for the bounded category, then disable diagnostic logging after investigation.

Release 2.5.4 retains the versioned browser/server retry envelope introduced in 2.5.3. The current module sends `X-YSAI-Client-Contract: 2`; a stale 2.5.2 module receives the legacy exact error object rather than fields it cannot validate. Deploy the complete directory and purge page, object, opcode, CDN, service-worker, and browser caches so PHP and JavaScript do not remain split across releases. Provider `Retry-After` is never shortened: the server waits the complete interval only when another usable attempt fits the shared deadline, otherwise it finalizes a bounded delayed fresh-turn retry.

Cleanup remediation is isolated from shopper traffic. A missing `ysai_daily_cleanup` event is checked on every boot, but automatic registration attempts occur only during activation, privileged non-AJAX administration, WP-Cron, or WP-CLI. When WordPress cannot durably store the one-hour diagnostic throttle, the administration warning remains visible and the log entry is suppressed rather than repeated. Gemini rejection handling also scans bounded top-level and nested reason fields so an unknown or generic first detail cannot hide a later specific canonical Google reason.

The wire adapter emits JSON Schema object maps explicitly, forces function selection for production chat, and projects the plugin's stricter local schemas to the portable provider subset. Local validation still enforces omitted wire constraints such as string lengths and regular-expression patterns before a function executes. No-argument tools use `"properties":{}` rather than `"properties":[]`; prior transcript remains untrusted current-input data; model steps are replayed exactly for stateless continuation; provider call IDs remain opaque; and an explicit empty terminal `product_refs` list renders no cards instead of causing a protocol failure or reusing unrelated products.

## Widget experience and merchant customization

The storefront is designed to feel like a current shopper-to-agent messenger rather than a generic support form. It includes a branded launcher, agent avatar and availability, a conversation header, grouped shopper and assistant bubbles, day separators, optional timestamps, typing feedback, copy/reply actions, unread and latest-message navigation, quick prompts, image and reply previews, privacy actions, cart context, and product cards inside the conversation.

On phones the open assistant becomes a full-screen safe-area-aware experience. The same interface supports keyboard-only use, visible focus, screen-reader announcements, reduced motion, forced-color mode, and mixed Arabic/Latin text without changing the underlying idempotent send and recovery protocol.

**WooCommerce → AI Assistant → Widget appearance** provides a live desktop, phone, and compact preview. Merchants can configure:

- theme preset plus independent brand, strong-brand, panel, canvas, assistant-bubble, shopper-bubble, receipt, and border colors;
- launcher text, shape, side, mobile label, avatar treatment, presence, welcome text, subtitle, density, timestamps, copy/reply actions, unread controls, and three quick prompts;
- panel width and height, panel/bubble/card radii, base font size, product carousel/grid/list layout, image ratio, cards per view, descriptions, indicators, and product-name size, weight, and line limit.

Preset changes update only colors still inherited from the previous preset; deliberate merchant overrides are preserved. Saved values are normalized and contrast-safe text colors are derived server-side before storefront rendering.

## Clean-rewrite and legacy-data policy

This codebase does not reuse or reproduce the former architecture. It uses the previous system only as a source of product requirements.

The new runtime owns only:

- the `ysai_options` option;
- the `ysai_schema_version` option;
- the `wp_ysai_v2_conversations` table;
- the `wp_ysai_v2_messages` table;
- the `wp_ysai_v2_turns` table;
- the `wp_ysai_v2_rate_limits` table.

The actual WordPress table prefix may differ from `wp_`. Old plugin tables are not read, migrated, modified, or deleted. Previous conversations are not imported. A legacy plaintext API key stored in `ysai_options` is encrypted once during startup when secure cryptography is available; an unencrypted key is otherwise refused at runtime.

See [Migration and rollout](docs/MIGRATION.md) before replacing an earlier release.

## Security model

The AI model never writes directly to WooCommerce. Store facts come from server tools, product and cart references are opaque and short-lived, and every cart plan is validated again on the server.

A cart mutation requires all of the following in the same turn:

1. a fresh server-side cart read;
2. an exact evidence quote from the shopper's current message;
3. a separate schema-constrained intent decision;
4. valid product or cart-line fingerprints;
5. WooCommerce stock, quantity, and extension validation;
6. a serialized commit followed by a separate direct read of the durable WooCommerce session row;
7. a server-generated receipt.

Idempotent turns use generation-fenced database leases whose issued duration is stored with the turn. The server allows only one processing turn per conversation, renews ownership before provider and commerce boundaries, and never recomputes an old lease from changed administration settings. Exact completed, failed, or checkpointed turns remain replayable. A stale checkpoint is finalized without repeating provider or cart work; a stale uncheckpointed turn is durably abandoned and forces the shopper to inspect the cart before explicitly resending. A response is never reported as successful until its assistant message is durably addressable.

Conversation lifecycle is also database-owned. New turn claims, message writes, deletion, and retention cleanup all require an active conversation row, so deletion cannot race a fresh processing lease. A global daily conversation-creation quota supplements browser and turn limits.

Before a browser sends a turn, it must persist a small recovery record containing the exact conversation and client turn identity. Only one unresolved turn is allowed through the widget at a time. Browser timeout and server lease values come from one timing policy, with the browser stopping before lease expiry to leave a recovery margin. One exact retained turn can replay after the normal inactivity window without extending activity or reopening ordinary conversation operations. If recovery finds no turn, it atomically seals that exact ID as a durable pre-acceptance rejection under the same lock used by claims; a delayed original request therefore cannot execute after the browser was told it was not accepted. The browser never silently resends a sealed request: it restores text for deliberate submission under a new ID, while an image must be reattached because image bytes remain page-memory-only.

The browser treats generic HTTP errors as ambiguous. A pre-claim authorization failure preserves the original pending identity until the recovery endpoint binds an exact result. Response bodies are read through a byte limit, replacement capabilities clear reply/cart authority from older conversations, and lost deletion acknowledgements are reconciled before later chat work is allowed.

Gemini structured decisions are validated again locally. The adapter rejects malformed status/step envelopes, non-object function arguments, duplicate call IDs, unsupported schema keywords, wrong types, missing or extra fields, invalid bounds or patterns, and JSON object/array confusion. REST, provider, and persisted JSON boundaries also reject duplicate object member names—including escaped-equivalent names—rather than accepting PHP's last-value-wins behavior.

Conversation tokens are high-entropy capabilities. Only their hashes are stored in the database. Gemini requests use stateless interactions with `store: false`. API keys are sent in the provider header, not in the request body, chat history, browser configuration, or diagnostic logs.

Anonymous rate-limit identity uses `REMOTE_ADDR` by default. Forwarded client addresses are accepted only when the immediate peer matches an explicitly configured trusted-proxy CIDR and the selected `Forwarded` or `X-Forwarded-For` chain passes strict bounded parsing. Cart locks never use an IP address: mutation authority requires a logged-in user or a validated WooCommerce customer session.

See [Security](docs/SECURITY.md) for the threat model and deployment controls.

## Architecture

The implementation separates product rules from infrastructure:

- `src/Domain`: immutable cart, conversation, UUID, and encoding rules.
- `src/Application`: chat orchestration, prompts, tool execution, intent checks, and ports.
- `src/Infrastructure`: Gemini, WordPress database, WooCommerce, encryption, rate limiting, and content adapters.
- `src/Presentation`: REST endpoints, administration, and storefront delivery.
- `assets` and `templates`: accessible RTL widget and admin UI.

See [Architecture](docs/ARCHITECTURE.md) and [Product requirements](docs/PRODUCT_REQUIREMENTS.md).

## REST API

The widget uses JSON-only routes under `/wp-json/yassin-ai/v2/`:

- `GET health`
- `POST boot`
- `POST chat`
- `POST turn/recover`
- `POST conversation/export`
- `POST conversation/delete`

The write endpoints are public WordPress routes by necessity, but operations are guarded by same-origin browser checks, rate limits, bounded JSON validation, and conversation capability authentication where state already exists. See [API](docs/API.md).

## Testing

Run the complete local verification from the plugin root:

```bash
bash scripts/verify.sh
```

This always runs PHP behavior tests, browser-logic tests, the focused concurrency/recovery matrix, the cross-layer chat-flow/repository audit, static accessibility checks, stable-v1 fixture contracts, syntax/security scans, and catalog-boundary guards. Real Chromium chat/recovery, focused widget-runtime, and administration appearance-editor contracts also run automatically when Chromium and Python Playwright are available. Make it mandatory with:

```bash
YSAI_REQUIRE_BROWSER_E2E=1 bash scripts/verify.sh
```

Individual local suites:

```bash
php tests/run.php
node --test tests/js/*.test.js
bash scripts/verify-concurrency.sh
bash scripts/verify-accessibility.sh
bash scripts/verify-browser.sh
```

Run the stable Gemini v1 structured-output and function-calling contract against real credentials:

```bash
YSAI_GEMINI_API_KEY=... bash scripts/verify-gemini-v1.sh
```

Run the real WooCommerce/database/object-cache fault suite only against a disposable local, development, or staging installation with the plugin active:

```bash
YSAI_WP_PATH=/path/to/wordpress bash scripts/verify-integration.sh
```

Run the complete environment-dependent automated matrix, with all three live gates mandatory:

```bash
YSAI_WP_PATH=/path/to/wordpress \
YSAI_GEMINI_API_KEY=... \
bash scripts/verify-live-matrix.sh
```

Set `YSAI_REQUIRE_EXTERNAL_OBJECT_CACHE=1` as well when the acceptance environment must prove behavior with a persistent object-cache drop-in.

The included local tests cover domain validation, Unicode handling without `mbstring`, encrypted settings, current-image-only prompting, identity-bound browser error dispositions, durable pending-turn recovery, reload transcript deduplication, accepted/rejected failure semantics, strict duplicate-key JSON, stable Gemini v1 protocol, production function-tool wire encoding, exact raw-step replay, opaque function-call IDs, structured-output validation, a full two-turn provider chat transaction, REST boundary validation, public error redaction, tool-loop sequencing, guarded user/activity/memory ownership, internal-role filtering, historical-message canonicalization, replay allowlists, deep export validation, explicit product-reference handling, WooCommerce-native catalog paging, nullable-price semantics, cart projection and receipt integrity, cart authorization, separated session writes and canonical reads, database/cache disagreement, mutation journaling, durable rollback verification, trusted-proxy chains, immutable per-turn leases, one-processing-turn enforcement, lifecycle-safe deletion, stale-turn abandonment, checkpoint finalization, bounded history loading, repository corruption, complete database verification, atomic rate limits, real-browser serialization/focus behavior, mobile modality, Unicode composer limits, image-only drafts, media fallbacks, actual carousel visibility, and accessibility semantics. The opt-in integration suite exercises a real `WC_Session_Handler`, `WC_Cart`, WPDB session row, WordPress object-cache API, and SQL fault injection.

The local verifier does not prove a skipped credentialed or platform-dependent gate. Production-labelled packaging now fails closed unless the exact source passes the representative WooCommerce integration suite, the credentialed Gemini v1 contracts, and real Chromium. A release record must retain those outputs plus manual cross-browser/screen-reader acceptance. An environment-dependent gate reported as not requested is not a pass. See [Testing](docs/TESTING.md).

## Packaging

Create a production-labelled archive only from a representative disposable acceptance environment that provides WordPress/WooCommerce, the real database/session topology, a Gemini acceptance key, and Chromium:

```bash
YSAI_WP_PATH=/path/to/wordpress \
YSAI_GEMINI_API_KEY=... \
bash scripts/package.sh
```

The default `release` profile creates `yassin-ai-assistant-2.5.4-rebuilt.zip` only after the exact source passes the mandatory live matrix. Missing environment, credentials, or an executed gate is a hard failure and leaves no package.

For code review or staging preparation in an environment that cannot run the live matrix, create an explicitly labelled non-production candidate:

```bash
YSAI_PACKAGE_PROFILE=candidate bash scripts/package.sh
```

That creates `yassin-ai-assistant-2.5.4-candidate.zip`. A candidate passes the complete local verifier and exact-package checks, but it is not production-approved. Both profiles hash the source before and after verification, create a clean `yassin-ai-assistant/` directory, reject duplicate/unsafe/symlink ZIP entries, extract and reverify the exact archive, compare it byte-for-byte with the staged tree, and write a verified SHA-256 sidecar. A different output directory may be supplied through the first argument, but the basename is enforced: release archives must end in the exact `-rebuilt.zip` name and candidate archives in the exact `-candidate.zip` name. This prevents a locally verified candidate from being mislabeled as a production-approved release.

## Operational boundaries

- No order lookup, creation, cancellation, refund, or payment handling.
- No automatic coupon generation or discount promises.
- No checkout form completion.
- No multisite support in this release.
- No migration of previous conversation tables.
- No server-side storage of uploaded image bytes.
- No guarantee that third-party WooCommerce extensions support programmatic cart changes; incompatible rules fail closed and should be tested on staging.
- Named database locks and a verified `CartSessionPersistence` adapter are required for chat-driven cart writes. The bundled adapter supports only WooCommerce's exact built-in database session handler and verifies commits and rollbacks through a write that returns no proof followed by a separate direct read of the canonical session row. A custom handler must provide an explicit adapter through `ysai_cart_session_persistence`; otherwise catalog and cart viewing remain available while assistant mutations are disabled.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
