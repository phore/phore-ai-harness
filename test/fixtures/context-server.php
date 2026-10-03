<?php

declare(strict_types=1);

// Local-only Responses fixture. No credentials and no outbound network calls.
$request = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
$scenario = explode('/', trim($_SERVER['REQUEST_URI'], '/'))[0];
$input = $request['input'] ?? [];
$followUp = is_array($input) && ($input[0]['type'] ?? null) === 'function_call_output';
$lastCall = $followUp ? $input[0]['call_id'] : null;
$toolOutput = $followUp ? json_decode($input[0]['output'], true) : null;
$tools = array_column($request['tools'] ?? [], null, 'name');
$hasImage = in_array('image_generation', array_column($request['tools'] ?? [], 'type'), true);
$format = $request['text']['format'] ?? null;
$output = [];
$text = 'ready';

if ($scenario === 'request-failure' && str_contains(json_encode($input), 'fail-now')) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"error":{"message":"fixture failure"}}';
    return;
}

// Common callbacks are requested once per high-level operation, not once per context.
if ($scenario === 'ask' && !$followUp && isset($tools['ask_user_question'])) {
    $output[] = ['type' => 'function_call', 'name' => 'ask_user_question', 'call_id' => 'ask', 'arguments' => '{"question":"Which variant?"}'];
    $text = '';
} elseif (isset($tools['write_text']) && (!$followUp || $lastCall === 'ask' || $scenario === 'text-limit' || ($toolOutput['ok'] ?? true) === false)) {
    $edits = [
        ['search' => 'one', 'replacement' => 'ONE'],
        ['search' => 'two', 'replacement' => 'TWO'],
    ];
    if ($scenario === 'rewrite') {
        $edits = [['search' => null, 'replacement' => 'new text']];
    } elseif ($scenario === 'text-limit' || ($scenario === 'text-retry' && !$followUp)) {
        $edits = [['search' => 'absent', 'replacement' => 'fixed']];
    }
    $output[] = ['type' => 'function_call', 'name' => 'write_text', 'call_id' => 'text', 'arguments' => json_encode(['edits' => $edits])];
    $text = '';
} elseif (isset($tools['write_files']) && (!$followUp || $lastCall === 'ask' || $scenario === 'file-limit' || ($toolOutput['ok'] ?? true) === false)) {
    if (in_array($scenario, ['file-retry', 'file-limit', 'file-unresolved'], true)) {
        if ($followUp && $scenario === 'file-unresolved') {
            $text = 'I stopped with unresolved files.';
        } else {
            $files = !$followUp || $scenario === 'file-limit'
                ? [
                    ['filename' => 'targetFile1', 'edits' => [['search' => 'one', 'replacement' => 'ONE']]],
                    ['filename' => 'targetFile2', 'edits' => [['search' => 'repeat', 'replacement' => 'fixed']]],
                ]
                : [['filename' => 'targetFile2', 'edits' => [['search' => 'repeat two', 'replacement' => 'fixed two']]]];
            $output[] = ['type' => 'function_call', 'name' => 'write_files', 'call_id' => 'files', 'arguments' => json_encode(['files' => $files])];
            $text = '';
        }
    } else {
        $output[] = ['type' => 'function_call', 'name' => 'write_files', 'call_id' => 'files', 'arguments' => json_encode([
            'files' => [['filename' => 'targetFile1', 'edits' => [['search' => null, 'replacement' => 'updated']]]],
        ])];
        $text = '';
    }
} elseif ($hasImage) {
    if ($request['stream'] ?? false) {
        http_response_code(400);
        echo '{"error":{"message":"Image calls must not stream"}}';
        return;
    }
    $output[] = ['type' => 'image_generation_call', 'result' => base64_encode('fixture-image')];
    $text = '';
} elseif (($format['name'] ?? null) === 'StructPatch') {
    $text = json_encode(['unsupported' => false, 'operations' => [
        ['op' => 'replace', 'path' => '/value', 'value_json' => '"updated"', 'from' => null],
    ]]);
} elseif ($format !== null) {
    $text = isset($format['schema']['properties']['items']) ? '{"items":[{"value":"ready"}]}' : '{"value":"ready"}';
}
if ($text !== '') {
    $output[] = ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]];
}
$body = [
    'id' => 'ctx_' . bin2hex(random_bytes(8)),
    'status' => 'completed',
    'model' => $request['model'],
    'output' => $output,
    'fixture_request' => $request,
    'usage' => ['input_tokens' => 2048, 'output_tokens' => 32, 'total_tokens' => 2080],
];
if ($scenario !== 'missing-cache') {
    $body['usage']['input_tokens_details'] = ['cached_tokens' => $scenario === 'zero-cache' ? 0 : 1024];
}
if ($request['stream'] ?? false) {
    header('Content-Type: text/event-stream');
    if ($text !== '') {
        echo 'data: ' . json_encode(['type' => 'response.output_text.delta', 'delta' => $text]) . "\n\n";
    }
    echo 'data: ' . json_encode(['type' => 'response.completed', 'response' => $body]) . "\n\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($body);
}
