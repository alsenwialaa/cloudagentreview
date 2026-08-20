<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Support/Fakes.php';

$files = glob(__DIR__ . '/Unit/*Test.php') ?: array();
sort($files);
foreach ($files as $file) {
    require $file;
}

$passed = 0;
$failed = 0;
$selected = 0;
$started = microtime(true);
$filter = getenv('YSAI_TEST_PATTERN');
$filter = is_string($filter) ? trim($filter) : '';
$expression = $filter === '' ? null : '~' . str_replace('~', '\\~', $filter) . '~iu';
if ($expression !== null && @preg_match($expression, '') === false) {
    fwrite(STDERR, "YSAI_TEST_PATTERN is not a valid regular expression.\n");
    exit(2);
}

foreach ($GLOBALS['ysai_test_cases'] as [$name, $case]) {
    if ($expression !== null && preg_match($expression, $name) !== 1) {
        continue;
    }
    ++$selected;
    try {
        $case();
        ++$passed;
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL  {$name}\n      {$error->getMessage()}\n");
    }
}

$duration = number_format(microtime(true) - $started, 3);
if ($selected === 0) {
    fwrite(STDERR, "No tests matched YSAI_TEST_PATTERN.\n");
    exit(2);
}
fwrite(STDOUT, "\n{$passed} passed, {$failed} failed in {$duration}s");
if ($filter !== '') {
    fwrite(STDOUT, " ({$selected} selected)");
}
fwrite(STDOUT, "\n");
exit($failed === 0 ? 0 : 1);
