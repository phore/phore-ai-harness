<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\EditableTextPrompt;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. Prepare two editable inputs for a conversation.
$intro = new EditableTextPrompt('Welcome to our practce.', alias: 'intro', type: 'markdown');
$footer = new EditableTextPrompt('Contact us for more information.', alias: 'footer');

$context = new AiContext(prompts: [$intro, $footer]);

// The context provides shared editing tools automatically. text() returns the chat reply.
$message = $context->text('Correct the spelling in intro and summarize the change.');
echo $message . PHP_EOL;
echo $intro->getContent() . PHP_EOL; // Expected: Welcome to our practice.
echo $intro->getOriginalContent() . PHP_EOL; // Welcome to our practce.
echo $footer->getContent() . PHP_EOL; // Unchanged.

// The same objects receive subsequent replacements or complete rewrites.
$message = $context->text('Rewrite intro as a friendly greeting and shorten footer. Explain both changes.');
echo $message . PHP_EOL;
echo $intro->getContent() . PHP_EOL;
echo $footer->getContent() . PHP_EOL;
$responseId = $context->getResponseId();

// Keep the objects for change detection and application-managed undo.
if ($intro->hasChanges()) {
    $intro->reset(); // Restore original; no AI call.
}
echo $intro->getContent() . PHP_EOL;

// A running context can also edit a new input for a single request.
$headline = new EditableTextPrompt('Our practice', alias: 'headline');
$message = $context->text([
    'Make headline more inviting and summarize the change.',
    $headline,
]);
echo $message . PHP_EOL;
echo $headline->getContent() . PHP_EOL;

// 2. Independent one-shot edit with a separate conversational reply.
$draft = new EditableTextPrompt('Welcome to our practce.', alias: 'draft');
$message = phore_ai_text([
    'Correct the spelling in draft and explain what changed.',
    $draft,
]);
echo $message . PHP_EOL;
echo $draft->getContent() . PHP_EOL; // Expected: Welcome to our practice.
echo $draft->getOriginalContent() . PHP_EOL; // Welcome to our practce.

// 3. Edit without a conversational reply. do() returns a success flag.
$ok = phore_ai_do([
    'Rewrite draft as a short, friendly greeting.',
    $draft,
]);
var_dump($ok);
echo $draft->getContent() . PHP_EOL;
