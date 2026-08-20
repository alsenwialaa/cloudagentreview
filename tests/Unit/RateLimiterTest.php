<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Database\WpRateLimiter;

final class FakeWpdbRateLimiter
{
    public string $prefix = 'wp_';
    /** @var array<string,array{query:string,args:list<mixed>}> */
    private array $prepared = array();
    /** @var array<string,int> */
    private array $hits = array();
    /** @var list<array{query:string,args:list<mixed>}> */
    public array $executed = array();
    public int $lastInsertId = 0;
    public bool $failQuery = false;
    private int $nextPrepared = 1;

    public function prepare(string $query, mixed ...$args): string
    {
        $key = 'prepared:' . $this->nextPrepared++;
        $this->prepared[$key] = array('query' => $query, 'args' => $args);
        return $key;
    }

    public function query(string $query): int|false
    {
        if ($this->failQuery) {
            return false;
        }
        $prepared = $this->prepared[$query] ?? array('query' => $query, 'args' => array());
        $this->executed[] = $prepared;
        if (str_contains($prepared['query'], 'INSERT INTO')) {
            $hash = (string) ($prepared['args'][0] ?? '');
            $this->hits[$hash] = min(4_294_967_295, ($this->hits[$hash] ?? 0) + 1);
            $this->lastInsertId = $this->hits[$hash];
        }
        return 1;
    }

    public function get_var(string $query): string
    {
        assert_same('SELECT LAST_INSERT_ID()', $query);
        return (string) $this->lastInsertId;
    }
}

test('WpRateLimiter returns the count from its own atomic upsert', static function (): void {
    $wpdb = new FakeWpdbRateLimiter();
    $GLOBALS['wpdb'] = $wpdb;
    $limiter = new WpRateLimiter(new TestClock());

    assert_same(true, $limiter->consume('browser_ai_turns', 'network:2001:db8::/64', 2, 300));
    assert_same(true, $limiter->consume('browser_ai_turns', 'network:2001:db8::/64', 2, 300));
    assert_same(false, $limiter->consume('browser_ai_turns', 'network:2001:db8::/64', 2, 300));
    assert_count_value(3, $wpdb->executed);
    assert_contains('LAST_INSERT_ID(1)', $wpdb->executed[0]['query']);
    assert_contains('LAST_INSERT_ID(IF', $wpdb->executed[0]['query']);
    assert_same(64, strlen((string) $wpdb->executed[0]['args'][0]));
});

test('WpRateLimiter rejects malformed bucket inputs and fails closed on database errors', static function (): void {
    $wpdb = new FakeWpdbRateLimiter();
    $GLOBALS['wpdb'] = $wpdb;
    $limiter = new WpRateLimiter(new TestClock());

    foreach (array(
        static fn (): bool => $limiter->consume('', 'id', 1, 60),
        static fn (): bool => $limiter->consume('Bad Scope', 'id', 1, 60),
        static fn (): bool => $limiter->consume('scope', '', 1, 60),
        static fn (): bool => $limiter->consume('scope', str_repeat('x', 513), 1, 60),
        static fn (): bool => $limiter->consume('scope', 'id', 0, 60),
        static fn (): bool => $limiter->consume('scope', 'id', 1, 2_592_001),
    ) as $operation) {
        assert_throws(InvalidArgumentException::class, $operation);
    }

    $wpdb->failQuery = true;
    assert_same(false, $limiter->consume('scope', 'id', 1, 60));
});
