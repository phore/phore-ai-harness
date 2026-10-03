<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\CallbackTool;

require dirname(__DIR__) . '/vendor/autoload.php';

$apiCalls = [];

$askUser = new CallbackTool(
    static function (string $question): string {
        // Mocked user answer for a deterministic example.
        return 'Dortmund';
    },
    name: 'ask_user',
    description: 'Ask the user for one missing value and return the answer.',
);

$mockApi = new CallbackTool(
    static function (string $city) use (&$apiCalls): string {
        $apiCalls[] = $city;

        return 'API call accepted for ' . $city;
    },
    name: 'mock_api_call',
    description: 'Mock an application API call for the supplied city.',
);

$context = new AiContext(
    prompts: [$askUser, $mockApi],
    options: ['model' => 'gpt-5-mini'],
);

// do() is for autonomous work where the model may reason and call tools while
// the caller only needs success/failure instead of generated text.
$ok = $context->do(
    'Ask the user which city to use, then call mock_api_call exactly once with that answer.',
    throw: true,
);
// returns: true

echo $apiCalls === ['Dortmund']
    ? "mock_api_call executed\n"
    : "mock_api_call was not executed as expected\n";

// The same context can be checkpointed or exported after do(); see 01-basic.php
// for checkpoint, rollback and state-resume usage.
