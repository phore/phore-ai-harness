<?php

declare(strict_types=1);

// Deterministic Responses API fixture; never forwards requests to an external API.
$request = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
$scenario = explode('/', trim($_SERVER['REQUEST_URI'], '/'))[0];
$round = isset($request['previous_response_id']) ? (int) substr($request['previous_response_id'], 2) + 1 : 0;
if ($scenario === 'transport') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'fixture authentication failure']]);
    return;
}
$output = [];
$text = "First line\nSecond line";
$toolScenario = in_array($scenario, ['retry', 'limit', 'business', 'task', 'internal', 'binding', 'named', 'serialize', 'two-errors', 'invalid-json'], true);
if ($toolScenario && ($round === 0 || $scenario === 'limit')) {
    $args = match ($scenario) {
        'retry', 'limit', 'two-errors' => '{"value":0}',
        'invalid-json' => '{bad-json',
        'binding' => '{"value":[]}',
        'named' => '{"unknown":1}',
        default => '{"value":1}',
    };
    $output[] = ['type' => 'function_call', 'name' => 'sample', 'call_id' => 'call_' . $round, 'arguments' => $args];
    if ($scenario === 'two-errors') {
        $output[] = ['type' => 'function_call', 'name' => 'sample', 'call_id' => 'call_second', 'arguments' => '{"value":0}'];
    }
    $text = '';
} elseif (in_array($scenario, ['retry', 'binding', 'named'], true) && $round === 1) {
    $output[] = ['type' => 'function_call', 'name' => 'sample', 'call_id' => 'call_1', 'arguments' => '{"value":7}'];
    $text = '';
} elseif ($scenario === 'image') {
    if ($request['stream'] ?? false) {
        http_response_code(400);
        echo '{"error":{"message":"Images must not stream"}}';
        return;
    }
    $output[] = ['type' => 'image_generation_call', 'result' => base64_encode('image-bytes')];
    $text = '';
} elseif ($scenario === 'struct' || $scenario === 'array') {
    $text = $scenario === 'struct' ? '{"value":7}' : '{"items":[{"value":7}]}';
} elseif ($scenario === 'invalid-struct') {
    $text = '{bad-json';
} elseif ($scenario === 'mode') {
    $text = ($request['stream'] ?? false) ? 'stream' : 'json';
} elseif ($scenario === 'file') {
    if ($round === 0) {
        $filenames = [];
        foreach ($request['input'] as $message) {
            foreach ($message['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'input_file') {
                    $filenames[] = $part['filename'];
                }
            }
        }
        $output[] = ['type' => 'function_call', 'name' => 'write_files', 'call_id' => 'file_0',
            'arguments' => json_encode(['filenames' => $filenames, 'contents' => array_fill(0, count($filenames), 'updated')])];
        $text = '';
    }
}
if ($text !== '') {
    $output[] = ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]];
}
$body = ['id' => 'r_' . $round, 'status' => 'completed', 'output' => $output];
if ($scenario !== 'no-usage') {
    $body['usage'] = ['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12];
}
if ($request['stream'] ?? false) {
    header('Content-Type: text/event-stream');
    foreach (str_split($text, 3) as $delta) {
        echo 'data: ' . json_encode(['type' => 'response.output_text.delta', 'delta' => $delta]) . "\n\n";
        flush();
    }
    echo 'data: ' . json_encode(['type' => 'response.completed', 'response' => $body]) . "\n\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($body);
}
