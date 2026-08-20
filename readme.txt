=== Yassin Store AI Sales Agent ===
Contributors: yassinstore
Tags: woocommerce, ai, arabic, shopping assistant, gemini
Requires at least: 7.0
Requires PHP: 8.3
Stable tag: 2.5.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Arabic-first WooCommerce sales assistant with live catalog tools, verified cart actions, optional image input, and native checkout handoff.

== Description ==

Yassin Store AI Sales Agent helps shoppers discover and compare live WooCommerce products, understand store policies, inspect their cart, and request controlled cart changes.

Cart changes are not delegated to the AI model. The server independently validates current-message consent, opaque product and cart references, product state, stock, quantity limits, WooCommerce extension rules, cart state, and the committed result. Successful mutations return a server-generated receipt.

The plugin uses stateless Gemini interactions, stores only hashes of conversation capability tokens, encrypts the Gemini API key, does not persist uploaded image bytes, and provides configurable retention, export, and deletion controls.

The 2.x codebase is an independent rewrite. It does not read, migrate, alter, or delete previous-release conversation tables.

== Phase 1 shopping completion ==

The directly updated 2.5.4 rewrite now supports bounded Arabic/English/transliterated recall, merchant synonym groups, deterministic best-match ranking, one-use catalog continuation, typed cart clarification, quantity-preserving replacement, and verified-receipt-only refresh of classic WooCommerce and Blocks cart surfaces. Continuation and clarification never authorize a write; the existing current-message evidence and durable cart verification remain mandatory.

== Installation ==

1. Back up the site and test on staging.
2. Upload and activate the plugin.
3. Open WooCommerce > AI Assistant.
4. Save a Gemini API key and run the connection test.
5. Configure the widget, limits, retention, and store links.
6. Exercise product search, variations, cart changes, retries, checkout, export, and deletion before production rollout.

== Frequently Asked Questions ==

= Does it place orders or process payments? =

No. It hands the shopper to the native WooCommerce checkout and does not manage orders, payments, refunds, or checkout fields.

= Are uploaded images stored? =

No. Validated bytes are sent only with the current Gemini request. The database stores MIME type, byte count, width, and height for history display.

= Does it migrate data from an older version? =

No. New tables use the `ysai_v2_` prefix. Old tables are intentionally untouched. Previous conversations are not imported.

= Where is the Gemini key stored? =

The database value is authenticated-encrypted with Sodium or AES-256-GCM. A `YSAI_GEMINI_API_KEY` constant in `wp-config.php` can be used instead.

= Why can a cart change be rejected even when the product is visible? =

The plugin fails closed when the cart has changed, a variation is not exact, stock or quantity limits fail, an extension rejects the operation, the current message does not clearly authorize it, or the database lock cannot be obtained.

= Why did every message return an AI-service failure? =

Release 2.5.4 retains the corrected production function contract and fixes cache compatibility, provider retry timing, cleanup remediation during option-write failures, multi-reason provider diagnosis, and delayed retry guidance. Save the key, verify the configured model, and run the administration connection test; it exercises the exact production tools rather than structured output alone. Replace the complete plugin directory and clear page, object, opcode, CDN, and browser caches so the 2.5.4 PHP, module, client utilities, template, and stylesheet load together.

== Changelog ==

= 2.5.4 =

* Completed Phase 1 shopping parity inside the clean rewrite: multilingual recall and synonyms, deterministic ranking, one-use continuation, typed clarification, locked quantity preservation, native cart presentation convergence, adversarial tests, and a fail-closed Phase 1 contract.
* Keep ordinary storefront requests out of automatic cleanup scheduling. Remediation is limited to activation, privileged non-AJAX administration, WP-Cron, and WP-CLI contexts.
* Suppress cleanup diagnostic logging when its throttle cannot be durably stored, preventing an option-write outage from creating an unbounded log storm while retaining the administration warning.
* Collect and classify bounded top-level and nested Gemini reasons so unknown or generic first details cannot conceal a later specific canonical reason.
* Added regressions for option-write failure, read-only storefront cleanup checks, remediation-context gating, nested reason precedence, breadth-first reason scanning, and exact-package enforcement.

= 2.5.3 =
* Changed the package and asset identity to 2.5.3 and added an explicit browser/server error-contract header; stale 2.5.2 clients continue to receive their legacy exact error object during rolling deployment.
* Honor the complete provider `Retry-After` interval only when it fits the shared request deadline; otherwise skip the early retry. Fractional timeouts and a finalization reserve keep all attempts inside one budget.
* Give structured provider reasons and canonical status codes precedence over incidental message words.
* Throttle cleanup-scheduling retries and logs to a bounded window without disabling REST, administration, or the storefront widget.
* Expose bounded delayed fresh-turn retry metadata and the HTTP `Retry-After` header, and reveal the shopper retry action only after the delay expires.
* Added cross-layer regressions for retry timing, deadline exhaustion, error classification, cleanup throttling, legacy/current response compatibility, and durable retry-delay replay.

= 2.5.2 =
* Added an evictable terminal `rolled_back` cart-journal state after direct durable rollback proof; exact replay cannot execute the failed mutation again, while ambiguous writes remain unresolved.
* Added bounded transient Gemini retries and explicit `retry_mode` semantics so same-turn recovery is never confused with deliberate submission under a fresh turn identity.
* Kept REST, administration, and the widget available when WP-Cron cleanup scheduling fails, while reporting and retrying the degraded cleanup state.
* Validated named cart-lock release results and tightened provider rejection classification using canonical reasons and narrow text fallbacks.
* Prevented removed widget instances from applying stale asynchronous boot, recovery, chat, export, or deletion results to a replacement widget.
* Moved the live announcer outside the hidden panel and added unread counts to the launcher accessible name while minimized.
* Kept product-rich replies anchored at their answer bubble, and added product-specific accessible names, Unicode-safe presentation truncation, and visual-viewport-aware mobile sizing and offsets.
* Added real-Chromium regressions for stale teardown responses, minimized unread delivery, named product actions, and zoomed visual viewport geometry.

= 2.5.1 =
* Made the mobile and short-landscape messenger a true modal with background isolation, scroll locking, focus containment, Escape dismissal, safe focus return, viewport-safe sizing, and complete teardown.
* Prevented delayed boot or recovery work from refocusing a closed widget and bound composer controls to valid Unicode drafts, connectivity, pending turns, send, recovery, export, and deletion state.
* Added image dimension and pixel validation, image-only messages, in-memory optimistic thumbnails, invalid-replacement clearing, object-URL cleanup, and accessible broken-media fallbacks without storing image bytes.
* Reworked carousel navigation to use actual rendered visibility, bounded the cart strip, completed privacy-menu keyboard and clipboard-failure behavior, and enforced presence and unread settings consistently.
* Added a focused real-Chromium widget runtime contract and expanded the fail-closed widget/package audits.

= 2.5.0 =
* Rebuilt the storefront as a familiar shopper-agent messenger with identity, presence, grouped bubbles, timestamps, typing, quick replies, reply/copy actions, unread/latest navigation, privacy controls, cart context, image previews, and configurable product cards.
* Added a live WooCommerce appearance workbench with desktop, phone, and compact previews; theme presets; preserved merchant overrides; eight color controls; dimensions; typography; launcher and avatar modes; message features; and product layouts.
* Added full-screen mobile safe-area behavior, bidirectional-text isolation, reduced-motion and forced-color support, visible keyboard focus, and screen-reader announcements.
* Preserved idempotent send/recovery/export/deletion behavior under the redesigned UI and corrected optimistic-message, hidden-preview, unread-disabled, and preset-override regressions found by real Chromium tests.
* Added fail-closed storefront and administration UI contracts to the local and exact-package verification gates.

= 2.4.9 =
* Forced function selection for production chat and rejected direct provider prose before it can become a generic shopper failure.
* Projected strict local schemas to the portable stable-Interactions wire subset while retaining all original local argument and structured-output checks.
* Allowed explicit empty terminal product lists, preserving the distinction between “show no cards” and “reuse the latest authoritative shortlist.”
* Reconstructed text when the optional provider convenience field is null and added distinct access, location, quota, credential, model, request, transport, and protocol diagnostics.
* Expanded end-to-end provider, static-contract, readiness, and exact-package regression coverage.

= 2.4.8 =
* Fixed universal chat rejection by emitting zero-argument function schemas with `properties:{}` instead of `properties:[]`.
* Added provider-wire tool validation, exact production-tool readiness, and local validation of selected function names and arguments.
* Preserved raw Gemini steps, empty argument objects, and opaque function-call IDs exactly across stateless tool rounds.
* Moved prior transcript into bounded untrusted current-input data instead of synthetic model-prefill output.
* Added distinct Arabic provider diagnostics and a full two-turn production chat regression.

= 2.4.7 =
* Replays the exact retained idempotent result after inactivity without refreshing or reopening the conversation.
* Reconciles unbound chat authorization through the exact recovery endpoint before retiring stale browser authority.
* Clears stale export/delete capabilities and hides requested-to-delete state while a lost deletion acknowledgement remains unresolved.
* Atomically seals an absent recovery ID before reporting it unaccepted, prevents delayed execution, restores text for deliberate new submission, and requires image reattachment after reload.
* Bounds browser response reads and keeps reply/cart authority tied to the exact validated conversation capability.
* Adds a fail-closed cross-layer chat-flow audit and expanded real-Chromium lifecycle coverage.

= 2.4.6 =
* Preserved exact pending-turn recovery across short inactivity windows.
* Prevented later turns from overtaking terminal results awaiting durable assistant-message presentation.
* Added generation-fenced user-message, memory, and failed-turn presentation writes.
* Hardened browser disposition handling for ambiguous and intermediary responses.
* Expanded end-to-end chat-flow and repository-ordering regression coverage.



= 2.4.5 =
* Bound every browser error decision to the exact conversation and client-turn identity; unproven HTTP errors preserve the pending turn and recover it instead of inviting a duplicate request.
* Atomically bind user-message acceptance, conversation activity, and shopping-memory writes to the exact live turn generation.
* Keep accepted failed turns pending until their assistant failure message is durable, and expose the client turn ID in user history so reload recovery renders the shopper message exactly once.
* Filter internal roles from history/export, canonicalize historical message kinds and commerce payloads, deeply validate export pages and shopping memory, and rebuild replay responses from strict public allowlists.
* Harden cart response validation so malformed receipts, inconsistent counts, and uncertain outcomes cannot assert a fresh cart.
* Expand PHP, JavaScript, concurrency, accessibility, Gemini fixture, and real-Chromium recovery coverage.

= 2.4.4 =

* Redacted uncategorized invalid-argument details before tool results enter Gemini history, with regression and static guards against model-visible internal exception leakage.
* Made production-labelled packaging fail closed unless the exact source passes the live WooCommerce/database/object-cache suite, credentialed Gemini stable-v1 contracts, and real Chromium gate.
* Added an explicit locally verified candidate profile, source-tree integrity hashing, profile-boundary tests, fresh-extraction verification, and documentation that separates code coverage from live acceptance evidence.
* Advanced and reverified the unchanged database schema contract.

= 2.4.3 =

* Replaced raw WordPress catalog prefilters with bounded WooCommerce-native search and pagination, live product-object filtering, and explicit truncation metadata.
* Preserved unavailable prices as `null` with availability metadata, kept legitimate free products at zero, and prevented unknown prices from winning price/value rankings.
* Reduced the public health response to liveness only and stopped exposing generic internal validation messages to shoppers.
* Migrated Gemini Interactions to stable `v1` and added opt-in live structured-output and function-calling contract checks.
* Added concurrency/recovery, static accessibility, real Chromium/accessibility-tree, stable-v1 fixture, ZIP path-safety, fresh-extraction, and expanded catalog/security release gates.

= 2.4.2 =

* Separated every WooCommerce session write from the canonical database read used to prove cart commits, rollbacks, and replay journals.
* Added an opt-in WP-CLI integration suite using a real WooCommerce cart, session handler, WPDB session table, object-cache API, and SQL fault injection on disposable staging environments.
* Added trusted-proxy CIDR configuration for authenticated `Forwarded` or `X-Forwarded-For` client identity with strict chain parsing and administrator diagnostics.
* Removed network-address fallback from cart locks; mutations require a logged-in user or validated WooCommerce customer session.
* Expanded persistence, rollback, proxy, settings, identity, cart-lock, release-gate, and documentation coverage.

= 2.4.1 =

* Replaced cache-based cart-session verification with a dedicated durable persistence adapter for WooCommerce's built-in database session handler.
* Verified cart commits and rollbacks through direct reads of the canonical WooCommerce session row, including nested serialized cart, coupon, and replay-journal values without serialized-object instantiation.
* Invalidated session cache after uncertain writes and failed closed when durable state cannot be established.
* Required custom session handlers to provide an explicit durable adapter; unsupported handlers remain read-only for assistant cart changes.
* Added database/cache fault injection, lost-response, dropped-rollback, serialization, expiry, and custom-handler regression coverage.

= 2.4.0 =

* Added database-backed conversation lifecycle gates so deletion and retention cannot race active turns.
* Persisted immutable per-turn lease durations, added ownership heartbeats, and enforced one processing turn per conversation while preserving exact replay.
* Finalized checkpointed stale turns without repeating work and durably abandoned uncheckpointed stale turns with explicit cart-review guidance.
* Rejected duplicate JSON object keys, bounded history reads by aggregate bytes, and added a daily conversation-creation quota.
* Unified browser timeout and server lease timing and hardened boot/deletion response validation.
* Expanded SQL-adapter, recovery, JSON, history, timing, and browser regression coverage and updated all deployment documentation.

= 2.3.0 =

* Made pending-turn identity durable before network transmission and blocked overlapping turns or deletion until recovery completes.
* Kept image bytes memory-only during retry and required reattachment rather than silently resending a changed image request after reload.
* Added strict local structured-output validation and hardened Gemini status, step, function-call, empty-container, and non-JSON HTTP error handling.
* Verified the complete database column, index, InnoDB, character-set, and collation contract before publishing schema version 2.3.0.
* Prevented stale explicit product references from displaying unrelated fallback cards and aligned message limits with Unicode code points.
* Derived package names from release metadata, expanded regression coverage, and corrected API and deployment documentation.

= 2.2.0 =

* Added generation-fenced turn leases so reclaimed requests cannot be overwritten by stale workers.
* Added deterministic completed-turn reconciliation and explicit retryable persistence-uncertainty handling.
* Fixed concurrent rate-limit accounting and removed User-Agent/cookie rotation from request identity.
* Verified required schema columns, ordered unique indexes, and InnoDB before publishing upgrades.
* Hardened conversation, turn, cart-session, journal, receipt, catalog, provider, image, URL, and uninstall boundaries.
* Serialized browser send, retry, export, and deletion operations and expanded PHP/browser regression coverage.

= 2.1.0 =

* Repaired the production REST boundary and added strict JSON, origin, and field validation tests.
* Bound cart views and writes to fresh persisted WooCommerce session state under the cart lock.
* Added a durable mutation journal, stale-session rejection, verified replay, and stronger rollback semantics.
* Bound reply context to stored assistant messages and tightened current-message cart authorization.
* Hardened catalog access, live filters, projection bounds, variation matching, image handling, URL output, and shopping-memory evidence.
* Preserved assistant message identifiers across direct-finalization replay and expanded PHP/browser regression coverage.
* Removed a duplicated cart-summary administration control and hardened inline storefront configuration encoding.

= 2.0.0 =

* Independent clean rewrite around product requirements rather than legacy architecture.
* Added Arabic-first accessible storefront widget and administration.
* Added stateless Gemini Interactions API tool loop and structured intent verification.
* Added server-owned product, content, cart, and checkout tools.
* Added verified, serialized, idempotent cart mutations with receipts and rollback verification.
* Added conversation capabilities, recovery, retention, export, deletion, and rate limiting.
* Added encrypted settings, current-image-only handling, security documentation, and automated tests.
* Added fresh `ysai_v2_` tables; legacy tables remain untouched.
