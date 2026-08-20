<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This contract validator is CLI-only.\n");
    exit(2);
}

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '';
if (!in_array($mode, array('structured', 'function'), true) || $file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php gemini-v1-contract.php <structured|function> <response.json>\n");
    exit(2);
}

$size = filesize($file);
if (!is_int($size) || $size < 2 || $size > 2_097_152) {
    fwrite(STDERR, "Gemini contract response size is invalid.\n");
    exit(1);
}
$body = file_get_contents($file);
if (!is_string($body)) {
    fwrite(STDERR, "Unable to read Gemini contract response.\n");
    exit(1);
}

try {
    $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    fwrite(STDERR, "Gemini contract response is not valid JSON: {$error->getMessage()}\n");
    exit(1);
}
if (!is_array($decoded) || array_is_list($decoded)) {
    fwrite(STDERR, "Gemini contract response must be a JSON object.\n");
    exit(1);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$outputText = static function (array $interaction) use ($fail): string {
    if (array_key_exists('output_text', $interaction)) {
        if (!is_string($interaction['output_text'])) {
            $fail('Stable v1 interaction returned malformed output_text.');
        }
        return trim($interaction['output_text']);
    }

    $steps = $interaction['steps'] ?? null;
    if (!is_array($steps) || !array_is_list($steps) || count($steps) > 100) {
        $fail('Stable v1 interaction returned an invalid step sequence.');
    }
    $parts = array();
    foreach ($steps as $step) {
        if (!is_array($step) || ($step['type'] ?? null) !== 'model_output') {
            continue;
        }
        $content = $step['content'] ?? null;
        if (!is_array($content) || !array_is_list($content) || count($content) > 100) {
            $fail('Stable v1 model output returned invalid content.');
        }
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }
    }
    return trim(implode("\n", $parts));
};

if ($mode === 'structured') {
    if (($decoded['status'] ?? null) !== 'completed') {
        $fail('Stable v1 structured interaction did not complete.');
    }
    $output = $outputText($decoded);
    if ($output === '') {
        $fail('Stable v1 structured interaction omitted text output.');
    }
    try {
        $value = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $fail('Stable v1 structured output_text is not valid JSON.');
    }
    if (!is_array($value) || array_is_list($value) || count($value) !== 2) {
        $fail('Stable v1 structured output did not satisfy the exact object contract.');
    }
    ksort($value, SORT_STRING);
    $expected = array('contract' => 'stable-v1', 'ready' => true);
    ksort($expected, SORT_STRING);
    if ($value !== $expected) {
        $fail('Stable v1 structured output did not satisfy the exact contract.');
    }
    fwrite(STDOUT, "Gemini stable v1 structured-output contract: passed.\n");
    exit(0);
}

if (($decoded['status'] ?? null) !== 'requires_action') {
    $fail('Stable v1 function interaction did not require action.');
}
$steps = $decoded['steps'] ?? null;
if (!is_array($steps) || !array_is_list($steps) || count($steps) < 1 || count($steps) > 20) {
    $fail('Stable v1 function interaction returned an invalid step sequence.');
}
$calls = array_values(array_filter(
    $steps,
    static fn (mixed $step): bool => is_array($step) && ($step['type'] ?? null) === 'function_call'
));
if (count($calls) !== 1) {
    $fail('Stable v1 function interaction did not return exactly one function call.');
}
$call = $calls[0];
if (($call['name'] ?? null) !== 'contract_echo'
    || !is_string($call['id'] ?? null)
    || $call['id'] === ''
    || strlen($call['id']) > 512
    || preg_match('//u', $call['id']) !== 1
    || preg_match('/[\x00-\x1F\x7F]/', $call['id']) === 1
    || ($call['arguments'] ?? null) !== array('value' => 'stable-v1')) {
    $fail('Stable v1 function call did not satisfy the exact contract.');
}
fwrite(STDOUT, "Gemini stable v1 function-calling contract: passed.\n");
