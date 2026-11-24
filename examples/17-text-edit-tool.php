<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\EditableTextPrompt;

require dirname(__DIR__) . '/vendor/autoload.php';

// Create the context once (or use an existing, not yet started context).
$context = new AiContext();

// Attach two independent editable inputs to the context.
$intro = new EditableTextPrompt('Welcome to our practce.', alias: 'intro', type: 'markdown');
$footer = new EditableTextPrompt('Contact us for more information.', alias: 'footer');

$context = $context->withPrepared([$intro, $footer]);

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
