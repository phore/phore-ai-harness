<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\ToolType\WebAccessTool;

require dirname(__DIR__) . '/vendor/autoload.php';

// Both variants below represent the same three-step workflow. The file path is
// intentionally illustrative; replace it with the real input file.

// Variant A: consecutive helper calls share state through the same name.
phore_ai_do([
    'Prüfe die Aussagen der Datei mit aktuellen Quellen im Web.',
    FilePrompt::fromFile('/path/to/File.pdf'),
    new WebAccessTool(),
], throw: true, options: ['ai_context' => 'article-review']);

$correct = phore_ai_yes_no(
    'Stimmen die zentralen Aussagen des zuvor geprüften Artikels?',
    options: ['ai_context' => 'article-review'],
);

$summary = phore_ai_text(
    'Fasse notwendige Korrekturen kurz zusammen.',
    ['ai_context' => 'article-review'],
);

// The string must match exactly on every helper call. A typo or renamed value
// selects another context, so repeated workflows are clearer as one object.

// Variant B: the same workflow with an explicit context object.
$context = new AiContext(prompts: [
    FilePrompt::fromFile('/path/to/File.pdf'),
    new WebAccessTool(),
]);

$context->do('Prüfe die Aussagen der Datei mit aktuellen Quellen im Web.', throw: true);
$context->setCheckpoint('verified');

$correct = $context->yesNo('Stimmen die zentralen Aussagen des zuvor geprüften Artikels?');
$summary = $context->text('Fasse notwendige Korrekturen kurz zusammen.');

// rollback() only restores the conversation cursor; it does not undo tool side
// effects, file changes or incurred cost.
$context->rollback('verified');
$alternative = $context->text('Nenne nur die drei wichtigsten Korrekturen.');

// A chat can persist the provider cursor between requests. Rebuild the same
// prompts/tools before importing the state on the next request.
$state = $context->exportState();

$resumed = new AiContext(prompts: [
    FilePrompt::fromFile('/path/to/File.pdf'),
    new WebAccessTool(),
]);
$resumed->importState($state);
$followUp = $resumed->text('Welche Korrektur ist am wichtigsten?');

var_dump($correct);
echo $summary . PHP_EOL . $alternative . PHP_EOL . $followUp . PHP_EOL;
