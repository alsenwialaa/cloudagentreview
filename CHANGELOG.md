# Changelog

## 2.5.4 — 2026-08-17

### Direct-update Phase 1 shopping completion

- Added bounded Arabic, English, and Arabizi/transliteration catalog recall with strictly validated merchant synonym groups while keeping WooCommerce authoritative for product facts.
- Added one-use, expiring, server-owned catalog continuation with deterministic sorting, explicit exclusions, queryless browsing, non-repeating traversal, and a hard 240-product authority budget.
- Implemented deterministic shopper-grounded `best_match` ranking, typed expiring cart clarification, and quantity-preserving replacement resolved from the fresh source line under the named cart lock.
- Added verified-receipt-only convergence for classic mini-cart/cart/checkout and WooCommerce Blocks. The browser module performs presentation refreshes only, uses bounded retries and sanitized same-origin HTML, and contains no mutation endpoint.
- Added Phase 1 adversarial behavior tests and a fail-closed static release contract. The plugin and schema versions remain 2.5.4 because this work updates the selected rewrite directly and changes no database table.

### Phase 1 edge hardening

- Removed ordinary storefront requests from automatic WP-Cron remediation. Missing privacy cleanup remains visible as a degraded administration state, while scheduling attempts are limited to activation, privileged non-AJAX administration, WP-Cron, and WP-CLI contexts.
- Made cleanup diagnostics fail closed when their one-hour option-backed throttle cannot be durably stored. The administration warning remains available, but an option-write outage cannot turn every eligible request into an unbounded log entry; the storefront cleanup-status path also stays read-only when cron inspection throws.
- Reworked Gemini rejection classification to collect a bounded, deduplicated set of top-level and nested structured reasons. Unknown and generic status-like entries no longer conceal a later specific Google ErrorInfo reason, while provider order remains authoritative within the same specificity tier.
- Added regressions for failed cleanup-option writes, storefront read-only behavior, remediation-context gating, nested and conflicting provider reasons, breadth-first sibling scanning, and package-level static enforcement of both corrected boundaries.

## 2.5.3 — 2026-08-16

### Phase 1 corrective release

- Bumped the plugin, schema marker, package metadata, module query string, tests, audits, and archive identity to `2.5.3`. Current browsers opt into error contract 2, while stale `2.5.2` clients receive the legacy exact error object during a rolling deployment.
- Corrected Gemini retry timing: a complete provider `Retry-After` delay is honored only when it and a usable next attempt fit the shared request deadline; otherwise the original transient result is returned without retrying early. Fractional attempt timeouts and a finalization reserve prevent late attempts from overrunning the turn budget.
- Made structured provider reason and status codes authoritative over generic message text, preventing permission, request-contract, credential, quota, model, and location failures from being mislabeled by incidental words.
- Throttled failed cleanup-scheduling remediation and diagnostics to one bounded retry window while preserving REST, administration, and storefront availability. Operational scheduling options are removed during uninstall independently of merchant-data retention.
- Aligned finalized rate-limit failures with delayed fresh-turn retry semantics, including bounded `retry_after_seconds`, the HTTP `Retry-After` header, and a browser action that appears only after the delay expires.
- Added regressions for long and fitting `Retry-After` windows, strict deadline exhaustion, canonical provider precedence, cleanup retry/log throttling, current/legacy REST contract compatibility, durable retry-delay replay, and delayed browser retry validation.

## 2.5.2 — 2026-08-16

### Phase 1 runtime hardening

- Made a durably verified cart rollback terminal: the operation journal now records an evictable `rolled_back` entry, replays the same operation as a bounded failure without executing WooCommerce again, and keeps only genuinely ambiguous outcomes in `started`.
- Added bounded Gemini transport retries for network failures, 408/409/425/429, and 5xx responses. All attempts share one configured request deadline, honor a capped `Retry-After`, and use jittered backoff.
- Replaced the ambiguous retry boolean with an explicit `retry_mode`: non-finalized persistence uncertainty recovers the same turn, while a finalized transient provider failure requires deliberate submission under a fresh turn identity.
- Kept the plugin runtime available when daily cleanup scheduling fails. Schema failure remains fatal; WP-Cron registration failure is now a logged administration warning and is retried on later boots.
- Validated named-lock release results without masking a completed cart operation, and tightened provider error classification around canonical reason codes and narrow credential, permission, location, quota, model, and schema signals.

### Widget authority, accessibility, and viewport completion

- Fenced every asynchronous widget lifecycle against dynamic teardown so a removed instance cannot finish a stale boot, recovery, chat, export, or deletion operation and overwrite the replacement widget's credentials, pending-turn state, or visible conversation.
- Kept minimized message delivery accessible by moving the live announcer outside the hidden panel and binding the launcher's accessible name to the current unread count.
- Gave every product-card action a product-specific accessible name while retaining the concise visible action label.
- Anchored product-rich assistant replies at the reply bubble instead of blindly scrolling to the bottom and hiding the answer above its cards, both after a live turn and when reopening saved history.
- Sized and positioned the full-screen mobile panel from the actual visual viewport, including horizontal offsets and zoomed viewport width, rather than assuming the layout viewport always matches the visible area.
- Replaced UTF-16 string slicing at widget presentation boundaries with Unicode code-point-safe truncation for reply quotes, image alternatives, product descriptions, product prompts, and bounded failure labels.

### Verification

- Added real-Chromium regressions for removed-instance stale responses, minimized unread accessibility, unique product-action names, and visual-viewport geometry.
- Extended static accessibility and cross-layer widget gates so the live-region boundary, lifecycle fencing, named product actions, and visual-viewport variables are release requirements.
- Advanced the schema marker without changing tables so startup re-verifies the existing persistence contract before enabling 2.5.2.

## 2.5.1 — 2026-08-16

### End-to-end widget lifecycle completion

- Made the phone and short-landscape widget a true modal conversation surface: background content becomes inert and hidden from assistive technology, document scrolling is locked, focus is contained, Escape dismisses safely, and every page state is restored when the panel closes, changes viewport mode, or is removed dynamically.
- Added generation-fenced open/close focus handling so a delayed boot or recovery cannot move focus into a panel the shopper already closed.
- Bound Send and attachment controls to the real draft, online, pending-turn, send, recovery, export, and deletion state. Whitespace-only drafts cannot send; unresolved turns require explicit recovery rather than an accidental Enter-key resend.
- Enforced the 4,000-character limit by Unicode code point instead of UTF-16 units and exposed a live count, near-limit state, and accessible over-limit error.
- Added client-side image dimension and pixel-budget validation, image-only submission, in-memory optimistic thumbnails, safe object-URL cleanup, invalid-replacement clearing, and accessible fallbacks for failed shopper, product, and merchant-avatar media. Image bytes remain absent from durable browser storage.
- Recalculated carousel position from cards actually visible in the rendered viewport, with correct navigation after scroll, resize, panel opening, layout changes, and single-product rendering. Bounded the cart strip to three visible lines plus a remaining-items summary.
- Completed privacy-menu keyboard navigation, clipboard-failure feedback, presence and unread feature enforcement, online/offline transitions, responsive safe-area handling, reduced motion, forced colors, and runtime observer teardown.

### Verification

- Added a focused real-Chromium widget runtime contract covering modality, focus containment, delayed-open races, Unicode limits, network transitions, in-flight locks, image-only messages, durable image privacy, broken-media fallbacks, compact carts, visible carousel ranges, disabled presence, and short-landscape behavior.
- Extended the fail-closed cross-layer widget audit and exact-package verifier so the runtime contract is required alongside the existing chat, recovery, cart, accessibility, and administration appearance contracts.
- Advanced the schema marker without changing tables so startup re-verifies the existing persistence contract before enabling 2.5.1.

## 2.5.0 — 2026-08-16

### Messaging-app storefront experience

- Rebuilt the storefront widget as a familiar shopper-to-agent conversation: branded launcher and agent identity, presence state, grouped message bubbles, day separators, timestamps, typing feedback, reply context, copy/reply actions, unread counts, latest-message navigation, quick replies, image previews, privacy actions, and an integrated cart strip.
- Added responsive full-screen mobile behavior with safe-area support, compact density, physical left/right launcher placement, bidirectional text isolation, reduced-motion handling, high-contrast forced-color support, keyboard focus management, and screen-reader announcements.
- Reworked product presentation with configurable carousel, grid, or list layouts; card paging controls; card count, image ratio, descriptions, name typography, price availability, stock state, and message-bound reply actions.

### Merchant appearance workbench

- Added a live WooCommerce administration editor for assistant identity, launcher style and position, presence, message density, timestamps, actions, unread controls, quick prompts, theme presets, eight independent color tokens, panel and bubble dimensions, product-card layout, image ratio, cards per view, and product-name typography.
- Added desktop, phone, and compact preview modes that use the same normalized appearance tokens as the storefront. Theme changes preserve deliberate merchant color overrides instead of resetting them.
- Fixed hidden preview controls that could remain visible because administration CSS overrode the HTML `hidden` state, and fixed unread-disabled configurations that still displayed a launcher badge.

### UI lifecycle and verification

- Preserved the existing idempotent chat and recovery state machine under the new interface, including optimistic-message acceptance, unverified/rejected states, in-flight minimization, unread delivery, retry, recovery, export, deletion, and capability replacement.
- Added a fail-closed cross-layer widget UI contract, bounded real Chromium storefront regressions, and a bounded real Chromium administration appearance-editor contract covering live customization, accessibility, responsive modes, preset inheritance, and merchant override preservation.
- Advanced the schema marker without changing tables so startup re-verifies the existing persistence contract before enabling 2.5.0.

## 2.4.9 — 2026-08-16

### Production chat completion

- Force Gemini to make a function call whenever the production tool bundle is present. Direct prose is rejected as a provider-protocol failure instead of falling through to a generic answer failure; tool-free structured probes explicitly disable tool selection.
- Added `GeminiSchemaProjector`, which emits only the portable stable-Interactions schema subset while retaining stricter local validation for string lengths, patterns, object-property counts, and every original function argument or structured result.
- Allow terminal answer and follow-up functions to send an explicit empty `product_refs` list. Empty means no cards; only an omitted field may reuse the latest authoritative shortlist.
- Treat a null SDK convenience `output_text` as absent and reconstruct valid text from REST `model_output` steps.

### Diagnostics and regression coverage

- Separate provider access denial, geographic restriction, and quota exhaustion from credential, model, request-contract, transport, and protocol failures, with bounded Arabic public guidance and fingerprint-only diagnostics.
- Updated the credentialed stable-v1 function probe to use the same function-only mode as storefront chat and kept strict readiness validation for the exact production tool registry.
- Added end-to-end regressions for forced terminal functions, durable provider-protocol failure presentation, portable wire schemas with strict local enforcement, explicit empty product-card lists, null output reconstruction, and provider error categorization.
- Extended the fail-closed static and packaging gates and advanced the schema marker without changing tables so startup re-verifies the existing persistence contract before enabling 2.4.9.

## 2.4.8 — 2026-08-16

### Production Gemini chat transport

- Fixed the blanket Arabic provider failure affecting every shopper message. Four zero-argument production tools were encoded as JSON Schema `properties:[]`; the Gemini function contract requires an object map, so the provider rejected the complete tool bundle before generating a response.
- Added a dedicated provider-wire validator that keeps application schemas transport-neutral while emitting explicit JSON objects at every schema-object boundary, including `properties:{}` for zero-argument tools.
- Made the administrator connection test exercise both structured output and the exact production function-tool bundle. A readiness probe can no longer pass while real chat requests are rejected.
- Locally validate each provider-selected function name and its arguments against the exact declared schema before any tool executes.

### Stateless interaction correctness

- Preserve model-generated interaction steps in raw JSON-native form and replay them exactly across stateless tool rounds, including empty argument objects and provider metadata.
- Treat provider function-call IDs as bounded opaque strings and return them byte-for-byte in `function_result` steps instead of imposing an undocumented alphabet or normalizing them.
- Send prior public transcript as bounded untrusted data inside the current user input rather than synthesizing historical assistant replies as model-prefill output.

### Failure diagnosis and regression coverage

- Map credentials, temporary outage, model availability, request-contract rejection, response-size, protocol, and incomplete-interaction failures to distinct Arabic shopper messages while keeping provider details in bounded diagnostics.
- Added a full two-turn chat transaction test using the real Gemini transport adapter, production tool registry, prompt factory, agent loop, and durable chat service. Added focused regressions for all zero-argument tools, exact raw-step replay, opaque call IDs, local argument validation, and production readiness.
- Extended the fail-closed chat-flow audit and advanced the schema marker without changing tables so startup re-verifies the existing persistence contract before enabling 2.4.8.

## 2.4.7 — 2026-08-16

### Exact response recovery

- Allow one exact retained turn to replay after the normal inactivity window, including fully presented success, accepted failure, and pre-acceptance rejection. Replay never refreshes conversation activity or reopens ordinary chat, history, export, or deletion.
- Keep unresolved, checkpointed, and presentation-pending recovery on the same durable idempotency identity without repeating provider or cart work.
- Seal an absent recovery identity as a durable pre-acceptance rejection while holding the same conversation lock used by new claims. A delayed original request can no longer execute immediately after recovery reported that it was never accepted; if the original claim wins first, recovery returns that real turn instead.
- Apply the same absence seal after the ordinary inactivity window without refreshing activity, and bound this comparatively expensive absence decision with a separate recovery quota.

### Browser capability reconciliation

- Treat pre-claim chat authorization failures as ambiguous until the recovery endpoint binds an exact unverified disposition; then retire the dead capability and pending record without inventing a replacement turn.
- Clear stale browser authority after exact export or deletion authorization rejection, and reconcile lost deletion acknowledgements before any later chat operation.
- Hide the requested-to-delete transcript and cart while deletion outcome is unknown, retain the old capability only as reconciliation input, and restore authoritative state only after a successful exact boot.
- Retire a durably sealed missing turn without silently resending it, restore the text as a draft for deliberate submission under a new ID, require image reattachment after reload, bound all browser JSON response reads, and prevent reply/cart authority from crossing a replacement conversation.

### Verification

- Added a fail-closed cross-layer chat-flow audit to the default verifier. It checks server disposition parity, typed request-ID conflicts, atomic missing-turn seals, generation and lease fencing, terminal presentation ordering, exact inactive replay, bounded browser transport, deletion reconciliation, stale-authority reset, and real-Chromium regression coverage.
- Expanded PHP, JavaScript, repository, REST, and Chromium coverage for both missing-turn race directions, inactive absence sealing, finalized absence responses, expired exact replay, unbound-then-unverified authorization, stale export capability, and unavailable deletion reconciliation.
- Advanced the schema marker without changing tables so startup re-verifies the existing persistence contract before enabling 2.4.7.

## 2.4.6 - End-to-end chat lifecycle completion

- Preserve exact recovery authority while an accepted turn is processing, checkpointed, or awaiting durable presentation, even when the normal inactivity window elapses.
- Prevent a later turn from overtaking a terminal result whose assistant message has not yet been durably attached.
- Fence user-message acceptance, conversation activity, shopping-memory writes, and terminal presentation to the exact active turn generation.
- Reconcile successful and failed terminal turns to one durable assistant-message identity before reporting finalization.
- Apply coarse request limiting before expensive reply-context and image processing without weakening idempotent recovery.
- Harden browser pending-turn disposition handling so ambiguous HTTP responses never discard an accepted operation.
- Add cross-layer chat-contract and repository-fencing audits plus regression coverage for short inactivity, lost writes, stale workers, presentation ordering, and failed-turn recovery.

## 2.4.5 — 2026-08-16

### End-to-end chat disposition and recovery

- Added an identity-bound request-disposition protocol. A browser clears its durable pending turn only after an exact finalized result or an exact server-authenticated rejection, conflict, or not-found disposition. Arbitrary HTTP 4xx/5xx, malformed JSON, intermediary responses, timeouts, and network failures preserve the original idempotency key and recover the same turn.
- Bound accepted user-message insertion and conversation activity extension atomically to the exact live turn generation. A reclaimed worker cannot append a ghost shopper message or extend retention after losing its lease.
- Bound shopping-memory writes to the same guarded claim and kept accepted failed turns pending until a durable assistant failure message is addressable.
- Preserved pending shopper messages across reload with the durable `client_turn_id`, while deduplicating them when durable history already contains the same turn.

### Public transcript and replay integrity

- Filtered internal/system roles at repository history and export queries and again at the application boundary.
- Canonicalized historical assistant kinds: unknown kinds become inert text, failed/uncertain history carries no product or cart authority, and cart receipts must contain a fully valid receipt and matching cart.
- Rebuilt terminal success and error replays from exact public allowlists rather than recursively returning stored fields.
- Deeply canonicalized exported messages and shopping memory, removed unknown memory fields, deduplicated categories, and added stable multi-page browser validation.
- Hardened public cart validation, including positive quantities, aggregate item-count consistency, exact presentation-notice semantics, and fail-closed malformed receipt handling.

### Verification

- Added regression coverage for ambiguous intermediary errors, accepted/rejected failure semantics, stale-worker message and memory ownership, malformed terminal envelopes, reload transcript restoration, cross-conversation recovery, internal-role filtering, export corruption, and cart uncertainty.
- Expanded the real Chromium widget contract to cover identity-bound recovery and same-conversation reload deduplication.
- Advanced the schema marker without changing tables or columns so startup re-verifies the existing database contract before enabling 2.4.5.

## 2.4.4 — 2026-08-15

### Model-visible error confidentiality

- Replaced generic `InvalidArgumentException` text at the application-to-model tool boundary with fixed bounded remediation. Domain, gateway, repository, database, and extension messages can no longer enter Gemini tool history for possible repetition to a shopper.
- Added behavior and static release guards that inject a sensitive internal sentinel, prove it is absent from model-visible tool results, and reject future reintroduction of exception-message forwarding.

### Fail-closed production packaging

- Split packaging into explicit `release` and `candidate` profiles. Production-labelled packaging now requires the exact source tree to pass the live WooCommerce/database/object-cache suite, credentialed Gemini stable-v1 contracts, and real Chromium gate; missing environment or credentials is a hard failure that leaves no archive.
- Kept locally verified builds available only through an explicit candidate profile and candidate filename, with a clear non-production warning.
- Added before/after source-tree hashing, fail-closed profile tests, exact-package extraction verification, byte-for-byte comparison, ZIP path/symlink checks, and checksum verification.

### Schema and documentation

- Advanced the schema marker without changing tables or columns so activation and startup reverify the complete existing persistence contract.
- Updated migration, testing, operations, API, security, packaging, and release documentation to distinguish implementation coverage from live acceptance evidence.

## 2.4.3 — 2026-08-14

### Catalog correctness

- Replaced raw WordPress product prefilters with WooCommerce-native product search and paginated `wc_get_products()` retrieval. Search relevance is preserved from the product data store while visibility, price, stock, sale, and extension-filtered state are evaluated from live product objects.
- Added bounded candidate scanning across multiple pages, explicit `results_truncated` and `scan_exhausted` metadata, category ID/name-to-slug resolution, paginated alternative-product retrieval, and a prompt rule that prevents bounded shortlists from being described as exhaustive.
- Represented unavailable or invalid catalog prices as `null` with explicit availability and kind fields. Legitimate zero-priced products remain numeric zero, while unknown prices sort after known prices and are excluded from value scoring.

### Public security boundaries

- Reduced the unauthenticated health route to an exact liveness response and kept version, configuration, and readiness details inside authenticated administration diagnostics.
- Stopped returning uncategorized internal validation exception messages to shoppers. Exact classes and messages remain available only through bounded diagnostic logging.

### Gemini stable v1

- Migrated production requests from the beta Interactions route to the generally available `v1/interactions` endpoint and removed the obsolete migration revision header.
- Added strict stable-v1 endpoint/header regression checks plus an opt-in live contract suite for structured output and function calling.

### Verification

- Added focused catalog pagination, nullable-price, ranking, health-disclosure, public-error, stable-v1, concurrency/recovery, static accessibility, real-Chromium/accessibility-tree, ZIP path-safety, and fresh-extraction release gates.
- Added a mandatory live-matrix wrapper for disposable WooCommerce/database, credentialed Gemini v1, and Chromium gates. The release report distinguishes every executed pass from each environment-dependent skip.

## 2.4.2 — 2026-08-14

### Durable cart rollback

- Split `CartSessionPersistence` into an explicit write operation and a separate canonical read operation. No persistence adapter may return its own write result as proof that a commit or rollback reached durable storage.
- Required the cart gateway to invalidate any potentially divergent session cache and perform a new canonical read after every mutation write, rollback write, operation-start journal, and completed replay receipt.
- Added regression coverage proving that a request-local rollback is insufficient and that rollback succeeds only when the separately read durable signature matches the original cart snapshot.

### Live WooCommerce fault integration

- Added a destructive, opt-in WP-CLI integration suite for a disposable local, development, or staging WordPress installation using the real built-in `WC_Session_Handler`, real WooCommerce session table, WPDB, WordPress object-cache API, and a real `WC_Cart`.
- Added SQL fault injection for divergent cache/database state, database write errors, silently dropped writes, lost post-commit responses, and dropped rollback writes.
- Added an integration release gate that runs automatically when `YSAI_WP_PATH` is supplied and can be made mandatory with `YSAI_REQUIRE_WOOCOMMERCE_INTEGRATION=1`.

### Trusted proxy identity

- Added bounded `Forwarded` and `X-Forwarded-For` resolution that accepts forwarded addresses only when the immediate peer is inside an explicitly configured trusted-proxy CIDR.
- Added canonical IPv4/IPv6 and CIDR handling, right-to-left trusted-chain evaluation, spoof resistance, strict header limits, diagnostics, settings, and `wp-config.php` constant overrides.
- Rejected trust-all `/0` proxy networks and malformed or ambiguous forwarding chains.
- Removed network-address fallback from cart locking. Assistant cart mutations now require a logged-in user or a validated WooCommerce customer-session identity; product discovery and cart inspection remain available when mutation ownership cannot be established.

### Tests and documentation

- Expanded proxy, settings, request-identity, cart-lock, persistence, and rollback tests.
- Updated architecture, security, operations, migration, testing, administration, and packaging guidance for release 2.4.2.

## 2.4.1 — 2026-08-14

### Cart durability

- Replaced cache-capable WooCommerce session reads in the cart gateway with an explicit `CartSessionPersistence` boundary.
- Added a database-backed adapter for the exact built-in `WC_Session_Handler` that preserves WooCommerce's full session write, checks database errors, bypasses WordPress object cache on verification, and decodes WooCommerce's nested serialized session values without instantiating PHP classes and under strict size, key, expiry, and depth limits.
- Verified every authorized cart result against a direct read of the canonical `woocommerce_sessions` row before returning success.
- Required rollback to restore both the request-local cart and the directly read durable cart signature. A lost write response or failed rollback is now reported as `CartStateUncertain` rather than ordinary failure.
- Invalidated the WooCommerce session cache after uncertain writes so a cached value cannot hide disagreement with durable storage.
- Restricted the default adapter to WooCommerce's built-in database handler. Custom handlers require an explicit `ysai_cart_session_persistence` adapter; otherwise catalog and cart viewing remain available while assistant mutations fail closed.

### Tests and documentation

- Added fault-injection coverage for database-write failure with a successful cache update, silently dropped mutation writes, post-commit response loss, dropped rollback writes, direct database reads, nested session serialization, blocked serialized-object wakeups, malformed rows, expiry overflow, and unsupported custom handlers.
- Updated architecture, security, operations, migration, testing, administration, and packaging guidance for the direct durable-session boundary.

## 2.4.0 — 2026-08-14

### Correctness and lifecycle

- Added a durable conversation lifecycle state so deletion, retention cleanup, new turn creation, and live processing cannot race each other.
- Enforced one processing turn per conversation at the database boundary while preserving exact replay of an existing completed, failed, or checkpointed turn.
- Persisted each turn's issued lease duration and used that immutable value for heartbeat, recovery, stale detection, and reclaim decisions instead of recomputing it from mutable settings.
- Added turn heartbeats before provider work, tool execution, independent cart authorization, and the journaled WooCommerce mutation boundary.
- Added deterministic stale-turn recovery: checkpointed work finalizes without repeating provider or cart effects; an uncheckpointed abandoned turn is durably failed and requires the shopper to inspect the cart before explicitly resending.
- Required every successful response to carry a durable assistant-message identifier, returning an explicit recovery state when final presentation is still pending.

### Security and resilience

- Added strict duplicate-key JSON rejection at REST, provider, and persisted-document boundaries, including escaped-equivalent member names and non-finite numeric overflow.
- Added a daily global conversation-creation quota in addition to turn and browser limits.
- Bounded conversation-history loading by both row count and an 8 MiB aggregate source budget using a metadata-first stable suffix and exact second-read verification.
- Hardened boot and deletion response validation in the browser so malformed credentials, history, cart envelopes, image metadata, or acknowledgements cannot overwrite local state.
- Unified provider, browser, and persisted lease timing through one policy that guarantees the browser aborts before lease expiry with a recovery margin.
- Expanded active-conversation and lease predicates across repository writes so zero-row updates cannot be mistaken for successful persistence after deletion or ownership loss.

### Build, tests, and documentation

- Added direct SQL-adapter tests for lifecycle locking, immutable leases, stale-owner fencing, exact terminal replay, checkpoint-only late finalization, and one-processing-turn enforcement.
- Added history-budget, strict-JSON, browser boot/delete, stale-turn recovery, and timing-policy regression coverage.
- Updated architecture, API, security, operations, migration, product-requirements, testing, and release documentation for the 2.4 persistence and recovery model.

## 2.3.0 — 2026-08-14

### Correctness

- Rechecked the browser operation lock after asynchronous boot so rapid submits cannot create independent turns.
- Required every outgoing turn identity to be recoverable from durable session storage before sending; unresolved turns now block later sends and conversation deletion until the original turn is recovered or conclusively rejected.
- Kept attached image bytes in page memory only. A reload recovers the original server turn but never silently resends an image request with the image removed.
- Prevented explicit empty, invalid, or stale terminal product references from falling back to unrelated previously displayed cards.
- Aligned browser message limits and draft restoration with the server's 4000-Unicode-code-point contract.
- Preserved retry and credential error semantics when Gemini returns a non-JSON HTTP error body.

### Security and resilience

- Added local validation for every schema-constrained Gemini result, including strict types, required and extra fields, bounds, patterns, enums, JSON object/array identity, and complexity limits.
- Rejected malformed Gemini interaction statuses, step envelopes, duplicate call IDs, non-object function arguments, inconsistent function-call status, unsupported local schema keywords, and type-inapplicable schema constraints.
- Expanded database upgrade verification to cover column types, signedness, nullability, defaults, auto-increment behavior, table and column character sets/collations, every required index, uniqueness, exact column order, full-width keys, usable visible BTREE indexes, and InnoDB.
- Sanitized legacy pending-turn records so image bytes and unknown fields cannot remain in browser session storage after upgrade.
- Removed unverified WordPress and WooCommerce “tested up to” compatibility claims while retaining explicit minimum runtime requirements.

### Build, tests, and documentation

- Bound release verification to the database schema version and made packaging derive its default archive name from the plugin version.
- Added regression coverage for durable pending turns, Unicode boundaries, provider HTTP failures, strict local schema enforcement, JSON container identity, full schema/index/encoding verification, and explicit terminal product references.
- Corrected the REST contract for server-resolved reply context and paginated exports, and updated architecture, security, operations, migration, testing, and product requirements.

## 2.2.0 — 2026-08-14

### Correctness

- Added generation-fenced turn leases so a stale worker cannot checkpoint, complete, or fail a turn after another request has reclaimed it.
- Added deterministic reconciliation for completed turns whose assistant message or message identifier was not durably attached during the original response.
- Replaced undurable success responses with an explicit retryable `turn_persistence_uncertain` result that preserves the original client turn identifier.
- Fixed atomic rate-limit accounting so each concurrent request receives its own increment result rather than a later shared count.
- Serialized browser send, retry, export, and deletion operations to prevent credential replacement, orphaned pending turns, and partial exports.

### Security and resilience

- Verified required schema columns, unique index order, and InnoDB engines before publishing the 2.2.0 schema version.
- Hardened conversation and turn repositories against malformed identifiers, dates, counters, statuses, hashes, and oversized stored JSON.
- Bounded cart session state, extension metadata, operation journals, receipts, product projections, image pixel counts, and persistence payloads.
- Removed User-Agent input from anonymous rate-limit and cart-lock identity, and normalized IPv6 privacy addresses to a network prefix.
- Restricted reply-based product authority to products actually displayed in the referenced stored assistant message.
- Rejected credential-bearing browser URLs, malformed provider `output_text`, and ambiguous oversized `Content-Length` values.
- Made uninstall deletion require an exact valid opt-in value; malformed settings now fail closed.

### Testing and documentation

- Added regression tests for lease fencing, replay healing, persistence uncertainty, schema verification, repository corruption, rate-limit concurrency, cart journal bounds, provider protocol validation, uninstall safety, and browser operation locking.
- Updated architecture, security, API, migration, operations, and staging guidance for the new persistence and concurrency model.

## 2.1.0 — 2026-08-14

### Fixed

- Repaired all stateful REST routes after a production-only controller regression and added strict request-boundary coverage.
- Restored production cart mutation support by binding every cart view to fresh persisted WooCommerce session state.
- Prevented stale in-memory carts and stale mutation journals from overwriting concurrent request results.
- Preserved assistant message identifiers when checkpoint persistence falls back to direct turn completion.
- Removed a duplicated administration setting and escaped inline storefront configuration against script-breakout sequences.

### Hardened

- Added durable cart operation start/completion records, verified replay, stale-plan detection, post-save durable verification, and explicit uncertain-state handling.
- Resolved reply context exclusively from stored assistant messages and their displayed product references.
- Tightened live catalog filters, access checks, projection and identity bounds, exact variation matching, same-origin links, protected content, image canonicalization, and shopping-memory evidence.
- Removed the unsupported Gemini `minimal` thinking mode and normalized older saved values to `low`.
- Expanded PHP and browser tests across REST, catalog, cart concurrency, authorization, recovery, export, and session expiry.

### Compatibility

- Stores using a custom WooCommerce session handler without the public fresh-session read contract remain fully read-only in the assistant; cart mutations fail closed with an actionable notice.
- The database schema remains `2.0.0`; no legacy or new-release table migration is required for this update.

## 2.0.0 — 2026-08-14

### Rebuilt

- Replaced the previous implementation with an independent clean architecture.
- Preserved the essential storefront product: Arabic-first shopping assistance, catalog discovery, comparisons, variation selection, store content, cart support, optional image input, conversation continuity, and checkout handoff.
- Removed all runtime dependency on legacy classes, service locators, generated vendor trees, and previous database tables.

### Added

- Stateless Gemini Interactions API client with function calling and schema-constrained decisions.
- Server-authoritative tool registry for catalog, content, shopping memory, cart reads, cart writes, and terminal responses.
- Independent current-message cart-intent verification.
- Opaque product and cart-line references with state fingerprints.
- Serialized cart commits, pre-change snapshots, rollback verification, post-change verification, and server receipts.
- Turn claim, checkpoint, recovery, and deterministic replay handling.
- Encrypted Gemini API-key storage with a configuration-constant override.
- Capability-token conversations, retention, rate limits, export, deletion, and scheduled cleanup.
- Accessible RTL storefront widget and WooCommerce administration page.
- PHP and JavaScript test harnesses, release verification, packaging scripts, and operational documentation.

### Data policy

- Added new `ysai_v2_` tables.
- No legacy conversation table is read, migrated, modified, or removed.
- Uploaded image bytes are not persisted.
