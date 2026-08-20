<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Contract\ConversationBusy;
use YassinStore\AiAssistant\Application\Contract\ConversationUnavailable;
use YassinStore\AiAssistant\Infrastructure\Database\WpConversationRepository;
use YassinStore\AiAssistant\Infrastructure\Database\WpTurnRepository;
use YassinStore\AiAssistant\Infrastructure\Security\TokenHasher;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class FakeWpdbConversationLifecycle
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $insert_id = 1;
    public ?string $lockedConversationId = '00000000-0000-4000-8000-000000000001';
    public int|string|null $exactTurnId = null;
    /** @var array<string,mixed>|null */
    public ?array $unresolvedTurnRow = null;
    /** @var list<array<string,mixed>> */
    public array $processingLeaseRows = array();
    /** @var list<array<string,mixed>> */
    public array $blockingRecoveryRows = array();
    public int|false $insertResult = 1;
    public int|false $markDeletingResult = 1;
    public int|false $turnWriteResult = 1;
    /** @var list<string> */
    public array $events = array();
    /** @var list<string> */
    public array $turnWriteQueries = array();
    /** @var array<string,mixed>|null */
    public ?array $turnRow = null;

    public function prepare(string $query, mixed ...$arguments): string
    {
        foreach ($arguments as $argument) {
            $replacement = is_int($argument)
                ? (string) $argument
                : "'" . str_replace("'", "''", (string) $argument) . "'";
            $query = preg_replace('/%(?:s|d)/', $replacement, $query, 1) ?? $query;
        }
        return $query;
    }

    public function query(string $query): int|bool
    {
        $normalized = strtoupper(trim($query));
        if ($normalized === 'START TRANSACTION') {
            $this->events[] = 'begin';
            return true;
        }
        if ($normalized === 'COMMIT') {
            $this->events[] = 'commit';
            return true;
        }
        if ($normalized === 'ROLLBACK') {
            $this->events[] = 'rollback';
            return true;
        }
        if (str_starts_with($normalized, 'INSERT IGNORE INTO')) {
            $this->events[] = 'insert_turn';
            assert_contains("lifecycle_state = 'active'", $query);
            assert_contains('expires_at >', $query);
            return $this->insertResult;
        }
        if (str_starts_with($normalized, 'UPDATE WP_YSAI_V2_TURNS T')) {
            $this->events[] = 'write_turn';
            $this->turnWriteQueries[] = $query;
            assert_contains('INNER JOIN wp_ysai_v2_conversations c', $query);
            assert_contains("c.lifecycle_state = 'active'", $query);
            return $this->turnWriteResult;
        }
        throw new RuntimeException('Unexpected lifecycle query: ' . $query);
    }

    public function get_var(string $query): mixed
    {
        if (str_contains($query, 'FROM wp_ysai_v2_conversations') && str_contains($query, 'FOR UPDATE')) {
            $this->events[] = 'lock_conversation';
            assert_contains("lifecycle_state = 'active'", $query);
            assert_contains('expires_at >', $query);
            return $this->lockedConversationId;
        }
        if (str_contains($query, 'FROM wp_ysai_v2_turns')
            && str_contains($query, 'client_turn_id')
            && !str_contains($query, 'FOR UPDATE')) {
            $this->events[] = 'inspect_exact_turn';
            return $this->exactTurnId;
        }
        if (str_contains($query, 'SELECT lifecycle_state')) {
            $this->events[] = 'read_lifecycle';
            return $this->lockedConversationId === null ? null : 'active';
        }
        throw new RuntimeException('Unexpected lifecycle scalar query: ' . $query);
    }

    /** @return list<mixed> */
    public function get_col(string $query): array
    {
        throw new RuntimeException('Unexpected lifecycle column query: ' . $query);
    }

    public function get_row(string $query, string $format): ?array
    {
        assert_same(ARRAY_A, $format);
        if (str_contains($query, 'SELECT t.client_turn_id,t.status')) {
            $this->events[] = 'lock_unresolved_turns';
            assert_contains('FOR UPDATE', $query);
            assert_contains("t.status = 'processing'", $query);
            assert_contains("t.status = 'completed'", $query);
            assert_contains("t.status = 'failed'", $query);
            assert_contains("role = 'assistant'", $query);
            return $this->unresolvedTurnRow;
        }
        $this->events[] = 'read_turn';
        return $this->turnRow;
    }

    /** @return list<array<string,mixed>> */
    public function get_results(string $query, string $format): array
    {
        assert_same(ARRAY_A, $format);
        if (str_contains($query, "status = 'processing'") && str_contains($query, 'lease_seconds')) {
            if (str_contains($query, 'FOR UPDATE')) {
                $this->events[] = 'lock_processing_leases';
                return $this->processingLeaseRows;
            }
            $this->events[] = 'inspect_blocking_turns';
            assert_contains("lifecycle_state = 'active'", $query);
            assert_contains('expires_at >', $query);
            assert_contains('client_turn_id <>', $query);
            return $this->blockingRecoveryRows;
        }
        throw new RuntimeException('Unexpected lifecycle row query: ' . $query);
    }

    public function update(string $table, array $data, array $where, array $format, array $whereFormat): int|false
    {
        if ($table === 'wp_ysai_v2_conversations' && ($data['lifecycle_state'] ?? null) === 'deleting') {
            $this->events[] = 'mark_deleting';
            return $this->markDeletingResult;
        }
        throw new RuntimeException('Unexpected lifecycle update: ' . $table);
    }

    public function delete(string $table, array $where, array $format): int|false
    {
        $this->events[] = 'delete:' . $table;
        return 1;
    }
}

/** @param callable():void $operation */
function with_lifecycle_wpdb(FakeWpdbConversationLifecycle $database, callable $operation): void
{
    $hadPrevious = array_key_exists('wpdb', $GLOBALS);
    $previous = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = $database;
    try {
        $operation();
    } finally {
        if ($hadPrevious) {
            $GLOBALS['wpdb'] = $previous;
        } else {
            unset($GLOBALS['wpdb']);
        }
    }
}

/** @return array<string,mixed> */
function lifecycle_turn_row(string $clientTurnId, string $requestHash): array
{
    return array(
        'id' => '1',
        'conversation_id' => '00000000-0000-4000-8000-000000000001',
        'client_turn_id' => $clientTurnId,
        'request_hash' => $requestHash,
        'status' => 'processing',
        'claim_version' => '1',
        'lease_seconds' => '120',
        'response_json' => '',
        'response_bytes' => '0',
        'error_code' => null,
        'created_at' => '2026-08-14 12:00:00',
        'updated_at' => '2026-08-14 12:00:00',
    );
}

test('WpTurnRepository serializes a new claim with conversation lifecycle and other active turns', static function (): void {
    $conversationId = '00000000-0000-4000-8000-000000000001';
    $clientTurnId = 'turn_lifecycle_lock_001';
    $requestHash = hash('sha256', 'lifecycle request');
    $database = new FakeWpdbConversationLifecycle();
    $database->turnRow = lifecycle_turn_row($clientTurnId, $requestHash);

    with_lifecycle_wpdb($database, static function () use ($database, $conversationId, $clientTurnId, $requestHash): void {
        $claim = (new WpTurnRepository(new TestClock()))->claim(
            $conversationId,
            $clientTurnId,
            $requestHash,
            120
        );
        assert_same('new', $claim['state']);
        assert_same(array(
            'begin',
            'lock_conversation',
            'inspect_exact_turn',
            'lock_unresolved_turns',
            'insert_turn',
            'read_turn',
            'commit',
        ), $database->events);
    });
});

test('WpTurnRepository seals a missing recovery identity under the claim conversation lock', static function (): void {
    $conversationId = '00000000-0000-4000-8000-000000000001';
    $clientTurnId = 'turn_missing_sql_seal_001';
    $response = array(
        'ok' => false,
        'conversation_id' => $conversationId,
        'client_turn_id' => $clientTurnId,
        'error' => array(
            'code' => 'turn_not_found',
            'message' => 'The request was not accepted.',
            'retryable' => false,
        ),
        '_http_status' => 404,
        'turn_finalized' => true,
        'request_accepted' => false,
        'kind' => 'safe_failure',
    );
    $requestHash = hash('sha256', "ysai-missing-turn-v1\0" . $conversationId . "\0" . $clientTurnId);
    $row = lifecycle_turn_row($clientTurnId, $requestHash);
    $row['status'] = 'failed';
    $row['response_json'] = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $row['response_bytes'] = (string) strlen((string) $row['response_json']);
    $row['error_code'] = 'turn_not_found';
    $database = new FakeWpdbConversationLifecycle();
    $database->turnRow = $row;

    with_lifecycle_wpdb($database, static function () use ($database, $conversationId, $clientTurnId, $response): void {
        $sealed = (new WpTurnRepository(new TestClock()))->sealMissingAsRejected(
            $conversationId,
            $clientTurnId,
            'turn_not_found',
            $response,
            120
        );
        assert_same('failed', $sealed['status']);
        assert_same(false, $sealed['response']['request_accepted']);
        assert_same(array('begin', 'lock_conversation', 'insert_turn', 'read_turn', 'commit'), $database->events);
    });
});

test('WpTurnRepository returns a concurrent real claim instead of overwriting it with an absence seal', static function (): void {
    $conversationId = '00000000-0000-4000-8000-000000000001';
    $clientTurnId = 'turn_missing_sql_race_001';
    $database = new FakeWpdbConversationLifecycle();
    $database->insertResult = 0;
    $database->turnRow = lifecycle_turn_row($clientTurnId, hash('sha256', 'real delayed request'));
    $response = array(
        'ok' => false,
        'conversation_id' => $conversationId,
        'client_turn_id' => $clientTurnId,
        'error' => array('code' => 'turn_not_found', 'message' => 'Not accepted.', 'retryable' => false),
        '_http_status' => 404,
        'turn_finalized' => true,
        'request_accepted' => false,
        'kind' => 'safe_failure',
    );

    with_lifecycle_wpdb($database, static function () use ($database, $conversationId, $clientTurnId, $response): void {
        $existing = (new WpTurnRepository(new TestClock()))->sealMissingAsRejected(
            $conversationId,
            $clientTurnId,
            'turn_not_found',
            $response,
            120
        );
        assert_same('processing', $existing['status']);
        assert_same(array(), $existing['response']);
        assert_same(array('begin', 'lock_conversation', 'insert_turn', 'read_turn', 'commit'), $database->events);
    });
});

test('WpTurnRepository refuses a second processing turn before provider work begins', static function (): void {
    $database = new FakeWpdbConversationLifecycle();
    $database->unresolvedTurnRow = array('client_turn_id' => 'turn_already_processing_01', 'status' => 'processing');

    with_lifecycle_wpdb($database, static function () use ($database): void {
        assert_throws(ConversationBusy::class, static fn (): array => (new WpTurnRepository(new TestClock()))->claim(
            '00000000-0000-4000-8000-000000000001',
            'turn_new_request_000001',
            hash('sha256', 'new request'),
            120
        ));
        assert_same(array('begin', 'lock_conversation', 'inspect_exact_turn', 'lock_unresolved_turns', 'rollback'), $database->events);
    });
});

test('WpTurnRepository refuses new work while an earlier terminal presentation is missing', static function (): void {
    foreach (array('completed', 'failed') as $status) {
        $database = new FakeWpdbConversationLifecycle();
        $database->unresolvedTurnRow = array(
            'client_turn_id' => 'turn_terminal_pending_' . $status,
            'status' => $status,
        );

        with_lifecycle_wpdb($database, static function () use ($database, $status): void {
            assert_throws(ConversationBusy::class, static fn (): array => (new WpTurnRepository(new TestClock()))->claim(
                '00000000-0000-4000-8000-000000000001',
                'turn_new_after_terminal_' . $status,
                hash('sha256', 'new after ' . $status),
                120
            ));
            assert_same(array('begin', 'lock_conversation', 'inspect_exact_turn', 'lock_unresolved_turns', 'rollback'), $database->events);
        });
    }
});

test('WpTurnRepository replays an exact terminal turn even while another turn is processing', static function (): void {
    $conversationId = '00000000-0000-4000-8000-000000000001';
    $clientTurnId = 'turn_terminal_replay_0001';
    $requestHash = hash('sha256', 'terminal replay');
    $response = array(
        'ok' => false,
        'conversation_id' => $conversationId,
        'client_turn_id' => $clientTurnId,
        'turn_id' => 1,
        'turn_finalized' => true,
        'error' => array(
            'code' => 'rate_limited',
            'message' => 'Stored failure',
            'retryable' => true,
        ),
        '_http_status' => 429,
    );

    $database = new FakeWpdbConversationLifecycle();
    $database->exactTurnId = '1';
    $database->insertResult = 0;
    $database->turnRow = lifecycle_turn_row($clientTurnId, $requestHash);
    $database->turnRow['status'] = 'failed';
    $database->turnRow['error_code'] = 'rate_limited';
    $database->turnRow['response_json'] = json_encode($response, JSON_THROW_ON_ERROR);
    $database->turnRow['response_bytes'] = (string) strlen((string) $database->turnRow['response_json']);

    with_lifecycle_wpdb($database, static function () use ($database, $conversationId, $clientTurnId, $requestHash, $response): void {
        $claim = (new WpTurnRepository(new TestClock()))->claim(
            $conversationId,
            $clientTurnId,
            $requestHash,
            120
        );
        assert_same('failed', $claim['state']);
        assert_same($response, $claim['response']);
        assert_false(in_array('lock_unresolved_turns', $database->events, true));
        assert_same(array(
            'begin',
            'lock_conversation',
            'inspect_exact_turn',
            'insert_turn',
            'read_turn',
            'commit',
        ), $database->events);
    });
});

test('WpTurnRepository does not reclaim an exact stale turn beside another processing owner', static function (): void {
    $conversationId = '00000000-0000-4000-8000-000000000001';
    $clientTurnId = 'turn_stale_exact_000001';
    $requestHash = hash('sha256', 'stale exact replay');
    $database = new FakeWpdbConversationLifecycle();
    $database->exactTurnId = '1';
    $database->insertResult = 0;
    $database->unresolvedTurnRow = array('client_turn_id' => 'turn_other_processing_01', 'status' => 'processing');
    $database->turnRow = lifecycle_turn_row($clientTurnId, $requestHash);
    $database->turnRow['updated_at'] = '2026-08-14 11:57:59';

    with_lifecycle_wpdb($database, static function () use ($database, $conversationId, $clientTurnId, $requestHash): void {
        assert_throws(ConversationBusy::class, static fn (): array => (new WpTurnRepository(new TestClock()))->claim(
            $conversationId,
            $clientTurnId,
            $requestHash,
            120
        ));
        assert_same(array(
            'begin',
            'lock_conversation',
            'inspect_exact_turn',
            'insert_turn',
            'read_turn',
            'lock_unresolved_turns',
            'rollback',
        ), $database->events);
    });
});

test('WpTurnRepository rolls back when the conversation is expired or unavailable', static function (): void {
    $database = new FakeWpdbConversationLifecycle();
    $database->lockedConversationId = null;

    with_lifecycle_wpdb($database, static function () use ($database): void {
        assert_throws(ConversationUnavailable::class, static fn (): array => (new WpTurnRepository(new TestClock()))->claim(
            '00000000-0000-4000-8000-000000000001',
            'turn_missing_conversation1',
            hash('sha256', 'missing'),
            120
        ));
        assert_same(array('begin', 'lock_conversation', 'rollback'), $database->events);
    });
});

test('WpTurnRepository returns a fresh checkpoint as a safe recovery candidate', static function (): void {
    $database = new FakeWpdbConversationLifecycle();
    $row = lifecycle_turn_row('turn_checkpoint_blocker_01', hash('sha256', 'checkpoint blocker'));
    $response = array(
        'ok' => true,
        'message' => 'Stored response',
        '_http_status' => 200,
    );
    $row['response_json'] = json_encode($response, JSON_THROW_ON_ERROR);
    $row['response_bytes'] = (string) strlen((string) $row['response_json']);
    $database->blockingRecoveryRows = array($row);

    with_lifecycle_wpdb($database, static function () use ($database, $response): void {
        $candidate = (new WpTurnRepository(new TestClock()))->blockingRecoveryCandidate(
            '00000000-0000-4000-8000-000000000001',
            'turn_current_request_0001'
        );
        assert_true(is_array($candidate));
        assert_same('turn_checkpoint_blocker_01', $candidate['client_turn_id']);
        assert_same($response, $candidate['response']);
        assert_same(array('inspect_blocking_turns'), $database->events);
    });
});

test('WpTurnRepository returns terminal presentation gaps as recovery candidates', static function (): void {
    foreach (array('completed', 'failed') as $status) {
        $database = new FakeWpdbConversationLifecycle();
        $row = lifecycle_turn_row('turn_terminal_blocker_' . $status, hash('sha256', 'terminal blocker ' . $status));
        $row['status'] = $status;
        $row['error_code'] = $status === 'failed' ? 'request_failed' : null;
        $response = $status === 'completed'
            ? array('ok' => true, 'message' => 'Stored success', '_http_status' => 200)
            : array(
                'ok' => false,
                'request_accepted' => true,
                'error' => array('code' => 'request_failed', 'message' => 'Stored failure', 'retryable' => true),
                '_http_status' => 500,
            );
        $row['response_json'] = json_encode($response, JSON_THROW_ON_ERROR);
        $row['response_bytes'] = (string) strlen((string) $row['response_json']);
        $database->blockingRecoveryRows = array($row);

        with_lifecycle_wpdb($database, static function () use ($status, $response): void {
            $candidate = (new WpTurnRepository(new TestClock()))->blockingRecoveryCandidate(
                '00000000-0000-4000-8000-000000000001',
                'turn_current_after_terminal'
            );
            assert_true(is_array($candidate));
            assert_same($status, $candidate['status']);
            assert_same($response, $candidate['response']);
        });
    }
});

test('WpTurnRepository never resolves another row while a fresh uncheckpointed worker exists', static function (): void {
    $database = new FakeWpdbConversationLifecycle();
    $checkpoint = lifecycle_turn_row('turn_checkpoint_blocker_02', hash('sha256', 'checkpoint blocker two'));
    $checkpoint['response_json'] = '{"ok":true}';
    $checkpoint['response_bytes'] = (string) strlen((string) $checkpoint['response_json']);
    $fresh = lifecycle_turn_row('turn_live_blocker_000001', hash('sha256', 'live blocker'));
    $fresh['id'] = '2';
    $fresh['updated_at'] = '2026-08-14 11:59:30';
    $database->blockingRecoveryRows = array($checkpoint, $fresh);

    with_lifecycle_wpdb($database, static function (): void {
        $candidate = (new WpTurnRepository(new TestClock()))->blockingRecoveryCandidate(
            '00000000-0000-4000-8000-000000000001',
            'turn_current_request_0002'
        );
        assert_same(null, $candidate);
    });
});

test('WpTurnRepository returns an uncheckpointed blocker only after its persisted lease expires', static function (): void {
    $database = new FakeWpdbConversationLifecycle();
    $stale = lifecycle_turn_row('turn_expired_blocker_0001', hash('sha256', 'expired blocker'));
    $stale['updated_at'] = '2026-08-14 11:57:59';
    $database->blockingRecoveryRows = array($stale);

    with_lifecycle_wpdb($database, static function (): void {
        $candidate = (new WpTurnRepository(new TestClock()))->blockingRecoveryCandidate(
            '00000000-0000-4000-8000-000000000001',
            'turn_current_request_0003'
        );
        assert_true(is_array($candidate));
        assert_same('turn_expired_blocker_0001', $candidate['client_turn_id']);
        assert_same(array(), $candidate['response']);
    });
});

test('WpConversationRepository blocks deletion while a processing lease is fresh', static function (): void {
    $database = new FakeWpdbConversationLifecycle();
    $database->processingLeaseRows = array(array(
        'id' => '7',
        'lease_seconds' => '120',
        'updated_at' => '2026-08-14 11:59:00',
    ));

    with_lifecycle_wpdb($database, static function () use ($database): void {
        $repository = new WpConversationRepository(new TestClock(), new TokenHasher());
        assert_throws(ConversationBusy::class, static fn (): null => $repository->delete(
            '00000000-0000-4000-8000-000000000001'
        ));
        assert_same(array('begin', 'mark_deleting', 'lock_processing_leases', 'rollback'), $database->events);
    });
});

test('WpConversationRepository fences an abandoned processing turn and completes deletion', static function (): void {
    $database = new FakeWpdbConversationLifecycle();
    $database->processingLeaseRows = array(array(
        'id' => '7',
        'lease_seconds' => '120',
        'updated_at' => '2026-08-14 11:57:59',
    ));

    with_lifecycle_wpdb($database, static function () use ($database): void {
        (new WpConversationRepository(new TestClock(), new TokenHasher()))->delete(
            '00000000-0000-4000-8000-000000000001'
        );
        assert_same(array(
            'begin',
            'mark_deleting',
            'lock_processing_leases',
            'delete:wp_ysai_v2_messages',
            'delete:wp_ysai_v2_turns',
            'delete:wp_ysai_v2_conversations',
            'commit',
        ), $database->events);
    });
});

test('WpTurnRepository SQL writes bind ownership to active lifecycle and persisted lease freshness', static function (): void {
    $database = new FakeWpdbConversationLifecycle();
    with_lifecycle_wpdb($database, static function () use ($database): void {
        $repository = new WpTurnRepository(new TestClock());
        $repository->heartbeat(1, 2);
        $repository->checkpoint(1, 2, array('ok' => true, 'message' => 'checkpoint'));

        assert_count_value(2, $database->turnWriteQueries);
        $heartbeat = $database->turnWriteQueries[0];
        assert_contains('t.claim_version = 2', $heartbeat);
        assert_contains("t.status = 'processing'", $heartbeat);
        assert_contains('TIMESTAMPADD(SECOND, t.lease_seconds, t.updated_at)', $heartbeat);

        $checkpoint = $database->turnWriteQueries[1];
        assert_contains('t.claim_version = 2', $checkpoint);
        assert_contains("t.status = 'processing'", $checkpoint);
        assert_contains("(t.response_json IS NULL OR t.response_json = '')", $checkpoint);
        assert_contains('TIMESTAMPADD(SECOND, t.lease_seconds, t.updated_at)', $checkpoint);
    });
});

test('WpTurnRepository SQL rejects expired direct completion but finalizes an immutable checkpoint', static function (): void {
    $stale = new FakeWpdbConversationLifecycle();
    $stale->turnRow = lifecycle_turn_row('turn_expired_completion_01', hash('sha256', 'expired completion'));
    $stale->turnRow['updated_at'] = '2026-08-14 11:57:59';

    with_lifecycle_wpdb($stale, static function () use ($stale): void {
        assert_throws(
            YassinStore\AiAssistant\Application\Contract\TurnLeaseLost::class,
            static fn (): null => (new WpTurnRepository(new TestClock()))->complete(1, 1, array('ok' => true)),
            'expired'
        );
        assert_count_value(0, $stale->turnWriteQueries);
    });

    $checkpointed = new FakeWpdbConversationLifecycle();
    $checkpoint = array(
        'ok' => true,
        'conversation_id' => '00000000-0000-4000-8000-000000000001',
        'client_turn_id' => 'turn_checkpoint_sql_0001',
        'turn_id' => 1,
        'kind' => 'answer',
        'message' => 'Stored result',
        'products' => array(),
        'cart' => null,
        'receipt' => null,
        '_message_payload' => array('kind' => 'answer'),
        '_http_status' => 200,
    );
    $checkpointed->turnRow = lifecycle_turn_row(
        'turn_checkpoint_sql_0001',
        hash('sha256', 'checkpoint sql')
    );
    $checkpointed->turnRow['updated_at'] = '2026-08-14 11:57:59';
    $checkpointed->turnRow['response_json'] = json_encode($checkpoint, JSON_THROW_ON_ERROR);
    $checkpointed->turnRow['response_bytes'] = (string) strlen((string) $checkpointed->turnRow['response_json']);

    with_lifecycle_wpdb($checkpointed, static function () use ($checkpointed, $checkpoint): void {
        $final = $checkpoint;
        $final['message_id'] = 42;
        (new WpTurnRepository(new TestClock()))->complete(1, 1, $final);

        assert_count_value(1, $checkpointed->turnWriteQueries);
        $query = $checkpointed->turnWriteQueries[0];
        assert_contains("t.status = 'processing'", $query);
        assert_contains('BINARY COALESCE(t.response_json', $query);
        assert_false(str_contains($query, 'TIMESTAMPADD(SECOND, t.lease_seconds, t.updated_at)'));
    });

    $mutated = new FakeWpdbConversationLifecycle();
    $mutated->turnRow = $checkpointed->turnRow;
    with_lifecycle_wpdb($mutated, static function () use ($mutated, $checkpoint): void {
        $changed = $checkpoint;
        $changed['message'] = 'Changed result';
        $changed['message_id'] = 43;
        assert_throws(
            YassinStore\AiAssistant\Application\Contract\TurnLeaseLost::class,
            static fn (): null => (new WpTurnRepository(new TestClock()))->complete(1, 1, $changed),
            'cannot be replaced'
        );
        assert_count_value(0, $mutated->turnWriteQueries);
    });
});

