<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\CodeInterpreterTool;

require dirname(__DIR__) . '/vendor/autoload.php';

$remembered = [];
$remember = new CallbackTool(
    static function (string $note) use (&$remembered): string {
        $remembered[] = $note;
        return 'saved';
    },
    name: 'remember_result',
);

$context = new AiContext(
    prompts: [
        new CodeInterpreterTool(['container' => ['type' => 'auto']]),
        $remember,
    ],
    options: ['model' => 'gpt-5-mini'],
);

// Reasoning and tools run normally; only the final text response is not returned.
$context->do(
    'Calculate 17 * 23, call remember_result with the result and keep it for the next step.',
    throw: true,
);

$context->setCheckpoint('prepared');

$file = tempnam(sys_get_temp_dir(), 'phore-ai-do-');
if ($file === false) {
    throw new RuntimeException('Could not create the temporary example file.');
}

try {
    file_put_contents($file, "Calculation result:\n");
    $context->file(
        'Update the target file with the prepared result and a one-sentence explanation.',
        $file,
    );

    echo file_get_contents($file);
} finally {
    @unlink($file);
}
