#!/usr/bin/env python3
"""Fail-closed cross-layer audit for the durable chat lifecycle contract."""

from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(relative: str) -> str:
    path = ROOT / relative
    if not path.is_file():
        raise SystemExit(f"Missing chat-flow contract source: {relative}")
    return path.read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(message)


def require_terms(source: str, terms: tuple[str, ...], label: str) -> None:
    missing = [term for term in terms if term not in source]
    require(not missing, f"{label} is missing: {', '.join(missing)}")


def require_order(source: str, terms: tuple[str, ...], label: str) -> None:
    positions = [source.find(term) for term in terms]
    require(all(position >= 0 for position in positions), f"{label} is incomplete.")
    require(positions == sorted(positions), f"{label} is ordered incorrectly.")


def function_block(source: str, signature: str) -> str:
    start = source.find(signature)
    require(start >= 0, f"Missing function boundary: {signature}")
    if signature.rstrip().endswith("{"):
        brace = start + signature.rfind("{")
    else:
        brace = source.find("{", start + len(signature))
    require(brace >= 0, f"Missing function body: {signature}")
    depth = 0
    quote: str | None = None
    escaped = False
    for index in range(brace, len(source)):
        char = source[index]
        if quote is not None:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            continue
        if char in ("'", '"'):
            quote = char
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[start : index + 1]
    raise SystemExit(f"Unclosed function body: {signature}")


plugin = read("yassin-ai-assistant.php")
version_match = re.search(r"^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$", plugin, re.MULTILINE)
require(version_match is not None, "Unable to identify the plugin version.")
version = version_match.group(1)

chat = read("src/Application/Chat/ChatService.php")
turn_contract = read("src/Application/Contract/TurnRepository.php")
conflict_type = read("src/Application/Contract/TurnRequestConflict.php")
conversation_contract = read("src/Application/Contract/ConversationRepository.php")
turn_repository = read("src/Infrastructure/Database/WpTurnRepository.php")
conversation_repository = read("src/Infrastructure/Database/WpConversationRepository.php")
rest = read("src/Presentation/Rest/RestController.php")
widget = read("assets/js/widget.js")
client = read("assets/js/client-utils.js")
logger = read("src/Infrastructure/WordPress/Logger.php")
chat_tests = read("tests/Unit/ChatServiceTest.php")
lifecycle_tests = read("tests/Unit/ConversationLifecycleTest.php")
rest_tests = read("tests/Unit/RestControllerTest.php")
client_tests = read("tests/js/client-utils.test.js")
browser_tests = read("tests/browser/widget-e2e.py")
provider_client = read("src/Infrastructure/Ai/GeminiInteractionsClient.php")
tool_validator = read("src/Infrastructure/Ai/FunctionToolValidator.php")
tool_registry = read("src/Application/Tool/ToolRegistry.php")
prompt_factory = read("src/Application/Chat/PromptFactory.php")
agent_loop = read("src/Application/Chat/AgentLoop.php")
admin_page = read("src/Presentation/Admin/AdminPage.php")
gemini_tests = read("tests/Unit/GeminiInteractionsClientTest.php")
provider_flow_tests = read("tests/Unit/ChatProviderFlowTest.php")
schema_projector = read("src/Infrastructure/Ai/GeminiSchemaProjector.php")
schema_projector_tests = read("tests/Unit/GeminiSchemaProjectorTest.php")
installer = read("src/Infrastructure/Database/Installer.php")
installer_tests = read("tests/Unit/InstallerTest.php")
storefront_widget = read("src/Presentation/Storefront/StorefrontWidget.php")

# Cheap abuse control and exact claim must precede all authority-bearing work.
chat_method = function_block(chat, "public function chat(array $request): array")
require_order(
    chat_method,
    ("browser_requests", "$this->reply(", "ImageInput::fromRequest(", "$this->turns->claim(", "appendUserMessageForTurn("),
    "Chat request authority sequence",
)
require_terms(
    chat,
    (
        "appendUserMessageForTurn(",
        "attachTerminalMessageId(",
        "resolveBlockingTurn(",
        "failedTurnResult(",
        "completedTurnResult(",
        "acceptedUserMessageState(",
        "sealMissingAsRejected(",
        "recovery_absence",
    ),
    "ChatService durability fencing",
)

# Recovery authenticates, rate-limits, and reads the exact turn before applying
# inactivity. Any retained exact turn may replay without refreshing activity;
# ordinary chat/export/delete remain closed by their own inactivity checks.
recover = function_block(chat, "public function recover(string $conversationId")
require_order(
    recover,
    ("authenticate(", "assertOperationRate(", "$this->turns->find(", "$inactive = $this->sessionExpired(", "$refreshConversation = !$inactive;"),
    "Exact-turn recovery sequence",
)
require("recoveryMayBypassInactivity" not in chat and "terminalPresentationPending" not in chat,
        "Recovery still depends on a separate presentation-status inactivity gate.")
terminal_recovery = recover[recover.find("$status = (string)"):]
require("if ($inactive" not in terminal_recovery,
        "An existing retained turn is still rejected solely because the activity window elapsed.")
require_terms(recover, ("$status === 'processing'", "$status === 'completed'", "$status === 'failed'"), "Exact-turn replay states")

# Request-ID conflict must be typed, never inferred from message text.
require("extends \\RuntimeException" in conflict_type, "TurnRequestConflict is not a dedicated runtime exception.")
require("catch (TurnRequestConflict)" in chat_method, "ChatService does not catch the typed turn conflict.")
claim_locked = function_block(turn_repository, "private function claimLocked(")
require("throw new TurnRequestConflict(" in claim_locked, "The repository does not throw the typed turn conflict.")
require("getMessage()" not in claim_locked.split("TurnRequestConflict", 1)[-1],
        "Turn conflict behavior depends on exception-message matching.")

# Repository-level ownership and transcript ordering.
require_terms(turn_contract, ("sealMissingAsRejected", "blockingRecoveryCandidate", "attachTerminalMessageId", "heartbeat", "expireStale"), "Turn repository contract")
require_terms(conversation_contract, ("appendUserMessageForTurn", "updateMemoryForTurn", "TurnLeaseLost"), "Conversation repository contract")
seal_missing = function_block(turn_repository, "public function sealMissingAsRejected(")
require_order(
    seal_missing,
    ("begin()", "lockActiveConversation(", "INSERT IGNORE INTO", "rowForConversation(", "commit()"),
    "Missing-turn absence seal",
)
require_terms(
    seal_missing,
    ("request_accepted", "turn_finalized", "ysai-missing-turn-v1", "'failed'", "publicTurnRecord"),
    "Missing-turn tombstone",
)
claim = function_block(turn_repository, "public function claim(")
require_order(claim, ("begin()", "lockActiveConversation(", "assertNoOtherUnresolvedTurn(", "claimLocked(", "commit()"), "Turn claim transaction")
unresolved = function_block(turn_repository, "private function assertNoOtherUnresolvedTurn(")
require_terms(
    unresolved,
    ("t.status = 'processing'", "t.status = 'completed'", "t.status = 'failed'", "am.role = 'assistant'", "um.role = 'user'", "fm.role = 'assistant'", "FOR UPDATE"),
    "Unpresented terminal-turn exclusion",
)
accepted_write = function_block(conversation_repository, "public function appendUserMessageForTurn(")
require_terms(accepted_write, ("FOR UPDATE", "claim_version = %d", "status = 'processing'", "TIMESTAMPADD(SECOND, lease_seconds, updated_at) > %s", "ROLLBACK"), "Generation-fenced user message")
memory_write = function_block(conversation_repository, "public function updateMemoryForTurn(")
require_terms(memory_write, ("claim_version = %d", "status = 'processing'", "TIMESTAMPADD(SECOND, t.lease_seconds, t.updated_at) > %s", "TurnLeaseLost"), "Generation-fenced shopping memory")

# REST only binds outcomes that prove an exact disposition. Pre-claim chat auth
# and coarse limits remain unbound; recovery may bind unauthorized as unverified.
disposition_start = rest.find("private function turnDisposition(")
require(disposition_start >= 0, "REST turn disposition function is missing.")
disposition = rest[disposition_start:]
require_terms(disposition, ("'processing'", "'conflict'", "'not_found'", "'unverified'", "'rejected'"), "REST turn dispositions")
recover_boundary = disposition.find("// These failures can occur before")
require(recover_boundary > 0, "REST recovery/chat disposition boundary is missing.")
recover_disposition = disposition[:recover_boundary]
require_terms(recover_disposition, ("if ($mode === 'recover')", "'conversation_unauthorized'", "return 'unverified';"), "Recovery disposition handling")
chat_unbound = disposition[recover_boundary:]
require_terms(chat_unbound, ("'rate_limited'", "'assistant_disabled'", "'conversation_unauthorized'", "'requirements_unavailable'", "return null;"), "Pre-claim chat failures")

# Browser response reads are bounded and pending state is retired only by exact
# schema-validated turn envelopes. Recovery after boot failure renders text only.
require_terms(client, ("readBoundedJsonResponse", "validateTurnResponse", "errorConfirmsTurnDisposition", "classifyTurnError", "errorConfirmsConversationUnauthorized"), "Browser validation utilities")
require("response.json()" not in widget, "Widget bypasses the bounded JSON response reader.")
require_terms(
    widget,
    (
        "recoverPendingWithoutBoot(",
        "rejectMissingPending(",
        "renderRecoveredWithoutBoot(",
        "refreshCurrentCart(",
        "classification === 'unverified'",
        "reconcileDeleteOutcome(",
        "resetConversationView(false)",
        "errorConfirmsConversationUnauthorized(error)",
        "replyStillAuthoritative",
    ),
    "Browser chat/recovery state machine",
)
boot_snapshot = function_block(widget, "  applyBootSnapshot(response) {")
require_terms(boot_snapshot, ("sameCapability", "replyStillAuthoritative", "this.clearReply()", "this.renderHistory(messages)"), "Boot reply authority")
cart_refresh = function_block(widget, "  async refreshCurrentCart() {")
require("this.applyBootSnapshot(response)" in cart_refresh, "Cart refresh does not atomically adopt a validated boot capability.")
no_boot_render = function_block(widget, "  renderRecoveredWithoutBoot(pending, terminal, accepted) {")
require_terms(no_boot_render, ("id: 0", "products: []", "receipt: null"), "No-boot text-only recovery")
send_body = function_block(widget, "  async sendBody(body, { withoutBoot = false } = {}) {")
require_terms(send_body, ("renderRecoveredWithoutBoot", "this.renderCart(null)", "this.clearReply()"), "No-boot chat response handling")
missing_rejection = function_block(widget, "  rejectMissingPending(pending, error, { withoutBoot = false } = {}) {")
require_terms(
    missing_rejection,
    ("this.state.clearPending()", "markOptimisticRejected", "pendingRequiresImageReattach", "this.ensureBoot()"),
    "Sealed missing-turn retirement",
)
for marker, next_marker in (
    ("  async recoverPendingWithoutBoot(pending, bootError) {", "  rejectMissingPending("),
    ("  async recoverPending() {", "  async recoverFromOriginalConversation("),
    ("  async recoverFromOriginalConversation(pending) {", "  async refreshCurrentCart("),
):
    start = widget.find(marker)
    end = widget.find(next_marker, start + len(marker))
    require(start >= 0 and end > start, f"Missing browser recovery boundary: {marker}")
    block = widget[start:end]
    require("sendBody(pending.body" not in block,
            f"{marker} automatically reuses a server-sealed missing-turn ID.")

require_terms(
    browser_tests,
    (
        "run_boot_failure_absence_seal_requires_explicit_new_turn",
        "run_booted_absence_seal_requires_explicit_new_turn",
        "run_boot_failure_not_found_requires_explicit_new_turn",
        "run_pending_not_found_after_boot_requires_explicit_new_turn",
        "assert_missing_turn_requires_explicit_new_submission",
        "run_replacement_boot_adoption_clears_reply",
        "run_lost_delete_response_reconciliation",
        "delete_reconciliation_unavailable",
        "run_stale_chat_capability_reboots_cleanly",
        "run_stale_export_capability_reboots_cleanly",
    ),
    "Real Chromium lifecycle coverage",
)

# The provider boundary must serialize the exact production tool contract.
require_terms(
    provider_client,
    (
        "https://generativelanguage.googleapis.com/v1/interactions",
        "FunctionToolValidator",
        "$prepared = $this->prepareTools($tools);",
        "$prepared['argument_schemas']",
        "provider_request_rejected",
        "provider_configuration_error",
        "_wire_steps",
        "assertValid($arguments, $argumentSchemas[$name])",
        "Gemini requested an undeclared function.",
    ),
    "Gemini production transport",
)
require("generativelanguage.googleapis.com/v1beta/interactions" not in provider_client,
        "The production provider client reverted to the beta endpoint.")
require("Api-Revision" not in provider_client,
        "The production provider client contains an obsolete API revision header.")
require("'tool_choice' => $prepared['tools'] === array() ? 'none' : 'any'" in provider_client,
        "Production chat does not force a function call when tools are present.")
require("'tool_choice' => 'auto'" not in provider_client,
        "Production chat reverted to permissive automatic tool selection.")
require("Gemini did not return the function call required by the production chat contract." in provider_client,
        "Direct provider prose is not rejected at the production chat boundary.")
require_terms(
    tool_validator,
    (
        "Function parameters must be a closed object schema.",
        "GeminiSchemaProjector",
        "$this->schemaProjector->project($parameters)",
    ),
    "Function-tool wire canonicalization",
)
require_terms(
    schema_projector,
    (
        "private const WIRE_KEYWORDS",
        "private const LOCAL_ONLY_KEYWORDS",
        "'minLength'",
        "'maxLength'",
        "'pattern'",
        "'minProperties'",
        "'maxProperties'",
        "new \\stdClass()",
        "if ($keyword === 'properties')",
        "$properties->{$name} = $this->project($childSchema);",
        "private function portableEnum",
    ),
    "Portable Gemini schema projection",
)
require("schemaForWire" not in tool_validator and "schemaForWire" not in provider_client,
        "A duplicated provider wire-schema serializer bypasses the shared projector.")
require("new \\stdClass" not in tool_registry,
        "The application tool registry contains provider-specific JSON object encoding.")
require_terms(
    agent_loop,
    (
        "$wireSteps = $response['_wire_steps'] ?? null;",
        "$history[] = $wireStep;",
        "signatures are not",
        "provider identifiers were validated",
        "must be returned byte-for-byte unchanged",
        "private function validCallId",
    ),
    "Stateless provider step replay",
)
require_terms(
    provider_client,
    (
        "Function-call IDs are provider-issued opaque strings.",
        "copy them exactly",
        "private function validCallId",
    ),
    "Opaque provider function-call identifiers",
)
require_terms(
    read("tests/Unit/AgentLoopTest.php"),
    ("AgentLoop preserves provider-issued opaque function-call identifiers exactly", "fc/opaque=part+1"),
    "Opaque function-call identifier regression",
)
require_terms(
    gemini_tests,
    ("call/empty=object+1", "assert_same('call/empty=object+1'"),
    "Opaque provider-call wire regression",
)
prompt_history = function_block(prompt_factory, "public function history(")
require_terms(
    prompt_history,
    (
        "'conversation_history' => array_reverse($selected)",
        "'instruction' => 'Handle this as the shopper",
        "return array(array('type' => 'user_input'",
    ),
    "Untrusted transcript prompt boundary",
)
require("'type' => 'model_output'" not in prompt_history,
        "Persisted assistant text is still synthesized as model-prefill output.")
require("readinessCheck($this->tools->schemas())" in admin_page,
        "The administrator readiness action does not test the exact production tool bundle.")
require_terms(
    gemini_tests,
    (
        "serializes the exact production zero-argument tools with JSON object properties",
        "preserves raw empty function arguments across stateless tool rounds",
        "readiness checks the exact production tool bundle and function transport",
        "rejects malformed local function schemas before making a request",
        "rejects undeclared functions and schema-invalid function arguments",
        "rejects direct prose when production tools require a function call",
        "disables tool selection when no tools are declared",
        "projects function schemas to the portable wire subset but validates original arguments locally",
        "projects structured schemas portably and still rejects locally invalid output",
        "treats a null SDK convenience output as absent and reconstructs REST model text",
        "distinguishes location, permission, and quota rejection categories",
    ),
    "Gemini production-wire regression tests",
)
require_terms(
    schema_projector_tests,
    (
        "emits only the portable wire subset and preserves object maps",
        "does not weaken strict local validation",
        "rejects array-shaped schema maps",
    ),
    "Gemini schema projection regression tests",
)
require_terms(
    provider_flow_tests,
    (
        "End-to-end chat flow uses the exact production Gemini tool contract and stateless replay",
        "End-to-end chat flow durably presents a function-only provider protocol failure",
        "'store_policy', 'store_info', 'cart_view', 'checkout_get_url'",
        "assert_same('any', $payload->generation_config->tool_choice)",
        "assert_same(0, $productRefs->minItems)",
        "assert_same(12, $productRefs->maxItems)",
        "assert_false(str_contains($body, '\"properties\":[]'))",
        "assert_false(str_contains($body, '\"type\":\"model_output\"'))",
    ),
    "End-to-end provider chat regression",
)
require_terms(
    chat_tests,
    (
        "actionable provider categories instead of the blanket AI failure",
        "provider_request_rejected",
        "provider_configuration_error",
        "provider_access_denied",
        "provider_location_restricted",
        "provider_quota_exhausted",
    ),
    "Provider error presentation regression",
)

# Phase 1 corrective boundaries are cross-layer contracts, not implementation
# details: cache negotiation, retry timing, durable delay propagation, provider
# classification precedence, and cleanup backoff must fail closed together.
require(f"client-utils.js?ver={version}" in widget, "The browser module cache key is stale for the plugin release.")
require_terms(
    rest,
    (
        "X-YSAI-Client-Contract",
        "CLIENT_CONTRACT_VERSION = '2'",
        "retry_after_seconds",
        "Retry-After",
        "unset($payload['error']['retry_mode'], $payload['error']['retry_after_seconds'])",
    ),
    "Versioned REST retry contract",
)
require_terms(
    widget,
    (
        "'X-YSAI-Client-Contract': '2'",
        "retryAfterSeconds",
        "showDeferredRetry(",
        "retryFinalizedAsNewTurn(",
    ),
    "Delayed browser retry contract",
)
require_terms(
    storefront_widget,
    ("'retryAfter'", "'retryNewTurn'"),
    "Storefront retry copy",
)
require_terms(
    provider_client,
    (
        "MIN_ATTEMPT_TIMEOUT_SECONDS",
        "FINALIZATION_RESERVE_SECONDS",
        "retryAfterMilliseconds(",
        "complete delay plus a usable next",
        "return $response;",
        "MAX_REMOTE_REASON_NODES",
        "SPECIFIC_REMOTE_REASON_CODES",
        "remoteReasons(",
        "Unknown entries are skipped.",
    ),
    "Provider retry and classification correction",
)
require_terms(
    gemini_tests,
    (
        "never shortens Retry-After and skips a retry that cannot fit the shared deadline",
        "waits the complete Retry-After window when it fits the shared deadline",
        "does not start another transport attempt after the shared deadline is consumed",
        "gives canonical status and schema signals precedence over generic message words",
        "skips unknown structured reasons and classifies a later canonical reason",
        "scans sibling reasons before nested detail noise exhausts its safety bound",
    ),
    "Provider retry timing and precedence regressions",
)
require_terms(
    chat_tests,
    (
        "replays a rejected failed turn after inactivity",
        "exposes a new-turn retry only for a durably finalized transient provider failure",
    ),
    "Durable retry-delay regressions",
)
require_terms(
    rest_tests,
    (
        "compatible with stale 2.5.2 clients",
        "delayed new-turn action for a finalized rejected rate-limited turn",
    ),
    "Current and legacy REST retry regressions",
)
require_terms(
    client_tests,
    ("errorConfirmsFinalizedTurn only accepts a failure payload for the exact turn", "retry_after_seconds: 60", "retry_after_seconds: 86401"),
    "Browser retry-delay validation regression",
)
require_terms(
    installer,
    (
        "CLEANUP_RETRY_SECONDS",
        "CLEANUP_RETRY_OPTION",
        "CLEANUP_LOG_OPTION",
        "shouldLogCleanupFailure",
        "automaticCleanupRemediationAllowed",
        "persistOperationalOption",
        "if (!$attemptScheduling)",
    ),
    "Cleanup scheduling backoff",
)
require_terms(
    installer_tests,
    (
        "cleanup scheduling failure as degraded instead of a schema failure",
        "persistent cleanup scheduling failure at most once per retry window",
        "never uses ordinary storefront traffic as cleanup scheduling remediation",
        "keeps the storefront cleanup path read-only when cron inspection throws",
    ),
    "Cleanup backoff regressions",
)

# Logs and public/model-visible errors must not admit raw internals.
logger_block = function_block(logger, "private function sanitize(")
for blocked in ("api_key", "token", "authorization", "image", "data", "request_body", "message", "exception_message", "trace", "stack", "sql", "query"):
    require(f"'{blocked}'" in logger_block, f"Logger redaction is missing {blocked}.")

# Behavioral tests must explicitly cover the cross-layer windows.
require_terms(
    chat_tests,
    (
        "replays a fully presented completed turn after inactivity",
        "replays a fully presented accepted failure after inactivity",
        "replays a rejected failed turn after inactivity",
        "exact inactive replay does not depend on a separate presentation-status lookup",
        "reconciles an earlier completed presentation before accepting a later turn",
        "reconciles an earlier accepted failure before accepting a later turn",
        "lost user-message write response",
        "atomically seals a missing recovery identity",
        "concurrent real claim instead of sealing a false absence",
    ),
    "Chat lifecycle regression tests",
)
require_terms(lifecycle_tests, ("terminal presentation gaps", "unresolved", "assistant"), "Repository lifecycle tests")
require_terms(rest_tests, ("coarse rate limits remain unbound", "inactivity remains unbound", "durable absence seal"), "REST disposition tests")
require_terms(client_tests, ("preserves the original turn for ambiguous failures", "never creates a new idempotency key automatically", "errorConfirmsConversationUnauthorized requires the exact non-turn REST envelope"), "Browser utility recovery tests")

chat_audit = json.loads(read("build/chat-contract-audit.json"))
repo_audit = json.loads(read("build/turn-repository-audit.json"))
require(chat_audit.get("generated_for_version") == version, "The chat contract audit version is stale.")
require(repo_audit.get("generated_for_version") == version, "The repository audit version is stale.")
require(all(chat_audit.get("checks", {}).values()), "The chat contract audit contains a failed check.")
for check in (
    "production_chat_forces_function_call",
    "provider_wire_schema_portable_subset",
    "local_schema_constraints_retained",
    "terminal_empty_product_list_supported",
    "provider_protocol_failure_durable",
    "provider_error_diagnostics_specific",
    "null_output_convenience_reconstructed",
    "provider_retry_after_respected",
    "provider_deadline_strict",
    "provider_canonical_status_precedence",
    "provider_multi_reason_classification",
    "release_contract_cache_busted",
    "delayed_rate_retry",
    "cleanup_retry_throttled",
    "cleanup_storefront_read_only",
):
    require(chat_audit.get("checks", {}).get(check) is True, f"The chat contract audit is missing {check}.")
require(all(repo_audit.get("checks", {}).values()), "The repository audit contains a failed check.")
require(chat_audit.get("server_dispositions") == ["processing", "conflict", "not_found", "unverified", "rejected"], "The recorded server disposition contract is stale.")
require(chat_audit.get("unsafe_status_only_clear_patterns") == [], "The chat audit records an unsafe pending clear.")

print(f"End-to-end chat-flow static contract: passed for {version}.")
