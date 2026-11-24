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

// 4. Edit multiple texts in one task and return a typed report instead of the
// full text. An unavailable fact leaves one target pending, without blocking
// independent edits. The report describes the work; the input objects hold it.
class PendingTextEdit
{
    public string $alias;
    public string $reason;
}

class TextEditReport
{
    public int $completedCount;
    public string $message;

    /** @var PendingTextEdit[] */
    public array $pending;
}

$intro = new EditableTextPrompt('Welcome to our practce.', alias: 'intro');
$footer = new EditableTextPrompt('Contakt us.', alias: 'footer');
$contact = new EditableTextPrompt('Phone: TBD', alias: 'contact');

$report = phore_ai_struct([
    'Correct intro and footer. Replace TBD in contact with our verified phone number. '
    . 'Do not invent missing facts. Complete independent edits even if others are blocked. '
    . 'Count targets whose requested edits were completed. Report unfinished targets by alias and reason.',
    $intro,
    $footer,
    $contact,
], TextEditReport::class);

echo $intro->getContent() . PHP_EOL;   // Expected: Welcome to our practice.
echo $footer->getContent() . PHP_EOL;  // Expected: Contact us.
echo $contact->getContent() . PHP_EOL; // Expected: Phone: TBD
echo $report->message . PHP_EOL;
echo $report->completedCount . PHP_EOL; // Expected: 2
foreach ($report->pending as $pending) {
    echo $pending->alias . ': ' . $pending->reason . PHP_EOL;
}
