<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ResumeOptions;
use Phore\AiHarness\ResumeStateException;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\ToolType\WebAccessTool;

require dirname(__DIR__) . '/vendor/autoload.php';

session_start();

// Rebuild the same prompt/tool setup from code on every request.
$context = new AiContext(
    prompts: [
        new SystemPrompt('Research carefully and answer concisely.'),
        new WebAccessTool(),
    ],
    options: ['model' => 'gpt-5-mini'],
);

if (isset($_SESSION['ai_context_state'])) {
    try {
        $context->importState($_SESSION['ai_context_state']);
    } catch (ResumeStateException $error) {
        // Default policy: handle changed setup/provider/state explicitly.
        error_log($error->getMessage());
        unset($_SESSION['ai_context_state']);
    }
}

$context->do('Prepare the current facts for the next answer.');
$context->setCheckpoint('prepared');
echo $context->text('Summarize the prepared facts in three sentences.') . PHP_EOL;

$_SESSION['ai_context_state'] = $context->exportState();

// Alternative: discard an incompatible imported cursor and start blank.
// $context->importState(
//     $_SESSION['ai_context_state'],
//     new ResumeOptions(onMismatch: ResumeOptions::ON_MISMATCH_RESTART),
// );
