<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\CallbackTool;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return array{code: string, message: string}
 */
function create_discount_code(string $customerId, int $percent): array
{
    return [
        'code' => strtoupper($customerId) . '-' . $percent . 'OFF',
        'message' => 'Rabattcode für ' . $customerId . ' mit ' . $percent . '% Rabatt.',
    ];
}

// CallbackTool is a normal prepared tool on AiContext. Its PHP signature and
// PHPDoc are converted to the OpenAI function schema through phore/schema.
$context = new AiContext(prompts: [
    new CallbackTool(
        'create_discount_code',
        name: 'create_discount_code',
    ),
]);

$response = $context->text(
    'Erzeuge für Kunde C-1001 genau 15 Prozent Rabatt. Nutze das Tool und nenne nur den Code.',
);

echo $response . PHP_EOL;
