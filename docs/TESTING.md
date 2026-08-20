# Testing

## Automated verification

Run from the plugin root:

```bash
bash scripts/verify.sh
```

The verification script performs:

1. Release, package, browser-asset, database-schema, test-bootstrap, WordPress readme, and changelog version consistency.
2. PHP, JavaScript, shell, and Python syntax checks for production, test, integration, browser, and release files.
3. The complete PHP behavior suite and Node browser-logic suite.
4. A separately reported PHP concurrency/recovery matrix selected from the behavior suite.
5. Static accessibility checks against the actual template, widget code, and CSS.
6. Stable Gemini v1 fixture contracts for structured output and function calling.
7. A real Chromium widget and accessibility-tree contract whenever Chromium and Python Playwright are available; an executed failure always fails verification.
8. Stable `v1/interactions` endpoint/header guards and the live Gemini contract when credentials are supplied.
9. WooCommerce-native catalog guards that reject raw WordPress product-query/meta prefilters.
10. The real WooCommerce/database/object-cache fault suite when `YSAI_WP_PATH` is supplied, or a hard failure when it is required but unavailable.
11. Scans for credentials, debug calls, unsafe execution, unresolved production markers, legacy-table references, generated dependencies/caches, lock files, version-control artifacts, symbolic links, and empty directories.

Individual local suites:

```bash
php tests/run.php
node --test tests/js/*.test.js
bash scripts/verify-concurrency.sh
bash scripts/verify-accessibility.sh
bash scripts/verify-browser.sh
```

Require real Chromium during the complete verifier rather than allowing an unavailable-environment report:

```bash
YSAI_REQUIRE_BROWSER_E2E=1 bash scripts/verify.sh
```

## Phase 1 verification

The default verifier requires `scripts/verify-phase-one-contract.py` in addition to behavior tests. The contract checks that continuation, clarification, deterministic ranking, merchant synonyms, locked quantity preservation, verified-receipt widget wiring, content-hashed module loading, and presentation-only native synchronization remain present.

Phase 1 behavior coverage includes one-use/replayed/tampered/expired continuation, generation invalidation, full 240-ID traversal, explicit exclusion retention, cross-turn restoration, malformed projection retry safety, deterministic ranking ties, Arabic and transliteration recall, strict synonym bounds, clarification expiry/tombstones/sensitive-text rejection/product-window retention, `preserve_source` resolution under the lock, receipt deduplication, retry cancellation, Blocks/classic/checkout fan-out, foreign URL rejection, bounded UTF-8 HTML reads, and DOM sanitization.

These local gates do not replace the credentialed Gemini contract or the representative WordPress/WooCommerce/database/object-cache/browser matrix required for a production-labelled archive.

## Stable Gemini v1 contract suite

Production requests are pinned to `https://generativelanguage.googleapis.com/v1/interactions`, use `store: false`, and do not send a beta migration revision header. Local tests verify the production endpoint, request headers, request/response byte limits, strict step/status/function validation, and local structured-output validation.

Run the live stable-v1 contract against a disposable or acceptance API key:

```bash
YSAI_GEMINI_API_KEY=... \
YSAI_GEMINI_MODEL=gemini-3.7-flash \
bash scripts/verify-gemini-v1.sh
```

The live suite sends two bounded, stateless requests: one exact JSON structured-output contract and one exact function-call contract. It writes the key only to a mode-0600 temporary curl configuration, limits response size and duration, validates the stable `steps` response locally, and deletes temporary files on exit.

To make the live contract mandatory in the complete verifier:

```bash
YSAI_REQUIRE_GEMINI_V1_CONTRACT=1 \
YSAI_GEMINI_API_KEY=... \
bash scripts/verify.sh
```

A line saying `Gemini stable v1 live contract: not requested` is an explicit skip, not a pass.

To require WooCommerce integration, Gemini stable v1, and the real browser together, set `YSAI_WP_PATH` and `YSAI_GEMINI_API_KEY`, then run `bash scripts/verify-live-matrix.sh`.

## Browser and accessibility gates

`verify-accessibility.sh` is always part of local verification and statically inspects the actual template, widget code, and CSS for labelled dialog semantics, live regions, form labels, explicit button types, focus behavior, reduced motion, screen-reader text, and product-list roles.

`verify-browser.sh` renders the real PHP widget template, inlines the exact production CSS and JavaScript modules without changing application statements, and executes them in headless Chromium. It validates boot and chat rendering, explicit unavailable-price presentation, keyboard opening and Escape dismissal, focus placement/restoration, one durable chat request, and Chromium's accessibility tree. The harness uses only in-memory test storage and mocked same-page responses; it performs no storefront or provider network call.

`verify.sh` runs this contract automatically when Chromium and Python Playwright are available. A browser test assertion failure is never treated as a skip. Use `YSAI_REQUIRE_BROWSER_E2E=1` to fail when the browser runtime itself is unavailable.

A successful smoke contract is still not complete product accessibility evidence. Production acceptance must include manual keyboard, screen-reader, 200% zoom, RTL/LTR, reduced-motion, mobile, and supported-browser testing. An unavailable browser gate is not a pass.

## Complete live automated matrix

The environment-dependent release gates can be made mandatory together:

```bash
YSAI_WP_PATH=/path/to/wordpress \
YSAI_GEMINI_API_KEY=... \
bash scripts/verify-live-matrix.sh
```

This wrapper requires the disposable WordPress/WooCommerce integration environment, a live Gemini acceptance key, and the real Chromium contract, then delegates to `verify.sh`. Add `YSAI_REQUIRE_EXTERNAL_OBJECT_CACHE=1` when the acceptance topology must include a persistent object-cache drop-in. Manual extension, checkout, responsive, and assistive-technology acceptance remains separate.

## Production release packaging gate

`bash scripts/package.sh` uses the `release` profile by default. It requires `YSAI_WP_PATH` and `YSAI_GEMINI_API_KEY`, then runs `verify-live-matrix.sh` so WooCommerce/database/object-cache, Gemini stable-v1, and real Chromium acceptance are mandatory for the exact source being packaged. If any dependency is missing or any gate fails, packaging stops before creating an archive.

```bash
YSAI_WP_PATH=/path/to/wordpress \
YSAI_GEMINI_API_KEY=... \
bash scripts/package.sh
```

A locally verified archive is available only as an explicitly labelled candidate:

```bash
YSAI_PACKAGE_PROFILE=candidate bash scripts/package.sh
```

Candidate packaging is appropriate for code review and staging preparation, not production approval. `verify-release-gate.sh` proves the default release path fails closed without the live environment, rejects unsupported profiles, rejects a candidate written under a production-looking release filename, and verifies that failed release gating leaves no ZIP or checksum. The package basename is fixed by the selected profile even when the first argument selects another output directory. Both profiles hash the source before and after verification to detect changes during the gate, then reverify the exact extracted package and compare it byte-for-byte with the staged source.

## Real WooCommerce/database/object-cache fault suite

The unit and behavior suites cannot prove how the installed WooCommerce handler, WPDB driver, session table, and object-cache drop-in interact. The current release includes a destructive, opt-in WP-CLI integration harness. Run it only on a disposable local, development, or staging WordPress installation with WooCommerce and this plugin active:

```bash
YSAI_WP_PATH=/path/to/wordpress \
bash scripts/verify-integration.sh
```

To require the live suite as part of the complete verifier:

```bash
YSAI_REQUIRE_WOOCOMMERCE_INTEGRATION=1 \
YSAI_WP_PATH=/path/to/wordpress \
bash scripts/verify.sh
```

To require a persistent object-cache drop-in rather than the normal request-local WordPress cache API:

```bash
YSAI_REQUIRE_EXTERNAL_OBJECT_CACHE=1 \
YSAI_WP_PATH=/path/to/wordpress \
bash scripts/verify-integration.sh
```

Run the script from the exact active plugin directory being tested. The harness verifies both the active plugin version and its resolved filesystem root before touching data. It refuses a production environment, creates isolated WooCommerce session rows and one temporary product, and removes them after each case. It uses the exact built-in `WC_Session_Handler`, a real `WC_Cart`, the real `<prefix>woocommerce_sessions` table, actual WordPress object-cache functions, and WPDB query interception. It verifies:

- a canonical adapter read ignores a divergent cache value;
- a database write error is rejected and the uncertain cache entry is invalidated;
- a write replaced with a successful SQL no-op is exposed by a separate canonical read;
- a mutation that commits before a lost response, followed by a silently dropped rollback write, is classified as `CartStateUncertain` even though the request-local cart was reconstructed.

The harness currently targets only the exact built-in database session handler. A custom session adapter needs an equivalent integration suite against its own canonical store.

## Automated coverage

The included tests cover:

- UUID, base64url, text, sensitive-data, image, URL, secret, and settings boundaries.
- Strict JSON grammar, duplicate plain/escaped keys, object/array identity, structural budgets, Unicode, and non-finite numbers.
- Gemini stable-v1 endpoint/header rules, request/response byte limits, HTTP error semantics, interaction status, explicit JSON-object function schemas, production-tool readiness, declared function names, local argument-schema validation, raw stateless-step replay, opaque call IDs, structured schemas, local result validation, full `Retry-After` handling, strict shared-deadline exhaustion, and canonical error-code precedence.
- Agent terminal-call discipline, exact JSON-native tool-step continuation, empty argument-object preservation, a full two-turn production-provider chat transaction, fixed model-visible invalid-argument remediation, internal exception-detail redaction, and tool budgets.
- Fresh-cart, exact-evidence, independent-consent, product-reference, and one-mutation rules.
- WooCommerce-native product-data-store search, paginated browse/alternatives, 240-candidate scan ceilings, category resolution, live extension-filtered catalog state, nullable price semantics, ranking rules, projection bounds, variation resolution, direct durable-session reads, nested session decoding, quantity validation, journals, receipts, rollback, replay, cache/database disagreement, and uncertain-state handling.
- Conversation authentication, lifecycle gating, active-only writes, deletion-busy behavior, cleanup, stable export, and bounded history suffix reads.
- One-processing-turn enforcement, immutable persisted leases, heartbeats, generation fencing, stale-owner rejection, exact terminal replay, stale checkpoint finalization, uncheckpointed abandonment, and direct SQL-adapter predicates.
- Durable success/failure contracts, assistant-message reconciliation, finalization pending, persistence uncertainty, and request-ID conflict.
- Atomic rate limits and daily conversation creation limits.
- Browser cryptographic IDs, Unicode limits, durable pending records without image bytes, one-unresolved-turn gating, timing-policy bounds, strict boot/delete responses, export validation, safe URLs, serialized operations, old/new REST contract compatibility, and delayed retry-action visibility.

The PHP behavior run uses fakes and focused SQL-adapter doubles. The local verifier also runs static accessibility, stable-v1 fixtures, and real Chromium when available, but it does not replace the opt-in live WooCommerce suite, an extension matrix, the credentialed Gemini contract, manual assistive-technology testing, or cross-browser acceptance. A line ending in `not requested` is an explicit skip, not evidence of a pass.

## Required staging acceptance

Use a representative staging clone with the exact production database engine, theme, WooCommerce extensions, proxy/CDN, and PHP minor version.

### Installation and administration

- Activate on PHP 8.3+ with WordPress 7.0+ and WooCommerce 11.0.1+.
- Verify activation fails clearly when requirements are absent.
- Upgrade an existing 2.4.4 installation and verify schema version 2.5.4 is published only after the unchanged table contract is reverified. Also test the original 2.3.x migration for `lifecycle_state` and `lease_seconds`.
- On a disposable clone, drift one required column, index, engine, charset, or collation and confirm activation refuses to publish the schema version.
- Save, clear, and rotate the Gemini key.
- Run **Test Gemini connection** and verify both `structured_output_ready` and `chat_tools_ready`; the second phase must use the exact production tool registry and force `respond_safe_failure`.
- Deliberately change one no-argument tool schema to serialize `properties` as an array, switch production chat to permissive automatic tool choice, or bypass `GeminiSchemaProjector` in a disposable copy; confirm local verification or readiness fails before storefront rollout.
- Send a greeting and a policy-only question whose terminal functions use `product_refs: []`; confirm both complete without product cards and without falling back to an earlier shortlist.
- Return a valid REST interaction with `output_text: null` and text in a `model_output` step; confirm the text is reconstructed. Return direct prose while production tools are present and confirm the accepted failure is durable, replayable, and does not repeat the provider call.
- Change WordPress salts on a disposable clone and confirm the key must be re-entered.
- Configure and exercise daily conversation and AI-turn limits.
- For a direct deployment, leave trusted proxy CIDRs empty and confirm supplied forwarding headers do not change rate-limit identity.
- For a proxy/CDN deployment, configure the exact edge CIDRs and selected header, verify the active administration diagnostic, test multi-hop IPv4/IPv6 chains, and confirm untrusted peers cannot spoof a different identity.
- Confirm cart mutation locking uses only the logged-in user or WooCommerce customer session and never merges unrelated shoppers because they share a proxy address.
- Run readiness, cleanup, explicit conversation deletion, and delete-all.
- Confirm scheduled cleanup is registered once and a real cron runner executes it when WP-Cron is disabled.
- Remove the cleanup event and reject writes to the three cleanup operational options. Confirm repeated storefront requests perform no scheduling attempt and emit no cleanup diagnostic; a privileged non-AJAX administration, WP-Cron, or WP-CLI request may attempt remediation, while the visible administration warning remains.
- Return a Gemini error whose first structured reason is unknown or generic and whose later sibling or nested ErrorInfo reason is canonical. Confirm the later specific reason controls the public category, while the first specific canonical reason remains authoritative when multiple specific reasons conflict.

### Catalog

- Simple, variable, sale, out-of-stock, backordered, sold-individually, hidden, draft, external, grouped, downloadable, virtual, and SKU products.
- Global and custom attributes, translated labels, non-Latin terms, wildcard variations, and exact variation uniqueness.
- Products with missing images, unavailable/nonnumeric prices, legitimate zero prices, ratings, descriptions, or pathological merchant metadata. Confirm unavailable prices are `null`, display an explicit fallback, sort after known prices in both directions, and never enter value scoring.
- Dynamic-pricing, currency, membership, stock, visibility, multilingual, and search extensions where live product objects disagree with raw post metadata. Confirm the live object is authoritative.
- Catalogs large enough to require multiple 24-product pages and hit the 240-candidate ceiling. Verify `results_truncated`, `scan_exhausted`, `scanned_candidates`, and `scan_limit` truthfully distinguish exhaustion from a bounded result.
- Category searches by ID, slug, translated name, missing category, and category-plus-search combinations.
- Search relevance ordering from WooCommerce's product data store, including title, description, excerpt, and SKU matches supported by the installed data store.

### Cart

- Guest and authenticated sessions.
- Add to empty and non-empty carts, including an existing identical product that merges into one line.
- Set, increment, decrement, remove, replace, clear, and bounded batches.
- Minimum/maximum quantities, sold-individually rules, stock changes, and variation changes between recommendation and execution.
- Cart changes in another tab after `cart_view`.
- Extensions using `woocommerce_add_to_cart_validation` and `woocommerce_update_cart_validation`.
- Coupons, fees, taxes, shipping packages, custom cart-item data, bundles, subscriptions, product add-ons, and currency/price extensions used by the store.
- Failed command mid-batch followed by rollback verified from a separate direct durable read. Confirm the journal transitions from `started` to terminal `rolled_back`, exact replay returns a bounded failure without another cart execution, and twenty verified rollbacks do not exhaust unresolved capacity.
- Force the rollback proof or terminal-journal write to become ambiguous. Confirm only the uncertain case remains `started` and later mutations fail closed rather than treating it as evictable history.
- Default `WC_Session_Handler` with WordPress object cache enabled; confirm the post-write result is read from the database row rather than the cache.
- Inject a database write failure while allowing the session cache to receive the new value; confirm the operation is rejected and the cache entry is invalidated.
- Simulate a write that reaches the database but loses its response, followed by a rollback write that is dropped; confirm the result is `CartStateUncertain` even though the request-local PHP cart was restored.
- Silently drop the mutation write while leaving the old durable row intact; confirm the mismatch is detected and ordinary failure is returned only after a durable rollback read proves the original state.
- Verify nested serialized `cart`, `applied_coupons`, and `ysai_v2_cart_receipts` values are decoded exactly from the canonical session row.
- Database named-lock contention and a host where named locks are unavailable.
- A custom session handler without a reviewed `CartSessionPersistence` adapter; confirm viewing works and mutation remains disabled.
- A custom handler with an explicit adapter; run the same database/cache, lost-response, rollback, expiry, and malformed-row fault matrix against its canonical store.

### Idempotency, lifecycle, and recovery

Interrupt responses:

- before the request reaches WordPress;
- during each Gemini call, including transient transport, 408/409/425/429, and 5xx sequences; verify at most three attempts share one deadline, a long `Retry-After` causes no early request, a fitting interval is honored exactly, an insufficient remaining budget starts no final one-second attempt, and an exhausted accepted turn exposes `new_turn` rather than same-turn recovery;
- during independent intent authorization;
- after the cart operation-start journal but before execution;
- after a successful cart commit but before its completed receipt journal;
- after the turn checkpoint but before assistant-message finalization;
- during recovery and conversation deletion.

Confirm:

- the original client turn ID is retained;
- a duplicate exact request replays and does not repeat provider/cart work;
- only one processing turn exists per conversation;
- a different live turn returns `conversation_busy`;
- exact terminal replay still works while another turn is active;
- exact completed, accepted-failure, and rejected turns replay after the inactivity window without changing conversation activity;
- an expired checkpoint finalizes without repeating work;
- an expired uncheckpointed turn becomes `turn_abandoned`;
- a new request encountering that blocker returns `previous_turn_abandoned` and is not executed;
- deletion and cleanup refuse a fresh turn and cannot race new claims or messages;
- changing timeout/tool-round settings does not alter existing turns' persisted lease duration;
- the browser stops before lease expiry and recovers rather than creating a new ID;
- every public success contains a durable `message_id` and `turn_finalized: true`;
- a generic or intermediary 4xx/5xx response cannot clear the pending record unless its exact identity-bound disposition validates;
- an unbound chat authorization failure preserves the pending turn until exact recovery returns `unverified`, then retires the dead capability without resending the old request under a new ID;
- exact unauthorized export/deletion responses clear stale local authority, while a lost deletion acknowledgement triggers capability reconciliation and hides the requested-to-delete transcript/cart until the outcome is known;
- a missing recovery ID is durably sealed under the claim lock, clears the pending record only after the exact finalized rejection validates, never automatically resends the sealed ID, restores text for deliberate submission under a new ID, and requires image reattachment because browser storage contains no image bytes;
- both absence races are exercised: a delayed real claim wins before the seal and recovery returns it, or the seal wins and the delayed chat conflicts without executing;
- a stale worker cannot append a user message, extend activity, or update shopping memory after losing its generation;
- an accepted failed turn remains pending until its assistant failure message is durable;
- reload renders a pending shopper message exactly once and deduplicates it when durable history contains the same `client_turn_id`;
- a finalized transient provider or accepted/rejected rate-limit failure clears the old pending identity, withholds the action until any advertised delay expires, then waits for explicit shopper action, creates one fresh cryptographic turn ID, preserves the message, and never calls same-turn recovery;
- current clients send `X-YSAI-Client-Contract: 2` and accept optional retry fields, while a stale 2.5.2 client receives its legacy exact error object from the new server and a new client still accepts an old server response without those fields;
- a non-finalized error is normalized to `same_turn` even if a malformed source claims `new_turn`;
- cleanup scheduling failure leaves REST, administration, and widget registration available while surfacing a degraded warning, skips repeated scheduling and logging during the backoff window, retries after expiry, and clears operational state after success or uninstall;
- a failed or exceptional `RELEASE_LOCK` result is logged without replacing the protected operation's return value or exception.

Repeat with `sessionStorage` unavailable or full. The request must fail before transmission. For image turns, reload before the request reaches WordPress and confirm bytes are not persisted or silently omitted; reattachment is required after conclusive resolution.

### History, export, and corruption boundaries

- Create enough large message payloads to exceed the 8 MiB history source budget and confirm boot returns only a stable newest suffix.
- Append a message between the history metadata and payload read using a controlled test harness and confirm drift fails closed.
- Export across multiple pages while new messages are appended and confirm the upper boundary remains stable.
- Verify export rejects cursor regressions, duplicates, excess aggregate size, and malformed stored JSON.
- On a disposable clone, corrupt lifecycle, lease, status, date, JSON, or message byte metadata and confirm reads fail closed without coercion.
- Insert internal/system messages and confirm they are absent from boot, prompt history, and export.
- Corrupt historical assistant kinds, receipts, carts, and product payloads; confirm unknown kinds are inert and failed/uncertain history asserts no commerce state.
- Inject unknown fields into stored success and failed-turn responses; confirm replay returns only the documented public allowlist.
- Change shopping memory or export boundaries between pages and confirm the browser aborts the export rather than merging inconsistent pages.

### Security and privacy

- Cross-origin request rejection and same-origin proxy behavior.
- Invalid, expired, guessed, and mismatched capabilities.
- Duplicate JSON keys including escaped equivalents.
- Very long strings, 4000/4001-code-point boundaries, malformed JSON/base64, MIME mismatches, oversized, high-dimension, and over-12-million-pixel images.
- Prompt injection inside product names, descriptions, pages, merchant guidance, and images.
- Model attempts to mix terminal/cart calls, duplicate call IDs, use array arguments, mismatch interaction status, exceed budgets, or violate local structured schemas.
- Model attempts to mutate without a fresh cart view or exact current-message evidence.
- HTML/script strings in every displayed field.
- Export contents and permanent deletion.
- Diagnostic logs for absence of key, capability, raw image, request body, and merchant/customer content.
- Public `GET health` returns exactly liveness and does not reveal plugin version, provider configuration, schema, WooCommerce readiness, or proxy posture.
- Trigger an uncategorized internal `InvalidArgumentException` and confirm its technical message is logged only when diagnostics are enabled and is never returned to the shopper.
- Run the real stable Gemini v1 structured-output and function-calling contract; confirm no `Api-Revision` header or beta endpoint is used.
- Content Security Policy and third-party script/image inventory.

### Accessibility and responsive UI

- Keyboard open, close, send, reply, copy, attach, export, and delete.
- Focus return, usable focus order, and screen-reader announcement of new messages/errors.
- RTL and LTR messages, Arabic, long product names, 200% zoom, reduced motion, mobile viewport, and narrow desktop sidebars.
- Malformed boot/delete responses injected through a proxy; confirm local credentials and history remain unchanged.

## Release evidence

Record the exact WordPress, WooCommerce, PHP, database, theme, extension, browser, and Gemini model versions used for acceptance. Retain:

- complete `scripts/verify.sh` output;
- separate `scripts/verify-integration.sh` output from the representative WordPress/WooCommerce/database/object-cache environment, including all four fault cases;
- separate `scripts/verify-gemini-v1.sh` output with the accepted stable model;
- separate real-browser smoke and manual accessibility/browser matrix results;
- the separately reported concurrency/recovery and static accessibility outputs;
- ZIP SHA-256;
- fresh-extraction verification output;
- source-versus-extracted recursive comparison;
- staging matrix results and any accepted extension-specific limitations.

## Storefront and appearance-editor contracts

The default verifier runs `scripts/verify-widget-ui-contract.py` before and after the behavioral suites. It fails when a setting is exposed without a corresponding storefront projection or administration control, when the messaging template loses required semantics, or when the browser regression inventory no longer covers the critical appearance defects.

When Chromium and Python Playwright are available, `scripts/verify-browser.sh` renders the exact production storefront template/CSS/module and the exact administration page/CSS/module. Each browser contract has a fail-closed 300-second default ceiling, configurable from 30 through 900 seconds with `YSAI_BROWSER_TEST_TIMEOUT_SECONDS`. The storefront contract covers dialog and log semantics, focus return, privacy controls, quick replies, typing state, grouped delivery, unread behavior while minimized, product cards, retries, recovery, cart responses, export, and deletion. The administration contract covers live text, inherited preset colors, preserved custom color overrides, launcher/avatar/density/position controls, hidden timestamps/actions/unread affordances, product layout and image ratio, phone mode, cards per view, and dimensional tokens.

For release acceptance, manually repeat the visual flow in the store's supported desktop and mobile browsers with its theme, cache stack, translation plugins, checkout extensions, zoom at 200%, keyboard-only navigation, one screen reader, reduced motion, and forced-color or high-contrast mode. Automated screenshots are regression evidence, not a substitute for representative merchant and shopper acceptance.

