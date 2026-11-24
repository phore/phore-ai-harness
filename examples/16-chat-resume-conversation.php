<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;

require dirname(__DIR__) . '/vendor/autoload.php';

// First request: start a new chat and return the answer and ID as JSON.
$context = new AiContext();
$responseJson = json_encode([
    'text' => $context->text('My name is Alex.'),
    'responseId' => $context->getResponseId(),
], JSON_THROW_ON_ERROR);
echo $responseJson . PHP_EOL;

// Simulated frontend: send only the next message and the received ID.
$response = json_decode($responseJson, true, flags: JSON_THROW_ON_ERROR);
$requestJson = json_encode([
    'message' => 'What is my name?',
    'responseId' => $response['responseId'],
], JSON_THROW_ON_ERROR);

// Next request: rebuild the context. In a real API, authorize the incoming ID first.
$request = json_decode($requestJson, true, flags: JSON_THROW_ON_ERROR);
$context = new AiContext();
$context->resumeFromResponseId($request['responseId']);

echo json_encode([
    'text' => $context->text($request['message']), // The answer remembers Alex.
    'responseId' => $context->getResponseId(), // Send this ID back for the next turn.
], JSON_THROW_ON_ERROR) . PHP_EOL;
