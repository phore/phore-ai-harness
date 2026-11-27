<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\ImagePrompt;
use Phore\AiHarness\PromptType\MultiPrompt;
use Phore\AiHarness\PromptType\StructPrompt;
use Phore\AiHarness\ToolType\CallbackTool;

require dirname(__DIR__) . '/vendor/autoload.php';

// Optional: php examples/05-callback-tool.php desktop.png mobile.png
// Supply screenshots captured at the indicated viewport sizes.
if (isset($argv[1], $argv[2])) {
    $desktopPath = $argv[1];
    $mobilePath = $argv[2];

    $context = new AiContext(prompts: [
        new CallbackTool(
            static fn (): MultiPrompt => new MultiPrompt([
                ImagePrompt::fromFile(
                    $desktopPath,
                    alias: 'desktop',
                    instructions: 'Desktop viewport 1440 × 900 CSS pixels. Inspect the navigation and spacing.',
                ),
                ImagePrompt::fromFile(
                    $mobilePath,
                    alias: 'mobile',
                    instructions: 'Mobile viewport 393 × 852 CSS pixels. Inspect wrapping and horizontal overflow.',
                ),
                new StructPrompt(
                    ['desktopWidth' => 1440, 'mobileWidth' => 393],
                    instructions: 'Viewport widths in CSS pixels; use these to interpret both screenshots.',
                ),
            ]),
            name: 'load_screenshots',
            description: 'Return desktop and mobile screenshots with viewport metadata.',
        ),
    ]);

    echo $context->text('Call load_screenshots and compare the responsive layout.') . PHP_EOL;
    return;
}

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
