<?php

declare(strict_types=1);

use Phore\AiHarness\PromptType\FilePrompt;

require dirname(__DIR__) . '/vendor/autoload.php';

// Start with the simplest possible one-shot request. Common options are shown
// here once; normal calls should only set the options they actually need.
echo phore_ai_text('Schreibe ein vierzeiliges Gedicht über einen verregneten Herbstmorgen.', [
    'client' => null,
    'model' => 'gpt-5-mini',
    'reasoning' => ['effort' => 'medium'],
    'timeout' => 120,
    'connect_timeout' => 10,
    'debug_log' => true,
]);

// One-shot questions can attach source material directly to the call.
$summary = phore_ai_text([
    'Fasse die wichtigsten Aussagen der Datei in drei Punkten zusammen.',
    FilePrompt::fromFile('/path/to/File.pdf'),
]);

// Small typed decisions are convenience calls when no shared conversation is
// needed.
$isRelevant = phore_ai_yes_no('Ist der Text für einen technischen Review relevant?');
$category = phore_ai_choice('Welche Kategorie passt?', ['news', 'guide', 'review']);

echo $summary . PHP_EOL;
var_dump($isRelevant, $category);

// For several calls that build on each other, continue with 02-context.php.
