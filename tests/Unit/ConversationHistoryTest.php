<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Database\WpConversationRepository;
use YassinStore\AiAssistant\Infrastructure\Security\TokenHasher;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class FakeWpdbConversationHistory
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var list<array<string,mixed>> */
    public array $metadataRows = array();
    /** @var list<array<string,mixed>> */
    public array $messageRows = array();
    /** @var list<string> */
    public array $queries = array();

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

    /** @return list<array<string,mixed>> */
    public function get_results(string $query, string $format): array
    {
        assert_same(ARRAY_A, $format);
        $this->queries[] = $query;
        if (str_contains($query, 'SELECT id,turn_id,OCTET_LENGTH(content) AS content_bytes')) {
            return $this->metadataRows;
        }
        if (str_contains($query, 'CASE WHEN OCTET_LENGTH(content)')) {
            return $this->messageRows;
        }
        throw new RuntimeException('Unexpected history query: ' . $query);
    }
}

/** @param callable():void $operation */
function with_history_wpdb(FakeWpdbConversationHistory $database, callable $operation): void
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

function history_payload_json(int $targetBytes): string
{
    $first = str_repeat('a', 1_047_000);
    $empty = json_encode(array('a' => $first, 'b' => ''), JSON_THROW_ON_ERROR);
    $secondLength = $targetBytes - strlen($empty);
    if ($secondLength < 1 || $secondLength > 1_048_576) {
        throw new RuntimeException('The requested test payload size is unsupported.');
    }
    $json = json_encode(
        array('a' => $first, 'b' => str_repeat('b', $secondLength)),
        JSON_THROW_ON_ERROR
    );
    if (!is_string($json) || strlen($json) !== $targetBytes) {
        throw new RuntimeException('Unable to construct the exact test payload.');
    }
    return $json;
}

/** @return array<string,mixed> */
function history_message_row(int $id, int $turnId, string $content, string $payload): array
{
    return array(
        'id' => (string) $id,
        'conversation_id' => '00000000-0000-4000-8000-000000000001',
        'turn_id' => (string) $turnId,
        'role' => $id % 2 === 0 ? 'assistant' : 'user',
        'content' => $content,
        'content_bytes' => (string) strlen($content),
        'payload_json' => $payload,
        'payload_bytes' => (string) strlen($payload),
        'created_at' => '2026-08-14 12:00:00',
    );
}

test('WpConversationRepository selects a stable newest history suffix within the aggregate byte budget', static function (): void {
    $content = str_repeat('م', 16_384); // 32,768 UTF-8 bytes.
    $payload = history_payload_json(2_095_000);
    $database = new FakeWpdbConversationHistory();
    foreach (array(10, 9, 8, 7, 6) as $id) {
        $database->metadataRows[] = array(
            'id' => (string) $id,
            'turn_id' => (string) ($id + 10),
            'content_bytes' => (string) strlen($content),
            'payload_bytes' => (string) strlen($payload),
        );
    }
    foreach (array(10, 9, 8) as $id) {
        $database->messageRows[] = history_message_row($id, $id + 10, $content, $payload);
    }

    with_history_wpdb($database, static function () use ($database): void {
        $messages = (new WpConversationRepository(new TestClock(), new TokenHasher()))->messages(
            '00000000-0000-4000-8000-000000000001',
            80,
            99
        );
        assert_same(array(8, 9, 10), array_column($messages, 'id'));
        assert_count_value(2, $database->queries);
        assert_contains('turn_id < 99', $database->queries[0]);
        assert_contains('ORDER BY id DESC LIMIT 80', $database->queries[0]);
        assert_contains('turn_id < 99', $database->queries[1]);
        assert_contains('id >= 8 AND id <= 10', $database->queries[1]);
        assert_contains('ORDER BY id DESC LIMIT 3', $database->queries[1]);
    });
});

test('WpConversationRepository rejects oversized or unordered history metadata before loading payloads', static function (): void {
    foreach (array(
        array(
            array('id' => '2', 'turn_id' => '2', 'content_bytes' => '1', 'payload_bytes' => '2097153'),
        ),
        array(
            array('id' => '2', 'turn_id' => '2', 'content_bytes' => '1', 'payload_bytes' => '2'),
            array('id' => '2', 'turn_id' => '1', 'content_bytes' => '1', 'payload_bytes' => '2'),
        ),
    ) as $metadata) {
        $database = new FakeWpdbConversationHistory();
        $database->metadataRows = $metadata;
        with_history_wpdb($database, static function () use ($database): void {
            assert_throws(
                RuntimeException::class,
                static fn (): array => (new WpConversationRepository(new TestClock(), new TokenHasher()))->messages(
                    '00000000-0000-4000-8000-000000000001'
                ),
                'history metadata'
            );
            assert_count_value(1, $database->queries);
        });
    }
});

test('WpConversationRepository rejects history rows that drift after the metadata window is fixed', static function (): void {
    $database = new FakeWpdbConversationHistory();
    $database->metadataRows = array(array(
        'id' => '5',
        'turn_id' => '7',
        'content_bytes' => '4',
        'payload_bytes' => '2',
    ));
    $database->messageRows = array(history_message_row(5, 7, 'test', '{}'));
    $database->messageRows[0]['payload_bytes'] = '3';

    with_history_wpdb($database, static function () use ($database): void {
        assert_throws(
            RuntimeException::class,
            static fn (): array => (new WpConversationRepository(new TestClock(), new TokenHasher()))->messages(
                '00000000-0000-4000-8000-000000000001'
            ),
            'changed while'
        );
        assert_count_value(2, $database->queries);
    });
});

test('WpConversationRepository returns an empty history without issuing a payload query', static function (): void {
    $database = new FakeWpdbConversationHistory();
    with_history_wpdb($database, static function () use ($database): void {
        assert_same(array(), (new WpConversationRepository(new TestClock(), new TokenHasher()))->messages(
            '00000000-0000-4000-8000-000000000001'
        ));
        assert_count_value(1, $database->queries);
    });
});
